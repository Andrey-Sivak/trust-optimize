<?php
/**
 * Saving the settings and the limits they feed.
 *
 * @package TrustOptimize\Tests
 */

use TrustOptimize\Admin\Settings;
use TrustOptimize\Planning\ImageLimits;
use TrustOptimize\Utils\DiskSpace;

/**
 * @covers \TrustOptimize\Admin\Settings
 */
class SettingsFormTest extends WP_UnitTestCase {

	public function test_saving_through_the_settings_api_stores_the_clean_array() {
		update_option( 'trust_optimize_options', array( 'image_quality' => 90, 'jpeg_quality' => 90, 'webp_quality' => 85 ) );

		update_option( 'trust_optimize_options', ( new Settings() )->sanitize( array( 'webp_quality' => 60, 'convert_to_webp' => 1, 'max_pixels' => 1000 ) ) );
		$options = get_option( 'trust_optimize_options' );

		$this->assertSame( 60, $options['webp_quality'] );
		$this->assertArrayNotHasKey( 'image_quality', $options );
		$this->assertArrayNotHasKey( 'jpeg_quality', $options );
	}

	public function test_legacy_uninstall_flag_is_read_and_removed_on_save() {
		update_option( 'trust_optimize_options', array( 'webp_quality' => 85 ) );
		update_option( Settings::LEGACY_REMOVE_DATA_OPTION, 1 );

		$this->assertSame( 1, ( new Settings() )->get( 'remove_data_on_uninstall' ) );

		update_option( 'trust_optimize_options', ( new Settings() )->sanitize( array( 'webp_quality' => 70 ) ) );

		$this->assertFalse( get_option( Settings::LEGACY_REMOVE_DATA_OPTION, false ) );
	}

	public function test_limits_settings_reach_the_limit_classes() {
		update_option( 'trust_optimize_options', ( new Settings() )->sanitize( array( 'max_pixels' => 1000, 'min_free_disk' => 7 ) ) );

		$this->assertSame( 'too_large', ImageLimits::skip_reason( 100, 100 ) );
		$this->assertSame( 7 * MB_IN_BYTES, DiskSpace::minimum_free() );
	}

	public function test_site_filter_overrides_the_setting() {
		update_option( 'trust_optimize_options', ( new Settings() )->sanitize( array( 'max_pixels' => 1000 ) ) );
		add_filter( 'trust_optimize_max_pixels', static fn() => 1000000 );

		$this->assertNull( ImageLimits::skip_reason( 100, 100 ) );
	}
}
