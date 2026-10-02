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

/**
 * Class ScheduleRegeneration
 *
 * A 1.x row (naming "legacy", status "done", checked by DetectCollisions) becomes the pending
 * 2.0 row of the same variant: the 1.x path moves to legacy_relative_path, where it keeps being
 * served until the 2.0 file exists. The planner then deals with the attachment as with any other:
 * rows of variants that are no longer wanted (PNG variants of WebP/AVIF sources, disabled
 * formats, vanished sizes) are removed together with their 1.x file, the rest is queued.
 * Rows marked legacy_conflict are not touched.
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
	 * Constructor.
	 *
	 * @param VariantRepository $variants Variant repository.
	 * @param ConversionQueue   $queue    Conversion queue.
	 */
	public function __construct( VariantRepository $variants, ConversionQueue $queue ) {
		$this->variants = $variants;
		$this->queue    = $queue;
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

				$moved = $this->variants->transition(
					$row['id'],
					VariantStatus::DONE,
					VariantStatus::PENDING,
					array(
						'naming'               => 'v2',
						'legacy_relative_path' => $row['relative_path'],
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
}
