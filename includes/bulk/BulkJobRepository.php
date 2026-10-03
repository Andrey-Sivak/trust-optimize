<?php
/**
 * Bulk job repository.
 *
 * @package TrustOptimize\Bulk
 */

namespace TrustOptimize\Bulk;

use TrustOptimize\Database\DatabaseManager;
use TrustOptimize\Domain\JobStatus;

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
	 * Option that holds the job that owns the library (the mutex of create()).
	 *
	 * The value is the ID of that job or, between taking the mutex and inserting the job,
	 * the time it was taken. It is read and written with SQL like the core upgrade lock does:
	 * INSERT IGNORE on the primary key of wp_options is atomic, add_option() is not.
	 */
	const ACTIVE_OPTION = 'trust_optimize_bulk_active';

	/**
	 * Age after which a mutex without a job counts as left behind by a crashed request.
	 */
	const MUTEX_STALE_SECONDS = 60;

	/**
	 * Constructor.
	 *
	 * @param DatabaseManager $db_manager Database manager.
	 */
	public function __construct( DatabaseManager $db_manager ) {
		$this->db_manager = $db_manager;
	}

	/**
	 * Create a new bulk job unless another one owns the library.
	 *
	 * Two concurrent calls cannot both succeed: add_option() is atomic (primary key of wp_options).
	 *
	 * @param string $type              Job type.
	 * @param array  $settings_snapshot Settings the job was created with.
	 * @param int    $total             Total candidate attachments.
	 * @return BulkJob|false False when another job is active.
	 */
	public function create( $type, array $settings_snapshot = array(), $total = 0 ) {
		if ( $this->get_active_job() || ! $this->acquire_mutex() ) {
			return false;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->insert(
			$this->get_table_name(),
			array(
				'type'              => $type,
				'status'            => JobStatus::PENDING,
				'cursor_id'         => 0,
				'total'             => (int) $total,
				'settings_snapshot' => wp_json_encode( $settings_snapshot ),
				'updated_at'        => current_time( 'mysql' ),
			)
		);

		if ( ! $result ) {
			$this->release_mutex();

			return false;
		}

		$this->write_mutex( (int) $wpdb->insert_id );

		return $this->get( (int) $wpdb->insert_id );
	}

	/**
	 * Take the mutex, first removing one that was left behind.
	 *
	 * @return bool True when this call owns the mutex.
	 */
	private function acquire_mutex() {
		if ( $this->insert_mutex() ) {
			return true;
		}

		$holder = $this->read_mutex();

		// A job ID is stale when the job is finished; a timestamp when its request died long ago.
		$stale = $holder > 1000000000 ? $holder < time() - self::MUTEX_STALE_SECONDS : ! $this->is_active( $this->get( $holder ) );

		if ( ! $stale ) {
			return false;
		}

		$this->release_mutex();

		return $this->insert_mutex();
	}

	/**
	 * Insert the mutex row; false when it exists.
	 *
	 * @return bool
	 */
	private function insert_mutex() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return 1 === (int) $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
				self::ACTIVE_OPTION,
				(string) time()
			)
		);
	}

	/**
	 * Record the job that owns the mutex.
	 *
	 * @param int $job_id Job ID.
	 */
	private function write_mutex( $job_id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update( $wpdb->options, array( 'option_value' => (string) $job_id ), array( 'option_name' => self::ACTIVE_OPTION ) );
	}

	/**
	 * Job ID (or timestamp) held by the mutex.
	 *
	 * @return int 0 when there is none.
	 */
	private function read_mutex() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::ACTIVE_OPTION ) );
	}

	/**
	 * Remove the mutex.
	 */
	private function release_mutex() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( $wpdb->options, array( 'option_name' => self::ACTIVE_OPTION ) );
	}

	/**
	 * Whether a job exists and is not finished.
	 *
	 * @param BulkJob|null $job Job.
	 * @return bool
	 */
	private function is_active( ?BulkJob $job ) {
		return null !== $job && in_array( $job->get_status(), JobStatus::active(), true );
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
		$statuses = JobStatus::active();

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
	 * Mark an unfinished job as running; a finished job is never revived.
	 *
	 * @param int $job_id Job ID.
	 * @return bool True when the job is running now.
	 */
	public function mark_running( $job_id ) {
		global $wpdb;

		$now = current_time( 'mysql' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$changed = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$this->get_table_name()} SET status = %s, started_at = COALESCE(started_at, %s), updated_at = %s WHERE id = %d AND status IN (%s, %s, %s)",
				JobStatus::RUNNING,
				$now,
				$now,
				(int) $job_id,
				JobStatus::PENDING,
				JobStatus::RUNNING,
				JobStatus::PAUSED
			)
		);
		// phpcs:enable

		if ( $changed ) {
			return true;
		}

		$job = $this->get( $job_id );

		return null !== $job && JobStatus::RUNNING === $job->get_status();
	}

	/**
	 * Pause a job that has not finished.
	 *
	 * @param int         $job_id Job ID.
	 * @param string|null $reason Why it was paused by the plugin (stored as the last error).
	 * @return bool True when the job was paused.
	 */
	public function pause( $job_id, $reason = null ) {
		$data = array( 'status' => JobStatus::PAUSED );

		if ( null !== $reason ) {
			$data['last_error'] = $reason;
		}

		return $this->transition( $job_id, array( JobStatus::PENDING, JobStatus::RUNNING ), $data );
	}

	/**
	 * Cancel a job.
	 *
	 * @param int $job_id Job ID.
	 * @return bool
	 */
	public function cancel( $job_id ) {
		return $this->finish( $job_id, JobStatus::CANCELLED );
	}

	/**
	 * Complete a job.
	 *
	 * @param int  $job_id      Job ID.
	 * @param bool $with_errors Whether some attachments failed.
	 * @return bool
	 */
	public function complete( $job_id, $with_errors = false ) {
		return $this->finish( $job_id, $with_errors ? JobStatus::COMPLETED_WITH_ERRORS : JobStatus::COMPLETED );
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
			JobStatus::FAILED,
			array( 'last_error' => $last_error )
		);
	}

	/**
	 * Move the cursor to the last attachment handed over to the queue.
	 *
	 * @param int $job_id    Job ID.
	 * @param int $cursor_id Last handled attachment ID.
	 * @return bool
	 */
	public function set_cursor( $job_id, $cursor_id ) {
		return $this->update( $job_id, array( 'cursor_id' => (int) $cursor_id ) );
	}

	/**
	 * Record that the job is alive (a producer run that had nothing to hand over).
	 *
	 * @param int $job_id Job ID.
	 * @return bool
	 */
	public function touch( $job_id ) {
		return $this->update( $job_id, array() );
	}

	/**
	 * Merge values into one section of the stored snapshot (the inventory result).
	 *
	 * @param int    $job_id Job ID.
	 * @param string $key    Snapshot section.
	 * @param array  $values Values to set in it.
	 * @return bool
	 */
	public function merge_snapshot( $job_id, $key, array $values ) {
		$job = $this->get( $job_id );

		if ( ! $job ) {
			return false;
		}

		$snapshot         = $job->get_settings_snapshot();
		$snapshot[ $key ] = array_merge( (array) ( $snapshot[ $key ] ?? array() ), $values );

		return $this->update( $job_id, array( 'settings_snapshot' => wp_json_encode( $snapshot ) ) );
	}

	/**
	 * Close the active jobs of schema 1.x: their tick runner no longer exists.
	 *
	 * Jobs of 2.0 always store the settings they were created with; those of 1.x stored none.
	 *
	 * @return int Number of jobs closed.
	 */
	public function supersede_legacy_jobs() {
		global $wpdb;

		$table = $this->get_table_name();
		$now   = current_time( 'mysql' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$closed = (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = %s, last_error = %s, finished_at = %s, updated_at = %s WHERE status IN (%s, %s, %s) AND (settings_snapshot IS NULL OR settings_snapshot IN ('', '[]'))",
				JobStatus::CANCELLED,
				'superseded by 2.0',
				$now,
				$now,
				JobStatus::PENDING,
				JobStatus::RUNNING,
				JobStatus::PAUSED
			)
		);
		// phpcs:enable

		return $closed;
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
				JobStatus::RUNNING,
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

			if ( $this->transition(
				$job->get_id(),
				array( JobStatus::RUNNING ),
				array(
					'status'     => JobStatus::PAUSED,
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
	 * Finish an unfinished job with a terminal status and release the library.
	 *
	 * @param int    $job_id Job ID.
	 * @param string $status Terminal status.
	 * @param array  $data   Additional data.
	 * @return bool True when the job was finished by this call.
	 */
	private function finish( $job_id, $status, array $data = array() ) {
		$data['status']      = $status;
		$data['finished_at'] = current_time( 'mysql' );
		$finished            = $this->transition( $job_id, JobStatus::active(), $data );

		if ( $finished && $this->read_mutex() === (int) $job_id ) {
			$this->release_mutex();
		}

		return $finished;
	}

	/**
	 * Change fields of a job that is in one of the given statuses (compare-and-set).
	 *
	 * @param int      $job_id Job ID.
	 * @param string[] $from   Statuses the job may be in.
	 * @param array    $data   Columns to set.
	 * @return bool True when a row was changed.
	 */
	private function transition( $job_id, array $from, array $data ) {
		global $wpdb;

		$data['updated_at'] = current_time( 'mysql' );
		$sets               = array();
		$values             = array();

		foreach ( $data as $column => $value ) {
			$sets[]   = "{$column} = %s";
			$values[] = $value;
		}

		$values[] = (int) $job_id;
		$values   = array_merge( $values, $from );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		return 1 === (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$this->get_table_name()} SET " . implode( ', ', $sets ) . ' WHERE id = %d AND status IN (' . implode( ', ', array_fill( 0, count( $from ), '%s' ) ) . ')',
				$values
			)
		);
		// phpcs:enable
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
