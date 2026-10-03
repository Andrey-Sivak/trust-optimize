<?php
/**
 * Defaults of a new install.
 *
 * @package TrustOptimize\Tests
 */

use TrustOptimize\Admin\Settings;
use TrustOptimize\Capabilities\CapabilityService;

/**
 * @covers \TrustOptimize\Admin\Settings::add_default_settings
 */
class SettingsDefaultsTest extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		delete_option( 'trust_optimize_options' );
	}

	public function test_a_new_install_gets_safe_defaults() {
		update_option( CapabilityService::OPTION, array( 'webp' => true, 'avif' => true ) );

		( new Settings() )->add_default_settings( new CapabilityService() );
		$options = get_option( 'trust_optimize_options' );

		$this->assertSame( 1, $options['enable_adaptive_images'] );
		$this->assertSame( 1, $options['convert_to_webp'] );
		$this->assertSame( 1, $options['convert_to_avif'] );
		$this->assertSame( 0, $options['force_lazy'] );
		$this->assertSame( 50000000, $options['max_pixels'] );
		$this->assertSame( 0, $options['min_free_disk'], '0 means max( 1 GB, 5 % of the disk ).' );
		$this->assertSame( 0, $options['remove_data_on_uninstall'] );
	}

	public function test_avif_starts_off_where_it_is_not_supported() {
		update_option( CapabilityService::OPTION, array( 'webp' => true, 'avif' => false ) );

		( new Settings() )->add_default_settings( new CapabilityService() );

		$this->assertSame( 0, get_option( 'trust_optimize_options' )['convert_to_avif'] );
	}

	public function test_saved_settings_are_not_overwritten() {
		update_option( CapabilityService::OPTION, array( 'webp' => true, 'avif' => true ) );
		$saved = array( 'enable_adaptive_images' => 0, 'webp_quality' => 55 );
		update_option( 'trust_optimize_options', $saved );

		( new Settings() )->add_default_settings( new CapabilityService() );

		$this->assertSame( $saved, get_option( 'trust_optimize_options' ) );
	}
}
