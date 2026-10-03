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
 * the same as without the plugin. The <img> itself is never modified.
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
	 * Constructor.
	 *
	 * @param VariantRepository $variants Variant repository.
	 */
	public function __construct( VariantRepository $variants ) {
		$this->variants = $variants;
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
		$location   = $this->uploads_location();
		$src_path   = self::relative_path( $src, $location );
		$sources    = '';

		foreach ( self::FORMATS as $format => $mime ) {
			if ( null === $src_path || ! isset( $servable[ $format ][ $src_path ] ) ) {
				continue;
			}

			$items = array();
			foreach ( $candidates as list( $url, $descriptor ) ) {
				$path = self::relative_path( $url, $location );
				if ( null !== $path && isset( $servable[ $format ][ $path ] ) ) {
					$items[] = trim( self::directory_of( $url ) . $servable[ $format ][ $path ] . ' ' . $descriptor );
				}
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
	 * @param string $srcset Attribute value.
	 * @return array[] Pairs of URL and descriptor (such as "300w"; empty when absent).
	 */
	private static function parse_srcset( $srcset ) {
		$candidates = array();

		foreach ( preg_split( '/,\s+/', trim( $srcset ) ) as $candidate ) {
			$parts = preg_split( '/\s+/', trim( $candidate ), 2 );
			if ( '' !== $parts[0] ) {
				$candidates[] = array( $parts[0], $parts[1] ?? '' );
			}
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

	/**
	 * Where uploads are served from.
	 *
	 * @return array{prefix:string,hosts:string[]} URL path prefix with a trailing slash, and the accepted hosts in lower case.
	 */
	private function uploads_location() {
		$base = wp_parse_url( wp_upload_dir( null, false )['baseurl'] );

		/**
		 * Hosts that serve the uploads directory besides the site itself, for example a CDN.
		 *
		 * The path after the host must mirror the uploads URL.
		 *
		 * @param string[] $hosts Host names.
		 */
		$cdn_hosts = (array) apply_filters( 'trust_optimize_cdn_hosts', array() );

		return array(
			'prefix' => trailingslashit( $base['path'] ?? '' ),
			'hosts'  => array_map( 'strtolower', array_merge( array( $base['host'] ?? '' ), $cdn_hosts ) ),
		);
	}

	/**
	 * Path of an image URL relative to uploads; the scheme is ignored.
	 *
	 * @param string $url      Image URL.
	 * @param array  $location Result of uploads_location().
	 * @return string|null Null when the URL does not point into uploads.
	 */
	private static function relative_path( $url, array $location ) {
		$parts = wp_parse_url( $url );

		if ( empty( $parts['path'] ) || ( ! empty( $parts['host'] ) && ! in_array( strtolower( $parts['host'] ), $location['hosts'], true ) ) ) {
			return null;
		}

		return 0 === strpos( $parts['path'], $location['prefix'] ) ? substr( $parts['path'], strlen( $location['prefix'] ) ) : null;
	}
}
