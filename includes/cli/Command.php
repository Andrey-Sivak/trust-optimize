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
use TrustOptimize\Bulk\JobProgress;
use TrustOptimize\Migration\MigrationRunner;
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
	 * Migration runner.
	 *
	 * @var MigrationRunner
	 */
	private $migration;

	/**
	 * Constructor.
	 *
	 * @param BulkJobRepository   $jobs        Bulk job repository.
	 * @param EligibilityQuery    $eligibility Eligibility query.
	 * @param JobProgress         $progress    Bulk job progress.
	 * @param BulkProducer        $producer    Bulk job producer.
	 * @param AttachmentProcessor $processor   Attachment processor.
	 * @param ImageCleanupService $cleanup     Cleanup service.
	 * @param MigrationRunner     $migration   Migration runner.
	 */
	public function __construct( BulkJobRepository $jobs, EligibilityQuery $eligibility, JobProgress $progress, BulkProducer $producer, AttachmentProcessor $processor, ImageCleanupService $cleanup, MigrationRunner $migration ) {
		$this->jobs        = $jobs;
		$this->eligibility = $eligibility;
		$this->progress    = $progress;
		$this->producer    = $producer;
		$this->processor   = $processor;
		$this->cleanup     = $cleanup;
		$this->migration   = $migration;
	}

	/**
	 * Show image inventory summary.
	 *
	 * ## EXAMPLES
	 *
	 *     wp trust-optimize inventory
	 */
	public function inventory() {
		\WP_CLI::log( 'Eligible image attachments: ' . $this->eligibility->count_eligible_attachments() );
	}

	/**
	 * Start a bulk sync job.
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : Confirm full-library sync.
	 *
	 * ## EXAMPLES
	 *
	 *     wp trust-optimize sync --yes
	 *
	 * @param array $args Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function sync( $args, $assoc_args ) {
		if ( empty( $assoc_args['yes'] ) ) {
			\WP_CLI::error( 'Use --yes to confirm full-library sync.' );
		}

		$this->run_bulk_job( BulkJob::TYPE_SYNC );
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
	 * @param array $args Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function remove( $args, $assoc_args ) {
		if ( empty( $assoc_args['all'] ) || empty( $assoc_args['yes'] ) ) {
			\WP_CLI::error( 'Use --all --yes to confirm full-library generated file removal.' );
		}

		$this->run_bulk_job( BulkJob::TYPE_REMOVE );
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
	 * Run the migration from the 1.x data layout synchronously.
	 *
	 * ## OPTIONS
	 *
	 * [--batch-size=<number>]
	 * : Items to process per batch.
	 *
	 * ## EXAMPLES
	 *
	 *     wp trust-optimize migrate --batch-size=100
	 *
	 * @param array $args Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function migrate( $args, $assoc_args ) {
		if ( ! $this->migration->is_running() ) {
			\WP_CLI::success( 'No migration is pending.' );
			return;
		}

		$batch_size = isset( $assoc_args['batch-size'] ) ? max( 1, min( 500, (int) $assoc_args['batch-size'] ) ) : MigrationRunner::BATCH_SIZE;

		// The batches run here, so the background one must not run in parallel.
		$this->migration->unschedule();

		$total    = $this->migration->get_progress()[1];
		$progress = \WP_CLI\Utils\make_progress_bar( 'Migrating', $total );
		$done     = $this->migration->get_progress()[0] - 1;
		$errors   = $this->migration->get_state()['errors'];
		$previous = end( $errors );

		while ( $this->migration->is_running() ) {
			$state = $this->migration->run_batch( $batch_size );

			$errors = $state['errors'];
			$last   = end( $errors );

			if ( $last !== $previous ) {
				$this->migration->schedule( $this->migration->get_delay() );
				\WP_CLI::error( sprintf( 'Step %s failed: %s', $last['step'], $last['message'] ) );
			}

			if ( $this->migration->is_running() && $this->migration->get_delay() > 0 ) {
				$this->migration->schedule( $this->migration->get_delay() );
				$progress->finish();
				\WP_CLI::warning( sprintf( 'Step %s is waiting; it will be retried in the background in %d seconds.', $state['step'], $this->migration->get_delay() ) );
				return;
			}

			$position = $this->migration->is_running() ? $this->migration->get_progress()[0] - 1 : $total;

			for ( ; $done < $position; $done++ ) {
				$progress->tick();
			}
		}

		$progress->finish();
		\WP_CLI::success( 'Migration finished.' );
	}

	/**
	 * Create a bulk job; Action Scheduler carries it out in the background.
	 *
	 * @param string $type Job type.
	 */
	private function run_bulk_job( $type ) {
		$job = $this->producer->launch( $type );

		if ( ! $job ) {
			\WP_CLI::error( 'Another bulk job is already active.' );
		}

		\WP_CLI::success( sprintf( 'Bulk job #%d started. Follow it with "wp trust-optimize status".', $job->get_id() ) );
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

		\WP_CLI::success( sprintf( 'Job #%d %s requested.', $job->get_id(), $action ) );
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
