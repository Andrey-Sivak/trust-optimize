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
	 * Claims that may start without finishing before the attachment is given up as a poison file.
	 */
	const MAX_ATTEMPTS = 3;

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
	 * Failed attempts of an attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return int
	 */
	public function get_attempts( $attachment_id ) {
		$row = $this->get( $attachment_id );

		return $row ? $row['attempts'] : 0;
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
	 * The claim counts as an attempt; the worker resets the count when it comes back alive.
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
				"UPDATE {$this->table} SET state = %s, attempts = LEAST(attempts + 1, 255), updated_at = %s WHERE attachment_id = %d AND (state <> %s OR updated_at < %s)",
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
	 * A "queued" mark older than a day, or a "processing" claim older than STALE_CLAIM_SECONDS
	 * (the worker died), is treated as lost and may be set again.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool True when this call changed the state to "queued".
	 */
	public function mark_queued( $attachment_id ) {
		global $wpdb;

		$this->ensure_row( $attachment_id );

		return 1 === (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$this->table} SET state = %s, updated_at = %s WHERE attachment_id = %d AND (state NOT IN (%s, %s) OR (state = %s AND updated_at < %s) OR (state = %s AND updated_at < %s))",
				AttachmentState::QUEUED,
				current_time( 'mysql', true ),
				(int) $attachment_id,
				AttachmentState::QUEUED,
				AttachmentState::PROCESSING,
				AttachmentState::QUEUED,
				gmdate( 'Y-m-d H:i:s', time() - self::STALE_QUEUED_SECONDS ),
				AttachmentState::PROCESSING,
				gmdate( 'Y-m-d H:i:s', time() - self::STALE_CLAIM_SECONDS )
			)
		);
	}

	/**
	 * Forget the failed attempts of an attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	public function reset_attempts( $attachment_id ) {
		global $wpdb;

		$wpdb->query( $wpdb->prepare( "UPDATE {$this->table} SET attempts = 0 WHERE attachment_id = %d AND attempts > 0", (int) $attachment_id ) );
	}

	/**
	 * Attachments that were queued or claimed long ago and never finished.
	 *
	 * @param int $older_than Seconds since the last change.
	 * @param int $limit      Maximum number of rows.
	 * @return array[] Rows with attachment_id, state and attempts, oldest first.
	 */
	public function find_stuck( $older_than, $limit ) {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT attachment_id, state, attempts FROM {$this->table} WHERE state IN (%s, %s) AND updated_at < %s ORDER BY updated_at ASC LIMIT %d",
				AttachmentState::QUEUED,
				AttachmentState::PROCESSING,
				gmdate( 'Y-m-d H:i:s', time() - (int) $older_than ),
				(int) $limit
			),
			ARRAY_A
		);

		return array_map(
			static function ( $row ) {
				return array(
					'attachment_id' => (int) $row['attachment_id'],
					'state'         => $row['state'],
					'attempts'      => (int) $row['attempts'],
				);
			},
			$rows
		);
	}

	/**
	 * Give up the claim on queued and processing attachments, remembering why (the queue was emptied).
	 *
	 * @param string $reason Reason stored with the state "none".
	 * @return int Number of attachments suspended.
	 */
	public function suspend_unfinished( $reason ) {
		global $wpdb;

		return (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$this->table} SET state = %s, reason = %s, updated_at = %s WHERE state IN (%s, %s)",
				AttachmentState::NONE,
				$reason,
				current_time( 'mysql', true ),
				AttachmentState::QUEUED,
				AttachmentState::PROCESSING
			)
		);
	}

	/**
	 * Attachments suspended for a reason.
	 *
	 * @param string $reason Reason given to suspend_unfinished().
	 * @param int    $limit  Maximum number of IDs.
	 * @return int[]
	 */
	public function find_suspended( $reason, $limit ) {
		global $wpdb;

		return array_map(
			'intval',
			$wpdb->get_col(
				$wpdb->prepare(
					"SELECT attachment_id FROM {$this->table} WHERE state = %s AND reason = %s ORDER BY attachment_id ASC LIMIT %d",
					AttachmentState::NONE,
					$reason,
					(int) $limit
				)
			)
		);
	}

	/**
	 * Mark a stuck attachment as queued again (compare-and-set on its state and age).
	 *
	 * @param int $attachment_id Attachment ID.
	 * @param int $older_than    Seconds the attachment must have been stuck.
	 * @return bool True when this call changed the state.
	 */
	public function requeue( $attachment_id, $older_than ) {
		global $wpdb;

		return 1 === (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$this->table} SET state = %s, updated_at = %s WHERE attachment_id = %d AND state IN (%s, %s) AND updated_at < %s",
				AttachmentState::QUEUED,
				current_time( 'mysql', true ),
				(int) $attachment_id,
				AttachmentState::QUEUED,
				AttachmentState::PROCESSING,
				gmdate( 'Y-m-d H:i:s', time() - (int) $older_than )
			)
		);
	}

	/**
	 * Attach an attachment to a bulk job, so the job's progress can be derived from the states.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @param int $job_id        Job ID.
	 */
	public function assign_job( $attachment_id, $job_id ) {
		global $wpdb;

		$this->ensure_row( $attachment_id );

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$this->table} SET job_id = %d WHERE attachment_id = %d",
				(int) $job_id,
				(int) $attachment_id
			)
		);
	}

	/**
	 * Number of attachments per state, over the whole library.
	 *
	 * @return int[] Counts keyed by AttachmentState; states without attachments are absent.
	 */
	public function count_states() {
		global $wpdb;

		$rows = $wpdb->get_results( "SELECT state, COUNT(*) AS total FROM {$this->table} GROUP BY state", ARRAY_A );

		return array_map( 'intval', array_column( $rows, 'total', 'state' ) );
	}

	/**
	 * Number of attachments of a job per state.
	 *
	 * @param int $job_id Job ID.
	 * @return int[] Counts keyed by AttachmentState; states without attachments are absent.
	 */
	public function count_states_for_job( $job_id ) {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT state, COUNT(*) AS total FROM {$this->table} WHERE job_id = %d GROUP BY state", (int) $job_id ),
			ARRAY_A
		);

		return array_map( 'intval', array_column( $rows, 'total', 'state' ) );
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
