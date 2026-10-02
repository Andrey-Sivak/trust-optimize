<?php
/**
 * A format that becomes unsupported must not destroy existing variants (02.15).
 *
 * @package TrustOptimize\Tests
 */

require_once ABSPATH . WPINC . '/class-wp-image-editor.php';
require_once ABSPATH . WPINC . '/class-wp-image-editor-gd.php';

use TrustOptimize\Capabilities\CapabilityService;
use TrustOptimize\Core\Plugin;
use TrustOptimize\Database\DatabaseManager;
use TrustOptimize\Domain\VariantStatus;
use TrustOptimize\Queue\ConversionQueue;
use TrustOptimize\Storage\AttachmentRepository;
use TrustOptimize\Storage\VariantRepository;

/**
 * Editor that writes JPEG/PNG only.
 */
class TrustOptimizeBasicEditor extends WP_Image_Editor_GD {

	public static function supports_mime_type( $mime_type ) {
		return in_array( $mime_type, array( 'image/jpeg', 'image/png' ), true ) && parent::supports_mime_type( $mime_type );
	}
}

/**
 * Editor that claims to write "image/zzz" but cannot load this file.
 */
class TrustOptimizeFailingLoadEditor extends WP_Image_Editor_GD {

	public static function supports_mime_type( $mime_type ) {
		return 'image/zzz' === $mime_type;
	}

	public function load() {
		return new WP_Error( 'image_load_failed', 'Out of memory while loading.' );
	}
}

/**
 * @covers \TrustOptimize\Capabilities\CapabilityService
 * @covers \TrustOptimize\Planning\VariantPlanner
 * @covers \TrustOptimize\Features\Optimization\ImageConverter
 */
class CapabilityResilienceTest extends WP_UnitTestCase {

	/**
	 * Variants.
	 *
	 * @var VariantRepository
	 */
	private $variants;

	public function set_up() {
		parent::set_up();
		update_option( CapabilityService::OPTION, array( 'webp' => true, 'avif' => true ) );
		update_option( 'trust_optimize_options', array( 'convert_to_webp' => 1, 'convert_to_avif' => 1 ) );
		as_unschedule_all_actions( ConversionQueue::HOOK_PROCESS );
		$this->variants = new VariantRepository( new DatabaseManager() );
	}

	private function abs( $relative ) {
		return wp_upload_dir()['basedir'] . '/' . $relative;
	}

	/**
	 * An uploaded image with a finished AVIF variant (a fake file owned by a row).
	 */
	private function attachment_with_avif() {
		$id = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		ConversionQueue::cancel_tasks_for_attachment( $id );
		Plugin::get_instance()->processor->sync( $id );

		$source = get_post_meta( $id, '_wp_attached_file', true );
		$target = $source . '.avif';
		file_put_contents( $this->abs( $target ), 'avif bytes' );
		$this->variants->upsert(
			array(
				'attachment_id'        => $id,
				'size_name'            => 'original',
				'format'               => 'avif',
				'status'               => VariantStatus::DONE,
				'source_relative_path' => $source,
				'relative_path'        => $target,
				'quality'              => 80,
				'file_hash'            => hash( 'sha256', 'avif bytes' ),
			)
		);

		return array( $id, $target );
	}

	private function avif_rows( $id ) {
		return array_values(
			array_filter(
				$this->variants->get_for_attachment( $id ),
				static function ( $row ) {
					return 'avif' === $row['format'];
				}
			)
		);
	}

	public function test_enabled_but_unsupported_format_keeps_existing_variants() {
		list( $id, $target ) = $this->attachment_with_avif();
		update_option( CapabilityService::OPTION, array( 'webp' => true, 'avif' => false ) );

		Plugin::get_instance()->processor->sync( $id );

		$this->assertFileExists( $this->abs( $target ) );
		$kept = array_values(
			array_filter(
				$this->variants->get_done_for_attachment( $id ),
				static function ( $row ) use ( $target ) {
					return $row['relative_path'] === $target;
				}
			)
		);
		$this->assertCount( 1, $kept, 'The finished AVIF variant is kept and still served.' );
	}

	public function test_format_disabled_by_the_user_is_removed() {
		list( $id, $target ) = $this->attachment_with_avif();
		update_option( 'trust_optimize_options', array( 'convert_to_webp' => 1, 'convert_to_avif' => 0 ) );

		Plugin::get_instance()->processor->sync( $id );

		$this->assertFileDoesNotExist( $this->abs( $target ) );
		$this->assertSame( array(), $this->avif_rows( $id ) );
	}

	public function test_failed_variants_of_an_unsupported_format_are_not_retried() {
		list( $id ) = $this->attachment_with_avif();
		$row        = $this->avif_rows( $id )[0];
		$this->variants->transition( $row['id'], VariantStatus::DONE, VariantStatus::FAILED, array( 'reason' => 'save_failed' ) );
		update_option( CapabilityService::OPTION, array( 'webp' => true, 'avif' => false ) );

		$plan = Plugin::get_instance()->planner->plan( $id );

		$this->assertSame( VariantStatus::FAILED, $this->avif_rows( $id )[0]['status'] );
		$this->assertSame( array(), $plan->to_delete() );
	}

	public function test_a_downgrade_made_in_another_environment_is_ignored() {
		$service = new CapabilityService();
		$service->recheck();
		$service->downgrade( 'avif', 'cli without avif' );
		$this->assertFalse( $service->supports( 'avif' ) );

		// Pretend the downgrade was recorded by a PHP build that is not this one.
		$stored                                      = get_option( CapabilityService::OPTION );
		$stored['downgraded']['avif']['env']['php'] = '5.6.0';
		update_option( CapabilityService::OPTION, $stored );

		$this->assertTrue( $service->supports( 'avif' ), 'A downgrade must only apply to the environment that recorded it.' );
	}

	public function test_a_file_that_cannot_be_loaded_does_not_downgrade_the_format() {
		$dir = wp_upload_dir()['basedir'] . '/resilience-' . wp_generate_uuid4();
		wp_mkdir_p( $dir );
		$im = imagecreatetruecolor( 20, 20 );
		imagejpeg( $im, $dir . '/heavy.jpg' );
		$relative = ltrim( substr( $dir, strlen( wp_upload_dir()['basedir'] ) ), '/' ) . '/heavy.jpg';
		$id       = $this->variants->upsert(
			array(
				'attachment_id'        => 4242,
				'size_name'            => 'original',
				'format'               => 'zzz',
				'source_relative_path' => $relative,
			)
		);
		update_option( CapabilityService::OPTION, array( 'webp' => true, 'zzz' => true ) );
		add_filter(
			'wp_image_editors',
			static function () {
				return array( 'TrustOptimizeBasicEditor', 'TrustOptimizeFailingLoadEditor' );
			}
		);

		$settings = new TrustOptimize\Settings\OptimizationSettings( array( 'zzz' ), array( 'zzz' ), array( 'zzz' => 80 ) );
		$row      = $this->variants->get_for_attachment( 4242 )[0];
		$converter = new TrustOptimize\Features\Optimization\ImageConverter( $this->variants, new TrustOptimize\Files\AtomicImageWriter( $this->variants ), new CapabilityService() );
		$result    = $converter->convert( $row, $settings );

		$this->assertTrue( $result->is_failed() );
		$this->assertSame( 'no_editor', $result->get_message() );
		$this->assertTrue( ( new CapabilityService() )->supports( 'zzz' ), 'A per-file load error must not switch the format off.' );

		unlink( $dir . '/heavy.jpg' );
		rmdir( $dir );
		unset( $id );
	}
}
