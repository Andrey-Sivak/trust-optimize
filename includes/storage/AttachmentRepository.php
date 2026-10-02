<?php
/**
 * Persistence of per-attachment optimization state.
 *
 * @package TrustOptimize
 */

namespace TrustOptimize\Storage;

use TrustOptimize\Database\DatabaseManager;
use TrustOptimize\Domain\AttachmentState;
use TrustOptimize\Domain\VariantStatus;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Repository over plugin tables; names come from DatabaseManager and values are prepared.

/**
 * Class AttachmentRepository
 */
class AttachmentRepository {

	/**
	 * Longest stored error message.
	 */
	const LAST_ERROR_LENGTH = 255;

	/**
	 * Default age of a "processing" claim after which it is considered abandoned.
	 */
	const STALE_CLAIM_SECONDS = 900;

	/**
	 * Age after which a "queued" mark is considered lost (its Action Scheduler action vanished).
	 */
	const STALE_QUEUED_SECONDS = DAY_IN_SECONDS;

	/**
	 * Attachments table name.
	 *
	 * @var string
	 */
	private $table;

	/**
	 * Variant repository (the aggregate is derived from its rows).
	 *
	 * @var VariantRepository
	 */
	private $variants;

	/**
	 * Constructor.
	 *
	 * @param DatabaseManager   $database Database manager.
	 * @param VariantRepository $variants Variant repository.
	 */
	public function __construct( DatabaseManager $database, VariantRepository $variants ) {
		$tables         = $database->get_plugin_table_names();
		$this->table    = $tables['attachments'];
		$this->variants = $variants;
	}

	/**
	 * Aggregate state from variant counts per status.
	 *
	 * @param int[] $counts Counts keyed by VariantStatus.
	 * @return string AttachmentState constant.
	 */
	public static function derive_state( array $counts ) {
		$count = static function ( $status ) use ( $counts ) {
			return (int) ( $counts[ $status ] ?? 0 );
		};

		if ( 0 === array_sum( $counts ) ) {
			return AttachmentState::NONE;
		}

		if ( $count( VariantStatus::PROCESSING ) > 0 ) {
			return AttachmentState::PROCESSING;
		}

		if ( $count( VariantStatus::PENDING ) > 0 ) {
			return AttachmentState::QUEUED;
		}

		if ( $count( VariantStatus::FAILED ) > 0 ) {
			return $count( VariantStatus::DONE ) > 0 ? AttachmentState::PARTIAL : AttachmentState::FAILED;
		}

		return AttachmentState::OPTIMIZED;
	}

	/**
	 * Attachment row.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array|null
	 */
	public function get( $attachment_id ) {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$this->table} WHERE attachment_id = %d", (int) $attachment_id ),
			ARRAY_A
		);

		if ( ! $row ) {
			return null;
		}

		$row['attachment_id'] = (int) $row['attachment_id'];
		$row['attempts']      = (int) $row['attempts'];

		return $row;
	}

	/**
	 * Current state, "none" when there is no row.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string
	 */
	public function get_state( $attachment_id ) {
		$row = $this->get( $attachment_id );

		return $row ? $row['state'] : AttachmentState::NONE;
	}

	/**
	 * Set the state explicitly (e.g. "skipped" for an unsupported attachment).
	 *
	 * @param int         $attachment_id Attachment ID.
	 * @param string      $state         AttachmentState constant.
	 * @param string|null $reason        Reason for skipped/failed.
	 * @param string|null $last_error    Last error message (truncated to 255 characters).
	 */
	public function set_state( $attachment_id, $state, $reason = null, $last_error = null ) {
		global $wpdb;

		$this->ensure_row( $attachment_id );

		$wpdb->update(
			$this->table,
			array(
				'state'      => $state,
				'reason'     => $reason,
				'last_error' => null === $last_error ? null : $this->truncate( $last_error ),
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'attachment_id' => (int) $attachment_id ),
			array( '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Recompute the aggregate state from the variant rows and store it.
	 *
	 * An explicit "skipped" state of an attachment without variants is kept.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string The resulting state.
	 */
	public function recompute( $attachment_id ) {
		$state = self::derive_state( $this->variants->count_by_status( $attachment_id ) );

		if ( AttachmentState::NONE === $state && AttachmentState::SKIPPED === $this->get_state( $attachment_id ) ) {
			return AttachmentState::SKIPPED;
		}

		$this->ensure_row( $attachment_id );
		$this->write_state( $attachment_id, $state );

		return $state;
	}

	/**
	 * Take ownership of an attachment for processing (compare-and-set).
	 *
	 * A claim older than $stale_after seconds is taken over: the worker that made it died.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @param int $stale_after   Seconds after which a "processing" claim counts as abandoned.
	 * @return bool True when this call changed the state to "processing".
	 */
	public function claim( $attachment_id, $stale_after = self::STALE_CLAIM_SECONDS ) {
		global $wpdb;

		$this->ensure_row( $attachment_id );

		return 1 === (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$this->table} SET state = %s, updated_at = %s WHERE attachment_id = %d AND (state <> %s OR updated_at < %s)",
				AttachmentState::PROCESSING,
				current_time( 'mysql', true ),
				(int) $attachment_id,
				AttachmentState::PROCESSING,
				gmdate( 'Y-m-d H:i:s', time() - (int) $stale_after )
			)
		);
	}

	/**
	 * Mark an attachment as queued unless it is already queued or processing (compare-and-set).
	 *
	 * A "queued" mark older than a day is treated as lost and may be set again.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool True when this call changed the state to "queued".
	 */
	public function mark_queued( $attachment_id ) {
		global $wpdb;

		$this->ensure_row( $attachment_id );

		return 1 === (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$this->table} SET state = %s, updated_at = %s WHERE attachment_id = %d AND (state NOT IN (%s, %s) OR (state = %s AND updated_at < %s))",
				AttachmentState::QUEUED,
				current_time( 'mysql', true ),
				(int) $attachment_id,
				AttachmentState::QUEUED,
				AttachmentState::PROCESSING,
				AttachmentState::QUEUED,
				gmdate( 'Y-m-d H:i:s', time() - self::STALE_QUEUED_SECONDS )
			)
		);
	}

	/**
	 * Delete the row of an attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	public function delete( $attachment_id ) {
		global $wpdb;

		$wpdb->delete( $this->table, array( 'attachment_id' => (int) $attachment_id ), array( '%d' ) );
	}

	/**
	 * Create the row if missing.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	private function ensure_row( $attachment_id ) {
		global $wpdb;

		$wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$this->table} (attachment_id, state, updated_at) VALUES (%d, %s, %s)",
				(int) $attachment_id,
				AttachmentState::NONE,
				current_time( 'mysql', true )
			)
		);
	}

	/**
	 * Store a state, clearing reason and error.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $state         State.
	 */
	private function write_state( $attachment_id, $state ) {
		global $wpdb;

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$this->table} SET state = %s, reason = NULL, last_error = NULL, updated_at = %s WHERE attachment_id = %d",
				$state,
				current_time( 'mysql', true ),
				(int) $attachment_id
			)
		);
	}

	/**
	 * Truncate to the column length.
	 *
	 * @param string $message Message.
	 * @return string
	 */
	private function truncate( $message ) {
		return mb_substr( (string) $message, 0, self::LAST_ERROR_LENGTH, 'UTF-8' );
	}
}
