<?php
/**
 * Bulk job repository.
 *
 * @package TrustOptimize\Bulk
 */

namespace TrustOptimize\Bulk;

use TrustOptimize\Database\DatabaseManager;

/**
 * Class BulkJobRepository
 */
class BulkJobRepository {

	/**
	 * Table name without prefix.
	 *
	 * @var string
	 */
	private $table = 'trust_optimize_jobs';

	/**
	 * Database manager.
	 *
	 * @var DatabaseManager
	 */
	private $db_manager;

	/**
	 * Constructor.
	 *
	 * @param DatabaseManager|null $db_manager Database manager.
	 */
	public function __construct( DatabaseManager $db_manager = null ) {
		$this->db_manager = $db_manager ? $db_manager : new DatabaseManager();
	}

	/**
	 * Create a new bulk job if there is no active library job.
	 *
	 * @param string $type              Job type.
	 * @param array  $settings_snapshot Settings snapshot.
	 * @param string $profile_hash      Profile hash.
	 * @param int    $total             Total candidate attachments.
	 * @return BulkJob|false
	 */
	public function create( $type, array $settings_snapshot = array(), $profile_hash = '', $total = 0 ) {
		if ( $this->get_active_job() ) {
			return false;
		}

		global $wpdb;

		$table = $this->get_table_name();
		$now   = current_time( 'mysql' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->insert(
			$table,
			array(
				'type'              => $type,
				'status'            => BulkJob::STATUS_PENDING,
				'cursor_id'         => 0,
				'total'             => (int) $total,
				'processed'         => 0,
				'skipped'           => 0,
				'failed_count'      => 0,
				'created_count'     => 0,
				'deleted_count'     => 0,
				'settings_snapshot' => wp_json_encode( $settings_snapshot ),
				'profile_hash'      => $profile_hash,
				'updated_at'        => $now,
			)
		);

		if ( ! $result ) {
			return false;
		}

		return $this->get( (int) $wpdb->insert_id );
	}

	/**
	 * Get a job by ID.
	 *
	 * @param int $job_id Job ID.
	 * @return BulkJob|null
	 */
	public function get( $job_id ) {
		global $wpdb;

		$table = $this->get_table_name();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $job_id ),
			ARRAY_A
		);
		// phpcs:enable

		return $row ? $this->hydrate( $row ) : null;
	}

	/**
	 * Get the active library job.
	 *
	 * @return BulkJob|null
	 */
	public function get_active_job() {
		$this->recover_stale_running();

		global $wpdb;

		$table    = $this->get_table_name();
		$statuses = array( BulkJob::STATUS_PENDING, BulkJob::STATUS_RUNNING, BulkJob::STATUS_PAUSED );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE status IN (%s, %s, %s) ORDER BY id DESC LIMIT 1",
				$statuses[0],
				$statuses[1],
				$statuses[2]
			),
			ARRAY_A
		);
		// phpcs:enable

		return $row ? $this->hydrate( $row ) : null;
	}

	/**
	 * Get the latest job.
	 *
	 * @return BulkJob|null
	 */
	public function get_latest_job() {
		global $wpdb;

		$table = $this->get_table_name();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			"SELECT * FROM {$table} ORDER BY id DESC LIMIT 1",
			ARRAY_A
		);
		// phpcs:enable

		return $row ? $this->hydrate( $row ) : null;
	}

	/**
	 * Mark a job as running.
	 *
	 * @param int $job_id Job ID.
	 * @return bool
	 */
	public function mark_running( $job_id ) {
		return $this->update(
			$job_id,
			array(
				'status'     => BulkJob::STATUS_RUNNING,
				'started_at' => current_time( 'mysql' ),
			)
		);
	}

	/**
	 * Pause a job.
	 *
	 * @param int $job_id Job ID.
	 * @return bool
	 */
	public function pause( $job_id ) {
		return $this->update( $job_id, array( 'status' => BulkJob::STATUS_PAUSED ) );
	}

	/**
	 * Resume a job.
	 *
	 * @param int $job_id Job ID.
	 * @return bool
	 */
	public function resume( $job_id ) {
		return $this->update( $job_id, array( 'status' => BulkJob::STATUS_PENDING ) );
	}

	/**
	 * Cancel a job.
	 *
	 * @param int $job_id Job ID.
	 * @return bool
	 */
	public function cancel( $job_id ) {
		return $this->finish( $job_id, BulkJob::STATUS_CANCELLED );
	}

	/**
	 * Complete a job.
	 *
	 * @param int $job_id Job ID.
	 * @return bool
	 */
	public function complete( $job_id ) {
		return $this->finish( $job_id, BulkJob::STATUS_COMPLETED );
	}

	/**
	 * Fail a job.
	 *
	 * @param int    $job_id     Job ID.
	 * @param string $last_error Last error.
	 * @return bool
	 */
	public function fail( $job_id, $last_error = '' ) {
		return $this->finish(
			$job_id,
			BulkJob::STATUS_FAILED,
			array( 'last_error' => $last_error )
		);
	}

	/**
	 * Persist cursor and counter increments after processing an attachment.
	 *
	 * @param int   $job_id     Job ID.
	 * @param int   $cursor_id  Last processed attachment ID.
	 * @param array $increments Counter increments.
	 * @return bool
	 */
	public function advance_cursor( $job_id, $cursor_id, array $increments = array() ) {
		global $wpdb;

		$table   = $this->get_table_name();
		$allowed = array( 'processed', 'skipped', 'failed_count', 'created_count', 'deleted_count' );
		$sets    = array( 'cursor_id = %d', 'updated_at = %s' );
		$values  = array( (int) $cursor_id, current_time( 'mysql' ) );

		foreach ( $allowed as $counter ) {
			if ( empty( $increments[ $counter ] ) ) {
				continue;
			}

			$sets[]   = "{$counter} = {$counter} + %d";
			$values[] = (int) $increments[ $counter ];
		}

		$values[] = (int) $job_id;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$result = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				"UPDATE {$table} SET " . implode( ', ', $sets ) . ' WHERE id = %d',
				$values
			)
		);
		// phpcs:enable

		return false !== $result;
	}

	/**
	 * Find stale running jobs.
	 *
	 * @param int $stale_after_seconds Stale threshold in seconds.
	 * @return array
	 */
	public function find_stale_running( $stale_after_seconds ) {
		global $wpdb;

		$table     = $this->get_table_name();
		$threshold = gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) ) - (int) $stale_after_seconds );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE status = %s AND updated_at < %s ORDER BY id ASC",
				BulkJob::STATUS_RUNNING,
				$threshold
			),
			ARRAY_A
		);
		// phpcs:enable

		return array_map( array( $this, 'hydrate' ), $rows );
	}

	/**
	 * Recover stale running jobs so they can be resumed manually.
	 *
	 * Stale jobs are moved to paused instead of pending to avoid starting work
	 * unexpectedly on admin/status requests.
	 *
	 * @param int|null $stale_after_seconds Stale threshold in seconds.
	 * @return int Number of recovered jobs.
	 */
	public function recover_stale_running( $stale_after_seconds = null ) {
		$stale_after_seconds = null === $stale_after_seconds ? $this->get_stale_after_seconds() : (int) $stale_after_seconds;
		$recovered           = 0;

		foreach ( $this->find_stale_running( $stale_after_seconds ) as $job ) {
			$data    = $job->to_array();
			$message = sprintf(
				'Bulk job recovered from stale running state after %d seconds of inactivity. Resume is required.',
				$stale_after_seconds
			);

			if ( ! empty( $data['last_error'] ) ) {
				$message = $data['last_error'] . "\n" . $message;
			}

			if ( $this->update(
				$job->get_id(),
				array(
					'status'     => BulkJob::STATUS_PAUSED,
					'last_error' => $message,
				)
			) ) {
				++$recovered;
			}
		}

		return $recovered;
	}

	/**
	 * Get stale running job threshold.
	 *
	 * @return int Threshold in seconds.
	 */
	private function get_stale_after_seconds() {
		$threshold = (int) apply_filters( 'trust_optimize_bulk_stale_after_seconds', 15 * MINUTE_IN_SECONDS );

		return max( MINUTE_IN_SECONDS, $threshold );
	}

	/**
	 * Update job fields.
	 *
	 * @param int   $job_id Job ID.
	 * @param array $data   Job data.
	 * @return bool
	 */
	public function update( $job_id, array $data ) {
		global $wpdb;

		$table              = $this->get_table_name();
		$data['updated_at'] = current_time( 'mysql' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->update(
			$table,
			$data,
			array( 'id' => (int) $job_id )
		);

		return false !== $result;
	}

	/**
	 * Finish a job with terminal status.
	 *
	 * @param int    $job_id Job ID.
	 * @param string $status Terminal status.
	 * @param array  $data   Additional data.
	 * @return bool
	 */
	private function finish( $job_id, $status, array $data = array() ) {
		$data['status']      = $status;
		$data['finished_at'] = current_time( 'mysql' );

		return $this->update( $job_id, $data );
	}

	/**
	 * Hydrate a job row.
	 *
	 * @param array $row Database row.
	 * @return BulkJob
	 */
	private function hydrate( array $row ) {
		if ( isset( $row['settings_snapshot'] ) && is_string( $row['settings_snapshot'] ) ) {
			$decoded                  = json_decode( $row['settings_snapshot'], true );
			$row['settings_snapshot'] = is_array( $decoded ) ? $decoded : array();
		}

		return new BulkJob( $row );
	}

	/**
	 * Get full table name.
	 *
	 * @return string
	 */
	private function get_table_name() {
		return $this->db_manager->get_table_name( $this->table );
	}
}
