<?php
/**
 * Helper utility functions
 *
 * @package TrustOptimize
 */

namespace TrustOptimize\Utils;

/**
 * Class Helper
 */
class Helper {

	/**
	 * Check if current request is an AJAX request
	 *
	 * @return bool
	 */
	public static function is_ajax() {
		return defined( 'DOING_AJAX' ) && DOING_AJAX;
	}

	/**
	 * Check if current request is on admin screen
	 *
	 * @return bool
	 */
	public static function is_admin_screen() {
		return is_admin() && ! self::is_ajax();
	}

	/**
	 * Get file size in human-readable format
	 *
	 * @param int $bytes     File size in bytes
	 * @param int $precision Precision of rounding
	 * @return string
	 */
	public static function format_file_size( $bytes, $precision = 2 ) {
		$units = array( 'B', 'KB', 'MB', 'GB', 'TB' );

		$bytes = max( $bytes, 0 );
		$pow   = floor( ( $bytes ? log( $bytes ) : 0 ) / log( 1024 ) );
		$pow   = min( $pow, count( $units ) - 1 );

		$bytes /= pow( 1024, $pow );

		return round( $bytes, $precision ) . ' ' . $units[ $pow ];
	}

	/**
	 * Get the savings percentage between original and optimized size
	 *
	 * @param int $original_size    Original file size in bytes
	 * @param int $optimized_size   Optimized file size in bytes
	 * @param int $precision        Precision of rounding
	 * @return string|null
	 */
	public static function get_savings_percentage( $original_size, $optimized_size, $precision = 1 ) {
		if ( ! $original_size || ! $optimized_size ) {
			return null;
		}

		$savings = ( ( $original_size - $optimized_size ) / $original_size ) * 100;
		return round( $savings, $precision ) . '%';
	}

	/**
	 * Get the attachment ID from an image URL
	 *
	 * @param string $image_url Image URL
	 * @return int|false
	 */
	public static function get_attachment_id_from_url( $image_url ) {
		global $wpdb;

		// Remove any image size from the URL
		$image_url = preg_replace( '/-\d+x\d+(?=\.(jpg|jpeg|png|gif)$)/i', '', $image_url );

		// Get the upload directory
		$upload_dir = wp_upload_dir();

		// Make sure URL is in uploads directory
		if ( strpos( $image_url, $upload_dir['baseurl'] . '/' ) === false ) {
			return false;
		}

		// Get path relative to uploads dir
		$relative_path = str_replace( $upload_dir['baseurl'] . '/', '', $image_url );

		// Query database for attachment by guid or file
		$attachment = $wpdb->get_col(
			$wpdb->prepare(
				"
            SELECT post_id 
            FROM $wpdb->postmeta 
            WHERE meta_key = '_wp_attached_file' 
            AND meta_value = %s
        ",
				$relative_path
			)
		);

		return ! empty( $attachment[0] ) ? $attachment[0] : false;
	}

	/**
	 * Check if the image is a valid image that can be optimized
	 *
	 * @param string $url Image URL
	 * @return bool
	 */
	public static function is_valid_image_url( $url ) {
		$parsed_url = parse_url( $url );

		// Skip if no path
		if ( ! isset( $parsed_url['path'] ) ) {
			return false;
		}

		// Check if it's a valid image extension
		$valid_extensions = array( 'jpg', 'jpeg', 'png', 'gif', 'webp' );
		$extension        = strtolower( pathinfo( $parsed_url['path'], PATHINFO_EXTENSION ) );

		return in_array( $extension, $valid_extensions, true );
	}

	/**
	 * Check if webp conversion is supported
	 *
	 * @return bool
	 */
	public static function is_webp_supported() {
		return function_exists( 'imagewebp' );
	}

	/**
	 * Check if avif conversion is supported
	 *
	 * @return bool
	 */
	public static function is_avif_supported() {
		return function_exists( 'imageavif' );
	}
}
