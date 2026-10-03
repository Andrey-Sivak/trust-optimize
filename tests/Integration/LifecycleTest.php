<?php
/**
 * Queued work survives a deactivation (04.7).
 *
 * @package TrustOptimize\Tests
 */

use TrustOptimize\Bulk\BulkJob;
use TrustOptimize\Bulk\BulkJobRepository;
use TrustOptimize\Bulk\BulkProducer;
use TrustOptimize\Capabilities\CapabilityService;
use TrustOptimize\Core\Plugin;
use TrustOptimize\Database\DatabaseManager;
use TrustOptimize\Domain\AttachmentState;
use TrustOptimize\Domain\JobStatus;
use TrustOptimize\Queue\ConversionQueue;
use TrustOptimize\Queue\Lifecycle;
use TrustOptimize\Queue\Maintenance;
use TrustOptimize\Storage\AttachmentRepository;
use TrustOptimize\Storage\VariantRepository;

/**
 * @covers \TrustOptimize\Queue\Lifecycle
 */
class LifecycleTest extends WP_UnitTestCase {

	/**
	 * Attachments.
	 *
	 * @var AttachmentRepository
	 */
	private $attachments;

	public function set_up() {
		parent::set_up();
		update_option( CapabilityService::OPTION, array( 'webp' => true, 'avif' => false ) );
		update_option( 'trust_optimize_options', array( 'convert_to_webp' => 1, 'convert_to_avif' => 0 ) );
		as_unschedule_all_actions( ConversionQueue::HOOK_PROCESS );

		$database          = new DatabaseManager();
		$this->attachments = new AttachmentRepository( $database, new VariantRepository( $database ) );
	}

	private function pending_in_group() {
		return as_get_scheduled_actions(
			array(
				'group'  => ConversionQueue::GROUP,
				'status' => ActionScheduler_Store::STATUS_PENDING,
			),
			'ids'
		);
	}

	public function test_work_queued_before_a_deactivation_is_finished_after_the_next_activation() {
		$ids = array(
			self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/test-image.jpg' ),
			self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/test-image.jpg' ),
		);
		$this->assertCount( 2, $this->pending_in_group() );

		$this->attachments->claim( $ids[1] );

		Lifecycle::deactivate();

		$this->assertSame( array(), $this->pending_in_group(), 'The plugin leaves nothing on the queue.' );
		foreach ( $ids as $id ) {
			$row = $this->attachments->get( $id );
			$this->assertSame( AttachmentState::NONE, $row['state'] );
			$this->assertSame( Lifecycle::REASON, $row['reason'] );
		}

		Lifecycle::activate();
		ActionScheduler_QueueRunner::instance()->run();

		foreach ( $ids as $id ) {
			$this->assertSame( AttachmentState::OPTIMIZED, $this->attachments->get_state( $id ) );
		}
		$this->assertNotFalse( as_next_scheduled_action( Maintenance::HOOK, null, ConversionQueue::GROUP ), 'The maintenance is scheduled again.' );
	}

	public function test_a_running_job_is_paused_by_the_deactivation() {
		$job = Plugin::get_instance()->bulk_producer->launch( BulkJob::TYPE_SYNC );

		Lifecycle::deactivate();

		$jobs = new BulkJobRepository( new DatabaseManager() );
		$this->assertSame( JobStatus::PAUSED, $jobs->get( $job->get_id() )->get_status() );
		$this->assertSame( Lifecycle::REASON, $jobs->get( $job->get_id() )->to_array()['last_error'] );
		$this->assertSame( array(), as_get_scheduled_actions( array( 'hook' => BulkProducer::HOOK_PRODUCE, 'status' => ActionScheduler_Store::STATUS_PENDING ), 'ids' ) );

		Lifecycle::activate();
		$this->assertSame( JobStatus::PAUSED, $jobs->get( $job->get_id() )->get_status(), 'Resuming a job is the user\'s decision.' );
	}

	public function test_finished_work_is_not_queued_again() {
		$id = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/test-image.jpg' );
		ActionScheduler_QueueRunner::instance()->run();
		$this->assertSame( AttachmentState::OPTIMIZED, $this->attachments->get_state( $id ) );

		Lifecycle::deactivate();
		Lifecycle::activate();

		$this->assertSame( AttachmentState::OPTIMIZED, $this->attachments->get_state( $id ) );
		$this->assertSame( array(), as_get_scheduled_actions( array( 'hook' => ConversionQueue::HOOK_PROCESS, 'status' => ActionScheduler_Store::STATUS_PENDING ), 'ids' ) );
	}

	public function test_restoring_queues_every_suspended_attachment_once() {
		$ids = array();
		for ( $i = 0; $i < 3; $i++ ) {
			$ids[] = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/test-image.jpg' );
		}
		Lifecycle::deactivate();

		$lifecycle = new Lifecycle( $this->attachments, Plugin::get_instance()->conversion_queue );
		$this->assertSame( 3, $lifecycle->restore() );
		$this->assertSame( 0, $lifecycle->restore(), 'Nothing is left to restore.' );
	}
}
