<?php
/**
 * Load and save HTML fragments without leaking an HTML 4 document wrapper.
 *
 * @package TrustOptimize
 */

namespace TrustOptimize\Utils;

use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * Class HtmlFragment
 */
class HtmlFragment {

	/**
	 * Temporary root id used only while the fragment lives in a DOM tree.
	 */
	const ROOT_ID = 'trust-optimize-fragment-root';

	/**
	 * Whether the fragment still contains an <img> that has not been rewritten.
	 *
	 * @param string $html HTML fragment.
	 * @return bool
	 */
	public static function contains_unprocessed_img( $html ) {
		if ( ! is_string( $html ) || '' === $html ) {
			return false;
		}

		if ( ! preg_match_all( '/<img\b[^>]*>/i', $html, $matches ) ) {
			return false;
		}

		foreach ( $matches[0] as $tag ) {
			if ( ! preg_match( '/\bdata-original-src\s*=/i', $tag ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Parse an HTML fragment into a DOM tree.
	 *
	 * The fragment is placed inside an explicit body wrapper so HTML5 start
	 * tags such as <section> are not absorbed into <head> by libxml.
	 *
	 * @param string $html HTML fragment.
	 * @return array{0: DOMDocument, 1: DOMElement}|null Document and root element, or null on failure.
	 */
	public static function load( $html ) {
		if ( ! is_string( $html ) || ! extension_loaded( 'dom' ) ) {
			return null;
		}

		$dom = new DOMDocument();
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$dom->encoding = 'UTF-8';

		$wrapped  = '<?xml encoding="UTF-8">';
		$wrapped .= '<html><head><meta http-equiv="Content-Type" content="text/html; charset=UTF-8"></head>';
		$wrapped .= '<body><div id="' . self::ROOT_ID . '">' . $html . '</div></body></html>';

		$previous = libxml_use_internal_errors( true );
		$loaded   = $dom->loadHTML( $wrapped, defined( 'LIBXML_HTML_NODEFDTD' ) ? LIBXML_HTML_NODEFDTD : 0 );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		if ( ! $loaded ) {
			return null;
		}

		$root = self::find_root( $dom );
		if ( ! $root instanceof DOMElement ) {
			return null;
		}

		return array( $dom, $root );
	}

	/**
	 * Serialize the fragment back to HTML without doctype/html/head/body.
	 *
	 * @param DOMDocument $dom  Document produced by load().
	 * @param DOMElement  $root Fragment root produced by load().
	 * @return string
	 */
	public static function save( DOMDocument $dom, DOMElement $root ) {
		$html = '';

		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		foreach ( iterator_to_array( $root->childNodes ) as $child ) {
			$html .= $dom->saveHTML( $child );
		}

		// DOMDocument emits closing tags for HTML5 void elements.
		$html = str_replace( '</source>', '', $html );

		return $html;
	}

	/**
	 * Locate the temporary fragment root.
	 *
	 * @param DOMDocument $dom Document.
	 * @return DOMElement|null
	 */
	private static function find_root( DOMDocument $dom ) {
		$xpath = new DOMXPath( $dom );
		$node  = $xpath->query( '//*[@id="' . self::ROOT_ID . '"]' )->item( 0 );

		return $node instanceof DOMElement ? $node : null;
	}
}
