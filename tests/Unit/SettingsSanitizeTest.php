<?php
/**
 * Settings::sanitize() tests.
 *
 * @package TrustOptimize\Tests\Unit
 */

namespace TrustOptimize\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TrustOptimize\Admin\Settings;

/**
 * Class SettingsSanitizeTest
 */
class SettingsSanitizeTest extends TestCase {

	public function test_drops_unknown_and_obsolete_keys() {
		$output = ( new Settings() )->sanitize(
			array(
				'webp_quality'  => 70,
				'breakpoints'   => array( 1 ),
				'lazy_load'     => 1,
				'image_quality' => 90,
				'jpeg_quality'  => 90,
			)
		);

		$this->assertSame(
			array( 'enable_adaptive_images', 'convert_to_webp', 'convert_to_avif', 'force_lazy', 'remove_data_on_uninstall', 'webp_quality', 'avif_quality', 'max_pixels', 'min_free_disk' ),
			array_keys( $output )
		);
	}

	public function test_absent_checkboxes_are_off() {
		$output = ( new Settings() )->sanitize( array( 'convert_to_webp' => '1' ) );

		$this->assertSame( 1, $output['convert_to_webp'] );
		$this->assertSame( 0, $output['enable_adaptive_images'] );
		$this->assertSame( 0, $output['remove_data_on_uninstall'] );
	}

	public function test_numbers_are_clamped() {
		$output = ( new Settings() )->sanitize(
			array(
				'webp_quality'  => 500,
				'avif_quality'  => -3,
				'max_pixels'    => 0,
				'min_free_disk' => -5,
			)
		);

		$this->assertSame( 100, $output['webp_quality'] );
		$this->assertSame( 1, $output['avif_quality'] );
		$this->assertSame( 50000000, $output['max_pixels'] );
		$this->assertSame( 0, $output['min_free_disk'] );
	}

	public function test_non_array_input_gives_defaults() {
		$output = ( new Settings() )->sanitize( null );

		$this->assertSame( 85, $output['webp_quality'] );
		$this->assertSame( 80, $output['avif_quality'] );
	}
}
