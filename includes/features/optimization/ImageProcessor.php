<?php
/**
 * Image processor class
 *
 * @package TrustOptimize
 */

namespace TrustOptimize\Features\Optimization;

use DOMDocument;
use DOMElement;
use TrustOptimize\Utils\Helper;
use TrustOptimize\Database\ImageModel;

/**
 * Class ImageProcessor
 */
class ImageProcessor implements OptimizerInterface {

	/**
	 * Image model instance
	 *
	 * @var ImageModel
	 */
	protected $image_model;

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->image_model = new ImageModel();
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
	 * Check if this optimizer is enabled.
	 *
	 * @return bool
	 */
	public function is_enabled() {
		return $this->is_feature_enabled();
	}

	/**
	 * Get optimizer statistics.
	 *
	 * @return array
	 */
	public function get_stats() {
		// Placeholder for real implementation
		return array(
			'processed' => 0,
			'saved'     => '0 KB',
		);
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

		// Use DOMDocument to parse HTML content
		if ( ! extension_loaded( 'dom' ) ) {
			return $content;
		}

		$dom = new DOMDocument();

		// Suppress errors from malformed HTML
		libxml_use_internal_errors( true );

		// Load the content with a proper header to help DOMDocument with encoding
		// Using a meta tag ensures DOMDocument interprets the content as UTF-8
		$dom->loadHTML( '<meta http-equiv="Content-Type" content="text/html; charset=UTF-8">' . $content );

		// Reset errors
		libxml_clear_errors();

		// Find all images
		$images = $dom->getElementsByTagName( 'img' );

		// Process each image
		foreach ( $images as $image ) {
			$this->process_image_element( $image );
		}

		// Save the modified HTML
		// Use saveHTML($dom->getElementsByTagName('body')->item(0)) to get only body content
		$body_element = $dom->getElementsByTagName( 'body' )->item( 0 );
		if ( $body_element ) {
			$processed_content = $dom->saveHTML( $body_element );
			// Remove the outer <body> tags added by saveHTML when processing a fragment
			$processed_content = preg_replace( '/^<body>(.*)<\/body>$/s', '$1', $processed_content );
		} else {
			// Fallback if body element is not found (shouldn't happen with typical HTML fragments)
			$processed_content = $dom->saveHTML();
		}

		return $processed_content;
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

		// Skip if not a valid image URL or already processed (check for parent <picture> tag)
		if ( empty( $src ) || 0 === strpos( $src, 'data:' ) || $image->parentNode->tagName === 'picture' ) {
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

		// Store original src
		$image->setAttribute( 'data-original-src', $src );

		// Create the <picture> element
		$dom     = $image->ownerDocument;
		$picture = $dom->createElement( 'picture' );

		// Get the original file format
		$original_format = pathinfo( $src, PATHINFO_EXTENSION );

		// Define standard sizes attribute for all source elements
		$sizes_attr = '(max-width: 2704px) 100vw, (max-width: 1024px) 100vw, (max-width: 300px) 100vw, 100vw';

		// Get all available formats for this image
		$formats = $this->image_model->get_available_formats( $attachment_id );

		// If no formats found, use at least the original format
		if ( empty( $formats ) && ! empty( $original_format ) ) {
			$formats = array( strtolower( $original_format ) );
		}

		// Process formats in order of preference (next-gen formats first, then original)
		$format_priorities = array( 'avif', 'webp' );

		// Add remaining formats at the end (excluding original format, which will be the fallback)
		foreach ( $formats as $format ) {
			if ( ! in_array( $format, $format_priorities, true ) &&
			     $format !== strtolower( $original_format ) &&
			     ! in_array( $format, array( 'webp', 'avif' ), true ) ) {
				$format_priorities[] = $format;
			}
		}

		// Add original format as the last priority (if not already a next-gen format)
		if ( ! empty( $original_format ) &&
		     ! in_array( strtolower( $original_format ), array( 'webp', 'avif' ), true ) &&
		     ! in_array( strtolower( $original_format ), $format_priorities, true ) ) {
			$format_priorities[] = strtolower( $original_format );
		}

		// Process formats in priority order
		foreach ( $format_priorities as $format ) {
			// For each format, check browser support and if we have this format variation
			if ( $format === 'webp' && ! Helper::is_webp_supported() ) {
				continue;
			}

			if ( $format === 'avif' && ! Helper::is_avif_supported() ) {
				continue;
			}

			// Skip if the format is not in our available formats
			if ( ! in_array( $format, $formats, true ) && $format !== strtolower( $original_format ) ) {
				continue;
			}

			// Create a source element for this format
			$source = $this->create_source_element(
				$dom,
				$src,
				$format,
				$metadata,
				$attachment_id,
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

		// Add srcset and sizes to the fallback img for browsers that don't support <picture> fully
		if ( ! empty( $fallback_srcset ) ) {
			$fallback_img->setAttribute( 'srcset', $fallback_srcset );
			$fallback_img->setAttribute( 'sizes', $sizes_attr );
		}

		// Add lazy loading and decoding attributes to the fallback img
		$fallback_img->setAttribute( 'loading', 'lazy' );
		$fallback_img->setAttribute( 'decoding', 'async' );

		// Append the fallback <img> to the <picture> element
		$picture->appendChild( $fallback_img );

		// Replace the original <img> with the new <picture> element
		$image->parentNode->replaceChild( $picture, $image );
	}

	/**
	 * Create a source element for a specific image format
	 *
	 * @param DOMDocument $dom The DOM document.
	 * @param string $src The original image source.
	 * @param string $format The image format ('webp', 'jpeg', 'png', etc.).
	 * @param array $metadata The attachment metadata.
	 * @param int $attachment_id The attachment ID.
	 * @param string $sizes_attr The sizes attribute for responsive images.
	 *
	 * @return DOMElement|null The source element, or null if it couldn't be created.
	 */
	private function create_source_element( $dom, $src, $format, $metadata, $attachment_id, $sizes_attr ) {
		// Get the proper MIME type for the format
		$mime_type = $this->get_mime_type_for_format( $format );

		// Generate srcset for this format
		$srcset = $this->generate_adaptive_srcset( $src, $format, $metadata, $attachment_id );

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
	 * @param string $format The desired image format (e.g., 'webp', 'jpeg').
	 * @param array $metadata The attachment metadata.
	 * @param int $attachment_id The attachment ID.
	 *
	 * @return string The srcset attribute.
	 */
	private function generate_adaptive_srcset( $original_src, $format = '', $metadata = array(), $attachment_id = 0 ) {
		$srcset_items = array();

		// Get the upload directory information
		$upload_dir = wp_upload_dir();
		$base_url   = $upload_dir['baseurl'];

		// Get the directory and file name relative to uploads from the original src
		$image_dir_relative = dirname( str_replace( trailingslashit( $base_url ), '', $original_src ) );

		// Add the original size (if it exists in our custom format model)
		if ( 'webp' === $format ) {
			$original_format_data = $this->image_model->get_format( $attachment_id, 'original', 'webp' );
			if ( $original_format_data && isset( $original_format_data['file'] ) ) {
				$file_name      = $original_format_data['file'];
				$width          = $metadata['width']; // Use original image width from WordPress metadata
				$srcset_items[] = trailingslashit( $base_url ) . trailingslashit( ltrim( $image_dir_relative, '/' ) ) . $file_name . ' ' . $width . 'w';
			}
		} elseif ( 'webp' !== $format && isset( $metadata['file'] ) && basename( $metadata['file'] ) === basename( $original_src ) ) {
			// Add the original size for non-webp formats using the original URL
			$width          = $metadata['width'];
			$srcset_items[] = $original_src . ' ' . $width . 'w';
		}

		// Add generated sizes
		if ( isset( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
			foreach ( $metadata['sizes'] as $size_name => $size_info ) {
				$file_name = '';
				$width     = $size_info['width'];

				if ( 'webp' === $format ) {
					// Get WebP variation for this size from our custom format model
					$size_format_data = $this->image_model->get_format( $attachment_id, $size_name, 'webp' );
					if ( $size_format_data && isset( $size_format_data['file'] ) ) {
						$file_name = $size_format_data['file'];
					}
				} elseif ( 'webp' !== $format ) {
					// Use the original size file name from size-specific metadata
					$file_name = $size_info['file'];
				}

				if ( ! empty( $file_name ) ) {
					// Construct the full URL for the size
					$srcset_items[] = trailingslashit( $base_url ) . trailingslashit( ltrim( $image_dir_relative, '/' ) ) . $file_name . ' ' . $width . 'w';
				}
			}
		}

		// Sort srcset items by width (optional but good practice)
		usort( $srcset_items, function ( $a, $b ) {
			$a_parts = explode( ' ', $a );
			$b_parts = explode( ' ', $b );
			$a_width = (int) rtrim( end( $a_parts ), 'w' );
			$b_width = (int) rtrim( end( $b_parts ), 'w' );

			return $a_width - $b_width;
		} );

		return implode( ', ', $srcset_items );
	}

	/**
	 * Get an adaptive URL for a specific width and format.
	 *
	 * @param string $src The original image URL.
	 * @param int $width The target width.
	 * @param string $format The desired image format.
	 *
	 * @return string The adaptive URL.
	 */
	private function get_adaptive_url( $src, $width, $format = '' ) {
		$parsed_url = parse_url( $src );
		$base_url   = $src;
		$query      = array();

		if ( isset( $parsed_url['query'] ) ) {
			parse_str( $parsed_url['query'], $query );
			$base_url = str_replace( '?' . $parsed_url['query'], '', $src );
		}

		$query['width']          = $width;
		$query['trust_optimize'] = 1; // Keep this to potentially indicate a request from the plugin

		// Add format parameter to the URL
		if ( ! empty( $format ) ) {
			$query['format'] = $format;
		}

		return $base_url . '?' . http_build_query( $query );
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
	 * Check if the adaptive image feature is enabled.
	 *
	 * @return bool
	 */
	private function is_feature_enabled() {
		// Placeholder - in a real plugin, this would check settings
		return true;
	}
}
