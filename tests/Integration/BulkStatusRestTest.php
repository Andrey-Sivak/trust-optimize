<?php
/**
 * GET requests of the bulk API never change anything (04.6).
 *
 * @package TrustOptimize\Tests
 */

use TrustOptimize\Bulk\BulkJob;
use TrustOptimize\Bulk\BulkJobRepository;
use TrustOptimize\Bulk\BulkProducer;
use TrustOptimize\Capabilities\CapabilityService;
use TrustOptimize\Core\Plugin;
use TrustOptimize\Database\DatabaseManager;
use TrustOptimize\Domain\JobStatus;
use TrustOptimize\Queue\ConversionQueue;

/**
 * @covers \TrustOptimize\API\RestController::get_bulk_status
 */
class BulkStatusRestTest extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		update_option( CapabilityService::OPTION, array( 'webp' => true, 'avif' => false ) );
		update_option( 'trust_optimize_options', array( 'convert_to_webp' => 1, 'convert_to_avif' => 0 ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		as_unschedule_all_actions( ConversionQueue::HOOK_PROCESS );
		as_unschedule_all_actions( BulkProducer::HOOK_PRODUCE );
	}

	/**
	 * Everything a status request could touch.
	 *
	 * @return array
	 */
	private function snapshot() {
		global $wpdb;

		$tables = ( new DatabaseManager() )->get_plugin_table_names();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		return array(
			$wpdb->get_results( "SELECT * FROM `{$tables['jobs']}` ORDER BY id", ARRAY_A ),
			$wpdb->get_results( "SELECT * FROM `{$tables['attachments']}` ORDER BY attachment_id", ARRAY_A ),
			$wpdb->get_results( "SELECT * FROM `{$tables['variants']}` ORDER BY id", ARRAY_A ),
			as_get_scheduled_actions( array( 'status' => ActionScheduler_Store::STATUS_PENDING ), 'ids' ),
		);
		// phpcs:enable
	}

	public function test_polling_a_running_job_changes_nothing_and_converts_nothing() {
		self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/test-image.jpg' );
		$job = Plugin::get_instance()->bulk_producer->launch( BulkJob::TYPE_SYNC );
		$before = $this->snapshot();

		$response = rest_do_request( new WP_REST_Request( 'GET', '/trust-optimize/v1/bulk/status' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $job->get_id(), (int) $response->get_data()['job']['id'] );
		$this->assertSame( JobStatus::RUNNING, $response->get_data()['job']['status'] );
		$this->assertSame( $before, $this->snapshot() );
	}

	public function test_polling_does_not_pause_an_abandoned_job() {
		$job = Plugin::get_instance()->bulk_producer->launch( BulkJob::TYPE_SYNC );
		( new BulkJobRepository( new DatabaseManager() ) )->update( $job->get_id(), array( 'updated_at' => gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) ) );
		$before = $this->snapshot();

		$response = rest_do_request( new WP_REST_Request( 'GET', '/trust-optimize/v1/bulk/status' ) );

		$this->assertSame( JobStatus::RUNNING, $response->get_data()['job']['status'] );
		$this->assertSame( $before, $this->snapshot() );
	}

	public function test_the_status_reports_derived_counters() {
		$id = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/test-image.jpg' );
		$producer = Plugin::get_instance()->bulk_producer;
		$job      = $producer->launch( BulkJob::TYPE_SYNC );
		$producer->produce( $job->get_id() );
		ActionScheduler_QueueRunner::instance()->run();

		$data = rest_do_request( new WP_REST_Request( 'GET', '/trust-optimize/v1/bulk/status' ) )->get_data()['job'];

		$this->assertGreaterThanOrEqual( 1, $data['processed'] );
		$this->assertSame( 0, $data['failed_count'] );
		$this->assertNotEmpty( $id );
	}
}
