<?php
/**
 * Bulk attachment eligibility query.
 *
 * @package TrustOptimize\Bulk
 */

namespace TrustOptimize\Bulk;

use TrustOptimize\Database\ImageModel;

/**
 * Class EligibilityQuery
 */
class EligibilityQuery {

	/**
	 * Image model instance.
	 *
	 * @var ImageModel
	 */
	private $image_model;

	/**
	 * Constructor.
	 *
	 * @param ImageModel|null $image_model Image model instance.
	 */
	public function __construct( ImageModel $image_model = null ) {
		$this->image_model = $image_model ? $image_model : new ImageModel();
	}

	/**
	 * Get next eligible image attachment IDs after cursor.
	 *
	 * @param int $cursor_id Last processed attachment ID.
	 * @param int $limit     Maximum IDs to return.
	 * @return array
	 */
	public function get_next_attachment_ids( $cursor_id, $limit ) {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts}
				WHERE post_type = %s
				AND post_mime_type LIKE %s
				AND ID > %d
				ORDER BY ID ASC
				LIMIT %d",
				'attachment',
				'image/%',
				(int) $cursor_id,
				(int) $limit
			)
		);
		// phpcs:enable

		return array_map( 'intval', $ids );
	}

	/**
	 * Count image attachments in the media library.
	 *
	 * @return int
	 */
	public function count_image_attachments() {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(1) FROM {$wpdb->posts}
				WHERE post_type = %s
				AND post_mime_type LIKE %s",
				'attachment',
				'image/%'
			)
		);
		// phpcs:enable
	}

	/**
	 * Count candidate image attachments.
	 *
	 * True optimization eligibility depends on filesystem/editor checks and is
	 * computed by the inventory preflight per attachment.
	 *
	 * @return int
	 */
	public function count_eligible_attachments() {
		return $this->count_image_attachments();
	}

	/**
	 * Get next attachment IDs that have plugin-managed generated variants.
	 *
	 * @param int $cursor_id Last processed attachment ID.
	 * @param int $limit     Maximum IDs to return.
	 * @return array
	 */
	public function get_next_plugin_managed_attachment_ids( $cursor_id, $limit ) {
		return $this->image_model->get_attachment_ids_with_generated_variants( $cursor_id, $limit );
	}

	/**
	 * Count attachments that have plugin-managed generated variants.
	 *
	 * @return int
	 */
	public function count_plugin_managed_attachments() {
		return $this->image_model->count_attachments_with_generated_variants();
	}
}
