<?php
/**
 * Regenerated metadata must clean up variants that no longer belong (02.13).
 *
 * @package TrustOptimize\Tests
 */

use TrustOptimize\Capabilities\CapabilityService;
use TrustOptimize\Core\Plugin;
use TrustOptimize\Database\DatabaseManager;
use TrustOptimize\Queue\ConversionQueue;
use TrustOptimize\Storage\AttachmentRepository;
use TrustOptimize\Storage\VariantRepository;

/**
 * @covers \TrustOptimize\Queue\ConversionQueue::handle_new_metadata
 */
class MetadataRegenerationTest extends WP_UnitTestCase {

	/**
	 * Variants.
	 *
	 * @var VariantRepository
	 */
	private $variants;

	public function set_up() {
		parent::set_up();
		update_option( CapabilityService::OPTION, array( 'webp' => true, 'avif' => true ) );
		update_option( 'trust_optimize_options', array( 'convert_to_webp' => 1, 'convert_to_avif' => 0 ) );
		as_unschedule_all_actions( ConversionQueue::HOOK_PROCESS );
		$this->variants = new VariantRepository( new DatabaseManager() );
	}

	private function optimized_upload() {
		$id = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		ActionScheduler_QueueRunner::instance()->run();

		return $id;
	}

	private function abs( $relative ) {
		return wp_upload_dir()['basedir'] . '/' . $relative;
	}

	private function medium_variant( $id ) {
		foreach ( $this->variants->get_for_attachment( $id ) as $row ) {
			if ( 'medium' === $row['size_name'] ) {
				return $row;
			}
		}

		return null;
	}

	public function test_regeneration_removes_the_variant_of_a_replaced_size_file() {
		$id  = $this->optimized_upload();
		$old = $this->medium_variant( $id );
		$this->assertNotNull( $old );
		$this->assertFileExists( $this->abs( $old['relative_path'] ) );

		// A theme change gives "medium" another file.
		$metadata                           = wp_get_attachment_metadata( $id );
		$new_name                           = 'regenerated-' . wp_generate_uuid4() . '.jpg';
		$metadata['sizes']['medium']['file'] = $new_name;
		copy( $this->abs( $old['source_relative_path'] ), dirname( $this->abs( $old['source_relative_path'] ) ) . '/' . $new_name );
		wp_update_attachment_metadata( $id, $metadata );

		apply_filters( 'wp_generate_attachment_metadata', $metadata, $id, 'update' );

		$this->assertFileDoesNotExist( $this->abs( $old['relative_path'] ), 'The old variant file must not be left behind.' );

		ActionScheduler_QueueRunner::instance()->run();

		$new = $this->medium_variant( $id );
		$this->assertSame( 'done', $new['status'] );
		$this->assertStringEndsWith( $new_name . '.webp', $new['relative_path'] );
		$this->assertFileExists( $this->abs( $new['relative_path'] ) );
		foreach ( $this->variants->get_servable_for_attachment( $id ) as $row ) {
			$this->assertFileExists( $this->abs( $row['relative_path'] ), 'No done row without a file.' );
		}
	}

	public function test_regeneration_with_only_removals_still_deletes_them() {
		$id  = $this->optimized_upload();
		$old = $this->medium_variant( $id );

		$metadata = wp_get_attachment_metadata( $id );
		unset( $metadata['sizes']['medium'] );
		wp_update_attachment_metadata( $id, $metadata );

		apply_filters( 'wp_generate_attachment_metadata', $metadata, $id, 'update' );

		$this->assertFileDoesNotExist( $this->abs( $old['relative_path'] ) );
		$this->assertNull( $this->medium_variant( $id ) );
	}

	public function test_removals_together_with_new_work_still_queue_the_attachment() {
		$id = $this->optimized_upload();

		$metadata = wp_get_attachment_metadata( $id );
		unset( $metadata['sizes']['thumbnail'] );
		$again                                = 'again-' . wp_generate_uuid4() . '.jpg';
		$metadata['sizes']['medium']['file'] = $again;
		$old                                 = $this->medium_variant( $id );
		copy( $this->abs( $old['source_relative_path'] ), dirname( $this->abs( $old['source_relative_path'] ) ) . '/' . $again );
		wp_update_attachment_metadata( $id, $metadata );
		as_unschedule_all_actions( ConversionQueue::HOOK_PROCESS );

		apply_filters( 'wp_generate_attachment_metadata', $metadata, $id, 'update' );

		$this->assertNotEmpty( as_get_scheduled_actions( array( 'hook' => ConversionQueue::HOOK_PROCESS, 'status' => ActionScheduler_Store::STATUS_PENDING ), 'ids' ) );
	}
}
