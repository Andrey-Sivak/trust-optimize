<?php
/**
 * One Action Scheduler action per attachment (a partial result is stored).
 *
 * @package TrustOptimize\Tests
 */

use TrustOptimize\Capabilities\CapabilityService;
use TrustOptimize\Database\DatabaseManager;
use TrustOptimize\Domain\AttachmentState;
use TrustOptimize\Domain\VariantStatus;
use TrustOptimize\Queue\ConversionQueue;
use TrustOptimize\Storage\AttachmentRepository;
use TrustOptimize\Storage\VariantRepository;

/**
 * @covers \TrustOptimize\Queue\ConversionQueue
 * @covers \TrustOptimize\Processing\AttachmentProcessor
 */
class ConversionQueueTest extends WP_UnitTestCase {

	/**
	 * Variants.
	 *
	 * @var VariantRepository
	 */
	private $variants;

	/**
	 * Attachments.
	 *
	 * @var AttachmentRepository
	 */
	private $attachments;

	public function set_up() {
		parent::set_up();
		update_option( CapabilityService::OPTION, array( 'webp' => true, 'avif' => true ) );
		update_option( 'trust_optimize_options', array( 'convert_to_webp' => 1, 'convert_to_avif' => 0 ) );

		$database          = new DatabaseManager();
		$this->variants    = new VariantRepository( $database );
		$this->attachments = new AttachmentRepository( $database, $this->variants );
		as_unschedule_all_actions( ConversionQueue::HOOK_PROCESS );
	}

	private function pending_actions( $attachment_id = null ) {
		$args = array(
			'hook'   => ConversionQueue::HOOK_PROCESS,
			'status' => ActionScheduler_Store::STATUS_PENDING,
		);
		if ( null !== $attachment_id ) {
			$args['args'] = array( 'attachment_id' => $attachment_id );
		}

		return as_get_scheduled_actions( $args, 'ids' );
	}

	private function run_queue() {
		ActionScheduler_QueueRunner::instance()->run();
	}

	private function upload() {
		$id = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		$this->assertGreaterThan( 0, $id );

		return $id;
	}

	public function test_upload_is_planned_queued_and_optimized_by_one_action() {
		$id = $this->upload();

		$this->assertCount( 1, $this->pending_actions( $id ), 'One action per attachment, not one per variant.' );
		$this->assertSame( AttachmentState::QUEUED, $this->attachments->get_state( $id ) );

		$this->run_queue();

		$this->assertSame( AttachmentState::OPTIMIZED, $this->attachments->get_state( $id ) );
		$done = $this->variants->get_servable_for_attachment( $id );
		$this->assertNotEmpty( $done );
		foreach ( $done as $row ) {
			$this->assertFileExists( wp_upload_dir()['basedir'] . '/' . $row['relative_path'] );
		}
		$this->assertSame( array(), $this->pending_actions( $id ) );
	}

	public function test_enqueueing_twice_does_not_duplicate_the_action() {
		$id    = $this->upload();
		$queue = $this->queue();

		$this->assertFalse( $queue->enqueue( $id ) );
		$this->assertCount( 1, $this->pending_actions( $id ) );
	}

	/**
	 * Regression: one failing variant must leave the attachment "partial", not stuck.
	 */
	public function test_one_failed_variant_gives_partial() {
		$id = $this->upload();
		foreach ( $this->variants->get_for_attachment( $id ) as $row ) {
			if ( 'thumbnail' === $row['size_name'] ) {
				unlink( wp_upload_dir()['basedir'] . '/' . $row['source_relative_path'] );
			}
		}

		$this->run_queue();

		$this->assertSame( AttachmentState::PARTIAL, $this->attachments->get_state( $id ) );
		$attachment = $this->attachments->get( $id );
		$this->assertSame( 'missing_file', $attachment['reason'] );
		$this->assertStringContainsString( 'Source file is missing', $attachment['last_error'] );
		$statuses = array_unique( array_column( $this->variants->get_for_attachment( $id ), 'status' ) );
		sort( $statuses );
		$this->assertSame( array( VariantStatus::DONE, VariantStatus::FAILED ), $statuses );
	}

	public function test_exhausted_time_budget_leaves_the_rest_pending_and_reschedules() {
		$id = $this->upload();
		as_unschedule_all_actions( ConversionQueue::HOOK_PROCESS );
		add_filter( 'trust_optimize_worker_time_budget', '__return_zero' );

		$this->queue()->process( $id );

		$this->assertSame( AttachmentState::QUEUED, $this->attachments->get_state( $id ) );
		$this->assertSame( array(), $this->variants->get_servable_for_attachment( $id ) );
		$this->assertCount( 1, $this->pending_actions( $id ), 'The run continues in a new action.' );
	}

	public function test_cancel_removes_the_pending_action() {
		$id = $this->upload();

		ConversionQueue::cancel_tasks_for_attachment( $id );

		$this->assertSame( array(), $this->pending_actions( $id ) );
	}

	/**
	 * The queue registered by the plugin on boot.
	 *
	 * @return ConversionQueue
	 */
	private function queue() {
		return \TrustOptimize\Core\Plugin::get_instance()->conversion_queue;
	}
}
