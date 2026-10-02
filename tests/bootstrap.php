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

if ( ! function_exists( 'wp_normalize_path' ) ) {
	/**
	 * Copy of the core helper (wp-includes/functions.php), minus the stream-wrapper handling.
	 *
	 * @param string $path Path.
	 * @return string
	 */
	function wp_normalize_path( $path ) {
		$path = str_replace( '\\', '/', $path );
		return preg_replace( '|(?<=.)/+|', '/', $path );
	}
}

if ( ! function_exists( 'untrailingslashit' ) ) {
	/**
	 * Copy of the core helper.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	function untrailingslashit( $value ) {
		return rtrim( $value, '/\\' );
	}
}

if ( ! function_exists( 'trailingslashit' ) ) {
	/**
	 * Copy of the core helper.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	function trailingslashit( $value ) {
		return untrailingslashit( $value ) . '/';
	}
}

if ( ! function_exists( 'wp_upload_dir' ) ) {
	/**
	 * Unit-test stub: the uploads directory is taken from $GLOBALS['trust_optimize_test_uploads'].
	 *
	 * @return array
	 */
	function wp_upload_dir() {
		return array( 'basedir' => isset( $GLOBALS['trust_optimize_test_uploads'] ) ? $GLOBALS['trust_optimize_test_uploads'] : '' );
	}
}

require_once __DIR__ . '/../includes/core/Requirements.php';
require_once __DIR__ . '/../includes/value/OperationResult.php';
require_once __DIR__ . '/../includes/value/OptimizeResult.php';
require_once __DIR__ . '/../includes/value/DeleteResult.php';
require_once __DIR__ . '/../includes/utils/HtmlFragment.php';
require_once __DIR__ . '/../includes/utils/UploadsPath.php';
require_once __DIR__ . '/../includes/domain/VariantStatus.php';
require_once __DIR__ . '/../includes/domain/AttachmentState.php';
require_once __DIR__ . '/../includes/storage/AttachmentRepository.php';
require_once __DIR__ . '/../includes/naming/VariantNaming.php';
require_once __DIR__ . '/../includes/settings/OptimizationSettings.php';
require_once __DIR__ . '/../includes/planning/VariantPlanner.php';
