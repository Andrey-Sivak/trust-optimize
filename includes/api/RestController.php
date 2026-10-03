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
use TrustOptimize\Bulk\BulkProducer;
use TrustOptimize\Bulk\JobProgress;
use TrustOptimize\Processing\AttachmentProcessor;
use TrustOptimize\Service\ImageCleanupService;
use TrustOptimize\Storage\AttachmentRepository;

/**
 * Class RestController
 */
class RestController extends WP_REST_Controller {

	/**
	 * Most attachments one status request may ask about.
	 */
	const MAX_STATUS_IDS = 100;

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
	 * Attachment repository.
	 *
	 * @var AttachmentRepository
	 */
	private $attachments;

	/**
	 * Attachment processor.
	 *
	 * @var AttachmentProcessor
	 */
	private $processor;

	/**
	 * Cleanup service.
	 *
	 * @var ImageCleanupService
	 */
	private $cleanup;

	/**
	 * Bulk job repository.
	 *
	 * @var BulkJobRepository
	 */
	private $jobs;

	/**
	 * Bulk job progress.
	 *
	 * @var JobProgress
	 */
	private $progress;

	/**
	 * Bulk job producer.
	 *
	 * @var BulkProducer
	 */
	private $producer;

	/**
	 * Constructor.
	 *
	 * @param AttachmentRepository $attachments Attachment repository.
	 * @param AttachmentProcessor  $processor   Attachment processor.
	 * @param ImageCleanupService  $cleanup     Cleanup service.
	 * @param BulkJobRepository    $jobs        Bulk job repository.
	 * @param JobProgress          $progress    Bulk job progress.
	 * @param BulkProducer         $producer    Bulk job producer.
	 */
	public function __construct( AttachmentRepository $attachments, AttachmentProcessor $processor, ImageCleanupService $cleanup, BulkJobRepository $jobs, JobProgress $progress, BulkProducer $producer ) {
		$this->attachments = $attachments;
		$this->processor   = $processor;
		$this->cleanup     = $cleanup;
		$this->jobs        = $jobs;
		$this->progress    = $progress;
		$this->producer    = $producer;
	}

	/**
	 * Register the routes on rest_api_init.
	 */
	public function register() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

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
			'/images/status',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_images_status' ),
					'permission_callback' => array( $this, 'media_permissions_check' ),
					'args'                => array(
						'ids' => array(
							'required'          => true,
							'type'              => 'array',
							'items'             => array( 'type' => 'integer' ),
							'maxItems'          => self::MAX_STATUS_IDS,
							'validate_callback' => 'rest_validate_request_arg',
							'sanitize_callback' => 'wp_parse_id_list',
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
	 * Check permissions for the media library status endpoint: whoever sees the library may poll it.
	 *
	 * @param \WP_REST_Request $request The request object.
	 * @return bool
	 */
	public function media_permissions_check( $request ) {
		return current_user_can( 'upload_files' );
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
	 * Optimization state of several attachments; reads only.
	 *
	 * @param \WP_REST_Request $request The request object.
	 * @return \WP_REST_Response
	 */
	public function get_images_status( $request ) {
		return rest_ensure_response( array( 'states' => $this->attachments->get_states( $request->get_param( 'ids' ) ) ) );
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
		$job = $this->jobs->get_active_job();

		if ( ! $job ) {
			$job = $this->jobs->get_latest_job();
		}

		return rest_ensure_response(
			array(
				'job' => $job ? $this->progress->describe( $job ) : null,
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

		$result = $this->processor->sync( $attachment_id );

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

		$result = $this->cleanup->cleanup_attachment( $attachment_id );

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
		$job = $this->producer->launch( $type );

		if ( ! $job ) {
			return new WP_Error(
				'trust_optimize_active_bulk_job_exists',
				__( 'Another bulk job is already active.', 'trust-optimize' ),
				array( 'status' => 409 )
			);
		}

		return rest_ensure_response(
			array(
				'job' => $this->progress->describe( $job ),
			)
		);
	}

	/**
	 * Control the active bulk job.
	 *
	 * @param string $action       Action name.
	 * @param bool   $allow_latest Allow latest job fallback.
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function control_active_job( $action, $allow_latest ) {
		$job = $this->jobs->get_active_job();

		if ( ! $job && $allow_latest ) {
			$job = $this->jobs->get_latest_job();
		}

		if ( ! $job ) {
			return rest_ensure_response( array( 'job' => null ) );
		}

		$this->producer->$action( $job->get_id() );

		return rest_ensure_response(
			array(
				'job' => $this->progress->describe( $this->jobs->get( $job->get_id() ) ),
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
