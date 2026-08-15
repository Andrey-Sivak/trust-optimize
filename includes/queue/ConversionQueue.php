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
use TrustOptimize\Service\ImageOptimizationService;
use TrustOptimize\Value\ImageVariant;

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
	 * Optimization service instance.
	 *
	 * @var ImageOptimizationService
	 */
	protected $optimization;

	/**
	 * Constructor.
	 *
	 * @param ImageConverter $converter Image converter instance.
	 */
	public function __construct( ImageConverter $converter ) {
		$this->converter    = $converter;
		$this->image_model  = new ImageModel();
		$this->optimization = new ImageOptimizationService( $this->converter, $this->image_model );
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
	 * Schedule conversion tasks for specific image variants.
	 *
	 * @param int   $attachment_id The attachment ID.
	 * @param array $variants      Variant plan records or ImageVariant instances.
	 * @return int Number of scheduled variants.
	 */
	public function schedule_variants( $attachment_id, array $variants ) {
		$scheduled = 0;

		foreach ( $variants as $variant ) {
			if ( $this->schedule_variant_conversion( $attachment_id, $variant ) ) {
				++$scheduled;
			}
		}

		return $scheduled;
	}

	/**
	 * Schedule conversion for one concrete image variant.
	 *
	 * @param int                $attachment_id The attachment ID.
	 * @param array|ImageVariant $variant       Variant payload.
	 * @return bool True when the action was scheduled.
	 */
	public function schedule_variant_conversion( $attachment_id, $variant ) {
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			return false;
		}

		$payload = $this->normalize_variant_payload( $attachment_id, $variant );

		if ( empty( $payload['size_name'] ) || empty( $payload['target_format'] ) || empty( $payload['target_mime'] ) ) {
			return false;
		}

		as_enqueue_async_action(
			self::HOOK_CONVERT,
			array( $payload ),
			self::GROUP
		);

		return true;
	}

	/**
	 * Schedule conversion tasks for all sizes and formats of an attachment.
	 *
	 * Backward-compatible wrapper for legacy callers. New code should schedule
	 * explicit variants to avoid accidental size x format fan-out.
	 *
	 * @param int   $attachment_id          The attachment ID.
	 * @param array $size_names             Array of size names to convert.
	 * @param array $conversion_strategies  Conversion strategies.
	 */
	public function schedule_conversions( $attachment_id, array $size_names, array $conversion_strategies ) {
		foreach ( $size_names as $size_name ) {
			foreach ( $conversion_strategies as $strategy ) {
				$this->schedule_variant_conversion(
					$attachment_id,
					array(
						'size_name'     => $size_name,
						'target_format' => isset( $strategy['target_format'] ) ? $strategy['target_format'] : '',
						'target_mime'   => isset( $strategy['target_mime'] ) ? $strategy['target_mime'] : '',
					)
				);
			}
		}
	}

	/**
	 * Process a single conversion task.
	 *
	 * Called by Action Scheduler when the task is ready to run. Supports the new
	 * single-payload variant format and already scheduled legacy positional args.
	 *
	 * @param int|array $attachment_id The attachment ID or variant payload.
	 * @param string    $size_name     The size name (legacy payload).
	 * @param string    $target_format The target format (legacy payload).
	 * @param string    $target_mime   The target MIME type (legacy payload).
	 */
	public function process_task( $attachment_id, $size_name = null, $target_format = null, $target_mime = null ) {
		$payload       = $this->normalize_task_payload( $attachment_id, $size_name, $target_format, $target_mime );
		$attachment_id = $payload['attachment_id'];
		$size_name     = $payload['size_name'];
		$target_format = $payload['target_format'];
		$target_mime   = $payload['target_mime'];

		if ( empty( $attachment_id ) || empty( $size_name ) || empty( $target_format ) || empty( $target_mime ) ) {
			return;
		}

		$this->optimization->process_variant_conversion( $attachment_id, $size_name, $target_format, $target_mime );
	}

	/**
	 * Normalize a variant into the Action Scheduler payload shape.
	 *
	 * @param int                $attachment_id Attachment ID.
	 * @param array|ImageVariant $variant       Variant data.
	 * @return array Normalized payload.
	 */
	private function normalize_variant_payload( $attachment_id, $variant ) {
		if ( $variant instanceof ImageVariant ) {
			$variant = $variant->to_array();
		}

		if ( ! is_array( $variant ) ) {
			$variant = array();
		}

		$payload = array(
			'attachment_id' => (int) $attachment_id,
			'size_name'     => isset( $variant['size_name'] ) ? (string) $variant['size_name'] : '',
			'target_format' => isset( $variant['target_format'] ) ? (string) $variant['target_format'] : '',
			'target_mime'   => isset( $variant['target_mime'] ) ? (string) $variant['target_mime'] : '',
		);

		foreach ( array( 'quality', 'source_path', 'target_path', 'size_info', 'profile_hash' ) as $key ) {
			if ( array_key_exists( $key, $variant ) ) {
				$payload[ $key ] = $variant[ $key ];
			}
		}

		return $payload;
	}

	/**
	 * Normalize current and legacy Action Scheduler task payloads.
	 *
	 * @param int|array $attachment_id Attachment ID or new variant payload.
	 * @param string    $size_name     Legacy size name.
	 * @param string    $target_format Legacy target format.
	 * @param string    $target_mime   Legacy target MIME.
	 * @return array Normalized payload.
	 */
	private function normalize_task_payload( $attachment_id, $size_name = null, $target_format = null, $target_mime = null ) {
		if ( is_array( $attachment_id ) ) {
			return $this->normalize_variant_payload(
				isset( $attachment_id['attachment_id'] ) ? (int) $attachment_id['attachment_id'] : 0,
				$attachment_id
			);
		}

		return $this->normalize_variant_payload(
			(int) $attachment_id,
			array(
				'size_name'     => $size_name,
				'target_format' => $target_format,
				'target_mime'   => $target_mime,
			)
		);
	}

	/**
	 * Cancel all pending conversion tasks for a specific attachment.
	 *
	 * @param int $attachment_id The attachment ID.
	 */
	public function cancel_attachment_tasks( $attachment_id ) {
		self::cancel_tasks_for_attachment( $attachment_id );
	}

	/**
	 * Cancel pending conversion tasks for a specific attachment.
	 *
	 * @param int $attachment_id The attachment ID.
	 */
	public static function cancel_tasks_for_attachment( $attachment_id ) {
		if ( ! function_exists( 'as_get_scheduled_actions' ) || ! function_exists( 'as_unschedule_action' ) ) {
			return;
		}

		$actions = as_get_scheduled_actions(
			array(
				'hook'     => self::HOOK_CONVERT,
				'group'    => self::GROUP,
				'status'   => \ActionScheduler_Store::STATUS_PENDING,
				'per_page' => -1,
			)
		);

		foreach ( $actions as $action ) {
			if ( ! is_object( $action ) || ! is_callable( array( $action, 'get_args' ) ) ) {
				continue;
			}

			$args = $action->get_args();
			if ( empty( $args ) || self::get_attachment_id_from_action_args( $args ) !== (int) $attachment_id ) {
				continue;
			}

			as_unschedule_action( self::HOOK_CONVERT, $args, self::GROUP );
		}
	}

	/**
	 * Extract attachment ID from current or legacy scheduled action args.
	 *
	 * @param array $args Action Scheduler args.
	 * @return int Attachment ID or zero.
	 */
	private static function get_attachment_id_from_action_args( array $args ) {
		$first = reset( $args );

		if ( is_array( $first ) ) {
			return isset( $first['attachment_id'] ) ? (int) $first['attachment_id'] : 0;
		}

		return (int) $first;
	}

	/**
	 * Cancel all pending TrustOptimize conversion tasks.
	 */
	public static function cancel_all_tasks() {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK_CONVERT, null, self::GROUP );
		}
	}
}
