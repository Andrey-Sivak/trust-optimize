<?php
/**
 * Result of one migration batch.
 *
 * @package TrustOptimize\Migration
 */

namespace TrustOptimize\Migration;

/**
 * Class BatchResult
 */
final class BatchResult {

	/**
	 * Cursor to continue from.
	 *
	 * @var int
	 */
	private $cursor;

	/**
	 * Whether the step has nothing left to process.
	 *
	 * @var bool
	 */
	private $done;

	/**
	 * Counters to add to the migration totals.
	 *
	 * @var array<string,int>
	 */
	private $counts;

	/**
	 * Constructor.
	 *
	 * @param int               $cursor Cursor to continue from.
	 * @param bool              $done   Whether the step is finished.
	 * @param array<string,int> $counts Counters to add to the migration totals.
	 */
	private function __construct( $cursor, $done, array $counts ) {
		$this->cursor = (int) $cursor;
		$this->done   = (bool) $done;
		$this->counts = $counts;
	}

	/**
	 * More items are left: continue from the given cursor.
	 *
	 * @param int               $cursor Cursor for the next batch.
	 * @param array<string,int> $counts Counters of this batch.
	 * @return self
	 */
	public static function more( $cursor, array $counts = array() ) {
		return new self( $cursor, false, $counts );
	}

	/**
	 * The step is finished.
	 *
	 * @param array<string,int> $counts Counters of this batch.
	 * @return self
	 */
	public static function finished( array $counts = array() ) {
		return new self( 0, true, $counts );
	}

	/**
	 * Cursor to continue from.
	 *
	 * @return int
	 */
	public function get_cursor() {
		return $this->cursor;
	}

	/**
	 * Whether the step is finished.
	 *
	 * @return bool
	 */
	public function is_done() {
		return $this->done;
	}

	/**
	 * Counters of this batch.
	 *
	 * @return array<string,int>
	 */
	public function get_counts() {
		return $this->counts;
	}
}
