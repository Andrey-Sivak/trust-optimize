<?php
/**
 * Variant file naming.
 *
 * @package TrustOptimize
 */

namespace TrustOptimize\Naming;

/**
 * Class VariantNaming
 *
 * The variant keeps the full name of its source plus the new extension
 * ("photo.jpg" -> "photo.jpg.webp"), so "photo.jpg" and "photo.png" in one
 * directory can never produce the same variant file (H-1).
 */
final class VariantNaming {

	/**
	 * Relative path of the variant file.
	 *
	 * @param string $source_relative_path Source path relative to uploads, e.g. "2026/05/photo.jpg".
	 * @param string $format               Target format / extension, e.g. "webp".
	 * @return string e.g. "2026/05/photo.jpg.webp".
	 */
	public static function target_relative_path( $source_relative_path, $format ) {
		return $source_relative_path . '.' . strtolower( $format );
	}
}
