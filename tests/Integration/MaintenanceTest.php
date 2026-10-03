<?php
/**
 * Hourly repair of lost tasks and poison files (04.4).
 *
 * @package TrustOptimize\Tests
 */

use TrustOptimize\Capabilities\CapabilityService;
use TrustOptimize\Core\Plugin;
use TrustOptimize\Database\DatabaseManager;
use TrustOptimize\Domain\AttachmentState;
use TrustOptimize\Queue\ConversionQueue;
use TrustOptimize\Queue\Maintenance;
use TrustOptimize\Storage\AttachmentRepository;
use TrustOptimize\Storage\VariantRepository;

/**
 * @covers \TrustOptimize\Queue\Maintenance
 * @covers \TrustOptimize\Storage\AttachmentRepository
 */
class MaintenanceTest extends WP_UnitTestCase {

	/**
	 * Attachments.
	 *
	 * @var AttachmentRepository
	 */
	private $attachments;

	/**
	 * Subject.
	 *
	 * @var Maintenance
	 */
	private $maintenance;

	public function set_up() {
		parent::set_up();
		update_option( CapabilityService::OPTION, array( 'webp' => true, 'avif' => false ) );
		update_option( 'trust_optimize_options', array( 'convert_to_webp' => 1, 'convert_to_avif' => 0 ) );
		as_unschedule_all_actions( ConversionQueue::HOOK_PROCESS );
		as_unschedule_all_actions( Maintenance::HOOK );

		$database          = new DatabaseManager();
		$this->attachments = new AttachmentRepository( $database, new VariantRepository( $database ) );
		$this->maintenance = new Maintenance( $this->attachments, Plugin::get_instance()->conversion_queue );
	}

	/**
	 * An attachment whose last change is 20 minutes old.
	 *
	 * @param string $state    AttachmentState.
	 * @param int    $attempts Failed attempts.
	 * @return int
	 */
	private function stuck( $state, $attempts = 0 ) {
		global $wpdb;

		$id = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/test-image.jpg' );
		as_unschedule_all_actions( ConversionQueue::HOOK_PROCESS );
		$this->attachments->set_state( $id, $state );

		$tables = ( new DatabaseManager() )->get_plugin_table_names();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "UPDATE `{$tables['attachments']}` SET attempts = %d, updated_at = %s WHERE attachment_id = %d", $attempts, gmdate( 'Y-m-d H:i:s', time() - 1200 ), $id ) );

		return $id;
	}

	private function pending( $id ) {
		return as_get_scheduled_actions(
			array(
				'hook'   => ConversionQueue::HOOK_PROCESS,
				'status' => ActionScheduler_Store::STATUS_PENDING,
				'args'   => array( 'attachment_id' => $id ),
			),
			'ids'
		);
	}

	public function test_a_poison_file_is_given_up_after_three_claims() {
		$id = $this->stuck( AttachmentState::PROCESSING, AttachmentRepository::MAX_ATTEMPTS );

		$this->assertSame( 1, $this->maintenance->run() );

		$row = $this->attachments->get( $id );
		$this->assertSame( AttachmentState::FAILED, $row['state'] );
		$this->assertSame( 'max_attempts', $row['reason'] );
		$this->assertSame( array(), $this->pending( $id ) );
	}

	public function test_a_stuck_attachment_below_the_limit_is_queued_again() {
		$id = $this->stuck( AttachmentState::PROCESSING, 1 );

		$this->maintenance->run();

		$this->assertSame( AttachmentState::QUEUED, $this->attachments->get_state( $id ) );
		$this->assertCount( 1, $this->pending( $id ) );

		ActionScheduler_QueueRunner::instance()->run();
		$this->assertSame( AttachmentState::OPTIMIZED, $this->attachments->get_state( $id ) );
		$this->assertSame( 0, $this->attachments->get_attempts( $id ), 'A worker that comes back alive resets the count.' );
	}

	public function test_a_queued_attachment_without_a_task_is_queued_again() {
		$id = $this->stuck( AttachmentState::QUEUED );

		$this->maintenance->run();

		$this->assertCount( 1, $this->pending( $id ) );
	}

	public function test_an_attachment_with_a_task_is_left_alone() {
		$id = $this->stuck( AttachmentState::QUEUED );
		as_enqueue_async_action( ConversionQueue::HOOK_PROCESS, array( 'attachment_id' => $id ), ConversionQueue::GROUP );

		$this->assertSame( 0, $this->maintenance->run() );
		$this->assertCount( 1, $this->pending( $id ) );
	}

	public function test_recent_work_is_left_alone() {
		$id = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/test-image.jpg' );
		as_unschedule_all_actions( ConversionQueue::HOOK_PROCESS );
		$this->attachments->claim( $id );

		$this->assertSame( 0, $this->maintenance->run() );
		$this->assertSame( AttachmentState::PROCESSING, $this->attachments->get_state( $id ) );
	}

	public function test_the_task_is_scheduled_once() {
		Maintenance::schedule();
		Maintenance::schedule();

		$this->assertCount( 1, as_get_scheduled_actions( array( 'hook' => Maintenance::HOOK, 'status' => ActionScheduler_Store::STATUS_PENDING ), 'ids' ) );

		Maintenance::unschedule();
		$this->assertSame( array(), as_get_scheduled_actions( array( 'hook' => Maintenance::HOOK, 'status' => ActionScheduler_Store::STATUS_PENDING ), 'ids' ) );
	}
}
