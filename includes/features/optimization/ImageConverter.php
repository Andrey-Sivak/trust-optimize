<?php
/**
 * Image converter class
 *
 * Handles image format conversions (e.g., to WebP, AVIF).
 *
 * @package TrustOptimize
 */

namespace TrustOptimize\Features\Optimization;

use TrustOptimize\Database\ImageModel;
use WP_Error;

/**
 * Class ImageConverter
 */
class ImageConverter {

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
	 * Generates format versions for all generated image sizes.
	 *
	 * This method is hooked into 'wp_generate_attachment_metadata'.
	 *
	 * @param array $metadata The attachment metadata.
	 * @param int $attachment_id The attachment ID.
	 *
	 * @return array The modified attachment metadata.
	 */
	public function handle_image_upload( $metadata, $attachment_id ) {
		// Get the file path of the original uploaded image
		$file_path = get_attached_file( $attachment_id );

		if ( ! $file_path ) {
			return $metadata; // Should not happen, but good to check
		}

		// Get the file type
		$file_type = wp_check_filetype( $file_path );
		$mime_type = $file_type['type'];

		// Initialize base metadata in our custom table
		$this->image_model->save( $attachment_id, $this->image_model->create_base_metadata( $metadata ) );

		// Determine conversion strategy based on mime type
		$conversion_strategy = $this->get_conversion_strategy($mime_type);
		if (empty($conversion_strategy)) {
			return $metadata;
		}

		// Process the image according to the determined strategy
		return $this->convert_image_formats(
			$metadata,
			$attachment_id,
			$file_path,
			$conversion_strategy['target_format'],
			$conversion_strategy['target_mime']
		);
	}

	/**
	 * Determine what format to convert to based on original mime type
	 *
	 * @param string $mime_type Original image mime type
	 * @return array|null Conversion strategy or null if no conversion needed
	 */
	private function get_conversion_strategy($mime_type) {
		if (in_array($mime_type, array('image/jpeg', 'image/png'), true)) {
			// Check if WebP conversion is enabled
			if (!$this->is_webp_conversion_enabled()) {
				return null;
			}
			return array(
				'target_format' => 'webp',
				'target_mime' => 'image/webp'
			);
		} elseif (in_array($mime_type, array('image/webp', 'image/avif'), true)) {
			return array(
				'target_format' => 'png',
				'target_mime' => 'image/png'
			);
		}

		return null; // No conversion for other types
	}

	/**
	 * Convert image to a different format for all sizes
	 *
	 * @param array $metadata The attachment metadata
	 * @param int $attachment_id The attachment ID
	 * @param string $file_path The file path of the original image
	 * @param string $target_format The target format extension (e.g., 'webp', 'png')
	 * @param string $target_mime The target mime type (e.g., 'image/webp', 'image/png')
	 * @return array The modified attachment metadata
	 */
	private function convert_image_formats($metadata, $attachment_id, $file_path, $target_format, $target_mime) {
		// Get the directory of the original image
		$image_dir = dirname($file_path);

		// Process the original image size
		$this->convert_single_image(
			$metadata,
			$attachment_id,
			$file_path,
			$image_dir,
			'original',
			$target_format,
			$target_mime
		);

		// Process all generated sizes
		if (isset($metadata['sizes']) && is_array($metadata['sizes'])) {
			foreach ($metadata['sizes'] as $size_name => &$size_info) {
				$image_path = trailingslashit($image_dir) . $size_info['file'];

				$this->convert_single_image(
					$metadata,
					$attachment_id,
					$image_path,
					$image_dir,
					$size_name,
					$target_format,
					$target_mime,
					$size_info
				);
			}
		}

		return $metadata;
	}

	/**
	 * Convert a single image to target format
	 *
	 * @param array $metadata The attachment metadata
	 * @param int $attachment_id The attachment ID
	 * @param string $source_path Source image path
	 * @param string $dest_dir Destination directory
	 * @param string $size_name Size name (e.g., 'original', 'thumbnail')
	 * @param string $target_format Target format extension
	 * @param string $target_mime Target mime type
	 * @param array $size_info Optional size info for non-original sizes
	 * @return bool Success or failure
	 */
	private function convert_single_image($metadata, $attachment_id, $source_path, $dest_dir, $size_name, $target_format, $target_mime, &$size_info = null) {
		$original_filename = basename($source_path);
		$target_path = trailingslashit($dest_dir) . pathinfo($original_filename, PATHINFO_FILENAME) . '.' . $target_format;

		$editor = wp_get_image_editor($source_path);

		if (is_wp_error($editor)) {
			error_log(sprintf(
				'TrustOptimize: Failed to get image editor for %s (%s): %s',
				$size_name,
				$source_path,
				$editor->get_error_message()
			));
			return false;
		}

		// Set quality
		$quality = apply_filters('trust_optimize_image_quality', 100);
		$editor->set_quality($quality);

		// Save in target format
		$saved = $editor->save($target_path, $target_mime);

		if (is_wp_error($saved)) {
			error_log(sprintf(
				'TrustOptimize: Failed to create %s for %s (%s): %s',
				$target_format,
				$size_name,
				$source_path,
				$saved->get_error_message()
			));
			return false;
		}

		// Add information to our custom format database
		$this->image_model->add_format_variation(
			$attachment_id,
			$size_name,
			$target_format,
			array(
				'file'      => basename($target_path),
				'mime_type' => $target_mime,
				'file_size' => filesize($target_path),
			)
		);

		// For backward compatibility, also update WordPress metadata
		$this->update_wp_metadata($metadata, $size_name, $target_format, $target_path, $size_info);

		return true;
	}

	/**
	 * Update WordPress metadata with converted format info
	 *
	 * @param array $metadata Main metadata array
	 * @param string $size_name Size name
	 * @param string $format Target format
	 * @param string $file_path Converted file path
	 * @param array $size_info Size info reference for non-original sizes
	 */
	private function update_wp_metadata(&$metadata, $size_name, $format, $file_path, &$size_info = null) {
		$format_data = array(
			'file'      => basename($file_path),
			'mime-type' => 'image/' . $format,
			'filesize'  => filesize($file_path),
		);

		if ($size_name === 'original') {
			// Add width and height for original size
			$format_data['width'] = $metadata['width'];
			$format_data['height'] = $metadata['height'];

			// Store in main metadata array
			if (!isset($metadata['trust_optimize_converted'])) {
				$metadata['trust_optimize_converted'] = array();
			}
			$metadata['trust_optimize_converted']['original_' . $format] = $format_data;
		} elseif ($size_info !== null) {
			// Add width and height for this size
			$format_data['width'] = $size_info['width'];
			$format_data['height'] = $size_info['height'];

			// Store in size-specific metadata
			if (!isset($size_info['trust_optimize_converted'])) {
				$size_info['trust_optimize_converted'] = array();
			}
			$size_info['trust_optimize_converted'][$format] = $format_data;
		}
	}

	/**
	 * Check if WebP conversion is enabled.
	 *
	 * @return bool
	 */
	private function is_webp_conversion_enabled() {
		// Placeholder - in a real plugin, this would check a plugin setting
		return apply_filters('trust_optimize_enable_webp_conversion', true);
	}
}
