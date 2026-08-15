<?php
/**
 * Image profile tests.
 *
 * @package TrustOptimize\Tests\Unit
 */

namespace TrustOptimize\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TrustOptimize\Value\ImageProfile;

/**
 * Class ImageProfileTest
 */
class ImageProfileTest extends TestCase {

	/**
	 * Same effective profile values should produce the same hash.
	 */
	public function test_hash_is_stable_for_same_effective_values() {
		$first = new ImageProfile(
			'1',
			array( 'webp', 'avif' ),
			array(
				'webp' => 90,
				'avif' => 85,
			),
			array(
				'size_names' => array( 'thumbnail', 'original' ),
			)
		);

		$second = new ImageProfile(
			'1',
			array( 'avif', 'webp' ),
			array(
				'avif' => 85,
				'webp' => 90,
			),
			array(
				'size_names' => array( 'thumbnail', 'original' ),
			)
		);

		$this->assertSame( $first->get_hash(), $second->get_hash() );
	}

	/**
	 * Quality changes should produce a different hash.
	 */
	public function test_hash_changes_when_quality_changes() {
		$first = new ImageProfile(
			'1',
			array( 'webp', 'avif' ),
			array(
				'webp' => 90,
				'avif' => 85,
			),
			array(
				'size_names' => array( 'original' ),
			)
		);

		$second = new ImageProfile(
			'1',
			array( 'webp', 'avif' ),
			array(
				'webp' => 80,
				'avif' => 85,
			),
			array(
				'size_names' => array( 'original' ),
			)
		);

		$this->assertNotSame( $first->get_hash(), $second->get_hash() );
	}

	/**
	 * Format changes should produce a different hash.
	 */
	public function test_hash_changes_when_formats_change() {
		$first  = new ImageProfile( '1', array( 'webp' ), array( 'webp' => 90 ) );
		$second = new ImageProfile( '1', array( 'webp', 'avif' ), array( 'webp' => 90, 'avif' => 85 ) );

		$this->assertNotSame( $first->get_hash(), $second->get_hash() );
	}

	/**
	 * Unsupported format metadata should not change the effective profile hash.
	 */
	public function test_hash_ignores_unsupported_output_format_metadata() {
		$first = new ImageProfile(
			'1',
			array( 'webp' ),
			array(
				'webp' => 90,
				'avif' => 75,
			),
			array(
				'size_names'                 => array( 'original' ),
				'unsupported_output_formats' => array( 'avif' ),
				'output_format_support'      => array(
					'webp' => true,
					'avif' => false,
				),
			)
		);

		$second = new ImageProfile(
			'1',
			array( 'webp' ),
			array(
				'webp' => 90,
				'avif' => 60,
			),
			array(
				'size_names'                 => array( 'original' ),
				'unsupported_output_formats' => array(),
				'output_format_support'      => array(
					'webp' => true,
					'avif' => false,
				),
			)
		);

		$this->assertSame( $first->get_hash(), $second->get_hash() );
	}

	/**
	 * Quality changes for an effective planned format should change the hash.
	 */
	public function test_hash_changes_when_effective_format_quality_changes() {
		$first  = new ImageProfile( '1', array( 'webp' ), array( 'webp' => 90, 'avif' => 75 ) );
		$second = new ImageProfile( '1', array( 'webp' ), array( 'webp' => 80, 'avif' => 75 ) );

		$this->assertNotSame( $first->get_hash(), $second->get_hash() );
	}
}
