<?php
/**
 * Image Converter class
 *
 * @package TrustOptimize\Features\Optimization
 */

namespace TrustOptimize\Features\Optimization;

use Imagick;
use TrustOptimize\Database\ImageModel;
use TrustOptimize\Admin\Settings;

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
	 * Settings instance
	 *
	 * @var Settings
	 */
	protected $settings;

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->image_model = new ImageModel();
		$this->settings    = new Settings();
	}

	/**
	 * Generates format versions for all generated image sizes.
	 *
	 * This method is hooked into 'wp_generate_attachment_metadata'.
	 *
	 * @param array $metadata The attachment metadata.
	 * @param int   $attachment_id The attachment ID.
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

		// Get conversion strategies based on mime type
		$conversion_strategies = $this->get_conversion_strategies( $mime_type );

		if ( empty( $conversion_strategies ) ) {
			return $metadata;
		}

		// Process each conversion strategy
		foreach ( $conversion_strategies as $strategy ) {
			$metadata = $this->convert_image_formats(
				$metadata,
				$attachment_id,
				$file_path,
				$strategy['target_format'],
				$strategy['target_mime']
			);
		}

		return $metadata;
	}

	/**
	 * Determine what formats to convert to based on original mime type and settings
	 *
	 * @param string $mime_type Original image mime type
	 * @return array Array of conversion strategies or empty array if no conversion needed
	 */
	private function get_conversion_strategies( $mime_type ) {
		$strategies = array();

		// Skip conversion if the image is already in one of our target formats
		if ( in_array( $mime_type, array( 'image/webp', 'image/avif' ), true ) ) {
			return array(
				array(
					'target_format' => 'png',
					'target_mime'   => 'image/png',
				),
			);
		}

		// Only convert jpeg and png images
		if ( ! in_array( $mime_type, array( 'image/jpeg', 'image/png' ), true ) ) {
			return array();
		}

		// Add AVIF strategy if enabled
		if ( $this->is_avif_conversion_enabled() ) {
			$strategies[] = array(
				'target_format' => 'avif',
				'target_mime'   => 'image/avif',
			);
		}

		// Add WebP strategy if enabled
		if ( $this->is_webp_conversion_enabled() ) {
			$strategies[] = array(
				'target_format' => 'webp',
				'target_mime'   => 'image/webp',
			);
		}

		return $strategies;
	}

	/**
	 * Convert image to a different format for all sizes
	 *
	 * @param array  $metadata The attachment metadata
	 * @param int    $attachment_id The attachment ID
	 * @param string $file_path The file path of the original image
	 * @param string $target_format The target format extension (e.g., 'webp', 'avif')
	 * @param string $target_mime The target mime type (e.g., 'image/webp', 'image/avif')
	 * @return array The modified attachment metadata
	 */
	private function convert_image_formats( $metadata, $attachment_id, $file_path, $target_format, $target_mime ) {
		// Get the directory of the original image
		$image_dir = dirname( $file_path );

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
		if ( isset( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
			foreach ( $metadata['sizes'] as $size_name => &$size_info ) {
				$image_path = trailingslashit( $image_dir ) . $size_info['file'];

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
	 * @param array  $metadata The attachment metadata
	 * @param int    $attachment_id The attachment ID
	 * @param string $source_path Source image path
	 * @param string $dest_dir Destination directory
	 * @param string $size_name Size name (e.g., 'original', 'thumbnail')
	 * @param string $target_format Target format extension
	 * @param string $target_mime Target mime type
	 * @param array  $size_info Optional size info for non-original sizes
	 * @return bool Success or failure
	 */
	private function convert_single_image( $metadata, $attachment_id, $source_path, $dest_dir, $size_name, $target_format, $target_mime, &$size_info = null ) {
		$original_filename = basename( $source_path );
		$target_path       = trailingslashit( $dest_dir ) . pathinfo( $original_filename, PATHINFO_FILENAME ) . '.' . $target_format;

		$editor = wp_get_image_editor( $source_path );

		if ( is_wp_error( $editor ) ) {
			error_log(
				sprintf(
					'TrustOptimize: Failed to get image editor for %s (%s): %s',
					$size_name,
					$source_path,
					$editor->get_error_message()
				)
			);
			return false;
		}

		// Set quality based on format
		$quality = $this->get_quality_for_format( $target_format );
		$editor->set_quality( $quality );

		// Save in target format
		$saved = $editor->save( $target_path, $target_mime );

		if ( is_wp_error( $saved ) ) {
			error_log(
				sprintf(
					'TrustOptimize: Failed to create %s for %s (%s): %s',
					$target_format,
					$size_name,
					$source_path,
					$saved->get_error_message()
				)
			);
			return false;
		}

		// Add information to our custom format database
		$this->image_model->add_format_variation(
			$attachment_id,
			$size_name,
			$target_format,
			array(
				'file'      => basename( $target_path ),
				'mime_type' => $target_mime,
				'file_size' => filesize( $target_path ),
			)
		);

		// For backward compatibility, also update WordPress metadata
		$this->update_wp_metadata( $metadata, $size_name, $target_format, $target_path, $size_info );

		return true;
	}

	/**
	 * Get the appropriate quality setting for a specific format
	 *
	 * @param string $format The image format (webp, avif, etc.)
	 * @return int The quality value to use
	 */
	private function get_quality_for_format( $format ) {
		// Get the base quality from settings
		$base_quality = (int) $this->settings->get( 'image_quality', 100 );

		// You might want different quality settings for different formats
		// AVIF often requires less quality for similar visual results
		switch ( $format ) {
			case 'avif':
				// AVIF typically needs lower quality values for similar visual results
				$quality = min( $base_quality, 85 ); // Cap at 85 for AVIF
				break;
			case 'webp':
				// WebP can use slightly lower quality than JPEG for similar results
				$quality = min( $base_quality, 90 ); // Cap at 90 for WebP
				break;
			default:
				$quality = $base_quality;
		}

		return apply_filters( "trust_optimize_{$format}_quality", $quality );
	}

	/**
	 * Update WordPress metadata with converted format info
	 *
	 * @param array  $metadata Main metadata array
	 * @param string $size_name Size name
	 * @param string $format Target format
	 * @param string $file_path Converted file path
	 * @param array  $size_info Size info reference for non-original sizes
	 */
	private function update_wp_metadata( &$metadata, $size_name, $format, $file_path, &$size_info = null ) {
		$format_data = array(
			'file'      => basename( $file_path ),
			'mime-type' => 'image/' . $format,
			'filesize'  => filesize( $file_path ),
		);

		if ( $size_name === 'original' ) {
			// Add width and height for original size
			$format_data['width']  = $metadata['width'];
			$format_data['height'] = $metadata['height'];

			// Store in main metadata array
			if ( ! isset( $metadata['trust_optimize_converted'] ) ) {
				$metadata['trust_optimize_converted'] = array();
			}
			$metadata['trust_optimize_converted'][ 'original_' . $format ] = $format_data;
		} elseif ( $size_info !== null ) {
			// Add width and height for this size
			$format_data['width']  = $size_info['width'];
			$format_data['height'] = $size_info['height'];

			// Store in size-specific metadata
			if ( ! isset( $size_info['trust_optimize_converted'] ) ) {
				$size_info['trust_optimize_converted'] = array();
			}
			$size_info['trust_optimize_converted'][ $format ] = $format_data;
		}
	}

	/**
	 * Check if WebP conversion is enabled.
	 *
	 * @return bool
	 */
	private function is_webp_conversion_enabled() {
		return (bool) $this->settings->get( 'convert_to_webp', 1 );
	}

	/**
	 * Check if AVIF conversion is enabled.
	 *
	 * @return bool
	 */
	private function is_avif_conversion_enabled() {
		return (bool) $this->settings->get( 'convert_to_avif', 1 );
	}

	/**
	 * Check if server supports AVIF image generation
	 *
	 * @return bool True if AVIF conversion is supported
	 */
	private function is_avif_supported() {
		// Check GD support for AVIF
		if ( function_exists( 'gd_info' ) ) {
			$gd_info = gd_info();
			if ( isset( $gd_info['AVIF Support'] ) && $gd_info['AVIF Support'] ) {
				return true;
			}
		}

		// Check Imagick support for AVIF
		if ( extension_loaded( 'imagick' ) ) {
			$imagick = new Imagick();
			$formats = $imagick->queryFormats();
			if ( in_array( 'AVIF', $formats ) ) {
				return true;
			}
		}

		return false;
	}
}
