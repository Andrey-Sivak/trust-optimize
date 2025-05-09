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
}
