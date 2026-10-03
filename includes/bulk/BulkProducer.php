<?php
/**
 * Hands the attachments of a bulk job over to the queue, a batch at a time.
 *
 * @package TrustOptimize\Bulk
 */

namespace TrustOptimize\Bulk;

use Throwable;
use TrustOptimize\Admin\Settings;
use TrustOptimize\Capabilities\CapabilityService;
use TrustOptimize\Domain\AttachmentState;
use TrustOptimize\Domain\JobStatus;
use TrustOptimize\Queue\ConversionQueue;
use TrustOptimize\Service\ImageCleanupService;
use TrustOptimize\Settings\OptimizationSettings;
use TrustOptimize\Storage\AttachmentRepository;
use TrustOptimize\Utils\DiskSpace;

/**
 * Class BulkProducer
 *
 * The producer is one Action Scheduler action per job that reschedules itself. It never converts
 * anything: a sync job puts an attachment task on the queue for every attachment (D-9), so one
 * crashing image cannot stop the others. Its position is the job's cursor, its progress is
 * derived by JobProgress, and it keeps the number of waiting tasks below a limit (backpressure).
 */
class BulkProducer {

	/**
	 * Action Scheduler hook of the producer.
	 */
	const HOOK_PRODUCE = 'trust_optimize_bulk_produce';

	/**
	 * Attachments handed over per run when nothing else is configured.
	 */
	const DEFAULT_BATCH_SIZE = 25;

	/**
	 * Largest batch (also the limit of the CLI option).
	 */
	const MAX_BATCH_SIZE = 100;

	/**
	 * Waiting attachment tasks above which the producer pauses.
	 */
	const DEFAULT_MAX_PENDING = 200;

	/**
	 * Seconds before a producer that waits for the queue looks again.
	 */
	const WAIT_SECONDS = 30;

	/**
	 * Seconds one run may spend handing attachments over.
	 */
	const DEFAULT_TIME_BUDGET = 20;

	/**
	 * Reason (stored as the last error) of a job paused for lack of disk space.
	 */
	const REASON_LOW_DISK = 'low_disk_space';

	/**
	 * Job repository.
	 *
	 * @var BulkJobRepository
	 */
	private $jobs;

	/**
	 * Eligibility query.
	 *
	 * @var EligibilityQuery
	 */
	private $eligibility;

	/**
	 * Job progress.
	 *
	 * @var JobProgress
	 */
	private $progress;

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
	 * Cleanup service.
	 *
	 * @var ImageCleanupService
	 */
	private $cleanup;

	/**
	 * Inventory.
	 *
	 * @var Inventory
	 */
	private $inventory;

	/**
	 * Plugin settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Capability service.
	 *
	 * @var CapabilityService
	 */
	private $capabilities;

	/**
	 * Constructor.
	 *
	 * @param BulkJobRepository    $jobs         Job repository.
	 * @param EligibilityQuery     $eligibility  Eligibility query.
	 * @param JobProgress          $progress     Job progress.
	 * @param AttachmentRepository $attachments  Attachment repository.
	 * @param ConversionQueue      $queue        Conversion queue.
	 * @param ImageCleanupService  $cleanup      Cleanup service.
	 * @param Inventory            $inventory    Inventory.
	 * @param Settings             $settings     Plugin settings.
	 * @param CapabilityService    $capabilities Capability service.
	 */
	public function __construct( BulkJobRepository $jobs, EligibilityQuery $eligibility, JobProgress $progress, AttachmentRepository $attachments, ConversionQueue $queue, ImageCleanupService $cleanup, Inventory $inventory, Settings $settings, CapabilityService $capabilities ) {
		$this->jobs         = $jobs;
		$this->eligibility  = $eligibility;
		$this->progress     = $progress;
		$this->attachments  = $attachments;
		$this->queue        = $queue;
		$this->cleanup      = $cleanup;
		$this->inventory    = $inventory;
		$this->settings     = $settings;
		$this->capabilities = $capabilities;
	}

	/**
	 * Register the producer action and the recovery of abandoned jobs.
	 */
	public function register() {
		add_action( self::HOOK_PRODUCE, array( $this, 'produce' ), 10, 1 );
		add_action( 'admin_init', array( $this, 'recover_stale_jobs' ) );
	}

	/**
	 * Create a job and start it.
	 *
	 * @param string $type Job type (BulkJob::TYPE_*).
	 * @return BulkJob|false False when another job is active.
	 */
	public function launch( $type ) {
		$total = BulkJob::TYPE_REMOVE === $type ? $this->eligibility->count_plugin_managed_attachments() : $this->eligibility->count_eligible_attachments();
		$job   = $this->jobs->create( $type, OptimizationSettings::from_options( $this->settings, $this->capabilities )->to_array(), $total );

		if ( ! $job ) {
			return false;
		}

		$this->resume( $job->get_id() );

		return $this->jobs->get( $job->get_id() );
	}

	/**
	 * Start a job, or continue a paused one.
	 *
	 * @param int $job_id Job ID.
	 * @return bool
	 */
	public function resume( $job_id ) {
		if ( ! $this->jobs->mark_running( $job_id ) ) {
			return false;
		}

		$this->schedule( $job_id );

		return true;
	}

	/**
	 * Pause a job. Attachments already on the queue are still processed.
	 *
	 * @param int $job_id Job ID.
	 * @return bool
	 */
	public function pause( $job_id ) {
		$paused = $this->jobs->pause( $job_id );

		if ( $paused ) {
			$this->unschedule( $job_id );
		}

		return $paused;
	}

	/**
	 * Cancel a job. Attachments already on the queue are still processed.
	 *
	 * @param int $job_id Job ID.
	 * @return bool
	 */
	public function cancel( $job_id ) {
		$cancelled = $this->jobs->cancel( $job_id );

		if ( $cancelled ) {
			$this->unschedule( $job_id );
		}

		return $cancelled;
	}

	/**
	 * Move jobs whose producer vanished to "paused".
	 *
	 * @return int Number of jobs recovered.
	 */
	public function recover_stale_jobs() {
		return $this->jobs->recover_stale_running();
	}

	/**
	 * Action Scheduler callback: hand the next batch over, then reschedule or finish the job.
	 *
	 * @param int $job_id Job ID.
	 */
	public function produce( $job_id ) {
		$job = $this->jobs->get( (int) $job_id );

		if ( ! $job || JobStatus::RUNNING !== $job->get_status() ) {
			return;
		}

		if ( BulkJob::TYPE_SYNC === $job->get_type() ) {
			if ( DiskSpace::is_low() ) {
				$this->jobs->pause( $job->get_id(), self::REASON_LOW_DISK );
				return;
			}

			if ( $this->has_backlog() ) {
				$this->wait( $job->get_id() );
				return;
			}
		}

		$limit    = self::batch_size();
		$ids      = $this->next_ids( $job, $limit );
		$deadline = microtime( true ) + max( 1, (int) apply_filters( 'trust_optimize_bulk_time_budget', self::DEFAULT_TIME_BUDGET ) );
		$handled  = 0;

		foreach ( $ids as $attachment_id ) {
			$this->hand_over( $job, $attachment_id );
			$this->jobs->set_cursor( $job->get_id(), $attachment_id );
			++$handled;

			if ( microtime( true ) >= $deadline ) {
				break;
			}
		}

		if ( BulkJob::TYPE_INVENTORY === $job->get_type() ) {
			$this->record_inspection( $job, array_slice( $ids, 0, $handled ) );
		}

		$job = $this->jobs->get( $job->get_id() );

		// The job may have been paused or cancelled meanwhile.
		if ( ! $job || JobStatus::RUNNING !== $job->get_status() ) {
			return;
		}

		// Fewer IDs than asked for, all handled: nothing is left behind the cursor.
		if ( count( $ids ) === $handled && $handled < $limit ) {
			$this->finish_or_wait( $job );
			return;
		}

		$this->schedule( $job->get_id() );
	}

	/**
	 * Most conversion tasks the producer leaves waiting on the queue.
	 *
	 * @return int
	 */
	public static function max_pending() {
		return max( 1, (int) apply_filters( 'trust_optimize_bulk_max_pending', self::DEFAULT_MAX_PENDING ) );
	}

	/**
	 * Number of attachments per run.
	 *
	 * @return int
	 */
	public static function batch_size() {
		$size = (int) apply_filters( 'trust_optimize_bulk_batch_size', self::DEFAULT_BATCH_SIZE );

		return max( 1, min( self::MAX_BATCH_SIZE, $size ) );
	}

	/**
	 * Cancel the scheduled producers of every job.
	 */
	public static function cancel_all_tasks() {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK_PRODUCE, null, ConversionQueue::GROUP );
		}
	}

	/**
	 * Do the work of the job for one attachment.
	 *
	 * Sync marks the attachment with the job and queues it; remove deletes through the cleanup
	 * service, which guards legacy files (D-15). A failure of one attachment never stops the job.
	 *
	 * @param BulkJob $job           Job.
	 * @param int     $attachment_id Attachment ID.
	 */
	private function hand_over( BulkJob $job, $attachment_id ) {
		try {
			if ( BulkJob::TYPE_REMOVE === $job->get_type() ) {
				$this->cleanup->cleanup_attachment( $attachment_id );
			} elseif ( BulkJob::TYPE_SYNC === $job->get_type() ) {
				$this->attachments->assign_job( $attachment_id, $job->get_id() );
				$this->queue->plan_and_enqueue( $attachment_id );
			}
		} catch ( Throwable $throwable ) {
			$this->attachments->set_state( $attachment_id, AttachmentState::FAILED, 'exception', $throwable->getMessage() );
		}
	}

	/**
	 * Next attachment IDs after the cursor, for the type of the job.
	 *
	 * @param BulkJob $job   Job.
	 * @param int     $limit Maximum number of IDs.
	 * @return int[]
	 */
	private function next_ids( BulkJob $job, $limit ) {
		if ( BulkJob::TYPE_REMOVE === $job->get_type() ) {
			return $this->eligibility->get_next_plugin_managed_attachment_ids( $job->get_cursor_id(), $limit );
		}

		return $this->eligibility->get_next_attachment_ids( $job->get_cursor_id(), $limit );
	}

	/**
	 * The cursor is exhausted: complete the job, or wait for its tasks on the queue.
	 *
	 * @param BulkJob $job Job.
	 */
	private function finish_or_wait( BulkJob $job ) {
		if ( $this->progress->in_flight( $job ) > 0 ) {
			$this->wait( $job->get_id() );
			return;
		}

		if ( BulkJob::TYPE_INVENTORY === $job->get_type() ) {
			$this->jobs->merge_snapshot( $job->get_id(), 'inventory', $this->inventory->summary() );
		}

		$this->jobs->complete( $job->get_id(), $this->progress->has_errors( $job ) );
	}

	/**
	 * Add what the inventory found in a batch to the totals stored with the job.
	 *
	 * @param BulkJob $job Inventory job.
	 * @param int[]   $ids Attachment IDs of the batch.
	 */
	private function record_inspection( BulkJob $job, array $ids ) {
		$totals = $job->get_settings_snapshot()['inventory'] ?? array();

		foreach ( $this->inventory->inspect( $ids ) as $key => $count ) {
			$totals[ $key ] = (int) ( $totals[ $key ] ?? 0 ) + $count;
		}

		$this->jobs->merge_snapshot( $job->get_id(), 'inventory', $totals );
	}

	/**
	 * Whether more attachment tasks wait than the queue should hold.
	 *
	 * @return bool
	 */
	private function has_backlog() {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			return false;
		}

		$max = self::max_pending();

		return count(
			as_get_scheduled_actions(
				array(
					'hook'     => ConversionQueue::HOOK_PROCESS,
					'group'    => ConversionQueue::GROUP,
					'status'   => \ActionScheduler_Store::STATUS_PENDING,
					'per_page' => $max + 1,
					'orderby'  => 'none',
				),
				'ids'
			)
		) > $max;
	}

	/**
	 * Look at the job again later, keeping it marked as alive.
	 *
	 * @param int $job_id Job ID.
	 */
	private function wait( $job_id ) {
		$this->jobs->touch( $job_id );
		$this->schedule( $job_id, self::WAIT_SECONDS );
	}

	/**
	 * Schedule the next producer run unless one is already waiting.
	 *
	 * The running action itself is not "pending", so a producer can schedule its successor.
	 *
	 * @param int $job_id Job ID.
	 * @param int $delay  Seconds from now.
	 */
	private function schedule( $job_id, $delay = 0 ) {
		if ( ! function_exists( 'as_enqueue_async_action' ) || $this->has_pending_producer( $job_id ) ) {
			return;
		}

		if ( $delay > 0 ) {
			as_schedule_single_action( time() + $delay, self::HOOK_PRODUCE, array( 'job_id' => (int) $job_id ), ConversionQueue::GROUP );
			return;
		}

		as_enqueue_async_action( self::HOOK_PRODUCE, array( 'job_id' => (int) $job_id ), ConversionQueue::GROUP );
	}

	/**
	 * Whether a producer run of the job is scheduled.
	 *
	 * @param int $job_id Job ID.
	 * @return bool
	 */
	private function has_pending_producer( $job_id ) {
		return ! empty(
			as_get_scheduled_actions(
				array(
					'hook'     => self::HOOK_PRODUCE,
					'args'     => array( 'job_id' => (int) $job_id ),
					'group'    => ConversionQueue::GROUP,
					'status'   => \ActionScheduler_Store::STATUS_PENDING,
					'per_page' => 1,
				),
				'ids'
			)
		);
	}

	/**
	 * Remove the scheduled producer runs of a job.
	 *
	 * @param int $job_id Job ID.
	 */
	private function unschedule( $job_id ) {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK_PRODUCE, array( 'job_id' => (int) $job_id ), ConversionQueue::GROUP );
		}
	}
}
