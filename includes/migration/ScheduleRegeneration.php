<?php
/**
 * Migration step: regenerate 1.x variants under 2.0 names.
 *
 * @package TrustOptimize\Migration
 */

namespace TrustOptimize\Migration;

use TrustOptimize\Domain\VariantStatus;
use TrustOptimize\Queue\ConversionQueue;
use TrustOptimize\Storage\VariantRepository;
use TrustOptimize\Utils\UploadsPath;

/**
 * Class ScheduleRegeneration
 *
 * A 1.x row (naming "legacy", status "done", checked by DetectCollisions) becomes the pending
 * 2.0 row of the same variant: the 1.x path moves to legacy_relative_path, where it keeps being
 * served until the 2.0 file exists. The planner then deals with the attachment as with any other:
 * rows of variants that are no longer wanted (PNG variants of WebP/AVIF sources, disabled
 * formats, vanished sizes) are removed together with their 1.x file, the rest is queued.
 * Rows marked legacy_conflict are not touched. A file whose hash differs from the one 1.x
 * recorded was changed by someone else: it is not handed over (not served, never deleted)
 * and goes to the conflict report with the source "hash_mismatch". A file that is gone is not
 * handed over either (the browser would get a 404 and not try another source), but it is no conflict.
 */
class ScheduleRegeneration implements MigrationStep {

	/**
	 * Variant repository.
	 *
	 * @var VariantRepository
	 */
	private $variants;

	/**
	 * Conversion queue.
	 *
	 * @var ConversionQueue
	 */
	private $queue;

	/**
	 * Conflict report.
	 *
	 * @var ConflictReport
	 */
	private $report;

	/**
	 * Constructor.
	 *
	 * @param VariantRepository $variants Variant repository.
	 * @param ConversionQueue   $queue    Conversion queue.
	 * @param ConflictReport    $report   Conflict report.
	 */
	public function __construct( VariantRepository $variants, ConversionQueue $queue, ConflictReport $report ) {
		$this->variants = $variants;
		$this->queue    = $queue;
		$this->report   = $report;
	}

	/**
	 * Step name.
	 *
	 * @return string
	 */
	public function name() {
		return 'schedule_regeneration';
	}

	/**
	 * Convert the 1.x rows of the next attachments and queue them, ordered by attachment ID.
	 *
	 * @param int $cursor Last processed attachment ID.
	 * @param int $limit  Maximum number of attachments.
	 * @return BatchResult Counts: attachments, regenerated (rows), queued.
	 */
	public function run_batch( $cursor, $limit ) {
		$ids    = $this->variants->get_legacy_attachment_ids_after( $cursor, $limit );
		$counts = array(
			'attachments' => 0,
			'regenerated' => 0,
			'queued'      => 0,
		);

		foreach ( $ids as $attachment_id ) {
			$cursor = $attachment_id;
			++$counts['attachments'];

			foreach ( $this->variants->get_for_attachment( $attachment_id ) as $row ) {
				if ( 'legacy' !== $row['naming'] || VariantStatus::DONE !== $row['status'] ) {
					continue;
				}

				$handover = $this->can_hand_over( $row, $exists );

				if ( $exists && ! $handover ) {
					$this->report->add( $attachment_id, $row['relative_path'], 0, 'hash_mismatch' );
				}

				$moved = $this->variants->transition(
					$row['id'],
					VariantStatus::DONE,
					VariantStatus::PENDING,
					array(
						'naming'               => 'v2',
						'legacy_relative_path' => $handover ? $row['relative_path'] : null,
						'relative_path'        => null,
						'file_hash'            => null,
						'reason'               => null,
					)
				);

				if ( $moved ) {
					++$counts['regenerated'];
				}
			}

			$counts['queued'] += (int) $this->queue->plan_and_enqueue( $attachment_id );
		}

		return count( $ids ) < (int) $limit ? BatchResult::finished( $counts ) : BatchResult::more( $cursor, $counts );
	}

	/**
	 * Whether the 1.x file can be handed over for serving: it exists and, if 1.x recorded a hash, still has it.
	 *
	 * @param array $row    Variant row.
	 * @param bool  $exists Set to whether the file exists (a missing file is not a conflict).
	 * @return bool
	 */
	private function can_hand_over( array $row, &$exists ) {
		$path   = UploadsPath::absolute( $row['relative_path'] );
		$exists = null !== $path && is_file( $path );

		return $exists && ( empty( $row['file_hash'] ) || hash_file( 'sha256', $path ) === $row['file_hash'] );
	}
}
