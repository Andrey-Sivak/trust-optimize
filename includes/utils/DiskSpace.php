<?php
/**
 * Free disk space of the uploads directory.
 *
 * @package TrustOptimize
 */

namespace TrustOptimize\Utils;

/**
 * Class DiskSpace
 */
class DiskSpace {

	/**
	 * Fixed part of the default free space threshold.
	 */
	const MIN_FREE_BYTES = GB_IN_BYTES;

	/**
	 * Share of the volume that must stay free by default.
	 */
	const MIN_FREE_SHARE = 0.05;

	/**
	 * Free bytes on the volume of the uploads directory.
	 *
	 * @return float|null Null when the value cannot be determined.
	 */
	public static function free() {
		return self::measure( 'disk_free_space' );
	}

	/**
	 * Free space that must remain: max( 1 GB, 5 % of the volume ), filterable.
	 *
	 * @return int
	 */
	public static function minimum_free() {
		$total   = self::measure( 'disk_total_space' );
		$minimum = max( self::MIN_FREE_BYTES, (int) ( (float) $total * self::MIN_FREE_SHARE ) );

		/**
		 * Filters the free disk space (in bytes) below which background conversion pauses.
		 *
		 * @param int $minimum Threshold in bytes.
		 */
		return max( 0, (int) apply_filters( 'trust_optimize_min_free_disk_bytes', $minimum ) );
	}

	/**
	 * Whether free space is known and below the threshold.
	 *
	 * @return bool
	 */
	public static function is_low() {
		$free = self::free();

		return null !== $free && $free < self::minimum_free();
	}

	/**
	 * Call a disk function on the uploads directory.
	 *
	 * @param callable $function disk_free_space or disk_total_space.
	 * @return float|null
	 */
	private static function measure( $function ) {
		$base = UploadsPath::basedir();

		if ( '' === $base ) {
			return null;
		}

		$value = @$function( $base ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Unsupported on some hosts; false is handled.

		return false === $value ? null : (float) $value;
	}
}
