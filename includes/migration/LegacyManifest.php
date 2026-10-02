<?php
/**
 * Reader of the 1.x variant manifest.
 *
 * @package TrustOptimize\Migration
 */

namespace TrustOptimize\Migration;

use TrustOptimize\Utils\UploadsPath;

/**
 * Class LegacyManifest
 *
 * Schema 1.3.0 kept the variants of an attachment as JSON in trust_optimize_images.metadata:
 * generated_variants["size:format"] = { size_name, format, file, relative_dir, file_hash, ... }.
 */
final class LegacyManifest {

	/**
	 * Variants listed in a manifest, with the paths resolved.
	 *
	 * @param int    $attachment_id Attachment the manifest belongs to.
	 * @param string $json          Value of the metadata column.
	 * @return array[] Entries with size_name, format, relative_path (relative to uploads) and file_hash; entries whose path is unsafe or unknown are left out.
	 */
	public static function variants( $attachment_id, $json ) {
		$manifest = json_decode( (string) $json, true );
		$entries  = array();

		if ( ! is_array( $manifest ) || ! is_array( $manifest['generated_variants'] ?? null ) ) {
			return $entries;
		}

		$attached = (string) get_post_meta( (int) $attachment_id, '_wp_attached_file', true );

		foreach ( $manifest['generated_variants'] as $variant ) {
			if ( ! is_array( $variant ) || empty( $variant['file'] ) || empty( $variant['format'] ) ) {
				continue;
			}

			$file = basename( (string) $variant['file'] );
			$dir  = trim( str_replace( '\\', '/', (string) ( $variant['relative_dir'] ?? '' ) ), '/' );

			if ( '' === $dir && '' !== $attached ) {
				$dir = '.' === dirname( $attached ) ? '' : dirname( $attached );
			} elseif ( '' === $dir ) {
				continue;
			}

			if ( null === UploadsPath::resolve( $dir, $file ) ) {
				continue;
			}

			$entries[] = array(
				'size_name'     => (string) ( $variant['size_name'] ?? 'original' ),
				'format'        => (string) $variant['format'],
				'relative_path' => '' === $dir ? $file : $dir . '/' . $file,
				'file_hash'     => ! empty( $variant['file_hash'] ) ? (string) $variant['file_hash'] : null,
			);
		}

		return $entries;
	}
}
