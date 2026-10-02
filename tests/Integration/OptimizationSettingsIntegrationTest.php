<?php
/**
 * OptimizationSettings::from_options() against real options.
 *
 * @package TrustOptimize\Tests
 */

use TrustOptimize\Admin\Settings;
use TrustOptimize\Capabilities\CapabilityService;
use TrustOptimize\Settings\OptimizationSettings;

/**
 * @covers \TrustOptimize\Settings\OptimizationSettings
 */
class OptimizationSettingsIntegrationTest extends WP_UnitTestCase {

	public function test_formats_are_enabled_intersect_supported() {
		update_option( CapabilityService::OPTION, array( 'webp' => true, 'avif' => false ) );
		update_option(
			'trust_optimize_options',
			array(
				'convert_to_webp' => 1,
				'convert_to_avif' => 1,
				'webp_quality'    => 70,
				'avif_quality'    => 55,
			)
		);

		$settings = OptimizationSettings::from_options( new Settings(), new CapabilityService() );

		$this->assertSame( array( 'webp', 'avif' ), $settings->enabled_formats() );
		$this->assertSame( array( 'webp' ), $settings->plannable_formats() );
		$this->assertSame( 70, $settings->quality_for( 'webp' ) );
		$this->assertSame( 55, $settings->quality_for( 'avif' ) );
	}

	public function test_disabled_format_is_left_out_and_filter_adjusts_quality() {
		update_option( CapabilityService::OPTION, array( 'webp' => true, 'avif' => true ) );
		update_option(
			'trust_optimize_options',
			array(
				'convert_to_webp' => 0,
				'convert_to_avif' => 1,
				'avif_quality'    => 55,
			)
		);
		add_filter( 'trust_optimize_avif_quality', static fn() => 500 );

		$settings = OptimizationSettings::from_options( new Settings(), new CapabilityService() );

		$this->assertSame( array( 'avif' ), $settings->enabled_formats() );
		$this->assertSame( array( 'avif' ), $settings->plannable_formats() );
		$this->assertSame( 100, $settings->quality_for( 'avif' ), 'Quality is clamped to 100.' );
	}
}
