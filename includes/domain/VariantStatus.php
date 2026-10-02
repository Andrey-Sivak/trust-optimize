<?php
/**
 * Variant status constants.
 *
 * @package TrustOptimize
 */

namespace TrustOptimize\Domain;

/**
 * Status of a single generated variant (one row of the variants table).
 */
final class VariantStatus {

	const PENDING    = 'pending';
	const PROCESSING = 'processing';
	const DONE       = 'done';
	const SKIPPED    = 'skipped';
	const FAILED     = 'failed';

	/**
	 * Maximum length of the status column.
	 */
	const COLUMN_LENGTH = 32;

	/**
	 * All statuses.
	 *
	 * @return string[]
	 */
	public static function all() {
		return array( self::PENDING, self::PROCESSING, self::DONE, self::SKIPPED, self::FAILED );
	}
}
