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
use TrustOptimize\Database\ImageModel;

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
	 * Get plugin status
	 *
	 * @param \WP_REST_Request $request The request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_status( $request ) {
		$data = array(
			'version'   => TRUST_OPTIMIZE_VERSION,
			'status'    => 'active',
			'timestamp' => current_time( 'timestamp' ),
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
}
