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
 * A variant is stale when its format is no longer enabled or it was encoded with
 * a different quality.
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
	 * Formats the user switched on, in conversion order.
	 *
	 * @var string[]
	 */
	private $enabled;

	/**
	 * Enabled formats that this environment can write now.
	 *
	 * @var string[]
	 */
	private $plannable;

	/**
	 * Quality per format.
	 *
	 * @var int[]
	 */
	private $quality;

	/**
	 * Constructor.
	 *
	 * @param string[] $enabled   Formats enabled in the settings.
	 * @param string[] $plannable Enabled formats that are currently supported.
	 * @param int[]    $quality   Quality (1-100) keyed by format.
	 */
	public function __construct( array $enabled, array $plannable, array $quality ) {
		$this->enabled   = array_values( $enabled );
		$this->plannable = array_values( array_intersect( $plannable, $enabled ) );
		$this->quality   = $quality;
	}

	/**
	 * Build from the stored options and the persisted capabilities.
	 *
	 * @param Settings          $settings     Plugin settings.
	 * @param CapabilityService $capabilities Capability service.
	 * @return self
	 */
	public static function from_options( Settings $settings, CapabilityService $capabilities ) {
		$enabled   = array();
		$plannable = array();
		$quality   = array();
		foreach ( self::FORMAT_OPTIONS as $format => $enabled_key ) {
			$value = (int) $settings->get( $format . '_quality' );

			$quality[ $format ] = max( 1, min( 100, (int) apply_filters( "trust_optimize_{$format}_quality", $value ) ) );

			if ( (bool) $settings->get( $enabled_key, 1 ) ) {
				$enabled[] = $format;

				if ( $capabilities->supports( $format ) ) {
					$plannable[] = $format;
				}
			}
		}

		return new self( $enabled, $plannable, $quality );
	}

	/**
	 * Formats switched on by the user. Variants of any other format are removed.
	 *
	 * @return string[]
	 */
	public function enabled_formats() {
		return $this->enabled;
	}

	/**
	 * Enabled formats that can be created now. Existing variants of an enabled but
	 * unsupported format are kept, but nothing new is planned for it.
	 *
	 * @return string[]
	 */
	public function plannable_formats() {
		return $this->plannable;
	}

	/**
	 * Whether the user enabled a format.
	 *
	 * @param string $format Format extension.
	 * @return bool
	 */
	public function is_enabled( $format ) {
		return in_array( $format, $this->enabled, true );
	}

	/**
	 * Whether new variants of a format can be created now.
	 *
	 * @param string $format Format extension.
	 * @return bool
	 */
	public function is_plannable( $format ) {
		return in_array( $format, $this->plannable, true );
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
	 * The settings as plain data (stored with a bulk job).
	 *
	 * @return array
	 */
	public function to_array() {
		return array(
			'enabled'   => $this->enabled,
			'plannable' => $this->plannable,
			'quality'   => $this->quality,
		);
	}

	/**
	 * Whether a stored variant no longer matches the settings.
	 *
	 * A variant of an enabled format that cannot be recreated now is not stale: it is
	 * kept as it is.
	 *
	 * @param array $variant_row Row with at least 'format' and 'quality'.
	 * @return bool
	 */
	public function is_stale( array $variant_row ) {
		$format = (string) ( $variant_row['format'] ?? '' );

		if ( ! $this->is_enabled( $format ) ) {
			return true;
		}

		return $this->is_plannable( $format ) && (int) ( $variant_row['quality'] ?? 0 ) !== $this->quality_for( $format );
	}
}
