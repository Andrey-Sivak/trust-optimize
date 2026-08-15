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
	 * Per-request cache for attachment data.
	 *
	 * @var array
	 */
	protected static $cache = array();

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
		if ( isset( self::$cache[ $attachment_id ] ) ) {
			return self::$cache[ $attachment_id ];
		}

		global $wpdb;

		$table  = $this->db_manager->get_table_name( $this->table );
		$result = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM $table WHERE attachment_id = %d", $attachment_id ),
			ARRAY_A
		);

		if ( ! $result ) {
			self::$cache[ $attachment_id ] = null;
			return null;
		}

		// Decode and normalize the metadata JSON. Legacy rows may not contain
		// newer manifest keys, so reads must remain non-fatal.
		$result['metadata'] = $this->normalize_metadata( json_decode( $result['metadata'], true ) );

		self::$cache[ $attachment_id ] = $result;
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

		$table    = $this->db_manager->get_table_name( $this->table );
		$existing = $this->get_by_attachment_id( $attachment_id );

		$data = array(
			'attachment_id' => $attachment_id,
			'metadata'      => wp_json_encode( $metadata ),
		);

		self::clear_cache( $attachment_id );

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
			$result               = $wpdb->insert( $table, $data );
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

		$table  = $this->db_manager->get_table_name( $this->table );
		$result = $wpdb->delete(
			$table,
			array( 'attachment_id' => $attachment_id )
		);

		self::clear_cache( $attachment_id );

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
			return array(
				'sizes'              => array(),
				'generated_variants' => array(),
				'failed_tasks'       => array(),
				'conversion_errors'  => array(),
			);
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
				$size_mime      = 'image/' . ( $size_extension === 'jpg' ? 'jpeg' : $size_extension );

				$sizes[ $size_name ]['formats'][ $size_extension ] = array(
					'file'      => $size_data['file'],
					'mime_type' => $size_mime,
					'file_size' => $size_data['filesize'] ?? 0,
				);
			}
		}

		return array(
			'sizes'              => $sizes,
			'generated_variants' => array(),
			'failed_tasks'       => array(),
			'conversion_errors'  => array(),
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
				$wp_size                         = $wp_metadata['sizes'][ $size_name ];
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

		$metadata = $this->upsert_generated_variant(
			$metadata,
			$attachment_id,
			$size_name,
			$format,
			$format_data
		);

		// Save updated metadata
		self::clear_cache( $attachment_id );
		return $this->save( $attachment_id, $metadata ) ? true : false;
	}

	/**
	 * Get all plugin-generated variant records for an attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array Generated variant manifest records.
	 */
	public function get_generated_variants( $attachment_id ) {
		$image_data = $this->get_by_attachment_id( $attachment_id );

		if ( ! $image_data || ! isset( $image_data['metadata']['generated_variants'] ) ) {
			return array();
		}

		return is_array( $image_data['metadata']['generated_variants'] ) ? $image_data['metadata']['generated_variants'] : array();
	}

	/**
	 * Store current attachment profile hash.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $profile_hash  Current profile hash.
	 * @return bool Success or failure.
	 */
	public function update_profile_hash( $attachment_id, $profile_hash ) {
		$image_data = $this->get_by_attachment_id( $attachment_id );

		if ( ! $image_data ) {
			$wp_metadata = wp_get_attachment_metadata( $attachment_id );
			if ( ! $wp_metadata ) {
				return false;
			}

			$metadata = $this->create_base_metadata( $wp_metadata );
		} else {
			$metadata = $image_data['metadata'];
		}

		$metadata['profile_hash'] = $profile_hash;

		self::clear_cache( $attachment_id );
		return $this->save( $attachment_id, $metadata ) ? true : false;
	}

	/**
	 * Get current attachment profile hash.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string Profile hash or empty string.
	 */
	public function get_profile_hash( $attachment_id ) {
		$image_data = $this->get_by_attachment_id( $attachment_id );

		if ( ! $image_data || empty( $image_data['metadata']['profile_hash'] ) ) {
			return '';
		}

		return $image_data['metadata']['profile_hash'];
	}

	/**
	 * Check whether a generated variant manifest record is stale.
	 *
	 * @param array  $variant      Generated variant manifest record.
	 * @param string $profile_hash Current profile hash.
	 * @return bool True when variant profile differs from current profile.
	 */
	public function is_variant_stale( array $variant, $profile_hash ) {
		return empty( $variant['profile_hash'] ) || $profile_hash !== $variant['profile_hash'];
	}

	/**
	 * Remove generated variant records from plugin metadata.
	 *
	 * @param int   $attachment_id Attachment ID.
	 * @param array $variant_keys  Variant keys in "size:format" form.
	 * @return bool Success or failure.
	 */
	public function remove_generated_variants( $attachment_id, array $variant_keys ) {
		$image_data = $this->get_by_attachment_id( $attachment_id );

		if ( ! $image_data ) {
			return false;
		}

		$metadata = $this->normalize_metadata( $image_data['metadata'] );

		foreach ( $variant_keys as $variant_key ) {
			if ( isset( $metadata['generated_variants'][ $variant_key ] ) ) {
				$variant = $metadata['generated_variants'][ $variant_key ];
				unset( $metadata['generated_variants'][ $variant_key ] );

				if ( ! empty( $variant['size_name'] ) && ! empty( $variant['format'] ) ) {
					unset( $metadata['sizes'][ $variant['size_name'] ]['formats'][ $variant['format'] ] );
				}
			}
		}

		self::clear_cache( $attachment_id );
		return $this->save( $attachment_id, $metadata ) ? true : false;
	}

	/**
	 * Record a failed conversion task without counting it as completed.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $size_name     Size name.
	 * @param string $format        Target format.
	 * @param string $mime_type     Target MIME type.
	 * @param string $message       Error message.
	 * @return bool True when all queued tasks have reached a terminal state.
	 */
	public function record_failed_task( $attachment_id, $size_name, $format, $mime_type, $message = '' ) {
		$image_data = $this->get_by_attachment_id( $attachment_id );

		if ( ! $image_data ) {
			$wp_metadata = wp_get_attachment_metadata( $attachment_id );
			if ( ! $wp_metadata ) {
				return false;
			}

			$metadata = $this->create_base_metadata( $wp_metadata );
		} else {
			$metadata = $image_data['metadata'];
		}

		$metadata = $this->normalize_metadata( $metadata );

		$key = $size_name . ':' . $format;
		$now = current_time( 'mysql' );

		$failure = array(
			'attachment_id' => (int) $attachment_id,
			'size_name'     => $size_name,
			'format'        => $format,
			'mime_type'     => $mime_type,
			'message'       => $message,
			'failed_at'     => $now,
		);

		$metadata['failed_tasks'][ $key ] = $failure;
		$metadata['conversion_errors'][]  = $failure;

		self::clear_cache( $attachment_id );
		$this->save( $attachment_id, $metadata );

		return $this->update_status_after_failed_task( $attachment_id );
	}

	/**
	 * Normalize metadata into the current structure.
	 *
	 * @param array|null $metadata Stored metadata.
	 * @return array Normalized metadata.
	 */
	private function normalize_metadata( $metadata ) {
		if ( ! is_array( $metadata ) ) {
			$metadata = array();
		}

		if ( ! isset( $metadata['sizes'] ) || ! is_array( $metadata['sizes'] ) ) {
			$metadata['sizes'] = array();
		}

		if ( ! isset( $metadata['generated_variants'] ) || ! is_array( $metadata['generated_variants'] ) ) {
			$metadata['generated_variants'] = array();
		}

		if ( ! isset( $metadata['failed_tasks'] ) || ! is_array( $metadata['failed_tasks'] ) ) {
			$metadata['failed_tasks'] = array();
		}

		if ( ! isset( $metadata['conversion_errors'] ) || ! is_array( $metadata['conversion_errors'] ) ) {
			$metadata['conversion_errors'] = array();
		}

		return $metadata;
	}

	/**
	 * Add or update a plugin-generated variant in metadata manifest.
	 *
	 * @param array  $metadata      Current plugin metadata.
	 * @param int    $attachment_id Attachment ID.
	 * @param string $size_name     Size name.
	 * @param string $format        Generated format.
	 * @param array  $format_data   Generated format data.
	 * @return array Updated plugin metadata.
	 */
	private function upsert_generated_variant( $metadata, $attachment_id, $size_name, $format, $format_data ) {
		$metadata = $this->normalize_metadata( $metadata );

		$file = isset( $format_data['file'] ) ? $format_data['file'] : '';
		if ( '' === $file ) {
			return $metadata;
		}

		$now      = current_time( 'mysql' );
		$existing = null;
		$key      = $size_name . ':' . $format;

		if ( isset( $metadata['generated_variants'][ $key ] ) && is_array( $metadata['generated_variants'][ $key ] ) ) {
			$existing = $metadata['generated_variants'][ $key ];
		}

		unset( $metadata['failed_tasks'][ $key ] );

		$created_at = isset( $existing['created_at'] ) ? $existing['created_at'] : $now;
		$file_path  = isset( $format_data['path'] ) ? $format_data['path'] : '';

		$metadata['generated_variants'][ $key ] = array(
			'attachment_id' => (int) $attachment_id,
			'size_name'     => $size_name,
			'format'        => $format,
			'mime_type'     => $format_data['mime_type'],
			'file'          => $file,
			'relative_dir'  => $this->get_relative_upload_dir( $file_path ),
			'file_size'     => $format_data['file_size'] ?? 0,
			'file_hash'     => $this->get_file_hash( $file_path ),
			'profile_hash'  => $format_data['profile_hash'] ?? '',
			'created_at'    => $created_at,
			'updated_at'    => $now,
		);

		return $metadata;
	}

	/**
	 * Get a path's directory relative to uploads basedir.
	 *
	 * @param string $file_path Absolute generated file path.
	 * @return string Relative upload directory, or empty string when unavailable.
	 */
	private function get_relative_upload_dir( $file_path ) {
		if ( '' === $file_path ) {
			return '';
		}

		$upload_dir = wp_upload_dir();
		if ( empty( $upload_dir['basedir'] ) ) {
			return '';
		}

		$base_dir = untrailingslashit( wp_normalize_path( $upload_dir['basedir'] ) );
		$file_dir = untrailingslashit( wp_normalize_path( dirname( $file_path ) ) );

		if ( 0 !== strpos( $file_dir, $base_dir ) ) {
			return '';
		}

		return ltrim( substr( $file_dir, strlen( $base_dir ) ), '/' );
	}

	/**
	 * Compute generated file hash when the file is available locally.
	 *
	 * @param string $file_path Absolute generated file path.
	 * @return string File hash or empty string.
	 */
	private function get_file_hash( $file_path ) {
		if ( '' === $file_path || ! is_readable( $file_path ) ) {
			return '';
		}

		$hash = hash_file( 'sha256', $file_path );

		return false === $hash ? '' : $hash;
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
	 * Get all variations of a specific format across all sizes
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $format Format (e.g., 'webp', 'avif', 'jpeg', 'png').
	 * @return array Associative array of size names and their format data
	 */
	public function get_format_variations( $attachment_id, $format ) {
		$image_data = $this->get_by_attachment_id( $attachment_id );
		$variations = array();

		if ( ! $image_data || ! isset( $image_data['metadata']['sizes'] ) ) {
			return $variations;
		}

		foreach ( $image_data['metadata']['sizes'] as $size_name => $size_data ) {
			if ( isset( $size_data['formats'][ $format ] ) ) {
				$format_data = $size_data['formats'][ $format ];
				// Include width and height from the size data for convenience
				$format_data['width']     = $size_data['width'] ?? 0;
				$format_data['height']    = $size_data['height'] ?? 0;
				$variations[ $size_name ] = $format_data;
			}
		}

		return $variations;
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
			return array(
				'sizes'              => array(),
				'generated_variants' => array(),
			);
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
			$webp_data                            = $wp_metadata['trust_optimize_converted']['original_webp'];
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
				$size_mime      = 'image/' . ( $size_extension === 'jpg' ? 'jpeg' : $size_extension );

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
							$parts      = explode( '_', $format );
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
			'sizes'              => $sizes,
			'generated_variants' => array(),
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
		$formats    = array();

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

	/**
	 * Update the queue status for an attachment
	 *
	 * @param int    $attachment_id WordPress attachment ID.
	 * @param string $status        Status value: 'pending', 'processing', 'completed', 'failed'.
	 * @param int    $total_tasks   Total number of conversion tasks (set only when status is 'pending').
	 * @return bool Success or failure
	 */
	public function update_status( $attachment_id, $status, $total_tasks = null ) {
		global $wpdb;

		$table = $this->db_manager->get_table_name( $this->table );
		$data  = array( 'status' => $status );

		if ( null !== $total_tasks ) {
			$data['total_tasks']     = $total_tasks;
			$data['completed_tasks'] = 0;
		}

		self::clear_cache( $attachment_id );

		$result = $wpdb->update(
			$table,
			$data,
			array( 'attachment_id' => $attachment_id )
		);

		return $result !== false;
	}

	/**
	 * Increment the completed task count and update status accordingly
	 *
	 * @param int $attachment_id WordPress attachment ID.
	 * @return bool True if all tasks are completed, false otherwise
	 */
	public function increment_completed_tasks( $attachment_id ) {
		global $wpdb;

		$table = $this->db_manager->get_table_name( $this->table );

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET completed_tasks = completed_tasks + 1, status = 'processing', date_modified = %s WHERE attachment_id = %d",
				current_time( 'mysql' ),
				$attachment_id
			)
		);

		self::clear_cache( $attachment_id );

		// Check if all tasks are completed
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT total_tasks, completed_tasks FROM {$table} WHERE attachment_id = %d",
				$attachment_id
			)
		);

		if ( $row && (int) $row->completed_tasks >= (int) $row->total_tasks ) {
			$wpdb->update(
				$table,
				array( 'status' => 'completed' ),
				array( 'attachment_id' => $attachment_id )
			);
			self::clear_cache( $attachment_id );
			delete_transient( 'trust_optimize_formats_' . $attachment_id );
			return true;
		}

		return false;
	}

	/**
	 * Update status after a conversion task failed.
	 *
	 * @param int $attachment_id WordPress attachment ID.
	 * @return bool True if all tasks reached a terminal state.
	 */
	private function update_status_after_failed_task( $attachment_id ) {
		global $wpdb;

		$table = $this->db_manager->get_table_name( $this->table );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT metadata, total_tasks, completed_tasks FROM {$table} WHERE attachment_id = %d",
				$attachment_id
			)
		);
		// phpcs:enable

		if ( ! $row ) {
			return false;
		}

		$metadata     = $this->normalize_metadata( json_decode( $row->metadata, true ) );
		$failed_count = count( $metadata['failed_tasks'] );
		$total_tasks  = (int) $row->total_tasks;
		$completed    = (int) $row->completed_tasks;
		$status       = $completed > 0 ? 'completed_with_errors' : 'failed';

		if ( 0 < $total_tasks && ( $completed + $failed_count ) < $total_tasks ) {
			$status = 'processing';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$table,
			array( 'status' => $status ),
			array( 'attachment_id' => $attachment_id )
		);

		self::clear_cache( $attachment_id );

		return 0 < $total_tasks && ( $completed + $failed_count ) >= $total_tasks;
	}

	/**
	 * Get the queue status for an attachment
	 *
	 * @param int $attachment_id WordPress attachment ID.
	 * @return array|null Status data or null if not found
	 */
	public function get_status( $attachment_id ) {
		global $wpdb;

		$table = $this->db_manager->get_table_name( $this->table );

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT status, total_tasks, completed_tasks FROM {$table} WHERE attachment_id = %d",
				$attachment_id
			),
			ARRAY_A
		);
	}

	/**
	 * Clear per-request cache
	 *
	 * @param int|null $attachment_id Specific attachment ID to clear, or null to clear all.
	 */
	public static function clear_cache( $attachment_id = null ) {
		if ( null !== $attachment_id ) {
			unset( self::$cache[ $attachment_id ] );
		} else {
			self::$cache = array();
		}
	}
}
