<?php
/**
 * Image processor class
 *
 * @package TrustOptimize
 */

namespace TrustOptimize\Features\Optimization;

use DOMDocument;
use DOMElement;
use TrustOptimize\Admin\Settings;
use TrustOptimize\Storage\VariantRepository;
use TrustOptimize\Utils\Helper;
use TrustOptimize\Utils\HtmlFragment;

/**
 * Class ImageProcessor
 */
class ImageProcessor {

	/**
	 * Variant repository.
	 *
	 * @var VariantRepository
	 */
	protected $variants;

	/**
	 * Settings instance.
	 *
	 * @var Settings
	 */
	protected $settings;

	/**
	 * Constructor.
	 *
	 * @param VariantRepository $variants Variant repository.
	 * @param Settings          $settings Settings instance.
	 */
	public function __construct( VariantRepository $variants, Settings $settings ) {
		$this->variants = $variants;
		$this->settings = $settings;
	}

	/**
	 * Register the frontend filters.
	 */
	public function register() {
		add_filter( 'the_content', array( $this, 'process_content' ), 999 );
		add_filter( 'post_thumbnail_html', array( $this, 'process_thumbnail' ), 999 );
		add_filter( 'wp_get_attachment_image', array( $this, 'process_attachment_image_html' ), 999, 5 );
	}

	/**
	 * Process content to optimize images.
	 *
	 * @param string $content The content to process.
	 *
	 * @return string
	 */
	public function process_content( $content ) {
		return $this->process_content_images( $content );
	}

	/**
	 * Process images in content.
	 *
	 * @param string $content The content to process.
	 *
	 * @return string
	 */
	public function process_content_images( $content ) {
		// Skip if not in frontend or if the feature is disabled or content is empty
		if ( is_admin() || ! $this->is_feature_enabled() || empty( $content ) ) {
			return $content;
		}

		if ( ! extension_loaded( 'dom' ) ) {
			return $content;
		}

		if ( ! HtmlFragment::contains_unprocessed_img( $content ) ) {
			return $content;
		}

		$loaded = HtmlFragment::load( $content );
		if ( null === $loaded ) {
			return $content;
		}

		list( $dom, $root ) = $loaded;

		$images = iterator_to_array( $root->getElementsByTagName( 'img' ) );
		foreach ( $images as $image ) {
			$this->process_image_element( $image );
		}

		return HtmlFragment::save( $dom, $root );
	}

	/**
	 * Process a single image element.
	 *
	 * @param DOMElement $image The image element.
	 */
	private function process_image_element( $image ) {
		// Get original attributes
		$src    = $image->getAttribute( 'src' );
		$alt    = $image->getAttribute( 'alt' );
		$width  = $image->getAttribute( 'width' );
		$height = $image->getAttribute( 'height' );
		$class  = $image->getAttribute( 'class' );
		$style  = $image->getAttribute( 'style' );

		// Skip if not a valid image URL or already processed.
		// The data-original-src attribute is set during processing, so its presence
		// reliably indicates the image was already wrapped in <picture>.
		// Note: checking parentNode->tagName for 'picture' is unreliable because
		// libxml2's HTML4 parser may not correctly model <picture> nesting.
		if ( empty( $src ) || 0 === strpos( $src, 'data:' ) || $image->hasAttribute( 'data-original-src' ) ) {
			return;
		}

		$attachment_id = Helper::get_attachment_id_from_url( $src );

		if ( ! $attachment_id ) {
			return;
		}

		// Get WordPress metadata for basic info like dimensions
		$metadata = wp_get_attachment_metadata( $attachment_id );

		// If metadata is empty or not an array, skip.
		if ( empty( $metadata ) || ! is_array( $metadata ) ) {
			return;
		}

		// Only variants with a servable file are used; without any the markup stays untouched.
		$done = $this->variants->get_servable_for_attachment( $attachment_id );
		if ( empty( $done ) ) {
			return;
		}

		// Store original src
		$image->setAttribute( 'data-original-src', $src );

		// Create the <picture> element
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$dom     = $image->ownerDocument;
		$picture = $dom->createElement( 'picture' );

		// Get the original file format
		$original_format = pathinfo( $src, PATHINFO_EXTENSION );

		// Define standard sizes attribute for all source elements
		$sizes_attr = '(max-width: 2704px) 100vw, (max-width: 1024px) 100vw, (max-width: 300px) 100vw, 100vw';

		// Formats this image has finished variants for
		$formats = array_values( array_unique( array_column( $done, 'format' ) ) );

		// Process formats in order of preference (next-gen formats first, then original)
		$format_priorities = array( 'avif', 'webp' );

		// Add remaining formats at the end (excluding original format, which will be the fallback)
		foreach ( $formats as $format ) {
			if (
				! in_array( $format, $format_priorities, true ) &&
				strtolower( $original_format ) !== $format &&
				! in_array( $format, array( 'webp', 'avif' ), true )
			) {
				$format_priorities[] = $format;
			}
		}

		// Add original format as the last priority (if not already a next-gen format)
		if (
			! empty( $original_format ) &&
			! in_array( strtolower( $original_format ), array( 'webp', 'avif' ), true ) &&
			! in_array( strtolower( $original_format ), $format_priorities, true )
		) {
			$format_priorities[] = strtolower( $original_format );
		}

		// Process formats in priority order
		foreach ( $format_priorities as $format ) {
			// Skip if the format is not in our available formats
			if ( ! in_array( $format, $formats, true ) && strtolower( $original_format ) !== $format ) {
				continue;
			}

			// Create a source element for this format
			$source = $this->create_source_element(
				$dom,
				$src,
				$format,
				$metadata,
				$done,
				$sizes_attr
			);

			// Add the source to the picture element if created successfully
			if ( $source ) {
				$picture->appendChild( $source );
			}
		}

		// Clone the original <img> element to use as a fallback
		$fallback_img = $image->cloneNode( true );

		// Remove attributes that will be handled by picture/source (srcset, sizes)
		$fallback_img->removeAttribute( 'srcset' );
		$fallback_img->removeAttribute( 'sizes' );

		// Ensure fallback img has the original src
		$fallback_img->setAttribute( 'src', $src );

		// Respect existing attributes from the original image markup.
		// Set defaults only when attributes are missing.
		if ( ! $fallback_img->hasAttribute( 'loading' ) ) {
			$fallback_img->setAttribute( 'loading', 'lazy' );
		}

		if ( ! $fallback_img->hasAttribute( 'decoding' ) ) {
			$fallback_img->setAttribute( 'decoding', 'async' );
		}

		// Append the fallback <img> to the <picture> element
		$picture->appendChild( $fallback_img );

		// Replace the original <img> with the new <picture> element
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$image->parentNode->replaceChild( $picture, $image );
	}

	/**
	 * Create a source element for a specific image format
	 *
	 * @param DOMDocument $dom The DOM document.
	 * @param string      $src The original image source.
	 * @param string      $format The image format ('webp', 'jpeg', 'png', etc.).
	 * @param array       $metadata The attachment metadata.
	 * @param array       $variants Variant rows of the attachment that are served.
	 * @param string      $sizes_attr The sizes attribute for responsive images.
	 *
	 * @return DOMElement|null The source element, or null if it couldn't be created.
	 */
	private function create_source_element( $dom, $src, $format, $metadata, array $variants, $sizes_attr ) {
		// Get the proper MIME type for the format
		$mime_type = $this->get_mime_type_for_format( $format );

		// Generate srcset for this format
		$srcset = $this->generate_adaptive_srcset( $src, $format, $metadata, $variants );

		// If we couldn't generate a srcset, return null
		if ( empty( $srcset ) ) {
			return null;
		}

		// Create and configure the source element
		$source = $dom->createElement( 'source' );
		$source->setAttribute( 'type', $mime_type );
		$source->setAttribute( 'srcset', $srcset );
		$source->setAttribute( 'sizes', $sizes_attr );

		return $source;
	}

	/**
	 * Get the MIME type for a specific format
	 *
	 * @param string $format The format (e.g., 'webp', 'jpeg', 'jpg', 'png').
	 *
	 * @return string The MIME type.
	 */
	private function get_mime_type_for_format( $format ) {
		$format = strtolower( $format );

		// Map file extensions to MIME types
		$mime_map = array(
			'jpg'  => 'image/jpeg',
			'jpeg' => 'image/jpeg',
			'png'  => 'image/png',
			'gif'  => 'image/gif',
			'webp' => 'image/webp',
			'avif' => 'image/avif',
		);

		return isset( $mime_map[ $format ] ) ? $mime_map[ $format ] : 'image/' . $format;
	}

	/**
	 * Generate srcset attribute for adaptive images.
	 *
	 * @param string $original_src The original image URL.
	 * @param string $format       The desired image format (e.g., 'webp', 'avif').
	 * @param array  $metadata     The attachment metadata.
	 * @param array  $variants     Variant rows of the attachment that are served.
	 *
	 * @return string The srcset attribute.
	 */
	private function generate_adaptive_srcset( $original_src, $format, array $metadata, array $variants ) {
		$base_url           = wp_upload_dir()['baseurl'];
		$image_dir_relative = dirname( str_replace( trailingslashit( $base_url ), '', $original_src ) );
		$srcset_items       = array();

		foreach ( $variants as $variant ) {
			$servable_path = VariantRepository::servable_path( $variant );

			if ( $variant['format'] !== $format || null === $servable_path ) {
				continue;
			}

			$size_name = $variant['size_name'];
			$width     = (int) $variant['width'];
			if ( $width <= 0 ) {
				$width = (int) ( 'original' === $size_name ? ( $metadata['width'] ?? 0 ) : ( $metadata['sizes'][ $size_name ]['width'] ?? 0 ) );
			}

			// Skip if we couldn't determine the width
			if ( $width <= 0 ) {
				continue;
			}

			// The variant sits next to the source candidate that the markup points at.
			$srcset_items[ $width ] = trailingslashit( $base_url ) . trailingslashit( ltrim( $image_dir_relative, '/' ) ) . basename( $servable_path ) . ' ' . $width . 'w';
		}

		ksort( $srcset_items );

		return implode( ', ', $srcset_items );
	}

	/**
	 * Process a post thumbnail.
	 *
	 * @param string $html The thumbnail HTML.
	 *
	 * @return string
	 */
	public function process_thumbnail( $html ) {
		return $this->process_content_images( $html );
	}

	/**
	 * Process HTML generated by wp_get_attachment_image().
	 *
	 * @param string       $html          Attachment image HTML markup.
	 * @param int          $attachment_id Image attachment ID.
	 * @param string|int[] $size          Requested image size.
	 * @param bool         $icon          Whether the image should be treated as an icon.
	 * @param string|array $attr          Attributes for the image markup.
	 * @return string
	 */
	public function process_attachment_image_html( $html, $attachment_id, $size, $icon, $attr ) {
		if ( empty( $html ) || $icon ) {
			return $html;
		}

		return $this->process_content_images( $html );
	}

	/**
	 * Check if the adaptive image feature is enabled.
	 *
	 * @return bool
	 */
	private function is_feature_enabled() {
		return (bool) $this->settings->get( 'enable_adaptive_images', 1 );
	}
}
