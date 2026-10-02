<?php
/**
 * Safe cleanup service for plugin-generated image variants.
 *
 * @package TrustOptimize\Service
 */

namespace TrustOptimize\Service;

use TrustOptimize\Domain\VariantStatus;
use TrustOptimize\Queue\ConversionQueue;
use TrustOptimize\Storage\AttachmentRepository;
use TrustOptimize\Storage\VariantRepository;
use TrustOptimize\Utils\UploadsPath;
use TrustOptimize\Value\DeleteResult;

/**
 * Class ImageCleanupService
 *
 * Deletes only files that a variant row of the plugin points at. A row is removed
 * once its file is confirmed gone (or turned out not to be ours); a file that cannot
 * be deleted keeps its row, marked failed.
 */
class ImageCleanupService {

	/**
	 * Variant repository.
	 *
	 * @var VariantRepository
	 */
	private $variants;

	/**
	 * Attachment repository.
	 *
	 * @var AttachmentRepository
	 */
	private $attachments;

	/**
	 * Constructor.
	 *
	 * @param VariantRepository    $variants    Variant repository.
	 * @param AttachmentRepository $attachments Attachment repository.
	 */
	public function __construct( VariantRepository $variants, AttachmentRepository $attachments ) {
		$this->variants    = $variants;
		$this->attachments = $attachments;
	}

	/**
	 * Clean up when an attachment is deleted.
	 */
	public function register() {
		add_action( 'delete_attachment', array( $this, 'cleanup_attachment' ) );
	}

	/**
	 * Remove every generated file and row of an attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return DeleteResult
	 */
	public function cleanup_attachment( $attachment_id ) {
		ConversionQueue::cancel_tasks_for_attachment( $attachment_id );

		$rows = $this->variants->get_for_attachment( $attachment_id );

		if ( empty( $rows ) ) {
			$this->attachments->delete( $attachment_id );
			$this->clear_caches( $attachment_id );

			return DeleteResult::skipped( 'no_generated_variants' );
		}

		$result = $this->remove( $attachment_id, $rows, true );

		if ( empty( $this->variants->get_for_attachment( $attachment_id ) ) ) {
			$this->attachments->delete( $attachment_id );
		} else {
			$this->attachments->recompute( $attachment_id );
		}

		$this->clear_caches( $attachment_id );

		return $result;
	}

	/**
	 * Remove selected variants (files and rows) of an attachment.
	 *
	 * @param int     $attachment_id Attachment ID.
	 * @param array[] $rows          Variant rows of that attachment.
	 * @return DeleteResult
	 */
	public function cleanup_variants( $attachment_id, array $rows ) {
		if ( empty( $rows ) ) {
			return DeleteResult::skipped( 'no_generated_variants' );
		}

		$result = $this->remove( $attachment_id, $rows, true );
		$this->attachments->recompute( $attachment_id );
		$this->clear_caches( $attachment_id );

		return $result;
	}

	/**
	 * Remove only the files of superseded rows (their rows were re-pointed at another source).
	 *
	 * @param int     $attachment_id Attachment ID.
	 * @param array[] $rows          Earlier snapshots of variant rows.
	 * @return DeleteResult
	 */
	public function cleanup_replaced_files( $attachment_id, array $rows ) {
		return empty( $rows ) ? DeleteResult::skipped( 'no_generated_variants' ) : $this->remove( $attachment_id, $rows, false );
	}

	/**
	 * Clean plugin-managed files for a bounded batch of attachments.
	 *
	 * Intended for uninstall/maintenance paths where running until timeout would be unsafe.
	 *
	 * @param int $cursor_id Last processed attachment ID.
	 * @param int $limit     Maximum attachments to process.
	 * @return array Batch summary.
	 */
	public function cleanup_managed_records_batch( $cursor_id = 0, $limit = 100 ) {
		$limit          = max( 1, (int) $limit );
		$attachment_ids = $this->variants->get_attachment_ids_after( (int) $cursor_id, $limit );
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
				$summary['deleted'] += count( $data['deleted'] ?? array() );
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
	 * Delete the files of rows after safety checks, and the rows themselves when asked to.
	 *
	 * @param int     $attachment_id Attachment ID.
	 * @param array[] $rows          Variant rows.
	 * @param bool    $delete_rows   Whether rows are removed once their file is gone.
	 * @return DeleteResult
	 */
	private function remove( $attachment_id, array $rows, $delete_rows ) {
		$protected = $this->get_protected_paths( $attachment_id );
		$deleted   = array();
		$skipped   = array();
		$errors    = array();

		foreach ( $rows as $row ) {
			$outcome = $this->remove_file( $row, $protected );

			if ( 'deleted' === $outcome['status'] ) {
				$deleted[] = $outcome['path'];
			} elseif ( 'failed' === $outcome['status'] ) {
				$errors[] = array(
					'variant' => $row,
					'reason'  => $outcome['reason'],
				);
				if ( $delete_rows ) {
					$this->variants->transition( $row['id'], $row['status'], VariantStatus::FAILED, array( 'reason' => $outcome['reason'] ) );
				}
				continue;
			} else {
				$skipped[] = array(
					'variant' => $row,
					'reason'  => $outcome['reason'],
				);
			}

			// "outside_uploads" is refused outright and keeps its row for inspection.
			if ( $delete_rows && 'outside_uploads' !== ( $outcome['reason'] ?? '' ) ) {
				$this->variants->delete( $row['id'] );
			}
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
	 * Delete the file of one row when it is safe to.
	 *
	 * @param array $row       Variant row.
	 * @param array $protected Protected paths keyed by normalized path.
	 * @return array{status:string,reason?:string,path?:string} Status: deleted, skipped or failed.
	 */
	private function remove_file( array $row, array $protected ) {
		if ( empty( $row['relative_path'] ) ) {
			return array(
				'status' => 'skipped',
				'reason' => 'no_file',
			);
		}

		$path = UploadsPath::absolute( $row['relative_path'] );

		if ( null === $path || ! UploadsPath::is_inside( $path ) ) {
			return array(
				'status' => 'skipped',
				'reason' => 'outside_uploads',
			);
		}

		if ( isset( $protected[ $path ] ) ) {
			return array(
				'status' => 'skipped',
				'reason' => 'protected_file',
			);
		}

		if ( ! file_exists( $path ) ) {
			return array(
				'status' => 'skipped',
				'reason' => 'missing_file',
			);
		}

		if ( ! empty( $row['file_hash'] ) && hash_file( 'sha256', $path ) !== $row['file_hash'] ) {
			return array(
				'status' => 'skipped',
				'reason' => 'hash_mismatch',
			);
		}

		wp_delete_file( $path );

		// wp_delete_file() returns nothing before WordPress 6.7: judge by the file system.
		if ( file_exists( $path ) ) {
			return array(
				'status' => 'failed',
				'reason' => 'delete_failed',
			);
		}

		return array(
			'status' => 'deleted',
			'path'   => $path,
		);
	}

	/**
	 * Originals and WordPress-generated files of an attachment, which are never deleted.
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
		if ( ! is_array( $metadata ) || ! $file_path ) {
			return $protected;
		}

		$base_dir = dirname( $file_path );

		if ( ! empty( $metadata['original_image'] ) ) {
			$protected[ wp_normalize_path( trailingslashit( $base_dir ) . basename( $metadata['original_image'] ) ) ] = true;
		}

		foreach ( (array) ( $metadata['sizes'] ?? array() ) as $size_data ) {
			if ( ! empty( $size_data['file'] ) ) {
				$protected[ wp_normalize_path( trailingslashit( $base_dir ) . basename( $size_data['file'] ) ) ] = true;
			}
		}

		return $protected;
	}

	/**
	 * Clear attachment-specific caches.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	private function clear_caches( $attachment_id ) {
		clean_attachment_cache( $attachment_id );
	}
}
