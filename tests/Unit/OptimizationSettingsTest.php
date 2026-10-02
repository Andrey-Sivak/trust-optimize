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
			array( 'webp', 'avif' ),
			array( 'webp' ),
			array(
				'webp' => 80,
				'avif' => 60,
			)
		);
	}

	public function test_separates_enabled_from_plannable_formats() {
		$settings = $this->settings();

		$this->assertSame( array( 'webp', 'avif' ), $settings->enabled_formats() );
		$this->assertSame( array( 'webp' ), $settings->plannable_formats() );
		$this->assertTrue( $settings->is_enabled( 'avif' ) );
		$this->assertFalse( $settings->is_plannable( 'avif' ) );
		$this->assertSame( 80, $settings->quality_for( 'webp' ) );
		$this->assertSame( 60, $settings->quality_for( 'avif' ) );
	}

	public function test_a_format_cannot_be_plannable_without_being_enabled() {
		$settings = new OptimizationSettings( array( 'webp' ), array( 'webp', 'avif' ), array() );

		$this->assertSame( array( 'webp' ), $settings->plannable_formats() );
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

	public function test_variant_of_a_disabled_format_is_stale() {
		$settings = new OptimizationSettings( array( 'webp' ), array( 'webp' ), array( 'webp' => 80 ) );

		$this->assertTrue(
			$settings->is_stale(
				array(
					'format'  => 'avif',
					'quality' => 60,
				)
			)
		);
	}

	public function test_variant_of_an_enabled_but_unsupported_format_is_kept_not_stale() {
		$this->assertFalse(
			$this->settings()->is_stale(
				array(
					'format'  => 'avif',
					'quality' => 10,
				)
			)
		);
	}

	public function test_unknown_format_is_stale() {
		$this->assertTrue( $this->settings()->is_stale( array() ) );
	}
}
