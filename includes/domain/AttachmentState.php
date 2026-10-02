<?php
/**
 * Attachment state constants.
 *
 * @package TrustOptimize
 */

namespace TrustOptimize\Domain;

/**
 * Aggregate state of an attachment, derived from its variants.
 */
final class AttachmentState {

	const NONE       = 'none';
	const QUEUED     = 'queued';
	const PROCESSING = 'processing';
	const OPTIMIZED  = 'optimized';
	const PARTIAL    = 'partial';
	const FAILED     = 'failed';
	const SKIPPED    = 'skipped';

	/**
	 * Maximum length of the state column.
	 */
	const COLUMN_LENGTH = 32;

	/**
	 * All states.
	 *
	 * @return string[]
	 */
	public static function all() {
		return array( self::NONE, self::QUEUED, self::PROCESSING, self::OPTIMIZED, self::PARTIAL, self::FAILED, self::SKIPPED );
	}
}
