<?php
/**
 * Bulk attachment eligibility query.
 *
 * @package TrustOptimize\Bulk
 */

namespace TrustOptimize\Bulk;

use TrustOptimize\Planning\VariantPlanner;
use TrustOptimize\Storage\VariantRepository;

/**
 * Class EligibilityQuery
 */
class EligibilityQuery {

	/**
	 * Image model instance.
	 *
	 * @var VariantRepository
	 */
	private $variants;

	/**
	 * Constructor.
	 *
	 * @param VariantRepository $variants Variant repository.
	 */
	public function __construct( VariantRepository $variants ) {
		$this->variants = $variants;
	}

	/**
	 * Get next attachment IDs of convertible images (JPEG and PNG) after the cursor.
	 *
	 * @param int $cursor_id Last processed attachment ID.
	 * @param int $limit     Maximum IDs to return.
	 * @return array
	 */
	public function get_next_attachment_ids( $cursor_id, $limit ) {
		global $wpdb;

		$mimes = VariantPlanner::SOURCE_MIMES;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts}
				WHERE post_type = %s
				AND post_mime_type IN (" . implode( ', ', array_fill( 0, count( $mimes ), '%s' ) ) . ')
				AND ID > %d
				ORDER BY ID ASC
				LIMIT %d',
				array_merge( array( 'attachment' ), $mimes, array( (int) $cursor_id, (int) $limit ) )
			)
		);
		// phpcs:enable

		return array_map( 'intval', $ids );
	}

	/**
	 * Count convertible image attachments (JPEG and PNG) after the cursor.
	 *
	 * @param int $after_id Count only attachments with a greater ID.
	 * @return int
	 */
	public function count_eligible_attachments( $after_id = 0 ) {
		global $wpdb;

		$mimes = VariantPlanner::SOURCE_MIMES;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(1) FROM {$wpdb->posts}
				WHERE post_type = %s
				AND post_mime_type IN (" . implode( ', ', array_fill( 0, count( $mimes ), '%s' ) ) . ')
				AND ID > %d',
				array_merge( array( 'attachment' ), $mimes, array( (int) $after_id ) )
			)
		);
		// phpcs:enable
	}

	/**
	 * Get next attachment IDs that have plugin-managed generated variants.
	 *
	 * @param int $cursor_id Last processed attachment ID.
	 * @param int $limit     Maximum IDs to return.
	 * @return array
	 */
	public function get_next_plugin_managed_attachment_ids( $cursor_id, $limit ) {
		return $this->variants->get_attachment_ids_after( (int) $cursor_id, (int) $limit );
	}

	/**
	 * Count attachments that have plugin-managed generated variants.
	 *
	 * @param int $after_id Count only attachments with a greater ID.
	 * @return int
	 */
	public function count_plugin_managed_attachments( $after_id = 0 ) {
		return $this->variants->count_attachments( $after_id );
	}
}
