<?php
/**
 * Image processor class
 *
 * @package TrustOptimize
 */

namespace TrustOptimize\Features\Optimization;

use DOMElement;

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
		// Skip if not in frontend or if the feature is disabled
		if ( is_admin() || ! $this->is_feature_enabled() ) {
			return $content;
		}

		// Use DOMDocument to parse HTML content
		if ( ! extension_loaded( 'dom' ) ) {
			return $content;
		}

		$dom = new \DOMDocument();

		// Suppress errors from malformed HTML
		libxml_use_internal_errors( true );

		// Load the content
		$dom->loadHTML( mb_convert_encoding( $content, 'HTML-ENTITIES', 'UTF-8' ) );

		// Reset errors
		libxml_clear_errors();

		// Find all images
		$images = $dom->getElementsByTagName( 'img' );

		// Process each image
		foreach ( $images as $image ) {
			$this->process_image_element( $image );
		}

		// Save the modified HTML
		$processed_content = $dom->saveHTML();

		// Extract body contents
		$body_start = strpos( $processed_content, '<body>' );
		$body_end   = strpos( $processed_content, '</body>' );

		if ( false !== $body_start && false !== $body_end ) {
			$processed_content = substr( $processed_content, $body_start + 6, $body_end - $body_start - 6 );
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
		$width  = $image->getAttribute( 'width' );
		$height = $image->getAttribute( 'height' );

		// Skip if not a valid image URL
		if ( empty( $src ) || 0 === strpos( $src, 'data:' ) ) {
			return;
		}

		// Store original src
		$image->setAttribute( 'data-original-src', $src );

		// Add data-adaptive attribute
		$image->setAttribute( 'data-adaptive', 'true' );

		// Add srcset for responsive images
		$srcset = $this->generate_adaptive_srcset( $src );
		if ( ! empty( $srcset ) ) {
			$image->setAttribute( 'srcset', $srcset );
			$image->setAttribute( 'sizes', 'auto' );
		}
	}

	/**
	 * Generate srcset attribute for adaptive images.
	 *
	 * @param string $src The original image URL.
	 * @return string The srcset attribute.
	 */
	private function generate_adaptive_srcset( $src ) {
		// Placeholder for actual implementation
		// In a real plugin, this would generate URLs for different dimensions

		$srcset = array();

		// Example breakpoints - in a real plugin, these would be customizable
		$breakpoints = array( 320, 480, 768, 1024, 1280, 1440, 1920 );

		foreach ( $breakpoints as $width ) {
			$srcset[] = $this->get_adaptive_url( $src, $width ) . ' ' . $width . 'w';
		}

		return implode( ', ', $srcset );
	}

	/**
	 * Get an adaptive URL for a specific width.
	 *
	 * @param string $src   The original image URL.
	 * @param int    $width The target width.
	 * @return string The adaptive URL.
	 */
	private function get_adaptive_url( $src, $width ) {
		// Placeholder for actual implementation
		// In a real plugin, this would generate a URL for the image service

		// Example implementation with query parameters
		$parsed_url = parse_url( $src );
		$base_url   = $src;
		$query      = array();

		if ( isset( $parsed_url['query'] ) ) {
			parse_str( $parsed_url['query'], $query );
			$base_url = str_replace( '?' . $parsed_url['query'], '', $src );
		}

		$query['width']          = $width;
		$query['trust_optimize'] = 1;

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
