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
	 * Seconds to wait before the next batch.
	 *
	 * @var int
	 */
	private $delay;

	/**
	 * Constructor.
	 *
	 * @param int               $cursor Cursor to continue from.
	 * @param bool              $done   Whether the step is finished.
	 * @param array<string,int> $counts Counters to add to the migration totals.
	 * @param int               $delay  Seconds to wait before the next batch.
	 */
	private function __construct( $cursor, $done, array $counts, $delay = 0 ) {
		$this->cursor = (int) $cursor;
		$this->done   = (bool) $done;
		$this->counts = $counts;
		$this->delay  = (int) $delay;
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
	 * Nothing can be done now: try the same batch again later.
	 *
	 * @param int               $cursor Cursor of the batch to repeat.
	 * @param int               $delay  Seconds to wait.
	 * @param array<string,int> $counts Counters of this attempt.
	 * @return self
	 */
	public static function retry( $cursor, $delay, array $counts = array() ) {
		return new self( $cursor, false, $counts, $delay );
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
	 * Seconds to wait before the next batch.
	 *
	 * @return int
	 */
	public function get_delay() {
		return $this->delay;
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
