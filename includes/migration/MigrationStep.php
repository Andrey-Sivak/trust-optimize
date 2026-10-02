<?php
/**
 * Migration step contract.
 *
 * @package TrustOptimize\Migration
 */

namespace TrustOptimize\Migration;

/**
 * One resumable stage of the 1.x to 2.0 migration.
 *
 * A step must be idempotent: the runner persists the cursor only after a batch
 * succeeded, so an interrupted batch is executed again from the previous cursor.
 */
interface MigrationStep {

	/**
	 * Stable step name, stored in the migration state.
	 *
	 * @return string
	 */
	public function name();

	/**
	 * Process one batch.
	 *
	 * @param int $cursor Value returned by the previous batch of this step (0 for the first one).
	 * @param int $limit  Maximum number of items to process.
	 * @return BatchResult
	 */
	public function run_batch( $cursor, $limit );
}
