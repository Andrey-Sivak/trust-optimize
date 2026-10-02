<?php
/**
 * Site Health tests for background processing.
 *
 * @package TrustOptimize\Tests
 */

use TrustOptimize\Capabilities\CapabilityService;
use TrustOptimize\Health\SiteHealth;
use TrustOptimize\Queue\ConversionQueue;

/**
 * @covers \TrustOptimize\Health\SiteHealth
 * @covers \TrustOptimize\Utils\DiskSpace
 */
class SiteHealthTest extends WP_UnitTestCase {

	/**
	 * Subject.
	 *
	 * @var SiteHealth
	 */
	private $health;

	public function set_up() {
		parent::set_up();
		update_option( CapabilityService::OPTION, array( 'webp' => true, 'avif' => false ) );
		$this->health = new SiteHealth( new CapabilityService() );
	}

	public function test_tests_are_registered_with_site_health() {
		( new SiteHealth( new CapabilityService() ) )->register();

		$tests = apply_filters( 'site_status_tests', array( 'direct' => array(), 'async' => array() ) );

		foreach ( array( 'trust_optimize_overdue_tasks', 'trust_optimize_formats', 'trust_optimize_disk_space' ) as $key ) {
			$this->assertArrayHasKey( $key, $tests['direct'] );
			$this->assertIsCallable( $tests['direct'][ $key ]['test'] );
		}
	}

	public function test_no_pending_tasks_is_good() {
		$this->assertSame( 'good', $this->health->test_overdue_tasks()['status'] );
	}

	public function test_a_fresh_task_is_not_overdue() {
		as_enqueue_async_action( ConversionQueue::HOOK_PROCESS, array( 'attachment_id' => 1 ), ConversionQueue::GROUP );

		$this->assertSame( 'good', $this->health->test_overdue_tasks()['status'] );
	}

	public function test_an_overdue_task_is_recommended() {
		as_schedule_single_action( time() - 2 * SiteHealth::OVERDUE_SECONDS, ConversionQueue::HOOK_PROCESS, array( 'attachment_id' => 2 ), ConversionQueue::GROUP );

		$result = $this->health->test_overdue_tasks();

		$this->assertSame( 'recommended', $result['status'] );
		$this->assertStringContainsString( 'wp action-scheduler run', $result['description'] );
	}

	public function test_an_overdue_task_of_another_group_is_ignored() {
		as_schedule_single_action( time() - 2 * SiteHealth::OVERDUE_SECONDS, 'some_other_hook', array(), 'other-group' );

		$this->assertSame( 'good', $this->health->test_overdue_tasks()['status'] );
	}

	public function test_formats_report_depends_on_webp_support() {
		$this->assertSame( 'good', $this->health->test_formats()['status'] );

		update_option( CapabilityService::OPTION, array( 'webp' => false, 'avif' => false ) );

		$this->assertSame( 'recommended', $this->health->test_formats()['status'] );
	}

	public function test_low_disk_space_is_recommended() {
		$this->assertSame( 'good', $this->health->test_disk_space()['status'] );

		add_filter( 'trust_optimize_min_free_disk_bytes', static function () {
			return PHP_INT_MAX;
		} );

		$this->assertSame( 'recommended', $this->health->test_disk_space()['status'] );
	}
}
