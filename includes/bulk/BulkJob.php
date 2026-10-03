<?php
/**
 * Bulk job value object.
 *
 * @package TrustOptimize\Bulk
 */

namespace TrustOptimize\Bulk;

/**
 * Class BulkJob
 */
class BulkJob {

	const TYPE_SYNC      = 'sync';
	const TYPE_REMOVE    = 'remove';
	const TYPE_INVENTORY = 'inventory';

	/**
	 * Job data.
	 *
	 * @var array
	 */
	private $data;

	/**
	 * Constructor.
	 *
	 * @param array $data Job data.
	 */
	public function __construct( array $data ) {
		$this->data = $data;
	}

	/**
	 * Get job ID.
	 *
	 * @return int
	 */
	public function get_id() {
		return isset( $this->data['id'] ) ? (int) $this->data['id'] : 0;
	}

	/**
	 * Get job type.
	 *
	 * @return string
	 */
	public function get_type() {
		return isset( $this->data['type'] ) ? $this->data['type'] : '';
	}

	/**
	 * Get job status.
	 *
	 * @return string
	 */
	public function get_status() {
		return isset( $this->data['status'] ) ? $this->data['status'] : '';
	}

	/**
	 * Get cursor attachment ID.
	 *
	 * @return int
	 */
	public function get_cursor_id() {
		return isset( $this->data['cursor_id'] ) ? (int) $this->data['cursor_id'] : 0;
	}

	/**
	 * Get the settings the job was created with.
	 *
	 * @return array
	 */
	public function get_settings_snapshot() {
		return isset( $this->data['settings_snapshot'] ) && is_array( $this->data['settings_snapshot'] ) ? $this->data['settings_snapshot'] : array();
	}

	/**
	 * Get total candidate attachments counted when the job was created.
	 *
	 * @return int
	 */
	public function get_total() {
		return isset( $this->data['total'] ) ? (int) $this->data['total'] : 0;
	}

	/**
	 * Export job as array.
	 *
	 * @return array
	 */
	public function to_array() {
		return $this->data;
	}
}
