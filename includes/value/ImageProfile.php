<?php
/**
 * Image optimization profile value object.
 *
 * @package TrustOptimize\Value
 */

namespace TrustOptimize\Value;

/**
 * Class ImageProfile
 */
class ImageProfile {

	/**
	 * Conversion schema version.
	 *
	 * @var string
	 */
	private $schema_version;

	/**
	 * Target formats.
	 *
	 * @var array
	 */
	private $formats;

	/**
	 * Quality settings keyed by format.
	 *
	 * @var array
	 */
	private $quality;

	/**
	 * Additional profile options.
	 *
	 * @var array
	 */
	private $options;

	/**
	 * Constructor.
	 *
	 * @param string $schema_version Conversion schema version.
	 * @param array  $formats        Target formats.
	 * @param array  $quality        Quality settings keyed by format.
	 * @param array  $options        Additional profile options.
	 */
	public function __construct( $schema_version, array $formats, array $quality = array(), array $options = array() ) {
		$this->schema_version = $schema_version;
		$this->formats        = array_values( $formats );
		$this->quality        = $quality;
		$this->options        = $options;

		sort( $this->formats );
		ksort( $this->quality );
		ksort( $this->options );
	}

	/**
	 * Get conversion schema version.
	 *
	 * @return string
	 */
	public function get_schema_version() {
		return $this->schema_version;
	}

	/**
	 * Get target formats.
	 *
	 * @return array
	 */
	public function get_formats() {
		return $this->formats;
	}

	/**
	 * Get quality settings keyed by format.
	 *
	 * @return array
	 */
	public function get_quality() {
		return $this->quality;
	}

	/**
	 * Get additional profile options.
	 *
	 * @return array
	 */
	public function get_options() {
		return $this->options;
	}

	/**
	 * Export the profile as an array.
	 *
	 * @return array
	 */
	public function to_array() {
		return array(
			'schema_version' => $this->schema_version,
			'formats'        => $this->formats,
			'quality'        => $this->quality,
			'options'        => $this->options,
		);
	}

	/**
	 * Get deterministic profile hash.
	 *
	 * @return string
	 */
	public function get_hash() {
		return hash( 'sha256', wp_json_encode( $this->canonicalize( $this->to_array() ) ) );
	}

	/**
	 * Recursively sort associative arrays for stable hashing.
	 *
	 * @param mixed $value Value to canonicalize.
	 * @return mixed Canonicalized value.
	 */
	private function canonicalize( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}

		if ( array_keys( $value ) !== range( 0, count( $value ) - 1 ) ) {
			ksort( $value );
		}

		foreach ( $value as $key => $item ) {
			$value[ $key ] = $this->canonicalize( $item );
		}

		return $value;
	}
}
