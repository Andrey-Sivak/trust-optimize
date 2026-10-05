<?php
/**
 * Wraps an <img> tag into <picture> with next-gen <source> elements.
 *
 * @package TrustOptimize
 */

namespace TrustOptimize\Frontend;

use TrustOptimize\Storage\VariantRepository;
use WP_HTML_Tag_Processor;

/**
 * Class PictureRenderer
 *
 * The <source> elements mirror the srcset and sizes that core computed for the <img>:
 * only the URLs and the type differ, so crops and the selection of a candidate stay
 * the same as without the plugin. A format is offered only when every candidate has a variant
 * in it. The <img> itself is never modified.
 */
class PictureRenderer {

	/**
	 * Formats offered in the order of preference.
	 *
	 * @var string[] MIME type keyed by format.
	 */
	const FORMATS = array(
		'avif' => 'image/avif',
		'webp' => 'image/webp',
	);

	/**
	 * Variant repository.
	 *
	 * @var VariantRepository
	 */
	private $variants;

	/**
	 * URL mapping.
	 *
	 * @var UploadsUrl
	 */
	private $urls;

	/**
	 * Constructor.
	 *
	 * @param VariantRepository $variants Variant repository.
	 * @param UploadsUrl        $urls     URL mapping.
	 */
	public function __construct( VariantRepository $variants, UploadsUrl $urls ) {
		$this->variants = $variants;
		$this->urls     = $urls;
	}

	/**
	 * Render the <picture> markup for an <img> tag.
	 *
	 * @param string $img_tag       Markup of one <img> tag.
	 * @param int    $attachment_id Attachment the image belongs to.
	 * @return string The <picture> markup, or the tag unchanged when nothing can be offered.
	 */
	public function render( $img_tag, $attachment_id ) {
		$processor = new WP_HTML_Tag_Processor( $img_tag );
		if ( ! $processor->next_tag( 'img' ) ) {
			return $img_tag;
		}

		$src = $processor->get_attribute( 'src' );
		if ( ! is_string( $src ) || '' === $src || 0 === strpos( $src, 'data:' ) ) {
			return $img_tag;
		}

		$rows = $this->variants->get_servable_for_attachment( $attachment_id );
		if ( empty( $rows ) ) {
			return $img_tag;
		}

		$servable = array();
		foreach ( $rows as $row ) {
			$servable[ $row['format'] ][ $row['source_relative_path'] ] = basename( (string) VariantRepository::servable_path( $row ) );
		}

		$srcset     = $processor->get_attribute( 'srcset' );
		$sizes      = $processor->get_attribute( 'sizes' );
		$candidates = is_string( $srcset ) && '' !== trim( $srcset ) ? self::parse_srcset( $srcset ) : array( array( $src, '' ) );
		$sources    = '';

		foreach ( self::FORMATS as $format => $mime ) {
			$items = array();

			// A source with fewer candidates than the <img> would make the browser choose among other widths.
			foreach ( $candidates as list( $url, $descriptor ) ) {
				$path = $this->urls->relative_path( $url );
				if ( null === $path || ! isset( $servable[ $format ][ $path ] ) ) {
					continue 2;
				}

				$items[] = trim( self::directory_of( $url ) . rawurlencode( $servable[ $format ][ $path ] ) . ' ' . $descriptor );
			}

			if ( $items ) {
				$sources .= '<source type="' . esc_attr( $mime ) . '" srcset="' . esc_attr( implode( ', ', $items ) ) . '"'
					. ( is_string( $sizes ) ? ' sizes="' . esc_attr( $sizes ) . '"' : '' ) . '>';
			}
		}

		return '' === $sources ? $img_tag : '<picture>' . $sources . $img_tag . '</picture>';
	}

	/**
	 * Split a srcset attribute into candidates.
	 *
	 * A URL is a run of characters without whitespace, so a comma inside it (a query string) does not
	 * split it; a comma after the descriptor, or ending the URL, separates the candidates.
	 *
	 * @param string $srcset Attribute value.
	 * @return array[] Pairs of URL and descriptor (such as "300w"; empty when absent).
	 */
	private static function parse_srcset( $srcset ) {
		$candidates = array();
		$length     = strlen( $srcset );
		$position   = 0;

		while ( $position < $length ) {
			$position += strspn( $srcset, " \t\n\r\f,", $position );
			if ( $position >= $length ) {
				break;
			}

			$url_length = strcspn( $srcset, " \t\n\r\f", $position );
			$url        = substr( $srcset, $position, $url_length );
			$position  += $url_length;

			if ( ',' === substr( $url, -1 ) ) {
				$candidates[] = array( rtrim( $url, ',' ), '' );
				continue;
			}

			$descriptor_length = strcspn( $srcset, ',', $position );
			$candidates[]      = array( $url, trim( substr( $srcset, $position, $descriptor_length ) ) );
			$position         += $descriptor_length;
		}

		return $candidates;
	}

	/**
	 * Directory part of a URL, with a trailing slash and without query or fragment.
	 *
	 * @param string $url URL of an image.
	 * @return string
	 */
	private static function directory_of( $url ) {
		$url = (string) strtok( $url, '?#' );

		return substr( $url, 0, (int) strrpos( $url, '/' ) + 1 );
	}
}
