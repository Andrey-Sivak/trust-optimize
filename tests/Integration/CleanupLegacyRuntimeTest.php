<?php
/**
 * Removal of the queue tasks, options and transients of schema 1.x (03.6).
 *
 * @package TrustOptimize\Tests
 */

use TrustOptimize\Bulk\BulkJob;
use TrustOptimize\Bulk\BulkJobRepository;
use TrustOptimize\Capabilities\CapabilityService;
use TrustOptimize\Core\Plugin;
use TrustOptimize\Database\DatabaseManager;
use TrustOptimize\Domain\JobStatus;
use TrustOptimize\Migration\CleanupLegacyRuntime;
use TrustOptimize\Queue\ConversionQueue;
use TrustOptimize\Storage\AttachmentRepository;
use TrustOptimize\Storage\VariantRepository;

/**
 * @covers \TrustOptimize\Migration\CleanupLegacyRuntime
 */
class CleanupLegacyRuntimeTest extends WP_UnitTestCase {

	/**
	 * Step under test.
	 *
	 * @var CleanupLegacyRuntime
	 */
	private $step;

	public function set_up() {
		parent::set_up();
		update_option( CapabilityService::OPTION, array( 'webp' => true, 'avif' => false ) );
		update_option( 'trust_optimize_options', array( 'convert_to_webp' => 1, 'convert_to_avif' => 0 ) );
		as_unschedule_all_actions( ConversionQueue::HOOK_PROCESS );
		as_unschedule_all_actions( CleanupLegacyRuntime::LEGACY_TASK_HOOK );
		as_unschedule_all_actions( CleanupLegacyRuntime::LEGACY_BULK_HOOK );

		$this->step = new CleanupLegacyRuntime( Plugin::get_instance()->conversion_queue, new BulkJobRepository( new DatabaseManager() ) );
	}

	/**
	 * An attachment whose upload-time conversion is forgotten, like an attachment with only pending 1.x tasks.
	 *
	 * @return int
	 */
	private function attachment() {
		$id        = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		$database  = new DatabaseManager();
		$variants  = new VariantRepository( $database );
		$variants->delete_for_attachment( $id );
		( new AttachmentRepository( $database, $variants ) )->delete( $id );
		as_unschedule_all_actions( ConversionQueue::HOOK_PROCESS );

		return $id;
	}

	private function pending( $hook, $args = null ) {
		return as_get_scheduled_actions(
			array(
				'hook'   => $hook,
				'status' => ActionScheduler_Store::STATUS_PENDING,
				'args'   => $args,
			),
			'ids'
		);
	}

	public function test_a_1x_task_that_runs_before_the_step_is_turned_into_an_attachment_task() {
		$id = $this->attachment();

		$this->assertNotFalse( has_action( CleanupLegacyRuntime::LEGACY_TASK_HOOK ) );

		// What the Action Scheduler does with a 1.x task: it runs the hook with the payload.
		do_action( CleanupLegacyRuntime::LEGACY_TASK_HOOK, array( 'attachment_id' => $id, 'size_name' => 'original' ) );

		$this->assertCount( 1, $this->pending( ConversionQueue::HOOK_PROCESS, array( 'attachment_id' => $id ) ) );
	}

	public function test_pending_1x_tasks_become_attachment_tasks_and_are_cancelled() {
		$a = $this->attachment();
		$b = $this->attachment();

		as_enqueue_async_action( CleanupLegacyRuntime::LEGACY_TASK_HOOK, array( array( 'attachment_id' => $a, 'size_name' => 'original', 'target_format' => 'webp' ) ), ConversionQueue::GROUP );
		as_enqueue_async_action( CleanupLegacyRuntime::LEGACY_TASK_HOOK, array( $b ), ConversionQueue::GROUP );
		as_enqueue_async_action( CleanupLegacyRuntime::LEGACY_TASK_HOOK, array( array( 'attachment_id' => 999999 ) ), ConversionQueue::GROUP );

		$result = $this->step->run_batch( 0, 10 );

		$this->assertTrue( $result->is_done() );
		$this->assertSame( 3, $result->get_counts()['tasks'] );
		$this->assertSame( array(), $this->pending( CleanupLegacyRuntime::LEGACY_TASK_HOOK ) );
		$this->assertCount( 1, $this->pending( ConversionQueue::HOOK_PROCESS, array( 'attachment_id' => $a ) ) );
		$this->assertCount( 1, $this->pending( ConversionQueue::HOOK_PROCESS, array( 'attachment_id' => $b ) ) );
		$this->assertSame( array(), $this->pending( ConversionQueue::HOOK_PROCESS, array( 'attachment_id' => 999999 ) ) );
	}

	public function test_batches_continue_until_no_task_is_left() {
		foreach ( array( 1, 2, 3 ) as $number ) {
			as_enqueue_async_action( CleanupLegacyRuntime::LEGACY_TASK_HOOK, array( array( 'attachment_id' => 900000 + $number ) ), ConversionQueue::GROUP );
		}
		set_transient( 'trust_optimize_formats_5', array( 'webp' ), HOUR_IN_SECONDS );

		$first = $this->step->run_batch( 0, 2 );
		$this->assertFalse( $first->is_done() );
		$this->assertSame( array( 'webp' ), get_transient( 'trust_optimize_formats_5' ), 'The options are deleted only after the last task.' );

		$second = $this->step->run_batch( $first->get_cursor(), 2 );
		$this->assertTrue( $second->is_done() );
		$this->assertSame( 1, $second->get_counts()['tasks'] );
		$this->assertFalse( get_transient( 'trust_optimize_formats_5' ) );
	}

	public function test_options_and_transients_of_1x_are_deleted() {
		update_option( 'trust_optimize_preflight', array( 'x' => 1 ) );
		set_transient( 'trust_optimize_formats_5', array( 'webp' ), HOUR_IN_SECONDS );
		set_transient( 'trust_optimize_formats_6', array( 'webp' ), HOUR_IN_SECONDS );
		set_transient( 'trust_optimize_bulk_status_tick_1', 1, HOUR_IN_SECONDS );
		update_option( 'trust_optimize_bulk_tick_lock_1', time() );
		as_enqueue_async_action( 'trust_optimize_bulk_tick', array( 'job_id' => 1 ), ConversionQueue::GROUP );

		$this->step->run_batch( 0, 10 );

		$this->assertFalse( get_option( 'trust_optimize_preflight' ) );
		$this->assertFalse( get_transient( 'trust_optimize_formats_5' ) );
		$this->assertFalse( get_transient( 'trust_optimize_formats_6' ) );
		$this->assertFalse( get_transient( 'trust_optimize_bulk_status_tick_1' ) );
		$this->assertFalse( get_option( 'trust_optimize_bulk_tick_lock_1' ) );
		$this->assertSame( array(), $this->pending( 'trust_optimize_bulk_tick' ) );
	}

	public function test_an_active_bulk_job_of_1x_is_closed_but_a_job_of_2_0_is_kept() {
		global $wpdb;

		$table = ( new DatabaseManager() )->get_table_name( 'trust_optimize_jobs' );
		$wpdb->insert(
			$table,
			array(
				'type'              => BulkJob::TYPE_SYNC,
				'status'            => JobStatus::RUNNING,
				'settings_snapshot' => '[]',
				'updated_at'        => current_time( 'mysql' ),
			)
		);
		$legacy_id = (int) $wpdb->insert_id;
		$jobs      = new BulkJobRepository( new DatabaseManager() );
		$current   = $jobs->create( BulkJob::TYPE_SYNC, array( 'enabled' => array( 'webp' ) ), 1 );

		// The legacy job is active, so create() refused above only if it were not closed first.
		$this->assertFalse( $current );
		$this->step->run_batch( 0, 10 );

		$closed = $jobs->get( $legacy_id );
		$this->assertSame( JobStatus::CANCELLED, $closed->get_status() );
		$this->assertSame( 'superseded by 2.0', $closed->to_array()['last_error'] );

		$current = $jobs->create( BulkJob::TYPE_SYNC, array( 'enabled' => array( 'webp' ) ), 1 );
		$this->assertNotFalse( $current );

		$this->step->run_batch( 0, 10 );
		$this->assertSame( JobStatus::PENDING, $jobs->get( $current->get_id() )->get_status() );
	}
}
