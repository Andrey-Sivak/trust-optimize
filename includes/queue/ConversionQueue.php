<?php
/**
 * Conversion Queue class
 *
 * Manages asynchronous image conversion via Action Scheduler: one action per attachment.
 *
 * @package TrustOptimize\Queue
 */

namespace TrustOptimize\Queue;

use Throwable;
use TrustOptimize\Domain\AttachmentState;
use TrustOptimize\Planning\VariantPlanner;
use TrustOptimize\Processing\AttachmentProcessor;
use TrustOptimize\Storage\AttachmentRepository;

/**
 * Class ConversionQueue
 */
class ConversionQueue {

	/**
	 * Action Scheduler hook that processes one attachment.
	 */
	const HOOK_PROCESS = 'trust_optimize_process_attachment';

	/**
	 * Action Scheduler group name.
	 */
	const GROUP = 'trust-optimize';

	/**
	 * Attachment repository.
	 *
	 * @var AttachmentRepository
	 */
	private $attachments;

	/**
	 * Attachment processor.
	 *
	 * @var AttachmentProcessor
	 */
	private $processor;

	/**
	 * Variant planner.
	 *
	 * @var VariantPlanner
	 */
	private $planner;

	/**
	 * Constructor.
	 *
	 * @param AttachmentRepository $attachments Attachment repository.
	 * @param AttachmentProcessor  $processor   Attachment processor.
	 * @param VariantPlanner       $planner     Variant planner.
	 */
	public function __construct( AttachmentRepository $attachments, AttachmentProcessor $processor, VariantPlanner $planner ) {
		$this->attachments = $attachments;
		$this->processor   = $processor;
		$this->planner     = $planner;
	}

	/**
	 * Register the Action Scheduler hook and the upload handler.
	 */
	public function register() {
		add_action( self::HOOK_PROCESS, array( $this, 'process' ), 10, 1 );
		add_filter( 'wp_generate_attachment_metadata', array( $this, 'handle_new_metadata' ), 20, 2 );
	}

	/**
	 * Plan and queue an attachment whose metadata was just generated.
	 *
	 * @param array $metadata      Attachment metadata (returned unchanged).
	 * @param int   $attachment_id Attachment ID.
	 * @return array
	 */
	public function handle_new_metadata( $metadata, $attachment_id ) {
		$this->plan_and_enqueue( $attachment_id );

		return $metadata;
	}

	/**
	 * Plan an attachment, remove what is no longer wanted and queue the work that is left.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool True when work was queued.
	 */
	public function plan_and_enqueue( $attachment_id ) {
		$plan = $this->planner->plan( $attachment_id );
		$this->processor->apply_removals( $attachment_id, $plan );

		if ( $plan->has_work() ) {
			return $this->enqueue( $attachment_id );
		}

		if ( ! $plan->is_skipped() ) {
			$this->attachments->recompute( $attachment_id );
		}

		return false;
	}

	/**
	 * Queue an attachment unless it is already queued or being processed.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool True when an action was scheduled.
	 */
	public function enqueue( $attachment_id ) {
		if ( ! function_exists( 'as_enqueue_async_action' ) || ! $this->attachments->mark_queued( $attachment_id ) ) {
			return false;
		}

		return $this->schedule( $attachment_id );
	}

	/**
	 * Queue an attachment again whose task was lost or whose worker died.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool True when an action was scheduled.
	 */
	public function requeue( $attachment_id ) {
		return function_exists( 'as_enqueue_async_action' )
			&& $this->attachments->requeue( $attachment_id, AttachmentRepository::STALE_CLAIM_SECONDS )
			&& $this->schedule( $attachment_id );
	}

	/**
	 * Action Scheduler callback: process one attachment, continuing in a new action when the time budget ran out.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @throws Throwable Whatever the processor threw, after the attachment was marked failed.
	 */
	public function process( $attachment_id ) {
		$attachment_id = (int) $attachment_id;

		try {
			$result = $this->processor->run( $attachment_id );
		} catch ( Throwable $throwable ) {
			// Only this attachment fails; Action Scheduler records the failed action.
			$this->attachments->set_state( $attachment_id, AttachmentState::FAILED, 'exception', $throwable->getMessage() );

			throw $throwable;
		}

		$data = $result->get_data();

		if ( ! empty( $data['more'] ) ) {
			$this->schedule( $attachment_id );
		}
	}

	/**
	 * Cancel pending tasks of an attachment.
	 *
	 * @param int $attachment_id The attachment ID.
	 */
	public function cancel_attachment_tasks( $attachment_id ) {
		self::cancel_tasks_for_attachment( $attachment_id );
	}

	/**
	 * Cancel pending tasks of an attachment.
	 *
	 * @param int $attachment_id The attachment ID.
	 */
	public static function cancel_tasks_for_attachment( $attachment_id ) {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK_PROCESS, array( 'attachment_id' => (int) $attachment_id ), self::GROUP );
		}
	}

	/**
	 * Cancel all pending TrustOptimize conversion tasks.
	 */
	public static function cancel_all_tasks() {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK_PROCESS, null, self::GROUP );
		}
	}

	/**
	 * Schedule the action for an attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool
	 */
	private function schedule( $attachment_id ) {
		return 0 !== as_enqueue_async_action( self::HOOK_PROCESS, array( 'attachment_id' => (int) $attachment_id ), self::GROUP );
	}
}
