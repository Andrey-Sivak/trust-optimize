<?php
/**
 * Base image operation result value object.
 *
 * @package TrustOptimize\Value
 */

namespace TrustOptimize\Value;

/**
 * Class OperationResult
 */
abstract class OperationResult {

	const STATUS_SUCCESS = 'success';
	const STATUS_SKIPPED = 'skipped';
	const STATUS_FAILED  = 'failed';
	const STATUS_PARTIAL = 'partial';

	/**
	 * Operation status.
	 *
	 * @var string
	 */
	private $status;

	/**
	 * Structured operation message.
	 *
	 * @var string
	 */
	private $message;

	/**
	 * Structured error details.
	 *
	 * @var array
	 */
	private $errors;

	/**
	 * Additional result data.
	 *
	 * @var array
	 */
	private $data;

	/**
	 * Constructor.
	 *
	 * @param string $status  Operation status.
	 * @param string $message Structured operation message.
	 * @param array  $errors  Structured error details.
	 * @param array  $data    Additional result data.
	 */
	protected function __construct( $status, $message = '', array $errors = array(), array $data = array() ) {
		$this->status  = $status;
		$this->message = $message;
		$this->errors  = $errors;
		$this->data    = $data;
	}

	/**
	 * Get operation status.
	 *
	 * @return string
	 */
	public function get_status() {
		return $this->status;
	}

	/**
	 * Get structured operation message.
	 *
	 * @return string
	 */
	public function get_message() {
		return $this->message;
	}

	/**
	 * Get structured error details.
	 *
	 * @return array
	 */
	public function get_errors() {
		return $this->errors;
	}

	/**
	 * Get additional result data.
	 *
	 * @return array
	 */
	public function get_data() {
		return $this->data;
	}

	/**
	 * Check whether the operation succeeded.
	 *
	 * @return bool
	 */
	public function is_success() {
		return self::STATUS_SUCCESS === $this->status;
	}

	/**
	 * Check whether the operation was skipped.
	 *
	 * @return bool
	 */
	public function is_skipped() {
		return self::STATUS_SKIPPED === $this->status;
	}

	/**
	 * Check whether the operation failed.
	 *
	 * @return bool
	 */
	public function is_failed() {
		return self::STATUS_FAILED === $this->status;
	}

	/**
	 * Check whether the operation completed with partial errors.
	 *
	 * @return bool
	 */
	public function is_partial() {
		return self::STATUS_PARTIAL === $this->status;
	}

	/**
	 * Export the result as an array.
	 *
	 * @return array
	 */
	public function to_array() {
		return array(
			'status'  => $this->status,
			'message' => $this->message,
			'errors'  => $this->errors,
			'data'    => $this->data,
		);
	}
}
