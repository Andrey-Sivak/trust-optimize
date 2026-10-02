<?php
/**
 * Migration step: remove the 1.x keys from the WordPress attachment metadata.
 *
 * @package TrustOptimize\Migration
 */

namespace TrustOptimize\Migration;

use TrustOptimize\Database\DatabaseManager;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Reads the legacy table; its name comes from DatabaseManager and values are prepared.

/**
 * Class StripAttachmentMetadata
 *
 * Schema 1.x wrote "trust_optimize_converted" into _wp_attachment_metadata, on the top level
 * and in every size. 2.0 keeps that state in its own tables (D-2).
 */
class StripAttachmentMetadata implements MigrationStep {

	/**
	 * Key written by 1.x.
	 */
	const KEY = 'trust_optimize_converted';

	/**
	 * Database manager.
	 *
	 * @var DatabaseManager
	 */
	private $database;

	/**
	 * Constructor.
	 *
	 * @param DatabaseManager $database Database manager.
	 */
	public function __construct( DatabaseManager $database ) {
		$this->database = $database;
	}

	/**
	 * Step name.
	 *
	 * @return string
	 */
	public function name() {
		return 'strip_attachment_metadata';
	}

	/**
	 * Clean the metadata of the next attachments of the 1.x registry, ordered by attachment ID.
	 *
	 * @param int $cursor Last processed attachment ID.
	 * @param int $limit  Maximum number of attachments.
	 * @return BatchResult Counts: stripped (attachments whose metadata changed).
	 */
	public function run_batch( $cursor, $limit ) {
		global $wpdb;

		$table = $this->database->get_plugin_table_names()['images'];

		if ( ! $this->database->table_exists( $table ) ) {
			return BatchResult::finished();
		}

		$stripped = 0;
		$ids      = array_map(
			'intval',
			$wpdb->get_col( $wpdb->prepare( "SELECT attachment_id FROM {$table} WHERE attachment_id > %d ORDER BY attachment_id LIMIT %d", (int) $cursor, (int) $limit ) )
		);

		foreach ( $ids as $attachment_id ) {
			$cursor   = $attachment_id;
			$metadata = get_post_meta( $attachment_id, '_wp_attachment_metadata', true );

			if ( ! is_array( $metadata ) ) {
				continue;
			}

			$clean = $metadata;
			unset( $clean[ self::KEY ] );

			foreach ( (array) ( $clean['sizes'] ?? array() ) as $size_name => $size ) {
				unset( $clean['sizes'][ $size_name ][ self::KEY ] );
			}

			if ( $clean === $metadata ) {
				continue;
			}

			// Straight to the meta table: wp_update_attachment_metadata() would wake offload and CDN plugins for every attachment.
			update_post_meta( $attachment_id, '_wp_attachment_metadata', $clean );
			clean_attachment_cache( $attachment_id );
			++$stripped;
		}

		return count( $ids ) < (int) $limit ? BatchResult::finished( array( 'stripped' => $stripped ) ) : BatchResult::more( $cursor, array( 'stripped' => $stripped ) );
	}
}
