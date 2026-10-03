<?php
/**
 * Migration step: remove the queue tasks, options and transients of schema 1.x.
 *
 * @package TrustOptimize\Migration
 */

namespace TrustOptimize\Migration;

use TrustOptimize\Bulk\BulkJobRepository;
use TrustOptimize\Queue\ConversionQueue;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Deletes plugin options by prefix; every value is prepared.

/**
 * Class CleanupLegacyRuntime
 *
 * Pending per-variant tasks of 1.x are turned into attachment tasks first: an attachment
 * that has no converted variant yet is unknown to the regeneration step. Then they are
 * cancelled and the options and transients that only 1.x used are deleted.
 *
 * The Action Scheduler runs 1.x tasks as soon as the plugin is upgraded, long before the
 * migration reaches this step, and an action without a callback is just completed. So the
 * hook keeps a callback that does what the step does, as long as the plugin is installed.
 *
 * The bulk tick runner of 1.x is gone: its tick actions, lock options and status transients are
 * removed and a bulk job of 1.x that is still active is closed. Its attachments are not lost,
 * a new job picks them up.
 */
class CleanupLegacyRuntime implements MigrationStep {

	/**
	 * Action Scheduler hook of the per-variant tasks of 1.x.
	 */
	const LEGACY_TASK_HOOK = 'trust_optimize_convert_image';

	/**
	 * Action Scheduler hook of the bulk ticks of 1.x.
	 */
	const LEGACY_BULK_HOOK = 'trust_optimize_bulk_tick';

	/**
	 * Prefix of the per-job lock options of the bulk ticks of 1.x.
	 */
	const LEGACY_BULK_LOCK_PREFIX = 'trust_optimize_bulk_tick_lock_';

	/**
	 * Prefix of the transients that throttled the status polling of 1.x.
	 */
	const LEGACY_BULK_STATUS_PREFIX = 'trust_optimize_bulk_status_tick_';

	/**
	 * Option written by the 1.x preflight.
	 */
	const PREFLIGHT_OPTION = 'trust_optimize_preflight';

	/**
	 * Prefix of the transients that cached the formats of an attachment in 1.x.
	 */
	const FORMATS_TRANSIENT_PREFIX = 'trust_optimize_formats_';

	/**
	 * Conversion queue.
	 *
	 * @var ConversionQueue
	 */
	private $queue;

	/**
	 * Bulk job repository.
	 *
	 * @var BulkJobRepository
	 */
	private $jobs;

	/**
	 * Constructor.
	 *
	 * @param ConversionQueue   $queue Conversion queue.
	 * @param BulkJobRepository $jobs  Bulk job repository.
	 */
	public function __construct( ConversionQueue $queue, BulkJobRepository $jobs ) {
		$this->queue = $queue;
		$this->jobs  = $jobs;
	}

	/**
	 * Register the callback for the 1.x task hook.
	 */
	public function register() {
		add_action( self::LEGACY_TASK_HOOK, array( $this, 'absorb_task' ), 10, 1 );
	}

	/**
	 * Action Scheduler callback: a 1.x per-variant task becomes an attachment task.
	 *
	 * @param int|array $payload The variant payload of 1.x (or, in older tasks, the attachment ID).
	 */
	public function absorb_task( $payload ) {
		$this->queue_attachment( $this->attachment_id( array( $payload ) ) );
	}

	/**
	 * Step name.
	 *
	 * @return string
	 */
	public function name() {
		return 'cleanup_legacy_runtime';
	}

	/**
	 * Absorb and cancel the next pending tasks; delete the options once none is left.
	 *
	 * @param int $cursor Unused: handled tasks are cancelled, so the next batch finds the following ones.
	 * @param int $limit  Maximum number of tasks.
	 * @return BatchResult Counts: tasks, transients.
	 */
	public function run_batch( $cursor, $limit ) {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			return BatchResult::finished();
		}

		$actions = as_get_scheduled_actions(
			array(
				'hook'     => self::LEGACY_TASK_HOOK,
				'group'    => ConversionQueue::GROUP,
				'status'   => \ActionScheduler_Store::STATUS_PENDING,
				'per_page' => (int) $limit,
			)
		);

		foreach ( $actions as $action_id => $action ) {
			$this->queue_attachment( $this->attachment_id( $action->get_args() ) );
			\ActionScheduler::store()->cancel_action( $action_id );
		}

		$counts = array( 'tasks' => count( $actions ) );

		if ( count( $actions ) >= (int) $limit ) {
			return BatchResult::more( $cursor, $counts );
		}

		delete_option( self::PREFLIGHT_OPTION );

		as_unschedule_all_actions( self::LEGACY_BULK_HOOK, null, ConversionQueue::GROUP );
		$this->delete_options_with_prefix( self::LEGACY_BULK_LOCK_PREFIX );
		$counts['jobs'] = $this->jobs->supersede_legacy_jobs();

		$counts['transients'] = $this->delete_transients( $limit );

		return $counts['transients'] >= (int) $limit ? BatchResult::more( $cursor, $counts ) : BatchResult::finished( $counts );
	}

	/**
	 * Plan and queue the attachment of a 1.x task, unless it is gone.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	private function queue_attachment( $attachment_id ) {
		if ( $attachment_id > 0 && 'attachment' === get_post_type( $attachment_id ) ) {
			$this->queue->plan_and_enqueue( $attachment_id );
		}
	}

	/**
	 * Attachment ID of a 1.x task: the arguments hold the variant payload (or, in older tasks, the ID itself).
	 *
	 * @param array $args Action arguments.
	 * @return int
	 */
	private function attachment_id( array $args ) {
		$payload = $args['attachment_id'] ?? reset( $args );

		return (int) ( is_array( $payload ) ? ( $payload['attachment_id'] ?? 0 ) : $payload );
	}

	/**
	 * Delete the transients that only 1.x used.
	 *
	 * @param int $limit Maximum number of transients.
	 * @return int Number of transients deleted.
	 */
	private function delete_transients( $limit ) {
		global $wpdb;

		$names = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s LIMIT %d",
				$wpdb->esc_like( '_transient_' . self::FORMATS_TRANSIENT_PREFIX ) . '%',
				$wpdb->esc_like( '_transient_' . self::LEGACY_BULK_STATUS_PREFIX ) . '%',
				(int) $limit
			)
		);

		foreach ( $names as $name ) {
			delete_transient( substr( $name, strlen( '_transient_' ) ) );
		}

		return count( $names );
	}

	/**
	 * Delete the options whose names start with a prefix (one per bulk job in 1.x).
	 *
	 * @param string $prefix Option name prefix.
	 */
	private function delete_options_with_prefix( $prefix ) {
		global $wpdb;

		$names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( $prefix ) . '%' ) );

		foreach ( $names as $name ) {
			delete_option( $name );
		}
	}
}
