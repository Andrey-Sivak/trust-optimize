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

	public function test_sync_regenerates_variants_when_the_source_was_replaced_in_place() {
		$id = $this->upload();
		$this->processor()->sync( $id );
		$file   = get_attached_file( $id );
		$before = $this->original_variant( $id );
		$this->assertSame( (int) wp_filesize( $file ), $before['source_file_size'] );

		// Enable Media Replace and the like: new bytes under the same name.
		copy( DIR_TESTDATA . '/images/test-image.jpg', $file );
		clearstatcache( true, $file );

		$result = $this->processor()->sync( $id );

		$this->assertTrue( $result->is_success(), wp_json_encode( $result->to_array() ) );
		$after = $this->original_variant( $id );
		$this->assertSame( $before['relative_path'], $after['relative_path'] );
		$this->assertNotSame( $before['file_hash'], $after['file_hash'], 'The variant was built from the new source.' );
		$this->assertSame( hash_file( 'sha256', wp_upload_dir()['basedir'] . '/' . $after['relative_path'] ), $after['file_hash'] );
		$this->assertSame( (int) wp_filesize( $file ), $after['source_file_size'] );

		$this->assertSame( 'up_to_date', $this->processor()->sync( $id )->get_message(), 'The next sync has nothing to do.' );
	}

	/**
	 * The served webp variant of the original file.
	 *
	 * @param int $id Attachment ID.
	 * @return array
	 */
	private function original_variant( $id ) {
		foreach ( $this->variants->get_servable_for_attachment( $id ) as $row ) {
			if ( 'original' === $row['size_name'] && 'webp' === $row['format'] ) {
				return $row;
			}
		}

		$this->fail( 'The original has no webp variant.' );
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

	public function test_media_column_renders_the_state() {
		$id = $this->upload();
		$this->processor()->sync( $id );

		ob_start();
		Plugin::get_instance()->admin->render_media_column( 'trust_optimize_status', $id );
		$html = ob_get_clean();

		$this->assertStringContainsString( 'data-status="optimized"', $html );
	}
}
