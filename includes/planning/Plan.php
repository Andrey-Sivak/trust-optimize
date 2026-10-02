<?php
/**
 * Result of planning the variants of one attachment.
 *
 * @package TrustOptimize
 */

namespace TrustOptimize\Planning;

/**
 * Class Plan
 */
final class Plan {

	/**
	 * Why the attachment is not optimized at all, or null.
	 *
	 * @var string|null
	 */
	private $skip_reason;

	/**
	 * Variant rows waiting for conversion.
	 *
	 * @var array[]
	 */
	private $pending;

	/**
	 * Rows whose file and row must be removed (format disabled, size gone).
	 *
	 * @var array[]
	 */
	private $to_delete;

	/**
	 * Earlier versions of rows that now point at another source file; only their files must be removed.
	 *
	 * @var array[]
	 */
	private $replaced;

	/**
	 * Constructor.
	 *
	 * @param string|null $skip_reason Skip reason.
	 * @param array[]     $pending     Pending rows.
	 * @param array[]     $to_delete   Rows to delete.
	 * @param array[]     $replaced    Superseded row snapshots.
	 */
	public function __construct( $skip_reason, array $pending, array $to_delete, array $replaced = array() ) {
		$this->skip_reason = $skip_reason;
		$this->pending     = $pending;
		$this->to_delete   = $to_delete;
		$this->replaced    = $replaced;
	}

	/**
	 * Skip reason.
	 *
	 * @return string|null
	 */
	public function skip_reason() {
		return $this->skip_reason;
	}

	/**
	 * Whether the attachment is not eligible.
	 *
	 * @return bool
	 */
	public function is_skipped() {
		return null !== $this->skip_reason;
	}

	/**
	 * Pending rows.
	 *
	 * @return array[]
	 */
	public function pending() {
		return $this->pending;
	}

	/**
	 * Rows to delete.
	 *
	 * @return array[]
	 */
	public function to_delete() {
		return $this->to_delete;
	}

	/**
	 * Superseded row snapshots.
	 *
	 * @return array[]
	 */
	public function replaced() {
		return $this->replaced;
	}

	/**
	 * Whether there is anything to convert.
	 *
	 * @return bool
	 */
	public function has_work() {
		return ! empty( $this->pending );
	}
}
