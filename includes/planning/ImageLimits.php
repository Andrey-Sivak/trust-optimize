<?php
/**
 * Limits that keep oversized images away from the image editors.
 *
 * @package TrustOptimize
 */

namespace TrustOptimize\Planning;

/**
 * Class ImageLimits
 *
 * Decides from the dimensions in the metadata, without decoding the file (D-10).
 */
final class ImageLimits {

	/**
	 * Largest image, in pixels, when nothing else is configured.
	 */
	const DEFAULT_MAX_PIXELS = 50000000;

	/**
	 * Memory an editor needs per pixel of a decoded image (bytes, estimate).
	 */
	const BYTES_PER_PIXEL = 5;

	/**
	 * Share of the memory still available that one conversion may use.
	 */
	const MEMORY_SHARE = 0.6;

	/**
	 * Why an image of these dimensions must not be converted.
	 *
	 * @param int $width  Width in pixels (0 when unknown).
	 * @param int $height Height in pixels (0 when unknown).
	 * @return string|null "too_large", "insufficient_memory" or null when it may be converted.
	 */
	public static function skip_reason( $width, $height ) {
		$pixels = (int) $width * (int) $height;

		if ( $pixels <= 0 ) {
			return null;
		}

		/**
		 * Filters the largest image (width x height) that is converted.
		 *
		 * @param int $max_pixels Pixels.
		 */
		if ( $pixels > (int) apply_filters( 'trust_optimize_max_pixels', self::DEFAULT_MAX_PIXELS ) ) {
			return 'too_large';
		}

		$limit = wp_convert_hr_to_bytes( ini_get( 'memory_limit' ) );

		if ( $limit > 0 && $pixels * self::BYTES_PER_PIXEL > ( $limit - memory_get_usage( true ) ) * self::MEMORY_SHARE ) {
			return 'insufficient_memory';
		}

		return null;
	}
}
