<?php
/**
 * Image model class
 *
 * @package TrustOptimize
 */

namespace TrustOptimize\Database;

/**
 * Class ImageModel
 * Handles CRUD operations for image data
 */
class ImageModel {

	/**
	 * Table name without prefix
	 *
	 * @var string
	 */
	protected $table = 'trust_optimize_images';

	/**
	 * DatabaseManager instance
	 *
	 * @var DatabaseManager
	 */
	protected $db_manager;

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->db_manager = new DatabaseManager();
	}

	/**
	 * Get image data by attachment ID
	 *
	 * @param int $attachment_id WordPress attachment ID.
	 * @return array|null Image data or null if not found
	 */
	public function get_by_attachment_id( $attachment_id ) {
		global $wpdb;

		$table = $this->db_manager->get_table_name( $this->table );
		$result = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM $table WHERE attachment_id = %d", $attachment_id ),
			ARRAY_A
		);

		if ( ! $result ) {
			return null;
		}

		// Decode the metadata JSON
		$result['metadata'] = json_decode( $result['metadata'], true );
		return $result;
	}

	/**
	 * Save image data
	 *
	 * @param int   $attachment_id WordPress attachment ID.
	 * @param array $metadata Image metadata.
	 * @return int|false The record ID or false on failure
	 */
	public function save( $attachment_id, $metadata ) {
		global $wpdb;

		$table = $this->db_manager->get_table_name( $this->table );
		$existing = $this->get_by_attachment_id( $attachment_id );

		$data = array(
			'attachment_id' => $attachment_id,
			'metadata'      => wp_json_encode( $metadata ),
		);

		// Update if exists, insert if not
		if ( $existing ) {
			$result = $wpdb->update(
				$table,
				$data,
				array( 'attachment_id' => $attachment_id )
			);
			return $result !== false ? $existing['id'] : false;
		} else {
			$data['date_created'] = current_time( 'mysql' );
			$result = $wpdb->insert( $table, $data );
			return $result ? $wpdb->insert_id : false;
		}
	}

	/**
	 * Delete image data
	 *
	 * @param int $attachment_id WordPress attachment ID.
	 * @return bool Success or failure
	 */
	public function delete( $attachment_id ) {
		global $wpdb;

		$table = $this->db_manager->get_table_name( $this->table );
		$result = $wpdb->delete(
			$table,
			array( 'attachment_id' => $attachment_id )
		);

		return $result !== false;
	}

	/**
	 * Create initial metadata structure from WordPress attachment metadata
	 *
	 * This only extracts basic information like dimensions and original format
	 * without handling any converted formats
	 *
	 * @param array $wp_metadata WordPress attachment metadata.
	 * @return array Basic metadata structure for our custom table
	 */
	public function create_base_metadata( $wp_metadata ) {
		if ( ! is_array( $wp_metadata ) || empty( $wp_metadata ) ) {
			return array( 'sizes' => array() );
		}

		$sizes = array();

		// Handle original size
		$sizes['original'] = array(
			'width'   => $wp_metadata['width'] ?? 0,
			'height'  => $wp_metadata['height'] ?? 0,
			'formats' => array(),
		);

		// Add original format
		if ( isset( $wp_metadata['file'] ) ) {
			$extension = pathinfo( $wp_metadata['file'], PATHINFO_EXTENSION );
			$mime_type = 'image/' . ( $extension === 'jpg' ? 'jpeg' : $extension );

			$sizes['original']['formats'][ $extension ] = array(
				'file'      => basename( $wp_metadata['file'] ),
				'mime_type' => $mime_type,
				'file_size' => $wp_metadata['filesize'] ?? 0,
			);
		}

		// Process all generated sizes from WordPress
		if ( isset( $wp_metadata['sizes'] ) && is_array( $wp_metadata['sizes'] ) ) {
			foreach ( $wp_metadata['sizes'] as $size_name => $size_data ) {
				$sizes[ $size_name ] = array(
					'width'   => $size_data['width'],
					'height'  => $size_data['height'],
					'formats' => array(),
				);

				// Add original format for this size
				$size_extension = pathinfo( $size_data['file'], PATHINFO_EXTENSION );
				$size_mime = 'image/' . ( $size_extension === 'jpg' ? 'jpeg' : $size_extension );

				$sizes[ $size_name ]['formats'][ $size_extension ] = array(
					'file'      => $size_data['file'],
					'mime_type' => $size_mime,
					'file_size' => $size_data['filesize'] ?? 0,
				);
			}
		}

		return array(
			'sizes' => $sizes,
		);
	}

	/**
	 * Add format variation to the existing image metadata
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $size_name Size name (e.g., 'original', 'medium', 'thumbnail').
	 * @param string $format Format (e.g., 'webp', 'avif').
	 * @param array  $format_data Format data with file, mime_type, and file_size.
	 * @return bool Success or failure
	 */
	public function add_format_variation( $attachment_id, $size_name, $format, $format_data ) {
		// Get current metadata
		$image_data = $this->get_by_attachment_id( $attachment_id );

		// If no record exists yet, create basic metadata from WordPress data
		if ( ! $image_data ) {
			$wp_metadata = wp_get_attachment_metadata( $attachment_id );
			if ( ! $wp_metadata ) {
				return false;
			}

			$metadata = $this->create_base_metadata( $wp_metadata );
		} else {
			$metadata = $image_data['metadata'];
		}

		// Validate required format_data fields
		if ( ! isset( $format_data['file'] ) || ! isset( $format_data['mime_type'] ) ) {
			return false;
		}

		// Ensure size exists in metadata
		if ( ! isset( $metadata['sizes'][ $size_name ] ) ) {
			// If this is a valid WP size but not in our metadata yet, add it
			$wp_metadata = wp_get_attachment_metadata( $attachment_id );

			if ( $size_name === 'original' ) {
				$metadata['sizes']['original'] = array(
					'width'   => $wp_metadata['width'] ?? 0,
					'height'  => $wp_metadata['height'] ?? 0,
					'formats' => array(),
				);
			} elseif ( isset( $wp_metadata['sizes'][ $size_name ] ) ) {
				$wp_size = $wp_metadata['sizes'][ $size_name ];
				$metadata['sizes'][ $size_name ] = array(
					'width'   => $wp_size['width'],
					'height'  => $wp_size['height'],
					'formats' => array(),
				);
			} else {
				return false; // Size doesn't exist in WP metadata
			}
		}

		// Add the format data
		$metadata['sizes'][ $size_name ]['formats'][ $format ] = array(
			'file'      => $format_data['file'],
			'mime_type' => $format_data['mime_type'],
			'file_size' => $format_data['file_size'] ?? 0,
		);

		// Save updated metadata
		return $this->save( $attachment_id, $metadata ) ? true : false;
	}

	/**
	 * Get format variations for a specific size
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $size_name Size name (e.g., 'original', 'medium', 'thumbnail').
	 * @return array|null Format variations or null if not found
	 */
	public function get_size_variations( $attachment_id, $size_name ) {
		$image_data = $this->get_by_attachment_id( $attachment_id );

		if ( ! $image_data || ! isset( $image_data['metadata']['sizes'][ $size_name ] ) ) {
			return null;
		}

		return $image_data['metadata']['sizes'][ $size_name ];
	}

	/**
	 * Get a specific format for a size
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $size_name Size name (e.g., 'original', 'medium', 'thumbnail').
	 * @param string $format Format (e.g., 'webp', 'avif', 'jpeg', 'png').
	 * @return array|null Format data or null if not found
	 */
	public function get_format( $attachment_id, $size_name, $format ) {
		$size_data = $this->get_size_variations( $attachment_id, $size_name );

		if ( ! $size_data || ! isset( $size_data['formats'][ $format ] ) ) {
			return null;
		}

		return $size_data['formats'][ $format ];
	}

	/**
	 * Legacy method to convert from WordPress metadata including custom fields
	 *
	 * This is kept for backward compatibility during transition
	 *
	 * @param array $wp_metadata WordPress attachment metadata.
	 * @return array Formatted metadata for our custom table
	 * @deprecated Use create_base_metadata() and add_format_variation() instead
	 */
	public function convert_from_wp_metadata( $wp_metadata ) {
		if ( ! is_array( $wp_metadata ) || empty( $wp_metadata ) ) {
			return array( 'sizes' => array() );
		}

		$sizes = array();

		// Handle original size
		$sizes['original'] = array(
			'width'   => $wp_metadata['width'] ?? 0,
			'height'  => $wp_metadata['height'] ?? 0,
			'formats' => array(),
		);

		// Add original format
		if ( isset( $wp_metadata['file'] ) ) {
			$extension = pathinfo( $wp_metadata['file'], PATHINFO_EXTENSION );
			$mime_type = 'image/' . ( $extension === 'jpg' ? 'jpeg' : $extension );

			$sizes['original']['formats'][ $extension ] = array(
				'file'      => basename( $wp_metadata['file'] ),
				'mime_type' => $mime_type,
				'file_size' => $wp_metadata['filesize'] ?? 0,
			);
		}

		// Add WebP format for original if exists
		if ( isset( $wp_metadata['trust_optimize_converted']['original_webp'] ) ) {
			$webp_data = $wp_metadata['trust_optimize_converted']['original_webp'];
			$sizes['original']['formats']['webp'] = array(
				'file'      => $webp_data['file'],
				'mime_type' => $webp_data['mime-type'],
				'file_size' => $webp_data['filesize'],
			);
		}

		// Handle all generated sizes
		if ( isset( $wp_metadata['sizes'] ) && is_array( $wp_metadata['sizes'] ) ) {
			foreach ( $wp_metadata['sizes'] as $size_name => $size_data ) {
				$sizes[ $size_name ] = array(
					'width'   => $size_data['width'],
					'height'  => $size_data['height'],
					'formats' => array(),
				);

				// Add original format for this size
				$size_extension = pathinfo( $size_data['file'], PATHINFO_EXTENSION );
				$size_mime = 'image/' . ( $size_extension === 'jpg' ? 'jpeg' : $size_extension );

				$sizes[ $size_name ]['formats'][ $size_extension ] = array(
					'file'      => $size_data['file'],
					'mime_type' => $size_mime,
					'file_size' => isset( $size_data['filesize'] ) ? $size_data['filesize'] : 0,
				);

				// Add converted formats if they exist
				if ( isset( $size_data['trust_optimize_converted'] ) && is_array( $size_data['trust_optimize_converted'] ) ) {
					foreach ( $size_data['trust_optimize_converted'] as $format => $format_data ) {
						$format_key = $format;

						// Handle special case where format might be 'webp', 'original_webp', etc.
						if ( strpos( $format, '_' ) !== false ) {
							$parts = explode( '_', $format );
							$format_key = end( $parts );
						}

						$sizes[ $size_name ]['formats'][ $format_key ] = array(
							'file'      => $format_data['file'],
							'mime_type' => $format_data['mime-type'],
							'file_size' => $format_data['filesize'],
						);
					}
				}
			}
		}

		return array(
			'sizes' => $sizes,
		);
	}

	/**
	 * Check if any size has a specific format variation
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $format Format to check for (e.g., 'webp', 'avif').
	 * @return bool True if the format exists for any size, false otherwise
	 */
	public function has_format_variation( $attachment_id, $format ) {
		$image_data = $this->get_by_attachment_id( $attachment_id );

		if ( ! $image_data || ! isset( $image_data['metadata']['sizes'] ) ) {
			return false;
		}

		// Check if any size has the requested format variation
		foreach ( $image_data['metadata']['sizes'] as $size_data ) {
			if ( isset( $size_data['formats'][ $format ] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Get all available format variations for an attachment
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array Array of formats that exist for this attachment (e.g., ['webp', 'jpeg', 'png'])
	 */
	public function get_available_formats( $attachment_id ) {
		$image_data = $this->get_by_attachment_id( $attachment_id );
		$formats = array();

		if ( ! $image_data || ! isset( $image_data['metadata']['sizes'] ) ) {
			return $formats;
		}

		// Check all sizes and collect unique formats
		foreach ( $image_data['metadata']['sizes'] as $size_data ) {
			if ( isset( $size_data['formats'] ) && is_array( $size_data['formats'] ) ) {
				foreach ( array_keys( $size_data['formats'] ) as $format ) {
					if ( ! in_array( $format, $formats, true ) ) {
						$formats[] = $format;
					}
				}
			}
		}

		return $formats;
	}
}
