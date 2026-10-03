<?php
/**
 * Bulk job status constants.
 *
 * @package TrustOptimize
 */

namespace TrustOptimize\Domain;

/**
 * Status of a bulk job (one row of the jobs table).
 */
final class JobStatus {

	const PENDING               = 'pending';
	const RUNNING               = 'running';
	const PAUSED                = 'paused';
	const COMPLETED             = 'completed';
	const COMPLETED_WITH_ERRORS = 'completed_with_errors';
	const CANCELLED             = 'cancelled';
	const FAILED                = 'failed';

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
		return array( self::PENDING, self::RUNNING, self::PAUSED, self::COMPLETED, self::COMPLETED_WITH_ERRORS, self::CANCELLED, self::FAILED );
	}

	/**
	 * Statuses of a job that is not finished: it blocks the creation of another one.
	 *
	 * @return string[]
	 */
	public static function active() {
		return array( self::PENDING, self::RUNNING, self::PAUSED );
	}
}
