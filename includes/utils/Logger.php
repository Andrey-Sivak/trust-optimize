<?php
/**
 * Logger utility class
 *
 * @package TrustOptimize
 */

namespace TrustOptimize\Utils;

/**
 * Class Logger
 */
class Logger {

	/**
	 * Log levels
	 */
	const DEBUG   = 'debug';
	const INFO    = 'info';
	const WARNING = 'warning';
	const ERROR   = 'error';

	/**
	 * Whether debugging is enabled
	 *
	 * @var bool
	 */
	private $debug_enabled;

	/**
	 * Log file path
	 *
	 * @var string
	 */
	private $log_file;

	/**
	 * Logger constructor.
	 */
	public function __construct() {
		$this->debug_enabled = defined( 'WP_DEBUG' ) && WP_DEBUG;
		$this->log_file      = WP_CONTENT_DIR . '/trust-optimize-debug.log';
	}

	/**
	 * Log a message
	 *
	 * @param string $message The message to log
	 * @param string $level   The log level
	 * @param array  $context Additional context
	 */
	public function log( $message, $level = self::INFO, $context = array() ) {
		// Skip if debugging is not enabled and level is not error
		if ( ! $this->debug_enabled && self::ERROR !== $level ) {
			return;
		}

		$time = current_time( 'mysql' );

		// Format the log entry
		$entry = sprintf(
			"[%s] %s: %s %s\n",
			$time,
			strtoupper( $level ),
			$message,
			! empty( $context ) ? json_encode( $context ) : ''
		);

		// Append to log file
		error_log( $entry, 3, $this->log_file );

		// If it's an error, also log to WordPress error log
		if ( self::ERROR === $level ) {
			error_log( sprintf( 'TrustOptimize Error: %s', $message ) );
		}
	}

	/**
	 * Log a debug message
	 *
	 * @param string $message The message to log
	 * @param array  $context Additional context
	 */
	public function debug( $message, $context = array() ) {
		$this->log( $message, self::DEBUG, $context );
	}

	/**
	 * Log an info message
	 *
	 * @param string $message The message to log
	 * @param array  $context Additional context
	 */
	public function info( $message, $context = array() ) {
		$this->log( $message, self::INFO, $context );
	}

	/**
	 * Log a warning message
	 *
	 * @param string $message The message to log
	 * @param array  $context Additional context
	 */
	public function warning( $message, $context = array() ) {
		$this->log( $message, self::WARNING, $context );
	}

	/**
	 * Log an error message
	 *
	 * @param string $message The message to log
	 * @param array  $context Additional context
	 */
	public function error( $message, $context = array() ) {
		$this->log( $message, self::ERROR, $context );
	}

	/**
	 * Clear the log file
	 */
	public function clear_log() {
		if ( file_exists( $this->log_file ) ) {
			unlink( $this->log_file );
		}
	}
}
