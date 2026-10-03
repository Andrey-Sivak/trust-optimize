<?php
/**
 * Keeps queued work across a deactivation of the plugin.
 *
 * @package TrustOptimize\Queue
 */

namespace TrustOptimize\Queue;

use TrustOptimize\Bulk\BulkJobRepository;
use TrustOptimize\Database\DatabaseManager;
use TrustOptimize\Storage\AttachmentRepository;
use TrustOptimize\Storage\VariantRepository;

/**
 * Class Lifecycle
 *
 * Deactivation removes every Action Scheduler action of the plugin, so attachments that were
 * queued or being converted would be marked as waiting for a task that never comes. They go
 * back to "none" with the reason "deactivated", running jobs are paused, and the next
 * activation queues the suspended attachments again in batches.
 */
class Lifecycle {

	/**
	 * Action Scheduler hook that queues suspended attachments again.
	 */
	const HOOK_RESTORE = 'trust_optimize_restore_queue';

	/**
	 * Reason stored on suspended attachments and paused jobs.
	 */
	const REASON = 'deactivated';

	/**
	 * Attachments queued again per run.
	 */
	const BATCH_SIZE = 100;

	/**
	 * Attachment repository.
	 *
	 * @var AttachmentRepository
	 */
	private $attachments;

	/**
	 * Conversion queue.
	 *
	 * @var ConversionQueue
	 */
	private $queue;

	/**
	 * Constructor.
	 *
	 * @param AttachmentRepository $attachments Attachment repository.
	 * @param ConversionQueue      $queue       Conversion queue.
	 */
	public function __construct( AttachmentRepository $attachments, ConversionQueue $queue ) {
		$this->attachments = $attachments;
		$this->queue       = $queue;
	}

	/**
	 * Register the restore task.
	 */
	public function register() {
		add_action( self::HOOK_RESTORE, array( $this, 'restore' ) );
	}

	/**
	 * Plugin deactivation: drop the scheduled actions, keep track of the work they stood for.
	 */
	public static function deactivate() {
		$database    = new DatabaseManager();
		$attachments = new AttachmentRepository( $database, new VariantRepository( $database ) );

		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( null, null, ConversionQueue::GROUP );
		}

		$attachments->suspend_unfinished( self::REASON );
		( new BulkJobRepository( $database ) )->pause_unfinished( self::REASON );
	}

	/**
	 * Plugin activation: schedule the maintenance and the restore of suspended work.
	 */
	public static function activate() {
		Maintenance::schedule();

		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::HOOK_RESTORE, array(), ConversionQueue::GROUP, true );
		}
	}

	/**
	 * Action Scheduler callback: queue the next batch of suspended attachments, then look again.
	 *
	 * @return int Number of attachments queued.
	 */
	public function restore() {
		$ids    = $this->attachments->find_suspended( self::REASON, self::BATCH_SIZE );
		$queued = 0;

		foreach ( $ids as $attachment_id ) {
			if ( $this->queue->enqueue( $attachment_id ) ) {
				++$queued;
			}
		}

		if ( $queued > 0 && count( $ids ) === self::BATCH_SIZE ) {
			as_enqueue_async_action( self::HOOK_RESTORE, array(), ConversionQueue::GROUP );
		}

		return $queued;
	}
}
