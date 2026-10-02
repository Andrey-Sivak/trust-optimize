<?php
/**
 * Safe cleanup service for plugin-generated image variants.
 *
 * @package TrustOptimize\Service
 */

namespace TrustOptimize\Service;

use TrustOptimize\Domain\VariantStatus;
use TrustOptimize\Migration\ConflictReport;
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
 * be deleted keeps its row, marked failed. A file of schema 1.x that is also a file of
 * another attachment is never deleted (D-15): the case is reported, and the row is
 * marked failed unless the attachment itself is being deleted.
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
	 * Guard for files of schema 1.x.
	 *
	 * @var LegacyPathGuard
	 */
	private $guard;

	/**
	 * Report of 1.x files that belong to other attachments.
	 *
	 * @var ConflictReport
	 */
	private $conflicts;

	/**
	 * Constructor.
	 *
	 * @param VariantRepository    $variants    Variant repository.
	 * @param AttachmentRepository $attachments Attachment repository.
	 * @param LegacyPathGuard      $guard       Guard for files of schema 1.x.
	 * @param ConflictReport       $conflicts   Conflict report.
	 */
	public function __construct( VariantRepository $variants, AttachmentRepository $attachments, LegacyPathGuard $guard, ConflictReport $conflicts ) {
		$this->variants    = $variants;
		$this->attachments = $attachments;
		$this->guard       = $guard;
		$this->conflicts   = $conflicts;
	}

	/**
	 * Clean up when an attachment is deleted.
	 */
	public function register() {
		add_action( 'delete_attachment', array( $this, 'on_attachment_deleted' ) );
	}

	/**
	 * The attachment is being deleted: remove its generated files and rows.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	public function on_attachment_deleted( $attachment_id ) {
		$this->cleanup_attachment( $attachment_id, true );
	}

	/**
	 * Remove every generated file and row of an attachment.
	 *
	 * @param int  $attachment_id  Attachment ID.
	 * @param bool $attachment_gone Whether the attachment itself is being deleted.
	 * @return DeleteResult
	 */
	public function cleanup_attachment( $attachment_id, $attachment_gone = false ) {
		ConversionQueue::cancel_tasks_for_attachment( $attachment_id );

		$rows = $this->variants->get_for_attachment( $attachment_id );

		if ( empty( $rows ) ) {
			$this->attachments->delete( $attachment_id );
			$this->clear_caches( $attachment_id );

			return DeleteResult::skipped( 'no_generated_variants' );
		}

		$result = $this->remove( $attachment_id, $rows, true, $attachment_gone );

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
	 * The aggregate state is left to the caller: it depends on what is queued next.
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
	 * Delete the 1.x file that a regenerated row still points at, after the checks of remove().
	 *
	 * The row keeps its 2.0 file; it forgets the 1.x path unless the file could not be deleted.
	 *
	 * @param int   $attachment_id Attachment ID.
	 * @param array $row           Variant row with a legacy_relative_path.
	 * @return DeleteResult
	 */
	public function retire_legacy_file( $attachment_id, array $row ) {
		if ( empty( $row['legacy_relative_path'] ) ) {
			return DeleteResult::skipped( 'no_legacy_file' );
		}

		$result = $this->remove(
			$attachment_id,
			array(
				array_merge(
					$row,
					array(
						'naming'        => 'v2',
						'relative_path' => null,
						'file_hash'     => null,
					)
				),
			),
			false
		);
		$this->clear_caches( $attachment_id );

		return $result;
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
	 * @param bool    $attachment_gone Whether the attachment itself is being deleted.
	 * @return DeleteResult
	 */
	private function remove( $attachment_id, array $rows, $delete_rows, $attachment_gone = false ) {
		$protected = $this->get_protected_paths( $attachment_id );
		$conflicts = $this->guard->find_conflicts( $attachment_id, $this->legacy_paths( $rows ) );
		$deleted   = array();
		$skipped   = array();
		$errors    = array();

		foreach ( $rows as $row ) {
			$keep    = false;
			$failed  = false;
			$legacy  = 'legacy' === ( $row['naming'] ?? '' );
			$targets = array( array( $row['relative_path'] ?? '', $row['file_hash'] ?? null, $legacy, false ) );

			if ( ! empty( $row['legacy_relative_path'] ) ) {
				$targets[] = array( $row['legacy_relative_path'], null, true, true );
			}

			foreach ( $targets as list( $relative_path, $hash, $guarded, $is_old_file ) ) {
				$conflict = $guarded ? ( $conflicts[ $relative_path ] ?? null ) : null;
				$outcome  = $this->remove_file( $relative_path, $hash, $protected, null !== $conflict );

				// A row that stays must not keep pointing at a 1.x file that is gone or must not be served.
				if ( $is_old_file && ! $delete_rows && 'failed' !== $outcome['status'] ) {
					$this->variants->clear_legacy_path( $row['id'] );
				}

				if ( 'deleted' === $outcome['status'] ) {
					$deleted[] = $outcome['path'];
					continue;
				}

				if ( 'failed' === $outcome['status'] ) {
					$failed   = true;
					$errors[] = array(
						'variant' => $row,
						'reason'  => $outcome['reason'],
					);
					if ( $delete_rows ) {
						$this->variants->transition( $row['id'], $row['status'], VariantStatus::FAILED, array( 'reason' => $outcome['reason'] ) );
					}
					continue;
				}

				$skipped[] = array(
					'variant' => $row,
					'reason'  => $outcome['reason'],
				);

				// "outside_uploads" is refused outright and keeps its row for inspection.
				$keep = $keep || 'outside_uploads' === $outcome['reason'];

				if ( 'legacy_conflict' === $outcome['reason'] ) {
					$this->conflicts->add( $attachment_id, $relative_path, $conflict['attachment_id'], $conflict['source'] );

					// The file is never deleted. A 1.x row that points at it is parked for the report, unless it goes away with its attachment.
					if ( $delete_rows && ! $attachment_gone && $legacy && $relative_path === $row['relative_path'] ) {
						$this->variants->transition( $row['id'], $row['status'], VariantStatus::FAILED, array( 'reason' => 'legacy_conflict' ) );
						$keep = true;
					}
				}
			}

			if ( $delete_rows && ! $failed && ! $keep ) {
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
	 * Paths of schema 1.x files in rows: the file of a 1.x row and the old file of a regenerated row.
	 *
	 * @param array[] $rows Variant rows.
	 * @return string[]
	 */
	private function legacy_paths( array $rows ) {
		$paths = array();

		foreach ( $rows as $row ) {
			if ( 'legacy' === ( $row['naming'] ?? '' ) ) {
				$paths[] = $row['relative_path'] ?? '';
			}
			$paths[] = $row['legacy_relative_path'] ?? '';
		}

		return array_filter( $paths );
	}

	/**
	 * Delete one file of a row when it is safe to.
	 *
	 * @param string      $relative_path Path relative to uploads, empty when the row has no file.
	 * @param string|null $hash          Expected SHA-256 of the file, if known.
	 * @param array       $protected     Protected paths keyed by normalized path.
	 * @param bool        $conflict      Whether the file belongs to another attachment (schema 1.x only).
	 * @return array{status:string,reason?:string,path?:string} Status: deleted, skipped or failed.
	 */
	private function remove_file( $relative_path, $hash, array $protected, $conflict ) {
		if ( empty( $relative_path ) ) {
			return array(
				'status' => 'skipped',
				'reason' => 'no_file',
			);
		}

		$path = UploadsPath::absolute( $relative_path );

		if ( null === $path || ! UploadsPath::is_inside( $path ) ) {
			return array(
				'status' => 'skipped',
				'reason' => 'outside_uploads',
			);
		}

		if ( $conflict ) {
			return array(
				'status' => 'skipped',
				'reason' => 'legacy_conflict',
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

		if ( ! empty( $hash ) && hash_file( 'sha256', $path ) !== $hash ) {
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
