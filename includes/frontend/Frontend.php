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
		// The current frontend path rewrites safe images to <picture> markup on
		// the server. Do not enqueue the legacy adaptive JS because no frontend
		// endpoint exists for width-based image generation.
	}
}
