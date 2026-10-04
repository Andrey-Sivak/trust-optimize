<?php
/**
 * WP-CLI commands for TrustOptimize.
 *
 * @package TrustOptimize\CLI
 */

namespace TrustOptimize\CLI;

use TrustOptimize\Bulk\BulkJob;
use TrustOptimize\Bulk\BulkJobRepository;
use TrustOptimize\Bulk\BulkProducer;
use TrustOptimize\Bulk\EligibilityQuery;
use TrustOptimize\Bulk\Inventory;
use TrustOptimize\Bulk\JobProgress;
use TrustOptimize\Domain\JobStatus;
use TrustOptimize\Processing\AttachmentProcessor;
use TrustOptimize\Service\ImageCleanupService;

/**
 * Class Command
 */
class Command {

	/**
	 * Bulk job repository.
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
	 * Inventory.
	 *
	 * @var Inventory
	 */
	private $inventory;

	/**
	 * Bulk job progress.
	 *
	 * @var JobProgress
	 */
	private $progress;

	/**
	 * Bulk job producer.
	 *
	 * @var BulkProducer
	 */
	private $producer;

	/**
	 * Attachment processor.
	 *
	 * @var AttachmentProcessor
	 */
	private $processor;

	/**
	 * Cleanup service.
	 *
	 * @var ImageCleanupService
	 */
	private $cleanup;

	/**
	 * Constructor.
	 *
	 * @param BulkJobRepository   $jobs        Bulk job repository.
	 * @param EligibilityQuery    $eligibility Eligibility query.
	 * @param Inventory           $inventory   Inventory.
	 * @param JobProgress         $progress    Bulk job progress.
	 * @param BulkProducer        $producer    Bulk job producer.
	 * @param AttachmentProcessor $processor   Attachment processor.
	 * @param ImageCleanupService $cleanup     Cleanup service.
	 */
	public function __construct( BulkJobRepository $jobs, EligibilityQuery $eligibility, Inventory $inventory, JobProgress $progress, BulkProducer $producer, AttachmentProcessor $processor, ImageCleanupService $cleanup ) {
		$this->jobs        = $jobs;
		$this->eligibility = $eligibility;
		$this->inventory   = $inventory;
		$this->progress    = $progress;
		$this->producer    = $producer;
		$this->processor   = $processor;
		$this->cleanup     = $cleanup;
	}

	/**
	 * Show the image inventory: counts per MIME type, state and variant status.
	 *
	 * ## EXAMPLES
	 *
	 *     wp trust-optimize inventory
	 */
	public function inventory() {
		$rows = array();

		foreach ( $this->inventory->summary() as $name => $value ) {
			$rows[] = array(
				'metric' => $name,
				'value'  => is_array( $value ) ? wp_json_encode( $value ) : $value,
			);
		}

		\WP_CLI\Utils\format_items( 'table', $rows, array( 'metric', 'value' ) );
	}

	/**
	 * Start a bulk sync job, or convert in this process.
	 *
	 * Without options the job is carried out in the background by Action Scheduler.
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : Confirm full-library sync.
	 *
	 * [--wait]
	 * : Wait for the job and show its progress. Exits with an error if attachments failed.
	 *
	 * [--now]
	 * : Convert the attachments in this process instead of the queue. Exits with an error if attachments failed.
	 *
	 * [--batch-size=<number>]
	 * : With --now: attachments fetched at a time (1-100).
	 *
	 * ## EXAMPLES
	 *
	 *     wp trust-optimize sync --yes --wait
	 *     wp trust-optimize sync --yes --now
	 *
	 * @param array $args Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function sync( $args, $assoc_args ) {
		if ( empty( $assoc_args['yes'] ) ) {
			\WP_CLI::error( 'Use --yes to confirm full-library sync.' );
		}

		if ( ! empty( $assoc_args['now'] ) ) {
			$this->sync_now( $assoc_args );
			return;
		}

		$this->run_bulk_job( BulkJob::TYPE_SYNC, $assoc_args );
	}

	/**
	 * Show latest bulk job status.
	 */
	public function status() {
		$job = $this->jobs->get_active_job();

		if ( ! $job ) {
			$job = $this->jobs->get_latest_job();
		}

		if ( ! $job ) {
			\WP_CLI::log( 'No bulk jobs found.' );
			return;
		}

		$data = $this->progress->describe( $job );

		\WP_CLI\Utils\format_items( 'table', array( $data ), array_keys( $data ) );
	}

	/**
	 * Pause the active bulk job.
	 */
	public function pause() {
		$this->control_active_job( 'pause' );
	}

	/**
	 * Resume the active bulk job.
	 */
	public function resume() {
		$this->control_active_job( 'resume' );
	}

	/**
	 * Cancel the active bulk job.
	 */
	public function cancel() {
		$this->control_active_job( 'cancel' );
	}

	/**
	 * Remove generated files for all eligible attachments.
	 *
	 * ## OPTIONS
	 *
	 * [--all]
	 * : Confirm all-library scope.
	 *
	 * [--yes]
	 * : Confirm destructive cleanup.
	 *
	 * [--wait]
	 * : Wait for the job and show its progress. Exits with an error if attachments failed.
	 *
	 * @param array $args Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function remove( $args, $assoc_args ) {
		if ( empty( $assoc_args['all'] ) || empty( $assoc_args['yes'] ) ) {
			\WP_CLI::error( 'Use --all --yes to confirm full-library generated file removal.' );
		}

		$this->run_bulk_job( BulkJob::TYPE_REMOVE, $assoc_args );
	}

	/**
	 * Sync one attachment.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Attachment ID.
	 *
	 * @param array $args Positional arguments.
	 */
	public function sync_attachment( $args ) {
		$attachment_id = isset( $args[0] ) ? (int) $args[0] : 0;
		$this->validate_attachment_or_exit( $attachment_id );

		$result = $this->processor->sync( $attachment_id );

		\WP_CLI::line( wp_json_encode( $result->to_array() ) );
	}

	/**
	 * Remove generated files for one attachment.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Attachment ID.
	 *
	 * @param array $args Positional arguments.
	 */
	public function remove_attachment( $args ) {
		$attachment_id = isset( $args[0] ) ? (int) $args[0] : 0;
		$this->validate_attachment_or_exit( $attachment_id );

		$result = $this->cleanup->cleanup_attachment( $attachment_id );

		\WP_CLI::line( wp_json_encode( $result->to_array() ) );
	}

	/**
	 * Create a bulk job; Action Scheduler carries it out in the background.
	 *
	 * @param string $type       Job type.
	 * @param array  $assoc_args Associative arguments (--wait).
	 */
	private function run_bulk_job( $type, array $assoc_args ) {
		$job = $this->producer->launch( $type );

		if ( ! $job ) {
			\WP_CLI::error( 'Another bulk job is already active.' );
		}

		if ( empty( $assoc_args['wait'] ) ) {
			\WP_CLI::success( sprintf( 'Bulk job #%d started. Follow it with "wp trust-optimize status".', $job->get_id() ) );
			return;
		}

		$this->wait_for( $job );
	}

	/**
	 * Show the progress of a job until it ends; exit with an error when attachments failed.
	 *
	 * Action Scheduler must run elsewhere (WP-Cron, or "wp action-scheduler run" in another shell).
	 *
	 * @param BulkJob $job Job.
	 */
	private function wait_for( BulkJob $job ) {
		$total = max( 1, $job->get_total() );
		$bar   = \WP_CLI\Utils\make_progress_bar( sprintf( 'Job #%d', $job->get_id() ), $total );
		$done  = 0;
		$data  = $this->progress->describe( $job );

		while ( in_array( $data['status'], array( JobStatus::PENDING, JobStatus::RUNNING ), true ) ) {
			sleep( 2 );

			$job  = $this->jobs->get( $job->get_id() );
			$data = $this->progress->describe( $job );

			$reached = min( $total, (int) $data['processed'] );

			if ( $reached > $done ) {
				$bar->tick( $reached - $done );
				$done = $reached;
			}
		}

		$bar->finish();
		$this->report( $data );
	}

	/**
	 * Convert the eligible attachments in this process, without the queue.
	 *
	 * @param array $assoc_args Associative arguments (--batch-size).
	 */
	private function sync_now( array $assoc_args ) {
		$batch_size = isset( $assoc_args['batch-size'] ) ? max( 1, min( BulkProducer::MAX_BATCH_SIZE, (int) $assoc_args['batch-size'] ) ) : BulkProducer::DEFAULT_BATCH_SIZE;
		$total      = $this->eligibility->count_eligible_attachments();
		$bar        = \WP_CLI\Utils\make_progress_bar( 'Syncing', max( 1, $total ) );
		$cursor     = 0;
		$done       = 0;
		$failed     = 0;

		do {
			$ids     = $this->eligibility->get_next_attachment_ids( $cursor, $batch_size );
			$fetched = count( $ids );

			foreach ( $ids as $attachment_id ) {
				$result = $this->processor->sync( $attachment_id );
				$cursor = $attachment_id;

				if ( $result->is_failed() || $result->is_partial() ) {
					++$failed;
				}

				$bar->tick();

				// Long runs would otherwise keep every post and meta row they touched.
				++$done;
				if ( 0 === $done % 50 ) {
					wp_cache_flush_runtime();
				}
			}
		} while ( $fetched === $batch_size );

		$bar->finish();

		if ( $failed > 0 ) {
			\WP_CLI::error( sprintf( '%d of %d attachments failed.', $failed, $done ) );
		}

		\WP_CLI::success( sprintf( '%d attachments processed.', $done ) );
	}

	/**
	 * Report the end of a job.
	 *
	 * @param array $data Job data with counters.
	 */
	private function report( array $data ) {
		$message = sprintf( 'Job #%d finished as %s: %d processed, %d failed.', $data['id'], $data['status'], $data['processed'], $data['failed_count'] );

		if ( (int) $data['failed_count'] > 0 || JobStatus::COMPLETED_WITH_ERRORS === $data['status'] || JobStatus::FAILED === $data['status'] ) {
			\WP_CLI::error( $message );
		}

		\WP_CLI::success( $message );
	}

	/**
	 * Control active job.
	 *
	 * @param string $action Producer action.
	 */
	private function control_active_job( $action ) {
		$job = $this->jobs->get_active_job();

		if ( ! $job ) {
			\WP_CLI::warning( 'No active bulk job.' );
			return;
		}

		$this->producer->$action( $job->get_id() );

		$message = sprintf( 'Job #%d %s requested.', $job->get_id(), $action );

		if ( in_array( $action, array( 'pause', 'cancel' ), true ) ) {
			$message .= sprintf( ' Attachments already queued (up to %d) will still be processed.', BulkProducer::max_pending() );
		}

		\WP_CLI::success( $message );
	}

	/**
	 * Validate attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	private function validate_attachment_or_exit( $attachment_id ) {
		if ( ! get_post( $attachment_id ) || 'attachment' !== get_post_type( $attachment_id ) ) {
			\WP_CLI::error( 'Invalid attachment ID.' );
		}
	}
}
