<?php
/**
 * Capability check result value object.
 *
 * @package TrustOptimize\Value
 */

namespace TrustOptimize\Value;

/**
 * Class CapabilityCheck
 */
class CapabilityCheck {

	const REASON_MISSING_FILE     = 'missing_file';
	const REASON_UNSUPPORTED_MIME = 'unsupported_mime';
	const REASON_TOO_LARGE        = 'too_large';
	const REASON_NO_EDITOR        = 'no_editor';

	/**
	 * Whether the operation is allowed.
	 *
	 * @var bool
	 */
	private $allowed;

	/**
	 * Structured capability reasons.
	 *
	 * @var array
	 */
	private $reasons;

	/**
	 * Additional check data.
	 *
	 * @var array
	 */
	private $data;

	/**
	 * Constructor.
	 *
	 * @param bool  $allowed Whether the operation is allowed.
	 * @param array $reasons Structured capability reasons.
	 * @param array $data    Additional check data.
	 */
	private function __construct( $allowed, array $reasons = array(), array $data = array() ) {
		$this->allowed = (bool) $allowed;
		$this->reasons = $reasons;
		$this->data    = $data;
	}

	/**
	 * Create an allowed capability check result.
	 *
	 * @param array $data Additional check data.
	 * @return self
	 */
	public static function allowed( array $data = array() ) {
		return new self( true, array(), $data );
	}

	/**
	 * Create a denied capability check result.
	 *
	 * @param array $reasons Structured capability reasons.
	 * @param array $data    Additional check data.
	 * @return self
	 */
	public static function denied( array $reasons, array $data = array() ) {
		return new self( false, $reasons, $data );
	}

	/**
	 * Check whether the operation is allowed.
	 *
	 * @return bool
	 */
	public function is_allowed() {
		return $this->allowed;
	}

	/**
	 * Get structured capability reasons.
	 *
	 * @return array
	 */
	public function get_reasons() {
		return $this->reasons;
	}

	/**
	 * Get additional check data.
	 *
	 * @return array
	 */
	public function get_data() {
		return $this->data;
	}

	/**
	 * Export the check as an array.
	 *
	 * @return array
	 */
	public function to_array() {
		return array(
			'allowed' => $this->allowed,
			'reasons' => $this->reasons,
			'data'    => $this->data,
		);
	}
}
