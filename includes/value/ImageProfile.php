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
	 * @param array $formats Target formats.
	 * @param array $quality Quality settings keyed by format.
	 * @param array $options Additional profile options.
	 */
	public function __construct( array $formats, array $quality = array(), array $options = array() ) {
		$this->formats = array_values( $formats );
		$this->quality = $quality;
		$this->options = $options;
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
			'formats' => $this->formats,
			'quality' => $this->quality,
			'options' => $this->options,
		);
	}
}
