<?php
/**
 * VariantPlanner::plan() against real attachments.
 *
 * @package TrustOptimize\Tests
 */

use TrustOptimize\Admin\Settings;
use TrustOptimize\Capabilities\CapabilityService;
use TrustOptimize\Database\DatabaseManager;
use TrustOptimize\Domain\AttachmentState;
use TrustOptimize\Domain\VariantStatus;
use TrustOptimize\Planning\VariantPlanner;
use TrustOptimize\Storage\AttachmentRepository;
use TrustOptimize\Storage\VariantRepository;

/**
 * @covers \TrustOptimize\Planning\VariantPlanner
 */
class VariantPlannerTest extends WP_UnitTestCase {

	/**
	 * Planner.
	 *
	 * @var VariantPlanner
	 */
	private $planner;

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
		$this->planner     = new VariantPlanner( $this->variants, $this->attachments, new Settings(), new CapabilityService() );
	}

	private function upload() {
		$id = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		$this->assertGreaterThan( 0, $id );
		// Ignore whatever the legacy upload hook did to the new tables.
		$this->variants->delete_for_attachment( $id );
		$this->attachments->delete( $id );

		return $id;
	}

	public function test_plans_pending_rows_for_original_and_sizes() {
		$id   = $this->upload();
		$plan = $this->planner->plan( $id );

		$this->assertFalse( $plan->is_skipped() );
		$this->assertTrue( $plan->has_work() );

		$sizes = array_column( $plan->pending(), 'size_name' );
		$this->assertContains( 'original', $sizes );
		$this->assertContains( 'thumbnail', $sizes );
		foreach ( $plan->pending() as $row ) {
			$this->assertSame( 'webp', $row['format'] );
			$this->assertSame( VariantStatus::PENDING, $row['status'] );
			$this->assertStringEndsWith( '.jpg', $row['source_relative_path'] );
		}
		$this->assertSame( AttachmentState::NONE, $this->attachments->get_state( $id ), 'Whoever queues or claims the attachment sets the state.' );
	}

	public function test_planning_twice_does_not_duplicate_rows() {
		$id = $this->upload();

		$first = count( $this->planner->plan( $id )->pending() );
		$this->planner->plan( $id );

		$this->assertCount( $first, $this->variants->get_for_attachment( $id ) );
	}

	public function test_stale_done_rows_return_to_pending_and_current_ones_do_not() {
		$id = $this->upload();
		$this->planner->plan( $id );
		foreach ( $this->variants->get_for_attachment( $id ) as $row ) {
			$this->variants->transition( $row['id'], VariantStatus::PENDING, VariantStatus::DONE, array( 'quality' => 85 ) );
		}
		update_option( 'trust_optimize_options', array( 'convert_to_webp' => 1, 'convert_to_avif' => 0, 'webp_quality' => 85 ) );

		$this->assertFalse( $this->planner->plan( $id )->has_work(), 'Same quality: nothing to redo.' );

		update_option( 'trust_optimize_options', array( 'convert_to_webp' => 1, 'convert_to_avif' => 0, 'webp_quality' => 60 ) );

		$this->assertTrue( $this->planner->plan( $id )->has_work(), 'Different quality: variants are reconverted.' );
	}

	public function test_disabling_a_format_lists_its_rows_for_deletion() {
		$id = $this->upload();
		$this->planner->plan( $id );
		update_option( 'trust_optimize_options', array( 'convert_to_webp' => 0, 'convert_to_avif' => 0 ) );

		$plan = $this->planner->plan( $id );

		$this->assertFalse( $plan->has_work() );
		$this->assertNotEmpty( $plan->to_delete() );
		$this->assertSame( 'webp', $plan->to_delete()[0]['format'] );
	}

	public function test_webp_source_gets_no_variants() {
		$id = self::factory()->attachment->create(
			array(
				'post_mime_type' => 'image/webp',
				'file'           => '2026/05/source.webp',
			)
		);

		$plan = $this->planner->plan( $id );

		$this->assertTrue( $plan->is_skipped() );
		$this->assertSame( 'unsupported_mime', $plan->skip_reason() );
		$this->assertSame( array(), $this->variants->get_for_attachment( $id ) );
		$this->assertSame( AttachmentState::SKIPPED, $this->attachments->get_state( $id ) );
	}
}
