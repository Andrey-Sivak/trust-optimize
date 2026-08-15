<?php
/**
 * Image optimization result value object.
 *
 * @package TrustOptimize\Value
 */

namespace TrustOptimize\Value;

/**
 * Class OptimizeResult
 */
class OptimizeResult extends OperationResult {

	/**
	 * Create a successful result.
	 *
	 * @param string $message Structured operation message.
	 * @param array  $data    Additional result data.
	 * @return self
	 */
	public static function success( $message = '', array $data = array() ) {
		return new self( self::STATUS_SUCCESS, $message, array(), $data );
	}

	/**
	 * Create a skipped result.
	 *
	 * @param string $reason Structured skip reason.
	 * @param array  $data   Additional result data.
	 * @return self
	 */
	public static function skipped( $reason, array $data = array() ) {
		return new self( self::STATUS_SKIPPED, $reason, array(), $data );
	}

	/**
	 * Create a failed result.
	 *
	 * @param string $reason Structured failure reason.
	 * @param array  $errors Structured error details.
	 * @param array  $data   Additional result data.
	 * @return self
	 */
	public static function failed( $reason, array $errors = array(), array $data = array() ) {
		return new self( self::STATUS_FAILED, $reason, $errors, $data );
	}

	/**
	 * Create a partially completed result.
	 *
	 * @param string $message Structured operation message.
	 * @param array  $errors  Structured error details.
	 * @param array  $data    Additional result data.
	 * @return self
	 */
	public static function partial( $message, array $errors = array(), array $data = array() ) {
		return new self( self::STATUS_PARTIAL, $message, $errors, $data );
	}
}
