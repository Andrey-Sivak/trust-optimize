<?php
/**
 * Conversion Queue class
 *
 * Manages asynchronous image conversion via Action Scheduler: one action per attachment.
 *
 * @package TrustOptimize\Queue
 */

namespace TrustOptimize\Queue;

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
	 * Per-variant hook of schema 1.x; its tasks are turned into attachment tasks (removed in 03.6).
	 */
	const HOOK_CONVERT = 'trust_optimize_convert_image';

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
	 * Register the Action Scheduler hooks and the upload handler.
	 *
	 * The queue runner is also triggered on admin page loads as a fallback for
	 * environments where WP-Cron loopback requests fail (removed in 04.1).
	 */
	public function register() {
		add_action( self::HOOK_PROCESS, array( $this, 'process' ), 10, 1 );
		add_action( self::HOOK_CONVERT, array( $this, 'process_legacy_task' ), 10, 4 );
		add_filter( 'wp_generate_attachment_metadata', array( $this, 'handle_new_metadata' ), 20, 2 );
		add_action( 'admin_init', array( $this, 'register_shutdown_dispatch' ) );
	}

	/**
	 * Plan and queue an attachment whose metadata was just generated.
	 *
	 * @param array $metadata      Attachment metadata (returned unchanged).
	 * @param int   $attachment_id Attachment ID.
	 * @return array
	 */
	public function handle_new_metadata( $metadata, $attachment_id ) {
		$plan = $this->planner->plan( $attachment_id );
		$this->processor->apply_removals( $attachment_id, $plan );

		if ( $plan->has_work() ) {
			$this->enqueue( $attachment_id );
		} elseif ( ! $plan->is_skipped() ) {
			$this->attachments->recompute( $attachment_id );
		}

		return $metadata;
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
	 * Action Scheduler callback: process one attachment, continuing in a new action when the time budget ran out.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	public function process( $attachment_id ) {
		$result = $this->processor->run( (int) $attachment_id );
		$data   = $result->get_data();

		if ( ! empty( $data['more'] ) ) {
			$this->schedule( (int) $attachment_id );
		}
	}

	/**
	 * Turn a schema 1.x per-variant task into an attachment task (removed in 03.6).
	 *
	 * @param int|array $payload Attachment ID or the old variant payload.
	 */
	public function process_legacy_task( $payload ) {
		$attachment_id = is_array( $payload ) ? (int) ( $payload['attachment_id'] ?? 0 ) : (int) $payload;

		if ( $attachment_id > 0 ) {
			$this->enqueue( $attachment_id );
		}
	}

	/**
	 * Register the shutdown dispatch if we have pending tasks.
	 *
	 * Hooked to 'admin_init' to ensure proper admin context.
	 */
	public function register_shutdown_dispatch() {
		if ( wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}

		add_action( 'shutdown', array( $this, 'maybe_dispatch_queue' ) );
	}

	/**
	 * Dispatch pending Action Scheduler tasks if any exist.
	 *
	 * Runs at the 'shutdown' hook to avoid impacting page response times.
	 */
	public function maybe_dispatch_queue() {
		if ( ! function_exists( 'as_has_scheduled_action' ) ) {
			return;
		}

		$has_pending = as_has_scheduled_action( self::HOOK_PROCESS, null, self::GROUP ) || as_has_scheduled_action( self::HOOK_CONVERT, null, self::GROUP );

		if ( $has_pending && class_exists( 'ActionScheduler_QueueRunner' ) ) {
			\ActionScheduler_QueueRunner::instance()->run();
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
			as_unschedule_all_actions( self::HOOK_CONVERT, null, self::GROUP );
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
