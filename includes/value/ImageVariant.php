<?php
/**
 * Image variant value object.
 *
 * @package TrustOptimize\Value
 */

namespace TrustOptimize\Value;

/**
 * Class ImageVariant
 */
class ImageVariant {

	/**
	 * WordPress image size name.
	 *
	 * @var string
	 */
	private $size_name;

	/**
	 * Source image path.
	 *
	 * @var string
	 */
	private $source_path;

	/**
	 * Target image format.
	 *
	 * @var string
	 */
	private $target_format;

	/**
	 * Target image MIME type.
	 *
	 * @var string
	 */
	private $target_mime;

	/**
	 * Target image path.
	 *
	 * @var string
	 */
	private $target_path;

	/**
	 * Constructor.
	 *
	 * @param string $size_name     WordPress image size name.
	 * @param string $source_path   Source image path.
	 * @param string $target_format Target image format.
	 * @param string $target_mime   Target image MIME type.
	 * @param string $target_path   Target image path.
	 */
	public function __construct( $size_name, $source_path, $target_format, $target_mime, $target_path ) {
		$this->size_name     = $size_name;
		$this->source_path   = $source_path;
		$this->target_format = $target_format;
		$this->target_mime   = $target_mime;
		$this->target_path   = $target_path;
	}

	/**
	 * Get WordPress image size name.
	 *
	 * @return string
	 */
	public function get_size_name() {
		return $this->size_name;
	}

	/**
	 * Get source image path.
	 *
	 * @return string
	 */
	public function get_source_path() {
		return $this->source_path;
	}

	/**
	 * Get target image format.
	 *
	 * @return string
	 */
	public function get_target_format() {
		return $this->target_format;
	}

	/**
	 * Get target image MIME type.
	 *
	 * @return string
	 */
	public function get_target_mime() {
		return $this->target_mime;
	}

	/**
	 * Get target image path.
	 *
	 * @return string
	 */
	public function get_target_path() {
		return $this->target_path;
	}

	/**
	 * Export the variant as an array.
	 *
	 * @return array
	 */
	public function to_array() {
		return array(
			'size_name'     => $this->size_name,
			'source_path'   => $this->source_path,
			'target_format' => $this->target_format,
			'target_mime'   => $this->target_mime,
			'target_path'   => $this->target_path,
		);
	}
}
