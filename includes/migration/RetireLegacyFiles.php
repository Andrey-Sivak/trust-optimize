<?php
/**
 * Migration step: delete 1.x files that a 2.0 file has replaced.
 *
 * @package TrustOptimize\Migration
 */

namespace TrustOptimize\Migration;

use TrustOptimize\Domain\VariantStatus;
use TrustOptimize\Service\ImageCleanupService;
use TrustOptimize\Storage\VariantRepository;

/**
 * Class RetireLegacyFiles
 *
 * The conversion of a regenerated row retires its 1.x file itself. This step catches the rest:
 * rows that were already done with a 2.0 file when the 1.x file was imported, and files that
 * could not be deleted earlier. Rows that are still pending or failed keep their 1.x file,
 * which is still served. The deletion goes through ImageCleanupService, so every check of the
 * guard applies.
 */
class RetireLegacyFiles implements MigrationStep {

	/**
	 * Variant repository.
	 *
	 * @var VariantRepository
	 */
	private $variants;

	/**
	 * Cleanup service.
	 *
	 * @var ImageCleanupService
	 */
	private $cleanup;

	/**
	 * Constructor.
	 *
	 * @param VariantRepository   $variants Variant repository.
	 * @param ImageCleanupService $cleanup  Cleanup service.
	 */
	public function __construct( VariantRepository $variants, ImageCleanupService $cleanup ) {
		$this->variants = $variants;
		$this->cleanup  = $cleanup;
	}

	/**
	 * Step name.
	 *
	 * @return string
	 */
	public function name() {
		return 'retire_legacy_files';
	}

	/**
	 * Retire the 1.x files of the next attachments, ordered by attachment ID.
	 *
	 * @param int $cursor Last processed attachment ID.
	 * @param int $limit  Maximum number of attachments.
	 * @return BatchResult Counts: retired (files deleted).
	 */
	public function run_batch( $cursor, $limit ) {
		$ids     = $this->variants->get_attachment_ids_with_legacy_file_after( $cursor, $limit );
		$retired = 0;

		foreach ( $ids as $attachment_id ) {
			$cursor = $attachment_id;

			foreach ( $this->variants->get_for_attachment( $attachment_id ) as $row ) {
				if ( VariantStatus::DONE === $row['status'] && ! empty( $row['legacy_relative_path'] ) ) {
					$retired += count( $this->cleanup->retire_legacy_file( $attachment_id, $row )->get_data()['deleted'] ?? array() );
				}
			}
		}

		$counts = array( 'retired' => $retired );

		return count( $ids ) < (int) $limit ? BatchResult::finished( $counts ) : BatchResult::more( $cursor, $counts );
	}
}
