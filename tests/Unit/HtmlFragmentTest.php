<?php
/**
 * HTML fragment serialization tests.
 *
 * @package TrustOptimize\Tests\Unit
 */

namespace TrustOptimize\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TrustOptimize\Utils\HtmlFragment;

/**
 * Class HtmlFragmentTest
 */
class HtmlFragmentTest extends TestCase {

	/**
	 * HTML5 fragments that start with <section> must not leak an HTML 4 document.
	 */
	public function test_section_fragment_does_not_leak_document_wrapper() {
		$html = '<section class="hero"><h1>ברוכים הבאים</h1><img src="x.jpg" alt=""></section>';

		$out = $this->round_trip( $html );

		$this->assertStringNotContainsString( '<!DOCTYPE', $out );
		$this->assertStringNotContainsString( '<html', $out );
		$this->assertStringNotContainsString( '<head', $out );
		$this->assertStringNotContainsString( '<body', $out );
		$this->assertStringNotContainsString( HtmlFragment::ROOT_ID, $out );
		$this->assertStringContainsString( '<section', $out );
		$this->assertStringContainsString( 'ברוכים הבאים', $out );
		$this->assertStringContainsString( '<img', $out );
	}

	/**
	 * A lone img fragment must also serialize without html/body wrappers.
	 */
	public function test_lone_img_does_not_leak_document_wrapper() {
		$html = '<img src="x.jpg" alt="logo" class="attachment-full">';

		$out = $this->round_trip( $html );

		$this->assertStringNotContainsString( '<!DOCTYPE', $out );
		$this->assertStringNotContainsString( '<html', $out );
		$this->assertStringNotContainsString( '<body', $out );
		$this->assertStringContainsString( '<img', $out );
		$this->assertStringContainsString( 'src="x.jpg"', $out );
	}

	/**
	 * Already rewritten markup should be left untouched by the cheap pre-check.
	 */
	public function test_processed_picture_is_not_considered_unprocessed() {
		$html  = '<section><picture>';
		$html .= '<source type="image/avif" srcset="a.avif">';
		$html .= '<img src="x.jpg" alt="" data-original-src="x.jpg">';
		$html .= '</picture></section>';

		$this->assertFalse( HtmlFragment::contains_unprocessed_img( $html ) );
	}

	/**
	 * A raw img still needs processing.
	 */
	public function test_raw_img_is_considered_unprocessed() {
		$this->assertTrue(
			HtmlFragment::contains_unprocessed_img( '<section><img src="x.jpg" alt=""></section>' )
		);
	}

	/**
	 * Fragments without img tags do not need a DOM pass.
	 */
	public function test_fragment_without_img_does_not_need_processing() {
		$this->assertFalse(
			HtmlFragment::contains_unprocessed_img( '<section><h2>No images</h2></section>' )
		);
	}

	/**
	 * Void source closers emitted by libxml must be stripped on save.
	 */
	public function test_source_void_closers_are_stripped() {
		$html  = '<picture>';
		$html .= '<source type="image/webp" srcset="a.webp 1w">';
		$html .= '<img src="x.jpg" alt="">';
		$html .= '</picture>';

		$out = $this->round_trip( $html );

		$this->assertStringNotContainsString( '</source>', $out );
		$this->assertStringContainsString( '<source', $out );
		$this->assertStringContainsString( '<img', $out );
	}

	/**
	 * Round-trip helper used by serialization assertions.
	 *
	 * @param string $html Fragment.
	 * @return string
	 */
	private function round_trip( $html ) {
		$loaded = HtmlFragment::load( $html );
		$this->assertIsArray( $loaded );
		$this->assertCount( 2, $loaded );

		return HtmlFragment::save( $loaded[0], $loaded[1] );
	}
}
