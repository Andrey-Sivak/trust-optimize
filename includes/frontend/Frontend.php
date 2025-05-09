<?php
/**
 * Frontend functionality class
 *
 * @package TrustOptimize
 */

namespace TrustOptimize\Frontend;

/**
 * Class Frontend
 */
class Frontend {

	/**
	 * Frontend constructor.
	 */
	public function __construct() {
		// Enqueue scripts and styles
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
	}

	/**
	 * Enqueue frontend scripts and styles.
	 */
	public function enqueue_scripts() {
		// Enqueue frontend scripts
		wp_enqueue_script(
			'trust-optimize',
			TRUST_OPTIMIZE_PLUGIN_URL . 'assets/js/trust-optimize.js',
			array( 'jquery' ),
			TRUST_OPTIMIZE_VERSION,
			true
		);

		// Localize script with settings
		wp_localize_script(
			'trust-optimize',
			'trustOptimizeSettings',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'trust-optimize-nonce' ),
			)
		);
	}
}
