<?php
/**
 * VariantNaming tests.
 *
 * @package TrustOptimize\Tests\Unit
 */

namespace TrustOptimize\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TrustOptimize\Naming\VariantNaming;

/**
 * Class VariantNamingTest
 */
class VariantNamingTest extends TestCase {

	public function provider() {
		return array(
			'plain'             => array( '2026/05/photo.jpg', 'webp', '2026/05/photo.jpg.webp' ),
			'png source'        => array( '2026/05/photo.png', 'avif', '2026/05/photo.png.avif' ),
			'upper case ext'    => array( '2026/05/PHOTO.JPG', 'webp', '2026/05/PHOTO.JPG.webp' ),
			'scaled'            => array( '2026/05/photo-scaled.jpg', 'webp', '2026/05/photo-scaled.jpg.webp' ),
			'size suffix'       => array( '2026/05/photo-300x200.jpg', 'webp', '2026/05/photo-300x200.jpg.webp' ),
			'uploads root'      => array( 'logo.png', 'webp', 'logo.png.webp' ),
			'nested directory'  => array( 'a/b/c/d.jpeg', 'WEBP', 'a/b/c/d.jpeg.webp' ),
			'dots in name'      => array( '2026/05/my.photo.v2.jpg', 'webp', '2026/05/my.photo.v2.jpg.webp' ),
		);
	}

	/**
	 * @dataProvider provider
	 *
	 * @param string $source   Source path.
	 * @param string $format   Format.
	 * @param string $expected Expected variant path.
	 */
	public function test_target_relative_path( $source, $format, $expected ) {
		$this->assertSame( $expected, VariantNaming::target_relative_path( $source, $format ) );
	}

	public function test_same_stem_different_extensions_do_not_collide() {
		$this->assertNotSame(
			VariantNaming::target_relative_path( '2026/05/photo.jpg', 'webp' ),
			VariantNaming::target_relative_path( '2026/05/photo.png', 'webp' )
		);
	}
}
