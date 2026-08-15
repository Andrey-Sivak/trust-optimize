<?php
/**
 * Conversion Queue class
 *
 * Manages asynchronous image conversion tasks via Action Scheduler.
 *
 * @package TrustOptimize\Queue
 */

namespace TrustOptimize\Queue;

use TrustOptimize\Features\Optimization\ImageConverter;
use TrustOptimize\Database\ImageModel;

/**
 * Class ConversionQueue
 */
class ConversionQueue {

	/**
	 * Action Scheduler hook name for individual conversion tasks.
	 */
	const HOOK_CONVERT = 'trust_optimize_convert_image';

	/**
	 * Action Scheduler group name.
	 */
	const GROUP = 'trust-optimize';

	/**
	 * Image converter instance.
	 *
	 * @var ImageConverter
	 */
	protected $converter;

	/**
	 * Image model instance.
	 *
	 * @var ImageModel
	 */
	protected $image_model;

	/**
	 * Constructor.
	 *
	 * @param ImageConverter $converter Image converter instance.
	 */
	public function __construct( ImageConverter $converter ) {
		$this->converter   = $converter;
		$this->image_model = new ImageModel();
	}

	/**
	 * Register the Action Scheduler hook for processing tasks
	 * and ensure the queue runner is triggered on admin page loads.
	 */
	public function init() {
		add_action( self::HOOK_CONVERT, array( $this, 'process_task' ), 10, 4 );

		// Trigger Action Scheduler queue processing on admin page loads.
		// This serves as a fallback for environments where WP-Cron loopback
		// requests fail (e.g., Docker, reverse proxies, firewalls).
		add_action( 'admin_init', array( $this, 'register_shutdown_dispatch' ) );
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
	 * Action Scheduler's own runner handles concurrency and batch limits.
	 */
	public function maybe_dispatch_queue() {
		if ( ! function_exists( 'as_has_scheduled_action' ) ) {
			return;
		}

		$has_pending = as_has_scheduled_action( self::HOOK_CONVERT, null, self::GROUP );

		if ( $has_pending && class_exists( 'ActionScheduler_QueueRunner' ) ) {
			\ActionScheduler_QueueRunner::instance()->run();
		}
	}

	/**
	 * Schedule conversion tasks for all sizes and formats of an attachment.
	 *
	 * @param int   $attachment_id       The attachment ID.
	 * @param array $size_names          Array of size names to convert (e.g., ['original', 'thumbnail', 'medium']).
	 * @param array $conversion_strategies Array of strategies with 'target_format' and 'target_mime' keys.
	 */
	public function schedule_conversions( $attachment_id, array $size_names, array $conversion_strategies ) {
		foreach ( $size_names as $size_name ) {
			foreach ( $conversion_strategies as $strategy ) {
				as_enqueue_async_action(
					self::HOOK_CONVERT,
					array(
						$attachment_id,
						$size_name,
						$strategy['target_format'],
						$strategy['target_mime'],
					),
					self::GROUP
				);
			}
		}
	}

	/**
	 * Process a single conversion task.
	 *
	 * Called by Action Scheduler when the task is ready to run.
	 *
	 * @param int    $attachment_id The attachment ID.
	 * @param string $size_name     The size name (e.g., 'original', 'thumbnail').
	 * @param string $target_format The target format (e.g., 'webp', 'avif').
	 * @param string $target_mime   The target MIME type (e.g., 'image/webp').
	 */
	public function process_task( $attachment_id, $size_name, $target_format, $target_mime ) {
		$result = $this->converter->convert_single_size( $attachment_id, $size_name, $target_format, $target_mime );

		if ( $result ) {
			$this->image_model->increment_completed_tasks( $attachment_id );
		} else {
			// Even on failure, count the task as completed to avoid stuck queues.
			$this->image_model->increment_completed_tasks( $attachment_id );

			error_log(
				sprintf(
					'TrustOptimize: Background conversion failed for attachment %d, size "%s", format "%s".',
					$attachment_id,
					$size_name,
					$target_format
				)
			);
		}
	}

	/**
	 * Cancel all pending conversion tasks for a specific attachment.
	 *
	 * @param int $attachment_id The attachment ID.
	 */
	public function cancel_attachment_tasks( $attachment_id ) {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			// Unschedule all pending tasks for this attachment across all formats/sizes.
			as_unschedule_all_actions( self::HOOK_CONVERT, null, self::GROUP );
		}
	}
}
