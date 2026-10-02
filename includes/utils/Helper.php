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
	 * Get the attachment ID from an image URL
	 *
	 * @param string $image_url Image URL
	 * @return int|false
	 */
	public static function get_attachment_id_from_url( $image_url ) {
		global $wpdb;

		// Remove any image size from the URL
		$image_url = preg_replace( '/-\d+x\d+(?=\.(jpg|jpeg|png|gif|webp|avif)$)/i', '', $image_url );

		// Get the upload directory
		$upload_dir = wp_upload_dir();

		// Make sure URL is in uploads directory
		if ( strpos( $image_url, $upload_dir['baseurl'] . '/' ) === false ) {
			return false;
		}

		// Get path relative to uploads dir
		$relative_path = str_replace( $upload_dir['baseurl'] . '/', '', $image_url );

		// Query database for attachment by guid or file
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Lookup by _wp_attached_file; core has no cached equivalent for a relative path.
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
}
