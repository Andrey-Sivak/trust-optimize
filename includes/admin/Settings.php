<?php
/**
 * Settings manager class
 *
 * @package TrustOptimize
 */

namespace TrustOptimize\Admin;

/**
 * Class Settings
 */
class Settings {

	/**
	 * Default settings.
	 *
	 * @var array
	 */
	private $defaults = array(
		'enable_adaptive_images' => 1,
		'image_quality'          => 100,
		'breakpoints'            => array( 320, 480, 768, 1024, 1280, 1440, 1920 ),
		'lazy_load'              => 1,
		'convert_to_webp'        => 1,
		'convert_to_avif'        => 1,
	);

	/**
	 * Settings constructor.
	 */
	public function __construct() {
		// Register default settings during activation
		add_action( 'activate_' . TRUST_OPTIMIZE_PLUGIN_BASENAME, array( $this, 'add_default_settings' ) );
	}

	/**
	 * Add default settings.
	 */
	public function add_default_settings() {
		if ( ! get_option( 'trust_optimize_options' ) ) {
			update_option( 'trust_optimize_options', $this->defaults );
		}
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
	 * Update a setting value.
	 *
	 * @param string $key The setting key.
	 * @param mixed  $value The setting value.
	 *
	 * @return bool
	 */
	public function update( $key, $value ) {
		$options         = get_option( 'trust_optimize_options', array() );
		$options[ $key ] = $value;

		return update_option( 'trust_optimize_options', $options );
	}

	/**
	 * Get all settings.
	 *
	 * @return array
	 */
	public function get_all() {
		return get_option( 'trust_optimize_options', $this->defaults );
	}

	/**
	 * Reset settings to defaults.
	 *
	 * @return bool
	 */
	public function reset() {
		return update_option( 'trust_optimize_options', $this->defaults );
	}
}
