<?php
/**
 * Directories left in uploads by the capability probe of schema 1.x.
 *
 * @package TrustOptimize
 */

namespace TrustOptimize\Utils;

/**
 * Class LegacyProbeDirectories
 *
 * 1.x wrote a test image into "trust-optimize-capability" or "trust-optimize-capability-<id>" in
 * the root of uploads and did not always remove the directory (L-10). Only an empty directory
 * of that name is removed, without recursion; one that still holds something is reported.
 */
class LegacyProbeDirectories {

	/**
	 * Name pattern of the probe directories.
	 */
	const PATTERN = '/^trust-optimize-capability(-.+)?$/';

	/**
	 * Remove the empty probe directories in the root of uploads.
	 *
	 * @return array{removed:string[],kept:string[]} Absolute paths of the removed and of the non-empty directories.
	 */
	public static function remove_empty() {
		$result = array(
			'removed' => array(),
			'kept'    => array(),
		);
		$base   = UploadsPath::basedir();
		$names  = '' === $base || ! is_dir( $base ) ? array() : scandir( $base );

		foreach ( (array) $names as $name ) {
			$path = $base . '/' . $name;

			if ( ! preg_match( self::PATTERN, $name ) || is_link( $path ) || ! is_dir( $path ) ) {
				continue;
			}

			if ( array() !== array_diff( (array) scandir( $path ), array( '.', '..' ) ) ) {
				$result['kept'][] = $path;
			} elseif ( rmdir( $path ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
				$result['removed'][] = $path;
			}
		}

		return $result;
	}
}
