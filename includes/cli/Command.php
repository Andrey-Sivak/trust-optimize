<?php
/**
 * WP-CLI commands for TrustOptimize.
 *
 * @package TrustOptimize\CLI
 */

namespace TrustOptimize\CLI;

use TrustOptimize\Bulk\BulkJob;
use TrustOptimize\Bulk\BulkJobRepository;
use TrustOptimize\Bulk\BulkJobRunner;
use TrustOptimize\Bulk\EligibilityQuery;
use TrustOptimize\Service\ImageCleanupService;
use TrustOptimize\Service\ImageOptimizationService;

/**
 * Class Command
 */
class Command {

	/**
	 * Show image inventory summary.
	 *
	 * ## EXAMPLES
	 *
	 *     wp trust-optimize inventory
	 */
	public function inventory() {
		$query = new EligibilityQuery();

		\WP_CLI::log( 'Eligible image attachments: ' . $query->count_eligible_attachments() );
	}

	/**
	 * Start and run a bulk sync job.
	 *
	 * ## OPTIONS
	 *
	 * [--batch-size=<number>]
	 * : Attachments to process per tick.
	 *
	 * [--yes]
	 * : Confirm full-library sync.
	 *
	 * ## EXAMPLES
	 *
	 *     wp trust-optimize sync --batch-size=10 --yes
	 *
	 * @param array $args Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function sync( $args, $assoc_args ) {
		if ( empty( $assoc_args['yes'] ) ) {
			\WP_CLI::error( 'Use --yes to confirm full-library sync.' );
		}

		$this->run_bulk_job( BulkJob::TYPE_SYNC, $assoc_args );
	}

	/**
	 * Show latest bulk job status.
	 */
	public function status() {
		$repository = new BulkJobRepository();
		$job        = $repository->get_active_job();

		if ( ! $job ) {
			$job = $repository->get_latest_job();
		}

		if ( ! $job ) {
			\WP_CLI::log( 'No bulk jobs found.' );
			return;
		}

		\WP_CLI\Utils\format_items( 'table', array( $job->to_array() ), array_keys( $job->to_array() ) );
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
	 * --all
	 * : Confirm all-library scope.
	 *
	 * --yes
	 * : Confirm destructive cleanup.
	 *
	 * [--batch-size=<number>]
	 * : Attachments to process per tick.
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

		$service = new ImageOptimizationService();
		$result  = $service->sync_attachment( $attachment_id );

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

		$service = new ImageCleanupService();
		$result  = $service->cleanup_attachment( $attachment_id );

		\WP_CLI::line( wp_json_encode( $result->to_array() ) );
	}

	/**
	 * Create and run a bulk job until it reaches a terminal state.
	 *
	 * @param string $type       Job type.
	 * @param array  $assoc_args Associative arguments.
	 */
	private function run_bulk_job( $type, array $assoc_args ) {
		$repository  = new BulkJobRepository();
		$eligibility = new EligibilityQuery();
		$total       = $eligibility->count_eligible_attachments();
		$job         = $repository->create( $type, array(), '', $total );

		if ( ! $job ) {
			\WP_CLI::error( 'Another bulk job is already active.' );
		}

		$batch_size = isset( $assoc_args['batch-size'] ) ? (int) $assoc_args['batch-size'] : 10;
		$batch_size = max( 1, min( 100, $batch_size ) );

		add_filter(
			'trust_optimize_bulk_batch_size',
			function () use ( $batch_size ) {
				return $batch_size;
			}
		);

		$runner = new BulkJobRunner( $repository, $eligibility );
		$repository->mark_running( $job->get_id() );

		do {
			$runner->tick( $job->get_id() );
			$job = $repository->get( $job->get_id() );
			\WP_CLI::log( sprintf( 'Job #%d: %s, processed %d/%d', $job->get_id(), $job->get_status(), (int) $job->to_array()['processed'], (int) $job->to_array()['total'] ) );
		} while ( in_array( $job->get_status(), array( BulkJob::STATUS_PENDING, BulkJob::STATUS_RUNNING ), true ) );

		\WP_CLI::success( 'Bulk job finished with status: ' . $job->get_status() );
	}

	/**
	 * Control active job.
	 *
	 * @param string $action Runner action.
	 */
	private function control_active_job( $action ) {
		$repository = new BulkJobRepository();
		$job        = $repository->get_active_job();

		if ( ! $job ) {
			\WP_CLI::warning( 'No active bulk job.' );
			return;
		}

		$runner = new BulkJobRunner( $repository );
		$runner->$action( $job->get_id() );

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
