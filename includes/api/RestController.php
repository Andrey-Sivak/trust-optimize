<?php
/**
 * REST API Controller
 *
 * @package TrustOptimize
 */

namespace TrustOptimize\API;

use WP_REST_Controller;
use WP_REST_Server;
use WP_Error;
use TrustOptimize\Bulk\BulkJob;
use TrustOptimize\Bulk\BulkJobRepository;
use TrustOptimize\Bulk\BulkJobRunner;
use TrustOptimize\Bulk\EligibilityQuery;
use TrustOptimize\Database\ImageModel;
use TrustOptimize\Service\ImageCleanupService;
use TrustOptimize\Service\ImageOptimizationService;

/**
 * Class RestController
 */
class RestController extends WP_REST_Controller {

	/**
	 * Plugin namespace
	 *
	 * @var string
	 */
	protected $namespace = 'trust-optimize/v1';

	/**
	 * Base for the endpoint
	 *
	 * @var string
	 */
	protected $rest_base = '';

	/**
	 * Register routes
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/status',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_status' ),
					'permission_callback' => array( $this, 'get_status_permissions_check' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/image/(?P<id>[\d]+)/status',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_image_status' ),
					'permission_callback' => array( $this, 'get_status_permissions_check' ),
					'args'                => array(
						'id' => array(
							'required'          => true,
							'validate_callback' => function ( $param ) {
								return is_numeric( $param );
							},
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/bulk/inventory',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'start_inventory' ),
					'permission_callback' => array( $this, 'manage_permissions_check' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/bulk/start',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'start_bulk' ),
					'permission_callback' => array( $this, 'manage_permissions_check' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/bulk/status',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_bulk_status' ),
					'permission_callback' => array( $this, 'manage_permissions_check' ),
				),
			)
		);

		foreach ( array( 'pause', 'resume', 'cancel' ) as $action ) {
			register_rest_route(
				$this->namespace,
				'/bulk/' . $action,
				array(
					array(
						'methods'             => WP_REST_Server::CREATABLE,
						'callback'            => array( $this, 'bulk_' . $action ),
						'permission_callback' => array( $this, 'manage_permissions_check' ),
					),
				)
			);
		}

		register_rest_route(
			$this->namespace,
			'/image/(?P<id>[\d]+)/sync',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'sync_image' ),
					'permission_callback' => array( $this, 'manage_permissions_check' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/image/(?P<id>[\d]+)/remove',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'remove_image' ),
					'permission_callback' => array( $this, 'manage_permissions_check' ),
				),
			)
		);
	}

	/**
	 * Check permissions for the status endpoint
	 *
	 * @param \WP_REST_Request $request The request object.
	 * @return bool|\WP_Error
	 */
	public function get_status_permissions_check( $request ) {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Check permissions for management endpoints.
	 *
	 * @param \WP_REST_Request $request The request object.
	 * @return bool|\WP_Error
	 */
	public function manage_permissions_check( $request ) {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Get plugin status
	 *
	 * @param \WP_REST_Request $request The request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_status( $request ) {
		$data = array(
			'version'   => TRUST_OPTIMIZE_VERSION,
			'status'    => 'active',
			'timestamp' => time(),
		);

		return rest_ensure_response( $data );
	}

	/**
	 * Get optimization status for a specific image attachment.
	 *
	 * @param \WP_REST_Request $request The request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_image_status( $request ) {
		$attachment_id = (int) $request->get_param( 'id' );

		if ( ! get_post( $attachment_id ) || 'attachment' !== get_post_type( $attachment_id ) ) {
			return new WP_Error(
				'trust_optimize_invalid_attachment',
				__( 'Invalid attachment ID.', 'trust-optimize' ),
				array( 'status' => 404 )
			);
		}

		$image_model = new ImageModel();
		$status_data = $image_model->get_status( $attachment_id );

		if ( ! $status_data ) {
			return rest_ensure_response(
				array(
					'attachment_id'   => $attachment_id,
					'status'          => 'none',
					'total_tasks'     => 0,
					'completed_tasks' => 0,
					'progress'        => 0,
				)
			);
		}

		$total     = (int) $status_data['total_tasks'];
		$completed = (int) $status_data['completed_tasks'];
		$progress  = $total > 0 ? round( ( $completed / $total ) * 100 ) : 100;

		return rest_ensure_response(
			array(
				'attachment_id'   => $attachment_id,
				'status'          => $status_data['status'],
				'total_tasks'     => $total,
				'completed_tasks' => $completed,
				'progress'        => $progress,
			)
		);
	}

	/**
	 * Start inventory job.
	 *
	 * @param \WP_REST_Request $request The request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function start_inventory( $request ) {
		return $this->create_and_start_bulk_job( BulkJob::TYPE_INVENTORY, $request );
	}

	/**
	 * Start sync/remove bulk job.
	 *
	 * @param \WP_REST_Request $request The request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function start_bulk( $request ) {
		$type = $request->get_param( 'type' );

		if ( ! in_array( $type, array( BulkJob::TYPE_SYNC, BulkJob::TYPE_REMOVE ), true ) ) {
			return new WP_Error(
				'trust_optimize_invalid_bulk_type',
				__( 'Invalid bulk job type.', 'trust-optimize' ),
				array( 'status' => 400 )
			);
		}

		if ( BulkJob::TYPE_REMOVE === $type && ! $this->is_confirmed( $request ) ) {
			return $this->confirmation_required_error();
		}

		return $this->create_and_start_bulk_job( $type, $request );
	}

	/**
	 * Get bulk job status.
	 *
	 * @param \WP_REST_Request $request The request object.
	 * @return \WP_REST_Response
	 */
	public function get_bulk_status( $request ) {
		$repository = new BulkJobRepository();
		$job        = $repository->get_active_job();

		if ( $job && in_array( $job->get_status(), array( BulkJob::STATUS_PENDING, BulkJob::STATUS_RUNNING ), true ) ) {
			$runner = new BulkJobRunner( $repository );
			$this->run_status_bounded_tick( $runner, $job );
			$job = $repository->get( $job->get_id() );
		}

		if ( ! $job ) {
			$job = $repository->get_latest_job();
		}

		return rest_ensure_response(
			array(
				'job' => $job ? $job->to_array() : null,
			)
		);
	}

	/**
	 * Pause active bulk job.
	 *
	 * @param \WP_REST_Request $request The request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function bulk_pause( $request ) {
		return $this->control_active_job( 'pause', false );
	}

	/**
	 * Resume active bulk job.
	 *
	 * @param \WP_REST_Request $request The request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function bulk_resume( $request ) {
		return $this->control_active_job( 'resume', false );
	}

	/**
	 * Cancel active bulk job.
	 *
	 * @param \WP_REST_Request $request The request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function bulk_cancel( $request ) {
		if ( ! $this->is_confirmed( $request ) ) {
			return $this->confirmation_required_error();
		}

		return $this->control_active_job( 'cancel', true );
	}

	/**
	 * Sync one image.
	 *
	 * @param \WP_REST_Request $request The request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function sync_image( $request ) {
		$attachment_id = (int) $request->get_param( 'id' );
		$error         = $this->validate_attachment( $attachment_id );

		if ( $error ) {
			return $error;
		}

		$service = new ImageOptimizationService();
		$result  = $service->optimize_attachment( $attachment_id );

		return rest_ensure_response( $result->to_array() );
	}

	/**
	 * Remove generated variants for one image.
	 *
	 * @param \WP_REST_Request $request The request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function remove_image( $request ) {
		if ( ! $this->is_confirmed( $request ) ) {
			return $this->confirmation_required_error();
		}

		$attachment_id = (int) $request->get_param( 'id' );
		$error         = $this->validate_attachment( $attachment_id );

		if ( $error ) {
			return $error;
		}

		$service = new ImageCleanupService();
		$result  = $service->cleanup_attachment( $attachment_id );

		return rest_ensure_response( $result->to_array() );
	}

	/**
	 * Create and start a bulk job.
	 *
	 * @param string           $type    Job type.
	 * @param \WP_REST_Request $request The request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function create_and_start_bulk_job( $type, $request ) {
		$repository  = new BulkJobRepository();
		$eligibility = new EligibilityQuery();
		$total       = BulkJob::TYPE_REMOVE === $type ? $eligibility->count_plugin_managed_attachments() : $eligibility->count_eligible_attachments();
		$job         = $repository->create( $type, array(), '', $total );

		if ( ! $job ) {
			return new WP_Error(
				'trust_optimize_active_bulk_job_exists',
				__( 'Another bulk job is already active.', 'trust-optimize' ),
				array( 'status' => 409 )
			);
		}

		$runner = new BulkJobRunner( $repository, $eligibility );
		$runner->start( $job->get_id() );
		$this->run_bounded_tick( $runner, $job->get_id(), 1, 3 );

		return rest_ensure_response(
			array(
				'job' => $repository->get( $job->get_id() )->to_array(),
			)
		);
	}

	/**
	 * Run one bounded tick from REST status polling.
	 *
	 * Action Scheduler/WP-Cron may not dispatch during admin REST requests in
	 * local Docker or locked-down hosting environments. Polling status can
	 * safely advance one bounded tick while keeping resumability and avoiding
	 * duplicate concurrent ticks.
	 *
	 * @param BulkJobRunner $runner Runner instance.
	 * @param BulkJob       $job    Job value object.
	 */
	private function run_status_bounded_tick( BulkJobRunner $runner, BulkJob $job ) {
		$lock_key = 'trust_optimize_bulk_status_tick_' . $job->get_id();

		if ( get_transient( $lock_key ) ) {
			return;
		}

		set_transient( $lock_key, 1, 15 );
		try {
			if ( BulkJob::STATUS_PENDING === $job->get_status() ) {
				$runner->start( $job->get_id() );
			}

			$this->run_bounded_tick( $runner, $job->get_id(), 1, 3 );
		} finally {
			delete_transient( $lock_key );
		}
	}

	/**
	 * Run one bounded bulk tick with temporary limits.
	 *
	 * @param BulkJobRunner $runner      Runner instance.
	 * @param int           $job_id      Job ID.
	 * @param int           $batch_size  Max attachments for this tick.
	 * @param int           $time_budget Max seconds for this tick.
	 */
	private function run_bounded_tick( BulkJobRunner $runner, $job_id, $batch_size, $time_budget ) {
		$batch_filter = function () use ( $batch_size ) {
			return $batch_size;
		};
		$time_filter  = function () use ( $time_budget ) {
			return $time_budget;
		};

		add_filter( 'trust_optimize_bulk_batch_size', $batch_filter, 99 );
		add_filter( 'trust_optimize_bulk_time_budget', $time_filter, 99 );

		try {
			$runner->tick( $job_id );
		} finally {
			remove_filter( 'trust_optimize_bulk_batch_size', $batch_filter, 99 );
			remove_filter( 'trust_optimize_bulk_time_budget', $time_filter, 99 );
		}
	}

	/**
	 * Control the active bulk job.
	 *
	 * @param string $action       Action name.
	 * @param bool   $allow_latest Allow latest job fallback.
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function control_active_job( $action, $allow_latest ) {
		$repository = new BulkJobRepository();
		$job        = $repository->get_active_job();

		if ( ! $job && $allow_latest ) {
			$job = $repository->get_latest_job();
		}

		if ( ! $job ) {
			return rest_ensure_response( array( 'job' => null ) );
		}

		$runner = new BulkJobRunner( $repository );
		$runner->$action( $job->get_id() );

		return rest_ensure_response(
			array(
				'job' => $repository->get( $job->get_id() )->to_array(),
			)
		);
	}

	/**
	 * Validate attachment exists.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return WP_Error|null
	 */
	private function validate_attachment( $attachment_id ) {
		if ( ! get_post( $attachment_id ) || 'attachment' !== get_post_type( $attachment_id ) ) {
			return new WP_Error(
				'trust_optimize_invalid_attachment',
				__( 'Invalid attachment ID.', 'trust-optimize' ),
				array( 'status' => 404 )
			);
		}

		return null;
	}

	/**
	 * Check destructive operation confirmation.
	 *
	 * @param \WP_REST_Request $request The request object.
	 * @return bool
	 */
	private function is_confirmed( $request ) {
		return true === rest_sanitize_boolean( $request->get_param( 'confirm' ) );
	}

	/**
	 * Build confirmation required error.
	 *
	 * @return WP_Error
	 */
	private function confirmation_required_error() {
		return new WP_Error(
			'trust_optimize_confirmation_required',
			__( 'Confirmation is required for this operation.', 'trust-optimize' ),
			array( 'status' => 400 )
		);
	}
}
