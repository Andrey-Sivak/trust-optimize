<?php
/**
 * OptimizationSettings tests.
 *
 * @package TrustOptimize\Tests\Unit
 */

namespace TrustOptimize\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TrustOptimize\Settings\OptimizationSettings;

/**
 * Class OptimizationSettingsTest
 */
class OptimizationSettingsTest extends TestCase {

	private function settings() {
		return new OptimizationSettings(
			array( 'webp' ),
			array(
				'webp' => 80,
				'avif' => 60,
			)
		);
	}

	public function test_exposes_formats_and_quality() {
		$settings = $this->settings();

		$this->assertSame( array( 'webp' ), $settings->formats() );
		$this->assertTrue( $settings->has_format( 'webp' ) );
		$this->assertFalse( $settings->has_format( 'avif' ) );
		$this->assertSame( 80, $settings->quality_for( 'webp' ) );
		$this->assertSame( 60, $settings->quality_for( 'avif' ) );
	}

	public function test_matching_variant_is_not_stale() {
		$this->assertFalse(
			$this->settings()->is_stale(
				array(
					'format'  => 'webp',
					'quality' => 80,
				)
			)
		);
	}

	public function test_different_quality_makes_a_variant_stale() {
		$this->assertTrue(
			$this->settings()->is_stale(
				array(
					'format'  => 'webp',
					'quality' => 85,
				)
			)
		);
	}

	public function test_disabled_or_unsupported_format_makes_a_variant_stale() {
		$this->assertTrue(
			$this->settings()->is_stale(
				array(
					'format'  => 'avif',
					'quality' => 60,
				)
			)
		);
	}

	public function test_unknown_format_is_stale() {
		$this->assertTrue( $this->settings()->is_stale( array() ) );
	}
}
