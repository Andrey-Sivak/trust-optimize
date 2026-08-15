<?php
/**
 * Safe cleanup service for plugin-generated image variants.
 *
 * @package TrustOptimize\Service
 */

namespace TrustOptimize\Service;

use TrustOptimize\Database\ImageModel;
use TrustOptimize\Queue\ConversionQueue;
use TrustOptimize\Value\DeleteResult;

/**
 * Class ImageCleanupService
 */
class ImageCleanupService {

	/**
	 * Image model instance.
	 *
	 * @var ImageModel
	 */
	private $image_model;

	/**
	 * Conversion queue instance.
	 *
	 * @var ConversionQueue|null
	 */
	private $conversion_queue;

	/**
	 * Constructor.
	 *
	 * @param ImageModel|null      $image_model      Image model instance.
	 * @param ConversionQueue|null $conversion_queue Conversion queue instance.
	 */
	public function __construct( ImageModel $image_model = null, ConversionQueue $conversion_queue = null ) {
		$this->image_model      = $image_model ? $image_model : new ImageModel();
		$this->conversion_queue = $conversion_queue;
	}

	/**
	 * Clean all TrustOptimize-generated files for a single attachment.
	 *
	 * @param int   $attachment_id Attachment ID.
	 * @param array $args          Optional cleanup args.
	 * @return DeleteResult
	 */
	public function cleanup_attachment( $attachment_id, array $args = array() ) {
		$this->cancel_pending_actions( $attachment_id );

		$variants = $this->filter_plugin_managed_variants(
			$attachment_id,
			$this->image_model->get_generated_variants( $attachment_id )
		);

		if ( empty( $variants ) ) {
			$this->remove_attachment_metadata( $attachment_id );

			if ( empty( $args['keep_record'] ) ) {
				$this->image_model->delete( $attachment_id );
			}

			$this->clear_caches( $attachment_id );
			return DeleteResult::skipped( 'no_generated_variants' );
		}

		$result = $this->delete_variant_files( $attachment_id, $variants );

		$this->remove_attachment_metadata( $attachment_id );

		if ( empty( $args['keep_record'] ) ) {
			$this->image_model->delete( $attachment_id );
		}

		$this->clear_caches( $attachment_id );

		return $result;
	}

	/**
	 * Delete selected TrustOptimize-generated variants for a single attachment.
	 *
	 * @param int   $attachment_id Attachment ID.
	 * @param array $variants      Generated variant manifest records.
	 * @return DeleteResult
	 */
	public function cleanup_variants( $attachment_id, array $variants ) {
		$variants = $this->filter_plugin_managed_variants( $attachment_id, $variants );

		if ( empty( $variants ) ) {
			return DeleteResult::skipped( 'no_generated_variants' );
		}

		$result = $this->delete_variant_files( $attachment_id, $variants );

		$this->remove_attachment_metadata_variants( $attachment_id, $variants );
		$this->clear_caches( $attachment_id );

		return $result;
	}

	/**
	 * Clean plugin-managed files for a bounded batch of image records.
	 *
	 * This is intended for uninstall/maintenance paths where loading every
	 * record into memory or running until timeout would be unsafe.
	 *
	 * @param int $cursor_id Last processed attachment ID.
	 * @param int $limit     Maximum records to process.
	 * @return array Batch summary.
	 */
	public function cleanup_managed_records_batch( $cursor_id = 0, $limit = 100 ) {
		$limit          = max( 1, (int) $limit );
		$attachment_ids = $this->image_model->get_attachment_ids_with_generated_variants( (int) $cursor_id, $limit );
		$summary        = array(
			'cursor_id' => (int) $cursor_id,
			'processed' => 0,
			'deleted'   => 0,
			'skipped'   => 0,
			'failed'    => 0,
			'errors'    => array(),
			'done'      => count( $attachment_ids ) < $limit,
		);

		foreach ( $attachment_ids as $attachment_id ) {
			$summary['cursor_id'] = (int) $attachment_id;
			++$summary['processed'];

			$result = $this->cleanup_attachment( (int) $attachment_id );

			if ( $result->is_success() ) {
				$data                = $result->get_data();
				$summary['deleted'] += isset( $data['deleted'] ) && is_array( $data['deleted'] ) ? count( $data['deleted'] ) : 0;
				continue;
			}

			if ( $result->is_skipped() ) {
				++$summary['skipped'];
				continue;
			}

			++$summary['failed'];
			$summary['errors'][] = array(
				'attachment_id' => (int) $attachment_id,
				'status'        => $result->get_status(),
				'message'       => $result->get_message(),
				'errors'        => $result->get_errors(),
			);
		}

		return $summary;
	}

	/**
	 * Cancel pending conversion actions for one attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	private function cancel_pending_actions( $attachment_id ) {
		if ( $this->conversion_queue ) {
			$this->conversion_queue->cancel_attachment_tasks( $attachment_id );
			return;
		}

		if ( method_exists( ConversionQueue::class, 'cancel_tasks_for_attachment' ) ) {
			ConversionQueue::cancel_tasks_for_attachment( $attachment_id );
		}
	}

	/**
	 * Keep only explicit TrustOptimize generated variant manifest records.
	 *
	 * @param int   $attachment_id Attachment ID.
	 * @param array $variants      Candidate variant manifest records.
	 * @return array Plugin-managed variant records.
	 */
	private function filter_plugin_managed_variants( $attachment_id, array $variants ) {
		$filtered = array();

		foreach ( $variants as $variant ) {
			if ( ! is_array( $variant ) ) {
				continue;
			}

			if ( isset( $variant['attachment_id'] ) && (int) $variant['attachment_id'] !== (int) $attachment_id ) {
				continue;
			}

			if ( empty( $variant['file'] ) || empty( $variant['size_name'] ) || empty( $variant['format'] ) ) {
				continue;
			}

			$filtered[] = $variant;
		}

		return $filtered;
	}

	/**
	 * Resolve a manifest variant to an absolute path.
	 *
	 * @param array $variant       Generated variant manifest record.
	 * @param int   $attachment_id Attachment ID.
	 * @return string Absolute target path or empty string.
	 */
	private function resolve_variant_path( array $variant, $attachment_id ) {
		if ( empty( $variant['file'] ) ) {
			return '';
		}

		$upload_dir = wp_upload_dir();
		if ( empty( $upload_dir['basedir'] ) ) {
			return '';
		}

		$file = basename( $variant['file'] );

		if ( isset( $variant['relative_dir'] ) && '' !== $variant['relative_dir'] ) {
			return trailingslashit( $upload_dir['basedir'] ) . trim( $variant['relative_dir'], '/' ) . '/' . $file;
		}

		$attached_file = get_attached_file( $attachment_id );
		if ( $attached_file ) {
			return trailingslashit( dirname( $attached_file ) ) . $file;
		}

		return '';
	}

	/**
	 * Delete generated variant files after safety validation.
	 *
	 * @param int   $attachment_id Attachment ID.
	 * @param array $variants      Generated variant manifest records.
	 * @return DeleteResult
	 */
	private function delete_variant_files( $attachment_id, array $variants ) {
		$protected_paths = $this->get_protected_paths( $attachment_id );
		$deleted         = array();
		$skipped         = array();
		$errors          = array();

		foreach ( $variants as $variant ) {
			$target_path = $this->resolve_variant_path( $variant, $attachment_id );

			if ( '' === $target_path || ! $this->is_inside_uploads( $target_path ) ) {
				$skipped[] = array(
					'variant' => $variant,
					'reason'  => 'outside_uploads',
				);
				continue;
			}

			$normalized_path = wp_normalize_path( $target_path );
			if ( isset( $protected_paths[ $normalized_path ] ) || $this->is_scaled_or_rotated_file( $normalized_path ) ) {
				$skipped[] = array(
					'variant' => $variant,
					'reason'  => 'protected_file',
				);
				continue;
			}

			if ( ! file_exists( $target_path ) ) {
				$skipped[] = array(
					'variant' => $variant,
					'reason'  => 'missing_file',
				);
				continue;
			}

			if ( wp_delete_file( $target_path ) ) {
				$deleted[] = $normalized_path;
				continue;
			}

			$errors[] = array(
				'variant' => $variant,
				'reason'  => 'delete_failed',
			);
		}

		$data = array(
			'deleted' => $deleted,
			'skipped' => $skipped,
		);

		if ( empty( $errors ) ) {
			return DeleteResult::success( 'deleted_generated_variants', $data );
		}

		if ( ! empty( $deleted ) || ! empty( $skipped ) ) {
			return DeleteResult::partial( 'deleted_with_errors', $errors, $data );
		}

		return DeleteResult::failed( 'delete_failed', $errors, $data );
	}

	/**
	 * Build protected path set for originals and WordPress-generated files.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array Protected normalized paths keyed by path.
	 */
	private function get_protected_paths( $attachment_id ) {
		$protected = array();
		$file_path = get_attached_file( $attachment_id );

		if ( $file_path ) {
			$protected[ wp_normalize_path( $file_path ) ] = true;
		}

		$metadata = wp_get_attachment_metadata( $attachment_id );
		if ( ! is_array( $metadata ) ) {
			return $protected;
		}

		$base_dir = $file_path ? dirname( $file_path ) : '';

		if ( ! empty( $metadata['original_image'] ) && '' !== $base_dir ) {
			$protected[ wp_normalize_path( trailingslashit( $base_dir ) . basename( $metadata['original_image'] ) ) ] = true;
		}

		if ( isset( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) && '' !== $base_dir ) {
			foreach ( $metadata['sizes'] as $size_data ) {
				if ( empty( $size_data['file'] ) ) {
					continue;
				}

				$protected[ wp_normalize_path( trailingslashit( $base_dir ) . basename( $size_data['file'] ) ) ] = true;
			}
		}

		return $protected;
	}

	/**
	 * Check whether a path is inside uploads basedir.
	 *
	 * @param string $path Absolute path.
	 * @return bool
	 */
	private function is_inside_uploads( $path ) {
		$upload_dir = wp_upload_dir();
		if ( empty( $upload_dir['basedir'] ) ) {
			return false;
		}

		$base = untrailingslashit( wp_normalize_path( $upload_dir['basedir'] ) );
		$path = wp_normalize_path( $path );

		return $path === $base || 0 === strpos( $path, trailingslashit( $base ) );
	}

	/**
	 * Check whether a filename is a WordPress scaled/rotated original.
	 *
	 * @param string $path Absolute path.
	 * @return bool
	 */
	private function is_scaled_or_rotated_file( $path ) {
		return (bool) preg_match( '/-(scaled|rotated)\.[^.]+$/i', basename( $path ) );
	}

	/**
	 * Remove TrustOptimize conversion data from WordPress attachment metadata.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	private function remove_attachment_metadata( $attachment_id ) {
		$metadata = wp_get_attachment_metadata( $attachment_id );

		if ( ! is_array( $metadata ) ) {
			return;
		}

		unset( $metadata['trust_optimize_converted'] );

		if ( isset( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
			foreach ( $metadata['sizes'] as $size_name => $size_data ) {
				unset( $metadata['sizes'][ $size_name ]['trust_optimize_converted'] );
			}
		}

		wp_update_attachment_metadata( $attachment_id, $metadata );
	}

	/**
	 * Remove selected TrustOptimize conversion data from attachment metadata.
	 *
	 * @param int   $attachment_id Attachment ID.
	 * @param array $variants      Generated variant manifest records.
	 */
	private function remove_attachment_metadata_variants( $attachment_id, array $variants ) {
		$metadata = wp_get_attachment_metadata( $attachment_id );

		if ( ! is_array( $metadata ) ) {
			return;
		}

		foreach ( $variants as $variant ) {
			if ( empty( $variant['size_name'] ) || empty( $variant['format'] ) ) {
				continue;
			}

			if ( 'original' === $variant['size_name'] ) {
				unset( $metadata['trust_optimize_converted'][ 'original_' . $variant['format'] ] );
			} elseif ( isset( $metadata['sizes'][ $variant['size_name'] ]['trust_optimize_converted'] ) ) {
				unset( $metadata['sizes'][ $variant['size_name'] ]['trust_optimize_converted'][ $variant['format'] ] );
			}
		}

		wp_update_attachment_metadata( $attachment_id, $metadata );
	}

	/**
	 * Clear attachment-specific caches.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	private function clear_caches( $attachment_id ) {
		ImageModel::clear_cache( $attachment_id );
		delete_transient( 'trust_optimize_formats_' . $attachment_id );
		clean_attachment_cache( $attachment_id );
	}
}
