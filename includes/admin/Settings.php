<?php
/**
 * Settings manager class
 *
 * @package TrustOptimize
 */

namespace TrustOptimize\Admin;

use TrustOptimize\Capabilities\CapabilityService;
use TrustOptimize\Planning\ImageLimits;

/**
 * Class Settings
 */
class Settings {

	/**
	 * Keys that are on/off switches.
	 *
	 * @var string[]
	 */
	const CHECKBOXES = array( 'enable_adaptive_images', 'convert_to_webp', 'convert_to_avif', 'force_lazy', 'remove_data_on_uninstall' );

	/**
	 * Keys that hold a quality from 1 to 100.
	 *
	 * @var string[]
	 */
	const QUALITY_KEYS = array( 'webp_quality', 'avif_quality' );

	/**
	 * Default settings.
	 *
	 * @var array
	 */
	private $defaults = array(
		'enable_adaptive_images'   => 1,
		'convert_to_webp'          => 1,
		'convert_to_avif'          => 1,
		'webp_quality'             => 85,
		'avif_quality'             => 80,
		'force_lazy'               => 0,
		'max_pixels'               => ImageLimits::DEFAULT_MAX_PIXELS,
		'min_free_disk'            => 0,
		'remove_data_on_uninstall' => 0,
	);

	/**
	 * Feed the stored limits into the filters that the limit classes already apply.
	 *
	 * Priority 5 lets site code that filters at the default priority override the setting.
	 */
	public function register() {
		add_filter( 'trust_optimize_max_pixels', array( $this, 'filter_max_pixels' ), 5 );
		add_filter( 'trust_optimize_min_free_disk_bytes', array( $this, 'filter_min_free_disk' ), 5 );
	}

	/**
	 * Largest image in pixels.
	 *
	 * @param int $default Value before the setting.
	 * @return int
	 */
	public function filter_max_pixels( $default ) {
		$value = (int) $this->get( 'max_pixels' );

		return $value > 0 ? $value : $default;
	}

	/**
	 * Free disk threshold in bytes; the setting is in megabytes and 0 keeps the automatic value.
	 *
	 * @param int $default Value before the setting.
	 * @return int
	 */
	public function filter_min_free_disk( $default ) {
		$value = (int) $this->get( 'min_free_disk' );

		return $value > 0 ? $value * MB_IN_BYTES : $default;
	}

	/**
	 * Validate submitted settings into a clean option value.
	 *
	 * Only known keys survive, so keys of earlier versions disappear on the next save. Checkboxes
	 * that are absent from the input are off, other missing values fall back to the defaults.
	 * max_megapixels, when positive, takes the place of max_pixels and is never stored itself.
	 *
	 * @param mixed $input Submitted values.
	 * @return array
	 */
	public function sanitize( $input ) {
		$input  = is_array( $input ) ? $input : array();
		$output = array();

		foreach ( self::CHECKBOXES as $key ) {
			$output[ $key ] = empty( $input[ $key ] ) ? 0 : 1;
		}

		foreach ( self::QUALITY_KEYS as $key ) {
			$output[ $key ] = max( 1, min( 100, (int) ( $input[ $key ] ?? $this->defaults[ $key ] ) ) );
		}

		// The form enters the limit in megapixels; code and CLI may still send pixels. Only pixels are stored.
		$megapixels = $input['max_megapixels'] ?? 0;
		$max_pixels = is_numeric( $megapixels ) && $megapixels > 0 ? (int) round( $megapixels * 1000000 ) : (int) ( $input['max_pixels'] ?? 0 );

		$output['max_pixels']    = $max_pixels > 0 ? $max_pixels : $this->defaults['max_pixels'];
		$output['min_free_disk'] = max( 0, (int) ( $input['min_free_disk'] ?? 0 ) );

		return $output;
	}

	/**
	 * Store the defaults of a new install.
	 *
	 * AVIF starts on only where the server can write it. Saved settings of an existing site are
	 * never replaced: add_option() does nothing when the option exists.
	 *
	 * @param CapabilityService $capabilities Capability service.
	 */
	public function add_default_settings( CapabilityService $capabilities ) {
		add_option( 'trust_optimize_options', $this->defaults_for( $capabilities ) );
	}

	/**
	 * Get a setting value.
	 *
	 * @param string $key The setting key.
	 * @param mixed  $default The default value if setting doesn't exist.
	 *
	 * @return mixed
	 */
	public function get( $key, $default = null ) {
		$options = get_option( 'trust_optimize_options', array() );

		if ( isset( $options[ $key ] ) ) {
			return $options[ $key ];
		}

		if ( null !== $default ) {
			return $default;
		}

		return isset( $this->defaults[ $key ] ) ? $this->defaults[ $key ] : null;
	}

	/**
	 * Get default settings.
	 *
	 * @return array
	 */
	public function get_defaults() {
		return $this->defaults;
	}

	/**
	 * Reset settings to the defaults of a new install.
	 *
	 * @param CapabilityService $capabilities Capability service.
	 * @return bool
	 */
	public function reset( CapabilityService $capabilities ) {
		return update_option( 'trust_optimize_options', $this->defaults_for( $capabilities ) );
	}

	/**
	 * Defaults for this server: AVIF is on only where it can be written.
	 *
	 * @param CapabilityService $capabilities Capability service.
	 * @return array
	 */
	private function defaults_for( CapabilityService $capabilities ) {
		$defaults                    = $this->defaults;
		$defaults['convert_to_avif'] = (int) $capabilities->supports( 'avif' );

		return $defaults;
	}
}
