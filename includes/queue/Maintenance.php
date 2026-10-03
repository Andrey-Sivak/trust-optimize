<?php
/**
 * Hourly repair of attachments whose queue task was lost or whose worker died.
 *
 * @package TrustOptimize\Queue
 */

namespace TrustOptimize\Queue;

use TrustOptimize\Domain\AttachmentState;
use TrustOptimize\Storage\AttachmentRepository;

/**
 * Class Maintenance
 *
 * An attachment "queued" or "processing" for longer than a worker may take, without an
 * Action Scheduler task, is put back on the queue. After MAX_ATTEMPTS claims that never
 * finished (a fatal error, an out-of-memory kill) the file is given up as a poison file.
 */
class Maintenance {

	/**
	 * Action Scheduler hook.
	 */
	const HOOK = 'trust_optimize_maintenance';

	/**
	 * Attachments looked at per run.
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
	 * Register the task and make sure it is scheduled.
	 */
	public function register() {
		add_action( self::HOOK, array( $this, 'run' ) );
		add_action( 'admin_init', array( self::class, 'schedule' ) );
	}

	/**
	 * Schedule the hourly task unless it is already scheduled.
	 */
	public static function schedule() {
		if ( function_exists( 'as_schedule_recurring_action' ) && ! as_has_scheduled_action( self::HOOK, null, ConversionQueue::GROUP ) ) {
			as_schedule_recurring_action( time() + HOUR_IN_SECONDS, HOUR_IN_SECONDS, self::HOOK, array(), ConversionQueue::GROUP, true );
		}
	}

	/**
	 * Remove the scheduled task.
	 */
	public static function unschedule() {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK, null, ConversionQueue::GROUP );
		}
	}

	/**
	 * Action Scheduler callback.
	 *
	 * @return int Number of attachments repaired.
	 */
	public function run() {
		$repaired = 0;

		foreach ( $this->attachments->find_stuck( AttachmentRepository::STALE_CLAIM_SECONDS, self::BATCH_SIZE ) as $row ) {
			$id = $row['attachment_id'];

			// A task that is pending or running still owns the attachment.
			if ( as_has_scheduled_action( ConversionQueue::HOOK_PROCESS, array( 'attachment_id' => $id ), ConversionQueue::GROUP ) ) {
				continue;
			}

			if ( AttachmentState::PROCESSING === $row['state'] && $row['attempts'] >= AttachmentRepository::MAX_ATTEMPTS ) {
				$this->attachments->set_state( $id, AttachmentState::FAILED, 'max_attempts', 'The worker died ' . $row['attempts'] . ' times on this file.' );
			} else {
				$this->queue->requeue( $id );
			}

			++$repaired;
		}

		return $repaired;
	}
}
