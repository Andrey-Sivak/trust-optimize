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

/**
 * Class ImageProcessor
 */
class ImageProcessor implements OptimizerInterface {

	/**
	 * Process content to optimize images.
	 *
	 * @param string $content The content to process.
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
		$body_element = $dom->getElementsByTagName('body')->item(0);
		if ($body_element) {
			$processed_content = $dom->saveHTML($body_element);
			// Remove the outer <body> tags added by saveHTML when processing a fragment
			$processed_content = preg_replace('/^<body>(.*)<\/body>$/s', '$1', $processed_content);
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

		// Generate srcset for WebP
		if ( Helper::is_webp_supported() && isset( $metadata['trust_optimize_converted']['original_webp'] ) ) {
			$webp_srcset = $this->generate_adaptive_srcset( $src, 'webp', $metadata );
			if ( ! empty( $webp_srcset ) ) {
				$source_webp = $dom->createElement( 'source' );
				$source_webp->setAttribute( 'type', 'image/webp' );
				$source_webp->setAttribute( 'srcset', $webp_srcset );
				$source_webp->setAttribute( 'sizes', '(max-width: 2704px) 100vw, (max-width: 1024px) 100vw, (max-width: 300px) 100vw, 100vw' );
				// $source_webp->setAttribute( 'media', '(min-width: 768px)' );
				$picture->appendChild( $source_webp );
			}
		}


		// Generate srcset for fallback format (e.g., original format like JPEG/PNG)
		$fallback_format = pathinfo( $src, PATHINFO_EXTENSION );
		// Ensure we have a valid fallback format (e.g., jpeg, png) and that we are not creating a duplicate webp source
		if ( ! empty( $fallback_format ) && ! in_array( strtolower( $fallback_format ), array( 'webp', 'avif' ), true ) ) {
			$fallback_srcset = $this->generate_adaptive_srcset( $src, $fallback_format, $metadata );
			if ( ! empty( $fallback_srcset ) ) {
				$source_fallback = $dom->createElement( 'source' );
				$source_fallback->setAttribute( 'type', 'image/' . $fallback_format );
				$source_fallback->setAttribute( 'srcset', $fallback_srcset );
				$source_fallback->setAttribute( 'sizes', '(max-width: 2704px) 100vw, (max-width: 1024px) 100vw, (max-width: 300px) 100vw, 100vw' );
				// $source_fallback->setAttribute( 'media', '(max-width: 767px)' );
				$picture->appendChild( $source_fallback );
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
			$fallback_img->setAttribute( 'sizes', '(max-width: 2704px) 100vw, (max-width: 1024px) 100vw, (max-width: 300px) 100vw, 100vw' );
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
	 * Generate srcset attribute for adaptive images.
	 *
	 * @param string $original_src The original image URL.
	 * @param string $format The desired image format (e.g., 'webp', 'jpeg').
	 * @param array  $metadata The attachment metadata.
	 * @return string The srcset attribute.
	 */
	private function generate_adaptive_srcset( $original_src, $format = '', $metadata = array() ) {
		$srcset_items = array();

		// Get the upload directory information
		$upload_dir = wp_upload_dir();
		$base_url   = $upload_dir['baseurl'];

		// Get the directory and file name relative to uploads from the original src
		$image_dir_relative = dirname( str_replace( trailingslashit( $base_url ), '', $original_src ) );

		// Add the original size (if it exists in metadata for this format)
		if ( 'webp' === $format && isset( $metadata['trust_optimize_converted']['original_webp']['file'] ) ) {
			$file_name = $metadata['trust_optimize_converted']['original_webp']['file'];
			$width     = $metadata['trust_optimize_converted']['original_webp']['width'];
			$srcset_items[] = trailingslashit( $base_url ) . trailingslashit( ltrim( $image_dir_relative, '/' ) ) . $file_name . ' ' . $width . 'w';
		} elseif ( 'webp' !== $format && isset( $metadata['file'] ) && basename( $metadata['file'] ) === basename( $original_src ) ) {
			// Add the original size for non-webp formats using the original URL
			$width = $metadata['width'];
			$srcset_items[] = $original_src . ' ' . $width . 'w';
		}

		// Add generated sizes
		if ( isset( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
			foreach ( $metadata['sizes'] as $size_info ) {
				$file_name = '';
				$width     = $size_info['width'];

				if ( 'webp' === $format && isset( $size_info['trust_optimize_converted']['webp']['file'] ) ) {
					// Use the converted WebP file name from size-specific metadata
					$file_name = $size_info['trust_optimize_converted']['webp']['file'];
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
		usort( $srcset_items, function( $a, $b ) {
			$a_parts = explode( ' ', $a );
			$b_parts = explode( ' ', $b );
			$a_width = (int) rtrim( end( $a_parts ), 'w' );
			$b_width = (int) rtrim( end( $b_parts ), 'w' );
			return $a_width - $b_width;
		});


		return implode( ', ', $srcset_items );
	}

	/**
	 * Get an adaptive URL for a specific width and format.
	 *
	 * @param string $src   The original image URL.
	 * @param int    $width The target width.
	 * @param string $format The desired image format.
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
