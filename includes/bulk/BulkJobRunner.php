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
		add_action( 'admin_init', array( $this, 'recover_stale_jobs' ) );
	}

	/**
	 * Start or continue a job.
	 *
	 * @param int $job_id Job ID.
	 * @return bool
	 */
	public function start( $job_id ) {
		$this->recover_stale_jobs();

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
		$updated = $this->jobs->pause( $job_id );
		if ( $updated ) {
			$this->unschedule_tick( $job_id );
		}

		return $updated;
	}

	/**
	 * Resume a paused job and schedule a continuation tick.
	 *
	 * @param int $job_id Job ID.
	 * @return bool
	 */
	public function resume( $job_id ) {
		$this->recover_stale_jobs();

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
		$updated = $this->jobs->cancel( $job_id );
		if ( $updated ) {
			$this->unschedule_tick( $job_id );
		}

		return $updated;
	}

	/**
	 * Recover stale running jobs.
	 *
	 * @return int Number of recovered jobs.
	 */
	public function recover_stale_jobs() {
		return $this->jobs->recover_stale_running();
	}

	/**
	 * Process one bulk job tick.
	 *
	 * @param int $job_id Job ID.
	 */
	public function tick( $job_id ) {
		$this->recover_stale_jobs();

		$job = $this->jobs->get( $job_id );

		if ( ! $job || BulkJob::STATUS_RUNNING !== $job->get_status() ) {
			return;
		}

		$batch_size  = (int) apply_filters( 'trust_optimize_bulk_batch_size', 5 );
		$batch_size  = max( 1, min( 25, $batch_size ) );
		$started_at  = time();
		$time_budget = (int) apply_filters( 'trust_optimize_bulk_time_budget', 20 );
		$ids         = $this->get_next_attachment_ids( $job, $batch_size );
		$inventory   = BulkJob::TYPE_INVENTORY === $job->get_type() ? $this->get_inventory_summary( $job ) : array();

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

			if ( BulkJob::TYPE_INVENTORY === $job->get_type() ) {
				$inventory             = $this->merge_inventory_result( $inventory, $result );
				$job_data              = $job->to_array();
				$snapshot              = isset( $job_data['settings_snapshot'] ) && is_array( $job_data['settings_snapshot'] ) ? $job_data['settings_snapshot'] : array();
				$snapshot['inventory'] = $inventory;

				$this->jobs->update(
					$job_id,
					array(
						'settings_snapshot' => wp_json_encode( $snapshot ),
					)
				);
			}

			if ( $this->should_stop( $started_at, $time_budget ) ) {
				break;
			}
		}

		$job = $this->jobs->get( $job_id );
		if ( $job && BulkJob::STATUS_RUNNING === $job->get_status() ) {
			$next_ids = $this->get_next_attachment_ids( $job, 1 );

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
				return $this->optimization->inventory_attachment( $attachment_id );
			}

			return $this->optimization->optimize_attachment( $attachment_id );
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
	 * Get next attachment IDs for the current job type.
	 *
	 * Remove jobs only iterate attachments with plugin-managed generated variants.
	 *
	 * @param BulkJob $job   Bulk job.
	 * @param int     $limit Maximum IDs to return.
	 * @return array Attachment IDs.
	 */
	private function get_next_attachment_ids( BulkJob $job, $limit ) {
		$scoped_ids = $this->get_scoped_attachment_ids( $job );

		if ( ! empty( $scoped_ids ) ) {
			$next_ids = array();
			$cursor   = $job->get_cursor_id();

			foreach ( $scoped_ids as $attachment_id ) {
				if ( $attachment_id <= $cursor ) {
					continue;
				}

				$next_ids[] = $attachment_id;

				if ( count( $next_ids ) >= $limit ) {
					break;
				}
			}

			return $next_ids;
		}

		if ( BulkJob::TYPE_REMOVE === $job->get_type() ) {
			return $this->eligibility->get_next_plugin_managed_attachment_ids( $job->get_cursor_id(), $limit );
		}

		return $this->eligibility->get_next_attachment_ids( $job->get_cursor_id(), $limit );
	}

	/**
	 * Get optional attachment scope from a job snapshot.
	 *
	 * MVP bulk jobs normally stream from EligibilityQuery. Smoke/integration
	 * jobs may store explicit attachment IDs in settings_snapshot to keep the
	 * job total and processed stream aligned without adding a job_items table.
	 *
	 * @param BulkJob $job Job value object.
	 * @return array Attachment IDs.
	 */
	private function get_scoped_attachment_ids( BulkJob $job ) {
		$data     = $job->to_array();
		$snapshot = isset( $data['settings_snapshot'] ) && is_array( $data['settings_snapshot'] ) ? $data['settings_snapshot'] : array();

		if ( empty( $snapshot['attachment_ids'] ) || ! is_array( $snapshot['attachment_ids'] ) ) {
			return array();
		}

		$ids = array_values( array_unique( array_map( 'intval', $snapshot['attachment_ids'] ) ) );
		$ids = array_filter(
			$ids,
			function ( $attachment_id ) {
				return $attachment_id > 0;
			}
		);

		sort( $ids );

		return array_values( $ids );
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

		if ( isset( $data['estimated_variants_to_create'] ) ) {
			$increments['created_count'] = (int) $data['estimated_variants_to_create'];
		}

		if ( isset( $data['deleted'] ) && is_array( $data['deleted'] ) ) {
			$increments['deleted_count'] = count( $data['deleted'] );
		} elseif ( isset( $data['deleted'] ) ) {
			$increments['deleted_count'] = (int) $data['deleted'];
		}

		if ( isset( $data['estimated_stale_variants_to_delete'] ) ) {
			$increments['deleted_count'] = (int) $data['estimated_stale_variants_to_delete'];
		}

		return $increments;
	}

	/**
	 * Get the current inventory summary from the job snapshot.
	 *
	 * @param BulkJob $job Job value object.
	 * @return array Inventory summary.
	 */
	private function get_inventory_summary( BulkJob $job ) {
		$data     = $job->to_array();
		$snapshot = isset( $data['settings_snapshot'] ) && is_array( $data['settings_snapshot'] ) ? $data['settings_snapshot'] : array();

		if ( isset( $snapshot['inventory'] ) && is_array( $snapshot['inventory'] ) ) {
			return $this->normalize_inventory_summary( $snapshot['inventory'], $data );
		}

		return $this->normalize_inventory_summary( array(), $data );
	}

	/**
	 * Normalize inventory summary shape.
	 *
	 * @param array $summary Existing summary.
	 * @param array $job     Job data.
	 * @return array Normalized summary.
	 */
	private function normalize_inventory_summary( array $summary, array $job ) {
		$defaults = array(
			'total_image_attachments'            => isset( $job['total'] ) ? (int) $job['total'] : 0,
			'eligible_attachments'               => 0,
			'unsupported_mime_types'             => array(),
			'unsupported_output_formats'         => array(),
			'missing_source_files'               => 0,
			'already_optimized_current_profile'  => 0,
			'outdated_profile'                   => 0,
			'plugin_managed_variants_count'      => 0,
			'estimated_variants_to_create'       => 0,
			'estimated_stale_variants_to_delete' => 0,
			'errors'                             => array(),
			'warnings'                           => array(),
		);

		return array_merge( $defaults, $summary );
	}

	/**
	 * Merge one attachment inventory result into the job summary.
	 *
	 * @param array          $summary Inventory summary.
	 * @param OptimizeResult $result  Attachment inventory result.
	 * @return array Updated summary.
	 */
	private function merge_inventory_result( array $summary, OptimizeResult $result ) {
		$data = $result->get_data();

		if ( ! empty( $data['eligible'] ) ) {
			++$summary['eligible_attachments'];
		}

		if ( ! empty( $data['unsupported_mime_type'] ) ) {
			$mime = $data['unsupported_mime_type'];

			if ( ! isset( $summary['unsupported_mime_types'][ $mime ] ) ) {
				$summary['unsupported_mime_types'][ $mime ] = 0;
			}

			++$summary['unsupported_mime_types'][ $mime ];
		}

		if ( ! empty( $data['unsupported_output_formats'] ) && is_array( $data['unsupported_output_formats'] ) ) {
			foreach ( $data['unsupported_output_formats'] as $format ) {
				if ( ! isset( $summary['unsupported_output_formats'][ $format ] ) ) {
					$summary['unsupported_output_formats'][ $format ] = 0;
				}

				++$summary['unsupported_output_formats'][ $format ];
			}
		}

		if ( ! empty( $data['missing_source_file'] ) ) {
			++$summary['missing_source_files'];
		}

		if ( ! empty( $data['already_optimized'] ) ) {
			++$summary['already_optimized_current_profile'];
		}

		if ( ! empty( $data['outdated_profile'] ) ) {
			++$summary['outdated_profile'];
		}

		foreach ( array( 'plugin_managed_variants', 'estimated_variants_to_create', 'estimated_stale_variants_to_delete' ) as $key ) {
			if ( isset( $data[ $key ] ) ) {
				$summary_key              = 'plugin_managed_variants' === $key ? 'plugin_managed_variants_count' : $key;
				$summary[ $summary_key ] += (int) $data[ $key ];
			}
		}

		$summary = $this->merge_inventory_notices( $summary, $data, 'warnings' );
		$summary = $this->merge_inventory_notices( $summary, $data, 'errors' );

		return $summary;
	}

	/**
	 * Merge bounded warning/error samples into inventory summary.
	 *
	 * @param array  $summary Inventory summary.
	 * @param array  $data    Attachment inventory data.
	 * @param string $key     Notice key.
	 * @return array Updated summary.
	 */
	private function merge_inventory_notices( array $summary, array $data, $key ) {
		if ( empty( $data[ $key ] ) || ! is_array( $data[ $key ] ) ) {
			return $summary;
		}

		foreach ( $data[ $key ] as $notice ) {
			if ( count( $summary[ $key ] ) >= 20 ) {
				break;
			}

			$summary[ $key ][] = array(
				'attachment_id' => isset( $data['attachment_id'] ) ? (int) $data['attachment_id'] : 0,
				'code'          => $notice,
			);
		}

		return $summary;
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
	 * Unschedule pending ticks for one job.
	 *
	 * @param int $job_id Job ID.
	 */
	private function unschedule_tick( $job_id ) {
		if ( ! function_exists( 'as_unschedule_action' ) ) {
			return;
		}

		as_unschedule_action(
			self::HOOK_BULK_TICK,
			array( (int) $job_id ),
			self::GROUP
		);
	}

	/**
	 * Cancel all pending TrustOptimize bulk ticks.
	 */
	public static function cancel_all_ticks() {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK_BULK_TICK, null, self::GROUP );
		}
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
