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
		return new ImageProfile(
			self::CONVERSION_SCHEMA_VERSION,
			$this->get_enabled_formats(),
			$this->get_effective_quality(),
			array(
				'size_names' => $this->get_size_names( $wp_metadata ),
			)
		);
	}

	/**
	 * Get enabled conversion formats.
	 *
	 * @return array
	 */
	private function get_enabled_formats() {
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
	 * Get effective quality settings keyed by target format.
	 *
	 * @return array
	 */
	private function get_effective_quality() {
		$base_quality = (int) $this->settings->get( 'image_quality', 100 );
		$quality      = array(
			'avif' => min( $base_quality, 85 ),
			'webp' => min( $base_quality, 90 ),
		);

		foreach ( $quality as $format => $value ) {
			$quality[ $format ] = (int) apply_filters( "trust_optimize_{$format}_quality", $value );
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
