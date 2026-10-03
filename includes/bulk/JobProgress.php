<?php
/**
 * Progress of a bulk job, derived from the data instead of kept in counters.
 *
 * @package TrustOptimize\Bulk
 */

namespace TrustOptimize\Bulk;

use TrustOptimize\Domain\AttachmentState;
use TrustOptimize\Storage\AttachmentRepository;

/**
 * Class JobProgress
 *
 * A sync job hands every attachment to the queue after marking it with the job ID, so its
 * progress is the number of its attachments per state. Jobs that do their work while walking
 * the cursor (remove, inventory) are measured against the attachments still ahead of it.
 */
class JobProgress {

	/**
	 * Attachment repository.
	 *
	 * @var AttachmentRepository
	 */
	private $attachments;

	/**
	 * Eligibility query.
	 *
	 * @var EligibilityQuery
	 */
	private $eligibility;

	/**
	 * Constructor.
	 *
	 * @param AttachmentRepository $attachments Attachment repository.
	 * @param EligibilityQuery     $eligibility Eligibility query.
	 */
	public function __construct( AttachmentRepository $attachments, EligibilityQuery $eligibility ) {
		$this->attachments = $attachments;
		$this->eligibility = $eligibility;
	}

	/**
	 * The job as data: its row with the counters filled in from the current state.
	 *
	 * @param BulkJob $job Job.
	 * @return array
	 */
	public function describe( BulkJob $job ) {
		return array_merge( $job->to_array(), $this->counters( $job ) );
	}

	/**
	 * Attachments of the job still waiting for or in a worker.
	 *
	 * @param BulkJob $job Job.
	 * @return int
	 */
	public function in_flight( BulkJob $job ) {
		return $this->waiting( $this->states( $job ) );
	}

	/**
	 * Whether some attachment of the job did not get all its variants.
	 *
	 * @param BulkJob $job Job.
	 * @return bool
	 */
	public function has_errors( BulkJob $job ) {
		return $this->counters( $job )['failed_count'] > 0;
	}

	/**
	 * Counters shown to the user.
	 *
	 * The created count is the number of attachments that received optimized variants.
	 *
	 * @param BulkJob $job Job.
	 * @return int[] processed, skipped, failed_count, created_count, deleted_count.
	 */
	private function counters( BulkJob $job ) {
		$counters = array(
			'processed'     => 0,
			'skipped'       => 0,
			'failed_count'  => 0,
			'created_count' => 0,
			'deleted_count' => 0,
		);

		if ( BulkJob::TYPE_SYNC === $job->get_type() ) {
			$states = $this->states( $job );

			$counters['processed']     = array_sum( $states ) - $this->waiting( $states );
			$counters['skipped']       = $states[ AttachmentState::SKIPPED ] ?? 0;
			$counters['failed_count']  = ( $states[ AttachmentState::FAILED ] ?? 0 ) + ( $states[ AttachmentState::PARTIAL ] ?? 0 );
			$counters['created_count'] = ( $states[ AttachmentState::OPTIMIZED ] ?? 0 ) + ( $states[ AttachmentState::PARTIAL ] ?? 0 );

			return $counters;
		}

		$total     = $job->get_total();
		$remaining = BulkJob::TYPE_REMOVE === $job->get_type()
			? $this->eligibility->count_plugin_managed_attachments( $job->get_cursor_id() )
			: $this->eligibility->count_eligible_attachments( $job->get_cursor_id() );

		$counters['processed'] = max( 0, $total - $remaining );

		if ( BulkJob::TYPE_REMOVE === $job->get_type() ) {
			// Whatever is still managed behind the cursor could not be removed.
			$counters['failed_count']  = max( 0, $this->eligibility->count_plugin_managed_attachments() - $remaining );
			$counters['deleted_count'] = max( 0, $counters['processed'] - $counters['failed_count'] );
		}

		return $counters;
	}

	/**
	 * Number of queued and processing attachments in a state map.
	 *
	 * @param int[] $states Counts keyed by AttachmentState.
	 * @return int
	 */
	private function waiting( array $states ) {
		return ( $states[ AttachmentState::QUEUED ] ?? 0 ) + ( $states[ AttachmentState::PROCESSING ] ?? 0 );
	}

	/**
	 * Attachments of a sync job per state.
	 *
	 * @param BulkJob $job Job.
	 * @return int[]
	 */
	private function states( BulkJob $job ) {
		return BulkJob::TYPE_SYNC === $job->get_type() ? $this->attachments->count_states_for_job( $job->get_id() ) : array();
	}
}
