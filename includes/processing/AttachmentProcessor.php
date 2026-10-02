<?php
/**
 * Converts the pending variants of one attachment.
 *
 * @package TrustOptimize
 */

namespace TrustOptimize\Processing;

use TrustOptimize\Admin\Settings;
use TrustOptimize\Capabilities\CapabilityService;
use TrustOptimize\Domain\AttachmentState;
use TrustOptimize\Domain\VariantStatus;
use TrustOptimize\Features\Optimization\ImageConverter;
use TrustOptimize\Planning\VariantPlanner;
use TrustOptimize\Service\ImageCleanupService;
use TrustOptimize\Settings\OptimizationSettings;
use TrustOptimize\Storage\AttachmentRepository;
use TrustOptimize\Storage\VariantRepository;
use TrustOptimize\Value\OptimizeResult;

/**
 * Class AttachmentProcessor
 */
class AttachmentProcessor {

	/**
	 * Default time budget of one run, in seconds.
	 */
	const DEFAULT_TIME_BUDGET = 20;

	/**
	 * Attachment repository.
	 *
	 * @var AttachmentRepository
	 */
	private $attachments;

	/**
	 * Variant repository.
	 *
	 * @var VariantRepository
	 */
	private $variants;

	/**
	 * Image converter.
	 *
	 * @var ImageConverter
	 */
	private $converter;

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
	 * Variant planner.
	 *
	 * @var VariantPlanner
	 */
	private $planner;

	/**
	 * Cleanup service.
	 *
	 * @var ImageCleanupService
	 */
	private $cleanup;

	/**
	 * Constructor.
	 *
	 * @param AttachmentRepository $attachments  Attachment repository.
	 * @param VariantRepository    $variants     Variant repository.
	 * @param ImageConverter       $converter    Image converter.
	 * @param VariantPlanner       $planner      Variant planner.
	 * @param ImageCleanupService  $cleanup      Cleanup service.
	 * @param Settings             $settings     Plugin settings.
	 * @param CapabilityService    $capabilities Capability service.
	 */
	public function __construct( AttachmentRepository $attachments, VariantRepository $variants, ImageConverter $converter, VariantPlanner $planner, ImageCleanupService $cleanup, Settings $settings, CapabilityService $capabilities ) {
		$this->attachments  = $attachments;
		$this->variants     = $variants;
		$this->converter    = $converter;
		$this->planner      = $planner;
		$this->cleanup      = $cleanup;
		$this->settings     = $settings;
		$this->capabilities = $capabilities;
	}

	/**
	 * Bring an attachment up to date right now: plan, remove what is no longer wanted, convert.
	 *
	 * This is the synchronous path used by REST, WP-CLI and bulk jobs; the queue uses run().
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return OptimizeResult Data: 'state', 'deleted' (files removed) and, for a skipped attachment, 'reason'.
	 */
	public function sync( $attachment_id ) {
		$plan    = $this->planner->plan( $attachment_id );
		$deleted = 0;

		$removals = array_filter(
			array(
				$plan->to_delete() ? $this->cleanup->cleanup_variants( $attachment_id, $plan->to_delete() ) : null,
				$plan->replaced() ? $this->cleanup->cleanup_replaced_files( $attachment_id, $plan->replaced() ) : null,
			)
		);
		foreach ( $removals as $removal ) {
			$deleted += count( $removal->get_data()['deleted'] ?? array() );
		}

		if ( $plan->is_skipped() ) {
			return OptimizeResult::skipped( $plan->skip_reason(), array( 'deleted' => $deleted ) );
		}

		if ( ! $plan->has_work() ) {
			$state = $this->attachments->recompute( $attachment_id );

			return OptimizeResult::success(
				'up_to_date',
				array(
					'state'   => $state,
					'deleted' => $deleted,
				)
			);
		}

		return $this->with_data( $this->run( $attachment_id, INF ), array( 'deleted' => $deleted ) );
	}

	/**
	 * Copy of a result with extra data.
	 *
	 * @param OptimizeResult $result Result.
	 * @param array          $extra  Extra data.
	 * @return OptimizeResult
	 */
	private function with_data( OptimizeResult $result, array $extra ) {
		$data = array_merge( $result->get_data(), $extra );

		if ( $result->is_success() ) {
			return OptimizeResult::success( $result->get_message(), $data );
		}
		if ( $result->is_partial() ) {
			return OptimizeResult::partial( $result->get_message(), $result->get_errors(), $data );
		}
		if ( $result->is_failed() ) {
			return OptimizeResult::failed( $result->get_message(), $result->get_errors(), $data );
		}

		return OptimizeResult::skipped( $result->get_message(), $data );
	}

	/**
	 * Convert the pending variants of an attachment, one by one.
	 *
	 * The attachment is claimed first, so two workers never process it together.
	 * When the time budget runs out the rest stays pending and the result asks for
	 * another run (data 'more'). The aggregate state is recomputed at the end.
	 *
	 * @param int        $attachment_id Attachment ID.
	 * @param float|null $budget        Time budget in seconds; null uses the filterable default.
	 * @return OptimizeResult Data: 'state' (AttachmentState) and 'more' (bool).
	 */
	public function run( $attachment_id, $budget = null ) {
		if ( ! $this->attachments->claim( $attachment_id ) ) {
			return OptimizeResult::skipped( 'busy', array( 'more' => false ) );
		}

		// This worker owns the attachment, so a row stuck in "processing" belongs to a crashed run.
		foreach ( $this->variants->get_for_attachment( $attachment_id ) as $row ) {
			if ( VariantStatus::PROCESSING === $row['status'] ) {
				$this->variants->transition( $row['id'], VariantStatus::PROCESSING, VariantStatus::PENDING );
			}
		}

		$settings = OptimizationSettings::from_options( $this->settings, $this->capabilities );
		$deadline = microtime( true ) + ( null === $budget ? $this->time_budget() : $budget );
		$last_err = null;
		$more     = false;

		foreach ( $this->variants->get_for_attachment( $attachment_id ) as $row ) {
			if ( VariantStatus::PENDING !== $row['status'] ) {
				continue;
			}

			if ( microtime( true ) >= $deadline ) {
				$more = true;
				break;
			}

			$result = $this->converter->convert( $row, $settings );
			if ( $result->is_failed() ) {
				$errors   = $result->get_errors();
				$last_err = $errors ? (string) reset( $errors ) : $result->get_message();
			}
		}

		$state = $this->attachments->recompute( $attachment_id );

		if ( null !== $last_err ) {
			$this->attachments->set_state( $attachment_id, $state, $this->failure_reason( $attachment_id ), $last_err );
		}

		$data = array(
			'state' => $state,
			'more'  => $more,
		);

		switch ( $state ) {
			case AttachmentState::OPTIMIZED:
				return OptimizeResult::success( $state, $data );
			case AttachmentState::PARTIAL:
				return OptimizeResult::partial( $state, array(), $data );
			case AttachmentState::FAILED:
				return OptimizeResult::failed( $state, array(), $data );
			default:
				return $more ? OptimizeResult::success( $state, $data ) : OptimizeResult::skipped( $state, $data );
		}
	}

	/**
	 * Reason of the first failed variant, kept on the attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string|null
	 */
	private function failure_reason( $attachment_id ) {
		foreach ( $this->variants->get_for_attachment( $attachment_id ) as $row ) {
			if ( VariantStatus::FAILED === $row['status'] ) {
				return $row['reason'];
			}
		}

		return null;
	}

	/**
	 * Time budget of one run.
	 *
	 * @return float Seconds.
	 */
	private function time_budget() {
		return max( 0.0, (float) apply_filters( 'trust_optimize_worker_time_budget', self::DEFAULT_TIME_BUDGET ) );
	}
}
