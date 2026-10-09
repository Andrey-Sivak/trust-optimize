<?php
/**
 * Dashboard statistics.
 *
 * @package TrustOptimize\Tests
 */

use TrustOptimize\Admin\Statistics;
use TrustOptimize\Capabilities\CapabilityService;
use TrustOptimize\Core\Plugin;
use TrustOptimize\Database\DatabaseManager;
use TrustOptimize\Domain\AttachmentState;
use TrustOptimize\Domain\VariantStatus;
use TrustOptimize\Storage\VariantRepository;

/**
 * @covers \TrustOptimize\Admin\Statistics
 * @covers \TrustOptimize\Storage\VariantRepository::sum_saved_bytes
 */
class StatisticsTest extends WP_UnitTestCase {

	/**
	 * Variants.
	 *
	 * @var VariantRepository
	 */
	private $variants;

	/**
	 * Subject.
	 *
	 * @var Statistics
	 */
	private $statistics;

	public function set_up() {
		parent::set_up();
		update_option( CapabilityService::OPTION, array( 'webp' => true, 'avif' => false ) );
		update_option( 'trust_optimize_options', array( 'convert_to_webp' => 1, 'convert_to_avif' => 0, 'webp_quality' => 80 ) );
		delete_transient( Statistics::TRANSIENT );

		$this->variants   = new VariantRepository( new DatabaseManager() );
		$this->statistics = new Statistics( Plugin::get_instance()->inventory, $this->variants );
	}

	private function image( $mime = 'image/jpeg' ) {
		return self::factory()->attachment->create( array( 'post_mime_type' => $mime ) );
	}

	private function variant( $attachment_id, $name, $format, $source_size, $file_size, $status = VariantStatus::DONE ) {
		$this->variants->upsert(
			array(
				'attachment_id'        => $attachment_id,
				'size_name'            => $name,
				'format'               => $format,
				'status'               => $status,
				'quality'              => 80,
				'source_file_size'     => $source_size,
				'file_size'            => $file_size,
				'source_relative_path' => 'stats/' . $name . '.jpg',
				'relative_path'        => 'stats/' . $name . '.jpg.' . $format,
			)
		);
	}

	private function fixture() {
		$optimized = $this->image();
		$failed    = $this->image();
		$queued    = $this->image( 'image/png' );
		$this->image( 'image/webp' );

		$repository = new TrustOptimize\Storage\AttachmentRepository( new DatabaseManager(), $this->variants );
		$repository->set_state( $optimized, AttachmentState::OPTIMIZED );
		$repository->set_state( $failed, AttachmentState::FAILED, 'x' );
		$repository->set_state( $queued, AttachmentState::QUEUED );

		$this->variant( $optimized, 'full', 'webp', 1000, 400 );
		$this->variant( $optimized, 'large', 'webp', 2000, 500 );
		$this->variant( $optimized, 'full', 'avif', 1000, 300 );
		$this->variant( $failed, 'full', 'webp', 5000, 100, VariantStatus::FAILED );

		return $optimized;
	}

	public function test_the_numbers_come_from_the_tables() {
		$this->fixture();

		$stats = $this->statistics->get();

		$this->assertSame( 4, $stats['total_images'] );
		$this->assertSame( 3, $stats['eligible'] );
		$this->assertSame( 1, $stats['optimized'] );
		$this->assertSame( 1, $stats['failed'] );
		$this->assertSame( 1, $stats['queued'] );
		$this->assertSame( 0, $stats['partial'] );
		$this->assertSame( 2100, $stats['saved_bytes_by_format']['webp'], 'Finished WebP variants only: (1000-400)+(2000-500).' );
		$this->assertSame( 700, $stats['saved_bytes_by_format']['avif'], 'Finished AVIF variants only: 1000-300.' );
		$this->assertSame( 1, $stats['outdated'], 'The AVIF variant, because AVIF is switched off.' );
		$this->assertEquals( 33.3, $stats['rate'] );
	}

	public function test_the_numbers_are_cached_until_a_task_finishes_or_settings_change() {
		$attachment = $this->fixture();
		$this->assertSame( 1, $this->statistics->get()['optimized'] );

		( new TrustOptimize\Storage\AttachmentRepository( new DatabaseManager(), $this->variants ) )->set_state( $attachment, AttachmentState::PARTIAL );
		$this->assertSame( 1, $this->statistics->get()['optimized'], 'Served from the cache.' );

		$this->statistics->register();
		$action = new ActionScheduler_Action( 'trust_optimize_process_attachment', array(), new ActionScheduler_NullSchedule(), 'other-plugin' );
		do_action( 'action_scheduler_after_execute', 1, $action );
		$this->assertSame( 1, $this->statistics->get()['optimized'], 'Tasks of other plugins do not flush.' );

		$action = new ActionScheduler_Action( 'trust_optimize_process_attachment', array(), new ActionScheduler_NullSchedule(), 'trust-optimize' );
		do_action( 'action_scheduler_after_execute', 1, $action );
		$this->assertSame( 0, $this->statistics->get()['optimized'] );
		$this->assertSame( 1, $this->statistics->get()['partial'] );

		( new TrustOptimize\Storage\AttachmentRepository( new DatabaseManager(), $this->variants ) )->set_state( $attachment, AttachmentState::OPTIMIZED );
		update_option( 'trust_optimize_options', array( 'convert_to_webp' => 1, 'webp_quality' => 70 ) );
		$this->assertSame( 1, $this->statistics->get()['optimized'], 'A changed setting flushes the cache.' );
	}

	public function test_an_empty_library_has_zero_rate() {
		$stats = $this->statistics->get();

		$this->assertSame( array( 'avif' => 0, 'webp' => 0 ), $stats['saved_bytes_by_format'] );
		$this->assertSame( 0, $stats['rate'] );
	}

	public function test_the_dashboard_shows_the_numbers() {
		$this->fixture();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		ob_start();
		Plugin::get_instance()->admin->display_admin_page();
		$html = ob_get_clean();

		$this->assertStringContainsString( '2.1 KB', $html );
		$this->assertStringContainsString( '700.0 B', $html );
		$this->assertStringContainsString( 'Saved with AVIF', $html );
		$this->assertStringContainsString( '33.3%', $html );
	}

	public function test_the_card_falls_back_to_webp_when_there_are_no_avif_files() {
		$image = $this->image();
		$this->variant( $image, 'full', 'webp', 1000, 400 );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		ob_start();
		Plugin::get_instance()->admin->display_admin_page();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'Saved with WebP', $html );
		$this->assertStringNotContainsString( 'Saved with AVIF', $html );
	}
}
