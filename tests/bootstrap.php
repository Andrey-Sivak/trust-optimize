<?php
/**
 * PHPUnit bootstrap for isolated unit tests.
 *
 * @package TrustOptimize\Tests
 */

if ( ! function_exists( 'wp_json_encode' ) ) {
	/**
	 * Minimal wp_json_encode() fallback for pure unit tests outside WordPress.
	 *
	 * @param mixed $data Data to encode.
	 * @return string|false
	 */
	function wp_json_encode( $data ) {
		return json_encode( $data );
	}
}

require_once __DIR__ . '/../includes/value/OperationResult.php';
require_once __DIR__ . '/../includes/value/OptimizeResult.php';
require_once __DIR__ . '/../includes/value/DeleteResult.php';
require_once __DIR__ . '/../includes/value/CapabilityCheck.php';
require_once __DIR__ . '/../includes/value/ImageProfile.php';
