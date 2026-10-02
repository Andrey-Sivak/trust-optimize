<?php
/**
 * Image optimization profile factory.
 *
 * @package TrustOptimize\Service
 */

namespace TrustOptimize\Service;

use TrustOptimize\Admin\Settings;
use TrustOptimize\Capabilities\CapabilityService;
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
	 * Capability service.
	 *
	 * @var CapabilityService
	 */
	private $capabilities;

	/**
	 * Constructor.
	 *
	 * @param Settings|null          $settings     Settings instance.
	 * @param CapabilityService|null $capabilities Capability service.
	 */
	public function __construct( ?Settings $settings = null, ?CapabilityService $capabilities = null ) {
		$this->settings     = $settings ? $settings : new Settings();
		$this->capabilities = $capabilities ? $capabilities : new CapabilityService();
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
	 * @return bool True when WordPress can save the target MIME through an image editor.
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
		return array(
			'webp' => $this->capabilities->supports( 'webp' ),
			'avif' => $this->capabilities->supports( 'avif' ),
			// PNG is the fallback target for WebP/AVIF sources; every editor writes it (removed with M-8).
			'png'  => true,
		);
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
