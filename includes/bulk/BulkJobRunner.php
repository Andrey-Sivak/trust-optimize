<?php
/**
 * Resumable bulk job runner.
 *
 * @package TrustOptimize\Bulk
 */

namespace TrustOptimize\Bulk;

use Throwable;
use TrustOptimize\Service\ImageCleanupService;
use TrustOptimize\Service\ImageOptimizationService;
use TrustOptimize\Value\DeleteResult;
use TrustOptimize\Value\OptimizeResult;

/**
 * Class BulkJobRunner
 */
class BulkJobRunner {

	const HOOK_BULK_TICK = 'trust_optimize_bulk_tick';
	const GROUP          = 'trust-optimize';

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
	 * Optimization service.
	 *
	 * @var ImageOptimizationService
	 */
	private $optimization;

	/**
	 * Cleanup service.
	 *
	 * @var ImageCleanupService
	 */
	private $cleanup;

	/**
	 * Constructor.
	 *
	 * @param BulkJobRepository|null        $jobs         Job repository.
	 * @param EligibilityQuery|null         $eligibility  Eligibility query.
	 * @param ImageOptimizationService|null $optimization Optimization service.
	 * @param ImageCleanupService|null      $cleanup      Cleanup service.
	 */
	public function __construct( BulkJobRepository $jobs = null, EligibilityQuery $eligibility = null, ImageOptimizationService $optimization = null, ImageCleanupService $cleanup = null ) {
		$this->jobs         = $jobs ? $jobs : new BulkJobRepository();
		$this->eligibility  = $eligibility ? $eligibility : new EligibilityQuery();
		$this->optimization = $optimization ? $optimization : new ImageOptimizationService();
		$this->cleanup      = $cleanup ? $cleanup : new ImageCleanupService();
	}

	/**
	 * Register Action Scheduler hook.
	 */
	public function init() {
		add_action( self::HOOK_BULK_TICK, array( $this, 'tick' ), 10, 1 );
	}

	/**
	 * Start or continue a job.
	 *
	 * @param int $job_id Job ID.
	 * @return bool
	 */
	public function start( $job_id ) {
		$job = $this->jobs->get( $job_id );
		if ( ! $job || BulkJob::STATUS_CANCELLED === $job->get_status() ) {
			return false;
		}

		$this->jobs->mark_running( $job_id );
		$this->schedule_tick( $job_id );

		return true;
	}

	/**
	 * Pause a job.
	 *
	 * @param int $job_id Job ID.
	 * @return bool
	 */
	public function pause( $job_id ) {
		return $this->jobs->pause( $job_id );
	}

	/**
	 * Resume a paused job and schedule a continuation tick.
	 *
	 * @param int $job_id Job ID.
	 * @return bool
	 */
	public function resume( $job_id ) {
		$updated = $this->jobs->mark_running( $job_id );
		if ( $updated ) {
			$this->schedule_tick( $job_id );
		}

		return $updated;
	}

	/**
	 * Cancel a job.
	 *
	 * @param int $job_id Job ID.
	 * @return bool
	 */
	public function cancel( $job_id ) {
		return $this->jobs->cancel( $job_id );
	}

	/**
	 * Process one bulk job tick.
	 *
	 * @param int $job_id Job ID.
	 */
	public function tick( $job_id ) {
		$job = $this->jobs->get( $job_id );

		if ( ! $job || BulkJob::STATUS_RUNNING !== $job->get_status() ) {
			return;
		}

		$batch_size  = (int) apply_filters( 'trust_optimize_bulk_batch_size', 5 );
		$batch_size  = max( 1, min( 25, $batch_size ) );
		$started_at  = time();
		$time_budget = (int) apply_filters( 'trust_optimize_bulk_time_budget', 20 );
		$ids         = $this->eligibility->get_next_attachment_ids( $job->get_cursor_id(), $batch_size );

		if ( empty( $ids ) ) {
			$this->jobs->complete( $job_id );
			return;
		}

		foreach ( $ids as $attachment_id ) {
			$result = $this->process_attachment( $job->get_type(), $attachment_id );

			$this->jobs->advance_cursor(
				$job_id,
				$attachment_id,
				$this->build_counter_increments( $result )
			);

			if ( $this->should_stop( $started_at, $time_budget ) ) {
				break;
			}
		}

		$job = $this->jobs->get( $job_id );
		if ( $job && BulkJob::STATUS_RUNNING === $job->get_status() ) {
			$next_ids = $this->eligibility->get_next_attachment_ids( $job->get_cursor_id(), 1 );

			if ( empty( $next_ids ) ) {
				$this->jobs->complete( $job_id );
				return;
			}

			$this->schedule_tick( $job_id );
		}
	}

	/**
	 * Process one attachment for the job type.
	 *
	 * @param string $type          Job type.
	 * @param int    $attachment_id Attachment ID.
	 * @return OptimizeResult|DeleteResult
	 */
	private function process_attachment( $type, $attachment_id ) {
		try {
			if ( BulkJob::TYPE_REMOVE === $type ) {
				return $this->cleanup->cleanup_attachment( $attachment_id );
			}

			if ( BulkJob::TYPE_INVENTORY === $type ) {
				return OptimizeResult::skipped( 'inventory_only' );
			}

			return $this->optimization->sync_attachment( $attachment_id );
		} catch ( Throwable $throwable ) {
			return OptimizeResult::failed(
				'exception',
				array(
					array(
						'message' => $throwable->getMessage(),
					),
				)
			);
		}
	}

	/**
	 * Build job counter increments from an operation result.
	 *
	 * @param OptimizeResult|DeleteResult $result Operation result.
	 * @return array
	 */
	private function build_counter_increments( $result ) {
		$data = $result->get_data();

		$increments = array(
			'processed' => 1,
		);

		if ( $result->is_skipped() ) {
			$increments['skipped'] = 1;
		}

		if ( $result->is_failed() || $result->is_partial() ) {
			$increments['failed_count'] = 1;
		}

		if ( isset( $data['completed'] ) ) {
			$increments['created_count'] = (int) $data['completed'];
		}

		if ( isset( $data['deleted'] ) && is_array( $data['deleted'] ) ) {
			$increments['deleted_count'] = count( $data['deleted'] );
		} elseif ( isset( $data['deleted'] ) ) {
			$increments['deleted_count'] = (int) $data['deleted'];
		}

		return $increments;
	}

	/**
	 * Schedule the next tick if one is not already pending/running.
	 *
	 * @param int $job_id Job ID.
	 */
	private function schedule_tick( $job_id ) {
		if ( ! function_exists( 'as_next_scheduled_action' ) || ! function_exists( 'as_enqueue_async_action' ) ) {
			return;
		}

		$args = array( (int) $job_id );

		if ( as_next_scheduled_action( self::HOOK_BULK_TICK, $args, self::GROUP ) ) {
			return;
		}

		as_enqueue_async_action( self::HOOK_BULK_TICK, $args, self::GROUP );
	}

	/**
	 * Check time and memory guards.
	 *
	 * @param int $started_at  Tick start timestamp.
	 * @param int $time_budget Time budget in seconds.
	 * @return bool
	 */
	private function should_stop( $started_at, $time_budget ) {
		if ( time() - $started_at >= $time_budget ) {
			return true;
		}

		$memory_limit = wp_convert_hr_to_bytes( ini_get( 'memory_limit' ) );
		if ( $memory_limit <= 0 ) {
			return false;
		}

		return memory_get_usage( true ) >= (int) ( $memory_limit * 0.8 );
	}
}
