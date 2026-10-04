<?php
/**
 * Admin notices (06.7).
 *
 * @package TrustOptimize\Tests
 */

use TrustOptimize\Admin\Notices;
use TrustOptimize\Bulk\BulkJob;
use TrustOptimize\Bulk\BulkJobRepository;
use TrustOptimize\Bulk\BulkProducer;
use TrustOptimize\Capabilities\CapabilityService;
use TrustOptimize\Core\Plugin;
use TrustOptimize\Database\DatabaseManager;
use TrustOptimize\Domain\JobStatus;
use TrustOptimize\Queue\ConversionQueue;

/**
 * @covers \TrustOptimize\Admin\Notices
 */
class NoticesTest extends WP_UnitTestCase {

	/**
	 * Subject.
	 *
	 * @var Notices
	 */
	private $notices;

	/**
	 * Jobs.
	 *
	 * @var BulkJobRepository
	 */
	private $jobs;

	public function set_up() {
		parent::set_up();
		update_option( CapabilityService::OPTION, array( 'webp' => true, 'avif' => true ) );
		as_unschedule_all_actions( BulkProducer::HOOK_PRODUCE );
		as_unschedule_all_actions( ConversionQueue::HOOK_PROCESS );

		$database      = new DatabaseManager();
		$this->jobs    = new BulkJobRepository( $database );
		$this->notices = new Notices( $this->jobs, Plugin::get_instance()->bulk_producer, new CapabilityService() );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		set_current_screen( 'plugins' );
	}

	private function html() {
		ob_start();
		$this->notices->render();

		return ob_get_clean();
	}

	/**
	 * Run an admin-post handler and return where it redirected.
	 *
	 * @param string $handler Method of Notices.
	 * @param string $action  Nonce action.
	 * @param array  $get     Query arguments.
	 * @return string
	 */
	private function request( $handler, $action, array $get = array() ) {
		$_REQUEST['_wpnonce'] = wp_create_nonce( $action );
		$_GET                 = $get;
		add_filter(
			'wp_redirect',
			static function ( $url ) {
				throw new RuntimeException( $url );
			}
		);

		try {
			$this->notices->$handler();
		} catch ( RuntimeException $e ) {
			return $e->getMessage();
		}

		return '';
	}

	private function downgrade( $at ) {
		( new CapabilityService() )->downgrade( 'avif', 'imageavif() failed' );

		$stored                          = get_option( CapabilityService::OPTION );
		$stored['downgraded']['avif']['at'] = $at;
		update_option( CapabilityService::OPTION, $stored );
	}

	public function test_nothing_without_news() {
		$this->assertSame( '', $this->html() );
	}

	public function test_nothing_for_users_who_cannot_manage_options() {
		$this->downgrade( '2026-10-01 10:00:00' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$this->assertSame( '', $this->html() );
	}

	public function test_a_job_paused_for_disk_space_offers_resume_and_resumes() {
		$job = Plugin::get_instance()->bulk_producer->launch( BulkJob::TYPE_SYNC );
		$this->jobs->pause( $job->get_id(), BulkProducer::REASON_LOW_DISK );

		$html = $this->html();

		$this->assertStringContainsString( 'disk is almost full', $html );
		$this->assertStringContainsString( 'Resume', $html );
		$this->assertStringNotContainsString( 'Dismiss', $html );

		$this->request( 'handle_resume', Notices::ACTION_RESUME );

		$this->assertSame( JobStatus::RUNNING, $this->jobs->get( $job->get_id() )->get_status() );
		$this->assertSame( '', $this->html() );
	}

	public function test_a_paused_job_with_another_reason_has_no_notice() {
		$job = Plugin::get_instance()->bulk_producer->launch( BulkJob::TYPE_SYNC );
		$this->jobs->pause( $job->get_id() );

		$this->assertSame( '', $this->html() );
	}

	public function test_a_format_downgrade_is_shown_and_a_new_one_shows_again_after_dismissal() {
		$this->downgrade( '2026-10-01 10:00:00' );

		$html = $this->html();
		$this->assertStringContainsString( 'AVIF files are no longer created', $html );
		$this->assertStringContainsString( 'imageavif() failed', $html );

		$this->request( 'handle_dismiss', Notices::ACTION_DISMISS, array( 'notice' => 'downgrade:avif:2026-10-01 10:00:00' ) );
		$this->assertSame( '', $this->html() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertStringContainsString( 'AVIF files are no longer created', $this->html() );

		$this->downgrade( '2026-10-02 10:00:00' );
		$this->assertStringContainsString( 'AVIF files are no longer created', $this->html() );
	}
}
