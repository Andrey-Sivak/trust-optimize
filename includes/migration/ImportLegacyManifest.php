<?php
/**
 * Migration step: move the 1.x manifest into the variants table.
 *
 * @package TrustOptimize\Migration
 */

namespace TrustOptimize\Migration;

use TrustOptimize\Database\DatabaseManager;
use TrustOptimize\Domain\VariantStatus;
use TrustOptimize\Planning\VariantPlanner;
use TrustOptimize\Storage\AttachmentRepository;
use TrustOptimize\Storage\VariantRepository;
use TrustOptimize\Utils\UploadsPath;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Reads the legacy table; its name comes from DatabaseManager and values are prepared.

/**
 * Class ImportLegacyManifest
 *
 * Every variant of a 1.x manifest whose file still exists becomes a row with naming "legacy"
 * and status "done", so it keeps being served. A 2.0 row that already exists for the same
 * variant (queued from an old task) only learns about the 1.x file.
 */
class ImportLegacyManifest implements MigrationStep {

	/**
	 * Database manager.
	 *
	 * @var DatabaseManager
	 */
	private $database;

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
	 * @param DatabaseManager      $database    Database manager.
	 * @param VariantRepository    $variants    Variant repository.
	 * @param AttachmentRepository $attachments Attachment repository.
	 */
	public function __construct( DatabaseManager $database, VariantRepository $variants, AttachmentRepository $attachments ) {
		$this->database    = $database;
		$this->variants    = $variants;
		$this->attachments = $attachments;
	}

	/**
	 * Step name.
	 *
	 * @return string
	 */
	public function name() {
		return 'import_legacy_manifest';
	}

	/**
	 * Import the manifests of the next attachments, ordered by attachment ID.
	 *
	 * @param int $cursor Last imported attachment ID.
	 * @param int $limit  Maximum number of attachments.
	 * @return BatchResult Counts: attachments, imported, missing (file gone), orphaned (attachment gone).
	 */
	public function run_batch( $cursor, $limit ) {
		global $wpdb;

		$table = $this->database->get_plugin_table_names()['images'];

		if ( ! $this->database->table_exists( $table ) ) {
			return BatchResult::finished();
		}

		$rows   = $wpdb->get_results(
			$wpdb->prepare( "SELECT attachment_id, metadata FROM {$table} WHERE attachment_id > %d ORDER BY attachment_id LIMIT %d", (int) $cursor, (int) $limit ),
			ARRAY_A
		);
		$counts = array(
			'attachments' => 0,
			'imported'    => 0,
			'missing'     => 0,
			'orphaned'    => 0,
		);

		foreach ( (array) $rows as $row ) {
			$attachment_id = (int) $row['attachment_id'];
			$cursor        = $attachment_id;
			++$counts['attachments'];

			if ( 'attachment' !== get_post_type( $attachment_id ) ) {
				++$counts['orphaned'];
				continue;
			}

			$sources  = $this->sources( $attachment_id );
			$existing = array();
			foreach ( $this->variants->get_for_attachment( $attachment_id ) as $variant ) {
				$existing[ $variant['size_name'] . '|' . $variant['format'] ] = $variant;
			}

			foreach ( LegacyManifest::variants( $attachment_id, $row['metadata'] ) as $entry ) {
				$path = UploadsPath::absolute( $entry['relative_path'] );

				if ( null === $path || ! UploadsPath::is_inside( $path ) || ! is_file( $path ) ) {
					++$counts['missing'];
					continue;
				}

				$this->import_variant( $attachment_id, $entry, $sources[ $entry['size_name'] ] ?? array(), $existing[ $entry['size_name'] . '|' . $entry['format'] ] ?? null, (int) wp_filesize( $path ) );
				++$counts['imported'];
			}

			$this->attachments->recompute( $attachment_id );
		}

		return count( (array) $rows ) < (int) $limit ? BatchResult::finished( $counts ) : BatchResult::more( $cursor, $counts );
	}

	/**
	 * Store one variant.
	 *
	 * @param int        $attachment_id Attachment ID.
	 * @param array      $entry         Manifest entry (see LegacyManifest).
	 * @param array      $source        Source file of the size: source_relative_path, width, height.
	 * @param array|null $existing      Existing row of the variant.
	 * @param int        $file_size     Size of the 1.x file.
	 */
	private function import_variant( $attachment_id, array $entry, array $source, $existing, $file_size ) {
		$key = array(
			'attachment_id' => $attachment_id,
			'size_name'     => $entry['size_name'],
			'format'        => $entry['format'],
		);

		if ( $existing && 'legacy' !== $existing['naming'] ) {
			$this->variants->upsert( $key + array( 'legacy_relative_path' => $entry['relative_path'] ) );

			return;
		}

		// Quality 0 is never a real setting, so the row counts as outdated.
		$this->variants->upsert(
			$key + array(
				'status'               => VariantStatus::DONE,
				'naming'               => 'legacy',
				'source_relative_path' => $source['source_relative_path'] ?? '',
				'relative_path'        => $entry['relative_path'],
				'width'                => $source['width'] ?? 0,
				'height'               => $source['height'] ?? 0,
				'quality'              => 0,
				'file_size'            => $file_size,
				'file_hash'            => $entry['file_hash'],
				'reason'               => null,
			)
		);
	}

	/**
	 * Source file, width and height of every size of an attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array[] Keyed by size name.
	 */
	private function sources( $attachment_id ) {
		$file     = get_attached_file( $attachment_id );
		$relative = $file ? UploadsPath::relative( $file ) : null;
		$metadata = wp_get_attachment_metadata( $attachment_id );
		$sources  = array();

		if ( null === $relative ) {
			return $sources;
		}

		// The planner decides which file every size is generated from; only the formats differ.
		foreach ( VariantPlanner::desired_variants( $relative, is_array( $metadata ) ? $metadata : array(), array( 'webp' ), (string) get_post_mime_type( $attachment_id ) ) as $desired ) {
			$sources[ $desired['size_name'] ] = array(
				'source_relative_path' => $desired['source_relative_path'],
				'width'                => $desired['width'],
				'height'               => $desired['height'],
			);
		}

		return $sources;
	}
}
