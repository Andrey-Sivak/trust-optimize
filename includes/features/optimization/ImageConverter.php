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

		// Check the image type and process accordingly
		if ( in_array( $mime_type, array( 'image/jpeg', 'image/png' ), true ) ) {
			// Check if WebP conversion is enabled
			if ( ! $this->is_webp_conversion_enabled() ) {
				return $metadata;
			}

			// Standard image - generate WebP
			$metadata = $this->process_standard_image_formats( $metadata, $attachment_id, $file_path );
		} elseif ( in_array( $mime_type, array( 'image/webp', 'image/avif' ), true ) ) {
			// Modern image format - generate PNG fallback
			$metadata = $this->generate_png_fallback( $metadata, $attachment_id, $file_path, $mime_type );
		}

		return $metadata;
	}

	/**
	 * Process standard image formats (JPEG, PNG) to create WebP versions.
	 *
	 * @param array $metadata The attachment metadata.
	 * @param int $attachment_id The attachment ID.
	 * @param string $file_path The file path of the original image.
	 *
	 * @return array The modified attachment metadata.
	 */
	private function process_standard_image_formats( $metadata, $attachment_id, $file_path ) {
		// Get the directory of the original image
		$image_dir = dirname( $file_path );

		// Process the original image size
		$original_file_name = basename( $file_path );
		$webp_original_path = trailingslashit( $image_dir ) . pathinfo( $original_file_name, PATHINFO_FILENAME ) . '.webp';

		$editor = wp_get_image_editor( $file_path );

		if ( ! is_wp_error( $editor ) ) {
			// Set WebP quality (can be a setting)
			$quality = apply_filters( 'trust_optimize_image_quality', 100 );
			$editor->set_quality( $quality );
			$saved = $editor->save( $webp_original_path, 'image/webp' );

			if ( ! is_wp_error( $saved ) ) {
				// Add WebP information directly to our custom format
				$this->image_model->add_format_variation(
					$attachment_id,
					'original',
					'webp',
					array(
						'file'      => basename( $webp_original_path ),
						'mime_type' => 'image/webp',
						'file_size' => filesize( $webp_original_path ),
					)
				);
			} else {
				// Log or handle the error if the original image conversion fails
				error_log( 'TrustOptimize: Failed to create WebP for original image ' . $file_path . ': ' . $saved->get_error_message() );
			}
		} else {
			// Log or handle error getting image editor for original image
			error_log( 'TrustOptimize: Failed to get image editor for original image ' . $file_path . ': ' . $editor->get_error_message() );
		}

		// Process generated sizes
		if ( isset( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
			foreach ( $metadata['sizes'] as $size_name => $size_info ) {
				$image_path = trailingslashit( $image_dir ) . $size_info['file'];
				$webp_path  = trailingslashit( $image_dir ) . pathinfo( $size_info['file'], PATHINFO_FILENAME ) . '.webp';

				$editor = wp_get_image_editor( $image_path );

				if ( ! is_wp_error( $editor ) ) {
					// Set WebP quality (using the same filter)
					$quality = apply_filters( 'trust_optimize_image_quality', 100 );
					$editor->set_quality( $quality );
					$saved = $editor->save( $webp_path, 'image/webp' );

					if ( ! is_wp_error( $saved ) ) {
						// Add WebP information directly to our custom format
						$this->image_model->add_format_variation(
							$attachment_id,
							$size_name,
							'webp',
							array(
								'file'      => basename( $webp_path ),
								'mime-type' => 'image/webp',
								'file_size' => filesize( $webp_path ),
							)
						);
					} else {
						// Log or handle error for specific size conversion
						error_log( 'TrustOptimize: Failed to create WebP for size ' . $size_name . ' (' . $image_path . '): ' . $saved->get_error_message() );
					}
				} else {
					// Log or handle error getting image editor for size
					error_log( 'TrustOptimize: Failed to get image editor for size ' . $size_name . ' (' . $image_path . '): ' . $editor->get_error_message() );
				}
			}
		}

		return $metadata;
	}

	/**
	 * Generate PNG fallback for modern image formats like WebP and AVIF.
	 *
	 * @param array $metadata The attachment metadata.
	 * @param int $attachment_id The attachment ID.
	 * @param string $file_path The file path of the original image.
	 * @param string $mime_type The MIME type of the original image.
	 *
	 * @return array The modified attachment metadata.
	 */
	private function generate_png_fallback( $metadata, $attachment_id, $file_path, $mime_type ) {
		// Get the directory of the original image
		$image_dir = dirname( $file_path );

		// Process the original image size
		$original_file_name = basename( $file_path );
		$png_original_path  = trailingslashit( $image_dir ) . pathinfo( $original_file_name, PATHINFO_FILENAME ) . '.png';

		$editor = wp_get_image_editor( $file_path );

		if ( ! is_wp_error( $editor ) ) {
			// Set PNG quality (can be a setting)
			$quality = apply_filters( 'trust_optimize_image_quality', 100 );
			$editor->set_quality( $quality );
			$saved = $editor->save( $png_original_path, 'image/png' );

			if ( ! is_wp_error( $saved ) ) {
				// Add PNG information directly to our custom format
				$this->image_model->add_format_variation(
					$attachment_id,
					'original',
					'png',
					array(
						'file'      => basename( $png_original_path ),
						'mime_type' => 'image/png',
						'file_size' => filesize( $png_original_path ),
					)
				);

				// Add to WordPress metadata for better integration
				if ( ! isset( $metadata['trust_optimize_converted'] ) ) {
					$metadata['trust_optimize_converted'] = array();
				}

				$metadata['trust_optimize_converted']['original_png'] = array(
					'file'      => basename( $png_original_path ),
					'width'     => $metadata['width'],
					'height'    => $metadata['height'],
					'mime-type' => 'image/png',
					'filesize'  => filesize( $png_original_path ),
				);
			} else {
				// Log or handle the error if the original image conversion fails
				error_log( 'TrustOptimize: Failed to create PNG fallback for original ' . $mime_type . ' image ' . $file_path . ': ' . $saved->get_error_message() );
			}
		} else {
			// Log or handle error getting image editor for original image
			error_log( 'TrustOptimize: Failed to get image editor for original ' . $mime_type . ' image ' . $file_path . ': ' . $editor->get_error_message() );
		}

		// Process generated sizes
		if ( isset( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
			foreach ( $metadata['sizes'] as $size_name => &$size_info ) {
				$image_path = trailingslashit( $image_dir ) . $size_info['file'];
				$png_path   = trailingslashit( $image_dir ) . pathinfo( $size_info['file'], PATHINFO_FILENAME ) . '.png';

				$editor = wp_get_image_editor( $image_path );

				if ( ! is_wp_error( $editor ) ) {
					// Set PNG quality
					$quality = apply_filters( 'trust_optimize_image_quality', 100 );
					$editor->set_quality( $quality );
					$saved = $editor->save( $png_path, 'image/png' );

					if ( ! is_wp_error( $saved ) ) {
						// Add PNG information directly to our custom format
						$this->image_model->add_format_variation(
							$attachment_id,
							$size_name,
							'png',
							array(
								'file'      => basename( $png_path ),
								'mime_type' => 'image/png',
								'file_size' => filesize( $png_path ),
							)
						);

						// Add to WordPress metadata for each size
						if ( ! isset( $size_info['trust_optimize_converted'] ) ) {
							$size_info['trust_optimize_converted'] = array();
						}

						$size_info['trust_optimize_converted']['png'] = array(
							'file'      => basename( $png_path ),
							'width'     => $size_info['width'],
							'height'    => $size_info['height'],
							'mime-type' => 'image/png',
							'filesize'  => filesize( $png_path ),
						);
					} else {
						// Log or handle error for specific size conversion
						error_log( 'TrustOptimize: Failed to create PNG fallback for size ' . $size_name . ' (' . $image_path . '): ' . $saved->get_error_message() );
					}
				} else {
					// Log or handle error getting image editor for size
					error_log( 'TrustOptimize: Failed to get image editor for size ' . $size_name . ' (' . $image_path . '): ' . $editor->get_error_message() );
				}
			}
		}

		return $metadata;
	}

	/**
	 * Check if WebP conversion is enabled.
	 *
	 * @return bool
	 */
	private function is_webp_conversion_enabled() {
		// Placeholder - in a real plugin, this would check a plugin setting
		// For now, let's use a filter to allow enabling/disabling
		return apply_filters( 'trust_optimize_enable_webp_conversion', true );
	}
}
