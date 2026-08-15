<?php
/**
 * Image optimization profile factory.
 *
 * @package TrustOptimize\Service
 */

namespace TrustOptimize\Service;

use TrustOptimize\Admin\Settings;
use TrustOptimize\Value\ImageProfile;

/**
 * Class ImageProfileFactory
 */
class ImageProfileFactory {

	const CONVERSION_SCHEMA_VERSION = '1';

	/**
	 * Settings instance.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param Settings|null $settings Settings instance.
	 */
	public function __construct( Settings $settings = null ) {
		$this->settings = $settings ? $settings : new Settings();
	}

	/**
	 * Build an image profile for an attachment metadata payload.
	 *
	 * @param array $wp_metadata WordPress attachment metadata.
	 * @return ImageProfile
	 */
	public function from_wp_metadata( array $wp_metadata ) {
		$capabilities = $this->get_output_format_capabilities();

		return new ImageProfile(
			self::CONVERSION_SCHEMA_VERSION,
			$this->get_supported_enabled_formats( $capabilities ),
			$this->get_effective_quality(),
			array(
				'size_names'                 => $this->get_size_names( $wp_metadata ),
				'output_format_support'      => $capabilities,
				'unsupported_output_formats' => $this->get_unsupported_enabled_formats( $capabilities ),
			)
		);
	}

	/**
	 * Check whether the environment can create an output format.
	 *
	 * @param string $format Target format.
	 * @return bool True when GD/Imagick can generate the format.
	 */
	public function is_output_format_supported( $format ) {
		$capabilities = $this->get_output_format_capabilities();

		return ! empty( $capabilities[ $format ] );
	}

	/**
	 * Get enabled conversion formats.
	 *
	 * @return array
	 */
	private function get_requested_output_formats() {
		$formats = array();

		if ( (bool) $this->settings->get( 'convert_to_webp', 1 ) ) {
			$formats[] = 'webp';
		}

		if ( (bool) $this->settings->get( 'convert_to_avif', 1 ) ) {
			$formats[] = 'avif';
		}

		return $formats;
	}

	/**
	 * Get enabled output formats supported by this environment.
	 *
	 * @param array $capabilities Output format capability map.
	 * @return array
	 */
	private function get_supported_enabled_formats( array $capabilities ) {
		$formats = array();

		foreach ( $this->get_requested_output_formats() as $format ) {
			if ( ! empty( $capabilities[ $format ] ) ) {
				$formats[] = $format;
			}
		}

		return $formats;
	}

	/**
	 * Get enabled output formats unsupported by this environment.
	 *
	 * @param array $capabilities Output format capability map.
	 * @return array
	 */
	private function get_unsupported_enabled_formats( array $capabilities ) {
		$formats = array();

		foreach ( $this->get_requested_output_formats() as $format ) {
			if ( empty( $capabilities[ $format ] ) ) {
				$formats[] = $format;
			}
		}

		return $formats;
	}

	/**
	 * Get output format capability map.
	 *
	 * @return array Format support keyed by extension.
	 */
	private function get_output_format_capabilities() {
		$formats      = array( 'webp', 'avif', 'png' );
		$capabilities = array();

		foreach ( $formats as $format ) {
			$capabilities[ $format ] = $this->detect_output_format_support( $format );
		}

		return $capabilities;
	}

	/**
	 * Detect support for one output format through WordPress image editors.
	 *
	 * @param string $format Target format.
	 * @return bool True when supported.
	 */
	private function detect_output_format_support( $format ) {
		$mime_type       = 'image/' . $format;
		$implementations = array( 'WP_Image_Editor_Imagick', 'WP_Image_Editor_GD' );

		foreach ( $implementations as $implementation ) {
			if ( ! class_exists( $implementation ) ) {
				continue;
			}

			if ( is_callable( array( $implementation, 'test' ) ) && ! call_user_func( array( $implementation, 'test' ) ) ) {
				continue;
			}

			if ( is_callable( array( $implementation, 'supports_mime_type' ) ) && call_user_func( array( $implementation, 'supports_mime_type' ), $mime_type ) ) {
				return true;
			}
		}

		if ( 'webp' === $format ) {
			return function_exists( 'imagewebp' ) || ( extension_loaded( 'imagick' ) && $this->imagick_supports_format( 'WEBP' ) );
		}

		if ( 'avif' === $format ) {
			return function_exists( 'imageavif' ) || ( extension_loaded( 'imagick' ) && $this->imagick_supports_format( 'AVIF' ) );
		}

		return 'png' === $format && function_exists( 'imagepng' );
	}

	/**
	 * Check Imagick format support.
	 *
	 * @param string $format Imagick format name.
	 * @return bool True when supported.
	 */
	private function imagick_supports_format( $format ) {
		if ( ! class_exists( 'Imagick' ) ) {
			return false;
		}

		try {
			$imagick = new \Imagick();
			return in_array( $format, $imagick->queryFormats(), true );
		} catch ( \Exception $exception ) {
			return false;
		}
	}

	/**
	 * Get effective quality settings keyed by target format.
	 *
	 * @return array
	 */
	private function get_effective_quality() {
		$options        = $this->settings->get_all();
		$legacy_quality = isset( $options['image_quality'] ) ? (int) $options['image_quality'] : 85;
		$quality        = array(
			'avif' => isset( $options['avif_quality'] ) ? (int) $options['avif_quality'] : min( $legacy_quality, 85 ),
			'jpeg' => isset( $options['jpeg_quality'] ) ? (int) $options['jpeg_quality'] : $legacy_quality,
			'webp' => isset( $options['webp_quality'] ) ? (int) $options['webp_quality'] : min( $legacy_quality, 90 ),
		);

		foreach ( $quality as $format => $value ) {
			$quality[ $format ] = max( 1, min( 100, (int) apply_filters( "trust_optimize_{$format}_quality", $value ) ) );
		}

		return $quality;
	}

	/**
	 * Get deterministic attachment size names from WordPress metadata.
	 *
	 * @param array $wp_metadata WordPress attachment metadata.
	 * @return array
	 */
	private function get_size_names( array $wp_metadata ) {
		$size_names = array( 'original' );

		if ( isset( $wp_metadata['sizes'] ) && is_array( $wp_metadata['sizes'] ) ) {
			$size_names = array_merge( $size_names, array_keys( $wp_metadata['sizes'] ) );
		}

		$size_names = array_values( array_unique( $size_names ) );
		sort( $size_names );

		return $size_names;
	}
}
