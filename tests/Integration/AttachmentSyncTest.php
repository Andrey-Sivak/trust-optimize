<?php
/**
 * Synchronous single-attachment path used by REST, WP-CLI and bulk jobs.
 *
 * @package TrustOptimize\Tests
 */

use TrustOptimize\Capabilities\CapabilityService;
use TrustOptimize\Core\Plugin;
use TrustOptimize\Database\DatabaseManager;
use TrustOptimize\Domain\AttachmentState;
use TrustOptimize\Queue\ConversionQueue;
use TrustOptimize\Storage\AttachmentRepository;
use TrustOptimize\Storage\VariantRepository;

/**
 * @covers \TrustOptimize\Processing\AttachmentProcessor
 * @covers \TrustOptimize\Planning\VariantPlanner::inventory
 */
class AttachmentSyncTest extends WP_UnitTestCase {

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
		as_unschedule_all_actions( ConversionQueue::HOOK_PROCESS );

		$database          = new DatabaseManager();
		$this->variants    = new VariantRepository( $database );
		$this->attachments = new AttachmentRepository( $database, $this->variants );
	}

	private function processor() {
		return Plugin::get_instance()->processor;
	}

	private function planner() {
		return Plugin::get_instance()->planner;
	}

	private function upload() {
		$id = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		// Start from a clean slate: the upload hook has already planned and queued it.
		ConversionQueue::cancel_tasks_for_attachment( $id );
		$this->variants->delete_for_attachment( $id );
		$this->attachments->delete( $id );

		return $id;
	}

	public function test_sync_converts_everything_and_is_idempotent() {
		$id = $this->upload();

		$result = $this->processor()->sync( $id );

		$this->assertTrue( $result->is_success(), wp_json_encode( $result->to_array() ) );
		$this->assertSame( AttachmentState::OPTIMIZED, $result->get_data()['state'] );
		$this->assertNotEmpty( $this->variants->get_servable_for_attachment( $id ) );

		$again = $this->processor()->sync( $id );
		$this->assertSame( 'up_to_date', $again->get_message() );
	}

	public function test_sync_removes_variants_of_a_disabled_format() {
		$id = $this->upload();
		$this->processor()->sync( $id );
		$paths = array_map(
			static function ( $row ) {
				return wp_upload_dir()['basedir'] . '/' . $row['relative_path'];
			},
			$this->variants->get_servable_for_attachment( $id )
		);
		update_option( 'trust_optimize_options', array( 'convert_to_webp' => 0, 'convert_to_avif' => 0 ) );

		$result = $this->processor()->sync( $id );

		$this->assertSame( count( $paths ), $result->get_data()['deleted'] );
		foreach ( $paths as $path ) {
			$this->assertFileDoesNotExist( $path );
		}
		$this->assertSame( array(), $this->variants->get_for_attachment( $id ) );
	}

	public function test_sync_skips_unsupported_sources() {
		$id = self::factory()->attachment->create(
			array(
				'post_mime_type' => 'image/webp',
				'file'           => '2026/05/source.webp',
			)
		);

		$result = $this->processor()->sync( $id );

		$this->assertTrue( $result->is_skipped() );
		$this->assertSame( 'unsupported_mime', $result->get_message() );
	}

	public function test_inventory_estimates_without_changing_anything() {
		$id = $this->upload();

		$before = $this->planner()->inventory( $id );

		$this->assertTrue( $before['eligible'] );
		$this->assertGreaterThan( 0, $before['estimated_variants_to_create'] );
		$this->assertFalse( $before['already_optimized'] );
		$this->assertSame( array(), $this->variants->get_for_attachment( $id ), 'Inventory must not write rows.' );

		$this->processor()->sync( $id );
		$after = $this->planner()->inventory( $id );

		$this->assertTrue( $after['already_optimized'] );
		$this->assertSame( 0, $after['estimated_variants_to_create'] );
	}

	public function test_inventory_reports_unsupported_mime_and_missing_files() {
		$webp = self::factory()->attachment->create(
			array(
				'post_mime_type' => 'image/webp',
				'file'           => '2026/05/source.webp',
			)
		);
		$this->assertSame( 'image/webp', $this->planner()->inventory( $webp )['unsupported_mime_type'] );

		$id = $this->upload();
		unlink( get_attached_file( $id ) );
		$this->assertTrue( $this->planner()->inventory( $id )['missing_source_file'] );
	}

	public function test_inventory_lists_enabled_but_unsupported_formats() {
		update_option( 'trust_optimize_options', array( 'convert_to_webp' => 1, 'convert_to_avif' => 1 ) );
		update_option( CapabilityService::OPTION, array( 'webp' => true, 'avif' => false ) );
		$id = $this->upload();

		$this->assertSame( array( 'avif' ), $this->planner()->inventory( $id )['unsupported_output_formats'] );
	}

	public function test_rest_image_status_reports_state_and_progress() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$id = $this->upload();
		$this->processor()->sync( $id );

		$response = rest_do_request( new WP_REST_Request( 'GET', "/trust-optimize/v1/image/{$id}/status" ) );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( AttachmentState::OPTIMIZED, $data['state'] );
		$this->assertSame( 'completed', $data['status'] );
		$this->assertSame( 100, $data['progress'] );
	}

	public function test_media_column_renders_the_state() {
		$id = $this->upload();
		$this->processor()->sync( $id );

		ob_start();
		Plugin::get_instance()->admin->render_media_column( 'trust_optimize_status', $id );
		$html = ob_get_clean();

		$this->assertStringContainsString( 'data-status="completed"', $html );
	}
}
