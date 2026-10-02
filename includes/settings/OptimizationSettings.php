<?php
/**
 * Immutable snapshot of the conversion settings.
 *
 * @package TrustOptimize
 */

namespace TrustOptimize\Settings;

use TrustOptimize\Admin\Settings;
use TrustOptimize\Capabilities\CapabilityService;

/**
 * Class OptimizationSettings
 *
 * Replaces the profile hash: a variant is stale when its format is no longer
 * enabled or it was encoded with a different quality (D-6).
 */
final class OptimizationSettings {

	/**
	 * Option key that enables a format.
	 *
	 * @var string[]
	 */
	const FORMAT_OPTIONS = array(
		'webp' => 'convert_to_webp',
		'avif' => 'convert_to_avif',
	);

	/**
	 * Formats that are enabled and supported, in conversion order.
	 *
	 * @var string[]
	 */
	private $formats;

	/**
	 * Quality per format.
	 *
	 * @var int[]
	 */
	private $quality;

	/**
	 * Constructor.
	 *
	 * @param string[] $formats Enabled and supported formats.
	 * @param int[]    $quality Quality (1-100) keyed by format.
	 */
	public function __construct( array $formats, array $quality ) {
		$this->formats = array_values( $formats );
		$this->quality = $quality;
	}

	/**
	 * Build from the stored options and the persisted capabilities.
	 *
	 * @param Settings          $settings     Plugin settings.
	 * @param CapabilityService $capabilities Capability service.
	 * @return self
	 */
	public static function from_options( Settings $settings, CapabilityService $capabilities ) {
		$options        = $settings->get_all();
		$legacy_quality = isset( $options['image_quality'] ) ? (int) $options['image_quality'] : 85;
		$defaults       = array(
			'webp' => min( $legacy_quality, 90 ),
			'avif' => min( $legacy_quality, 85 ),
		);

		$formats = array();
		$quality = array();
		foreach ( self::FORMAT_OPTIONS as $format => $enabled_key ) {
			$value = isset( $options[ $format . '_quality' ] ) ? (int) $options[ $format . '_quality' ] : $defaults[ $format ];

			$quality[ $format ] = max( 1, min( 100, (int) apply_filters( "trust_optimize_{$format}_quality", $value ) ) );

			if ( (bool) $settings->get( $enabled_key, 1 ) && $capabilities->supports( $format ) ) {
				$formats[] = $format;
			}
		}

		return new self( $formats, $quality );
	}

	/**
	 * Formats to generate.
	 *
	 * @return string[]
	 */
	public function formats() {
		return $this->formats;
	}

	/**
	 * Whether a format is generated.
	 *
	 * @param string $format Format extension.
	 * @return bool
	 */
	public function has_format( $format ) {
		return in_array( $format, $this->formats, true );
	}

	/**
	 * Quality used for a format.
	 *
	 * @param string $format Format extension.
	 * @return int
	 */
	public function quality_for( $format ) {
		return $this->quality[ $format ] ?? 85;
	}

	/**
	 * Whether a stored variant no longer matches the settings.
	 *
	 * @param array $variant_row Row with at least 'format' and 'quality'.
	 * @return bool
	 */
	public function is_stale( array $variant_row ) {
		$format = (string) ( $variant_row['format'] ?? '' );

		return ! $this->has_format( $format ) || (int) ( $variant_row['quality'] ?? 0 ) !== $this->quality_for( $format );
	}
}
