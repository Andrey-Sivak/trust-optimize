<?php
/**
 * Migration step: find 1.x variants that collide with files of other attachments.
 *
 * @package TrustOptimize\Migration
 */

namespace TrustOptimize\Migration;

use TrustOptimize\Domain\VariantStatus;
use TrustOptimize\Service\LegacyPathGuard;
use TrustOptimize\Storage\AttachmentRepository;
use TrustOptimize\Storage\VariantRepository;

/**
 * Class DetectCollisions
 *
 * Schema 1.x named a variant after its source without the extension, so "photo.jpg" and
 * "photo.png" both produced "photo.webp", and the PNG variant of "logo.webp" was "logo.png".
 * Such a variant may have overwritten the original of another attachment (H-1). The rows
 * are marked failed / legacy_conflict: they are not served, not retried and never deleted,
 * and the case goes to the conflict report. The checks are those of LegacyPathGuard.
 */
class DetectCollisions implements MigrationStep {

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
	 * Guard.
	 *
	 * @var LegacyPathGuard
	 */
	private $guard;

	/**
	 * Conflict report.
	 *
	 * @var ConflictReport
	 */
	private $report;

	/**
	 * Constructor.
	 *
	 * @param VariantRepository    $variants    Variant repository.
	 * @param AttachmentRepository $attachments Attachment repository.
	 * @param LegacyPathGuard      $guard       Guard.
	 * @param ConflictReport       $report      Conflict report.
	 */
	public function __construct( VariantRepository $variants, AttachmentRepository $attachments, LegacyPathGuard $guard, ConflictReport $report ) {
		$this->variants    = $variants;
		$this->attachments = $attachments;
		$this->guard       = $guard;
		$this->report      = $report;
	}

	/**
	 * Step name.
	 *
	 * @return string
	 */
	public function name() {
		return 'detect_collisions';
	}

	/**
	 * Check the 1.x rows of the next attachments, ordered by attachment ID.
	 *
	 * @param int $cursor Last checked attachment ID.
	 * @param int $limit  Maximum number of attachments.
	 * @return BatchResult Counts: checked, conflicts.
	 */
	public function run_batch( $cursor, $limit ) {
		$ids    = $this->variants->get_legacy_attachment_ids_after( $cursor, $limit );
		$counts = array(
			'checked'   => 0,
			'conflicts' => 0,
		);

		$this->guard->begin_batch();

		try {
			foreach ( $ids as $attachment_id ) {
				$cursor = $attachment_id;
				$rows   = array_filter(
					$this->variants->get_for_attachment( $attachment_id ),
					static function ( $row ) {
						return 'legacy' === $row['naming'] && VariantStatus::DONE === $row['status'];
					}
				);

				$counts['checked'] += count( $rows );
				$conflicts          = $this->guard->find_conflicts( $attachment_id, array_column( $rows, 'relative_path' ) );

				foreach ( $rows as $row ) {
					if ( ! isset( $conflicts[ $row['relative_path'] ] ) ) {
						continue;
					}

					$conflict = $conflicts[ $row['relative_path'] ];

					$this->variants->transition( $row['id'], VariantStatus::DONE, VariantStatus::FAILED, array( 'reason' => 'legacy_conflict' ) );
					$this->report->add( $attachment_id, $row['relative_path'], $conflict['attachment_id'], $conflict['source'] );
					++$counts['conflicts'];
				}

				$this->attachments->recompute( $attachment_id );
			}
		} finally {
			$this->guard->end_batch();
		}

		return count( $ids ) < (int) $limit ? BatchResult::finished( $counts ) : BatchResult::more( $cursor, $counts );
	}
}
