<?php
/**
 * Oversized images and repeated failures stay away from the editors (04.4).
 *
 * @package TrustOptimize\Tests
 */

use TrustOptimize\Capabilities\CapabilityService;
use TrustOptimize\Core\Plugin;
use TrustOptimize\Database\DatabaseManager;
use TrustOptimize\Domain\AttachmentState;
use TrustOptimize\Domain\VariantStatus;
use TrustOptimize\Features\Optimization\ImageConverter;
use TrustOptimize\Files\AtomicImageWriter;
use TrustOptimize\Files\FileOwnership;
use TrustOptimize\Processing\AttachmentProcessor;
use TrustOptimize\Queue\ConversionQueue;
use TrustOptimize\Storage\AttachmentRepository;
use TrustOptimize\Storage\VariantRepository;
use TrustOptimize\Value\OptimizeResult;

/**
 * Converter that leaves every row pending.
 */
class Stubborn_Converter extends ImageConverter {

	/**
	 * Calls so far.
	 *
	 * @var int
	 */
	public $calls = 0;

	public function convert( array $variant_row, TrustOptimize\Settings\OptimizationSettings $settings ) {
		if ( ++$this->calls > 20 ) {
			throw new RuntimeException( 'The same row was tried again and again.' );
		}

		return OptimizeResult::skipped( 'not_pending' );
	}
}

/**
 * @covers \TrustOptimize\Planning\ImageLimits
 * @covers \TrustOptimize\Planning\VariantPlanner
 * @covers \TrustOptimize\Processing\AttachmentProcessor
 */
class ImageGuardTest extends WP_UnitTestCase {

	/**
	 * Attachments.
	 *
	 * @var AttachmentRepository
	 */
	private $attachments;

	/**
	 * Variants.
	 *
	 * @var VariantRepository
	 */
	private $variants;

	public function set_up() {
		parent::set_up();
		update_option( CapabilityService::OPTION, array( 'webp' => true, 'avif' => false ) );
		update_option( 'trust_optimize_options', array( 'convert_to_webp' => 1, 'convert_to_avif' => 0 ) );
		as_unschedule_all_actions( ConversionQueue::HOOK_PROCESS );

		$database          = new DatabaseManager();
		$this->variants    = new VariantRepository( $database );
		$this->attachments = new AttachmentRepository( $database, $this->variants );
	}

	private function upload() {
		$id = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/test-image.jpg' );
		as_unschedule_all_actions( ConversionQueue::HOOK_PROCESS );

		return $id;
	}

	private function claim_times( $id, $times ) {
		for ( $i = 0; $i < $times; $i++ ) {
			global $wpdb;
			$tables = ( new DatabaseManager() )->get_plugin_table_names();
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			$wpdb->query( $wpdb->prepare( "UPDATE `{$tables['attachments']}` SET state = 'none' WHERE attachment_id = %d", $id ) );
			$this->attachments->claim( $id );
		}

		// The last worker died: the claim is long gone.
		$this->attachments->set_state( $id, AttachmentState::NONE );
	}

	public function test_an_image_with_too_many_pixels_is_skipped_by_its_metadata() {
		$id       = $this->upload();
		$metadata = wp_get_attachment_metadata( $id );
		wp_update_attachment_metadata( $id, array_merge( $metadata, array( 'width' => 12000, 'height' => 12000 ) ) );

		$result = Plugin::get_instance()->processor->sync( $id );

		$this->assertTrue( $result->is_skipped() );
		$this->assertSame( 'too_large', $result->get_message() );
		$row = $this->attachments->get( $id );
		$this->assertSame( AttachmentState::SKIPPED, $row['state'] );
		$this->assertSame( 'too_large', $row['reason'] );
	}

	public function test_the_pixel_limit_can_be_changed_by_a_filter() {
		$id = $this->upload();
		add_filter(
			'trust_optimize_max_pixels',
			static function () {
				return 10;
			}
		);

		$this->assertSame( 'too_large', Plugin::get_instance()->processor->sync( $id )->get_message() );
	}

	public function test_an_image_that_does_not_fit_into_the_free_memory_is_skipped() {
		$id       = $this->upload();
		$metadata = wp_get_attachment_metadata( $id );
		wp_update_attachment_metadata( $id, array_merge( $metadata, array( 'width' => 3000, 'height' => 3000 ) ) );
		$old = ini_get( 'memory_limit' );
		ini_set( 'memory_limit', (string) ( memory_get_usage( true ) + 10 * MB_IN_BYTES ) ); // phpcs:ignore WordPress.PHP.IniSet.memory_limit_Disallowed

		try {
			$reason = Plugin::get_instance()->processor->sync( $id )->get_message();
		} finally {
			ini_set( 'memory_limit', $old ); // phpcs:ignore WordPress.PHP.IniSet.memory_limit_Disallowed
		}

		$this->assertSame( 'insufficient_memory', $reason );
	}

	public function test_a_missing_file_is_skipped_before_any_row_is_converted() {
		$id = $this->upload();
		wp_delete_file( get_attached_file( $id ) );

		$this->assertSame( 'missing_file', Plugin::get_instance()->processor->sync( $id )->get_message() );
	}

	public function test_a_normal_image_is_converted() {
		$id = $this->upload();

		$this->assertTrue( Plugin::get_instance()->processor->sync( $id )->is_success() );
	}

	public function test_failed_variants_are_not_reset_while_the_attempts_are_used_up() {
		$id = $this->upload();
		Plugin::get_instance()->planner->plan( $id );
		foreach ( $this->variants->get_for_attachment( $id ) as $row ) {
			$this->variants->transition( $row['id'], VariantStatus::PENDING, VariantStatus::FAILED, array( 'reason' => 'save_failed' ) );
		}
		$this->claim_times( $id, AttachmentRepository::MAX_ATTEMPTS );

		$plan = Plugin::get_instance()->planner->plan( $id );

		$this->assertFalse( $plan->has_work(), 'A poison file is not retried by itself.' );

		$this->attachments->reset_attempts( $id );
		$this->assertTrue( Plugin::get_instance()->planner->plan( $id )->has_work() );
	}

	public function test_an_explicit_sync_gives_a_poison_file_another_chance() {
		$id = $this->upload();
		Plugin::get_instance()->planner->plan( $id );
		foreach ( $this->variants->get_for_attachment( $id ) as $row ) {
			$this->variants->transition( $row['id'], VariantStatus::PENDING, VariantStatus::FAILED, array( 'reason' => 'save_failed' ) );
		}
		$this->claim_times( $id, AttachmentRepository::MAX_ATTEMPTS );

		$result = Plugin::get_instance()->processor->sync( $id );

		$this->assertTrue( $result->is_success() );
		$this->assertSame( 0, $this->attachments->get_attempts( $id ) );
	}

	public function test_a_row_that_stays_pending_is_tried_once_per_run() {
		$id = $this->upload();
		Plugin::get_instance()->planner->plan( $id );

		$plugin    = Plugin::get_instance();
		$converter = new Stubborn_Converter( $this->variants, new AtomicImageWriter( $this->variants, new FileOwnership( $this->variants ) ), new CapabilityService() );
		$processor = new AttachmentProcessor( $this->attachments, $this->variants, $converter, $plugin->planner, $plugin->cleanup, new TrustOptimize\Admin\Settings(), new CapabilityService() );

		$processor->run( $id, INF );

		$this->assertSame( count( $this->variants->get_for_attachment( $id ) ), $converter->calls );
	}

	public function test_a_claim_counts_as_an_attempt_and_a_finished_run_resets_it() {
		$id = $this->upload();
		Plugin::get_instance()->planner->plan( $id );

		$this->claim_times( $id, 1 );
		$this->assertSame( 1, $this->attachments->get_attempts( $id ) );

		Plugin::get_instance()->processor->run( $id, INF );
		$this->assertSame( 0, $this->attachments->get_attempts( $id ) );
	}
}
