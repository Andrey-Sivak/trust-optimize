<?php
/**
 * Regeneration of 1.x variants under 2.0 names and retirement of the 1.x files (03.4).
 *
 * @package TrustOptimize\Tests
 */

require_once __DIR__ . '/legacy-schema-fixture.php';

use TrustOptimize\Capabilities\CapabilityService;
use TrustOptimize\Core\Plugin;
use TrustOptimize\Database\DatabaseManager;
use TrustOptimize\Domain\VariantStatus;
use TrustOptimize\Migration\ConflictReport;
use TrustOptimize\Migration\DetectCollisions;
use TrustOptimize\Migration\RetireLegacyFiles;
use TrustOptimize\Migration\ScheduleRegeneration;
use TrustOptimize\Queue\ConversionQueue;
use TrustOptimize\Service\LegacyPathGuard;
use TrustOptimize\Storage\AttachmentRepository;
use TrustOptimize\Storage\VariantRepository;

/**
 * @covers \TrustOptimize\Migration\ScheduleRegeneration
 * @covers \TrustOptimize\Migration\RetireLegacyFiles
 * @covers \TrustOptimize\Service\ImageCleanupService::retire_legacy_file
 */
class RegenerateLegacyTest extends WP_UnitTestCase {

	use Legacy_Schema_Fixture;

	/**
	 * Variants.
	 *
	 * @var VariantRepository
	 */
	private $variants;

	/**
	 * Steps under test.
	 *
	 * @var ScheduleRegeneration
	 */
	private $schedule;

	public function set_up() {
		parent::set_up();
		$this->install_legacy_table();
		delete_option( ConflictReport::OPTION );
		update_option( CapabilityService::OPTION, array( 'webp' => true, 'avif' => false ) );
		update_option( 'trust_optimize_options', array( 'convert_to_webp' => 1, 'convert_to_avif' => 0 ) );
		as_unschedule_all_actions( ConversionQueue::HOOK_PROCESS );

		$database       = new DatabaseManager();
		$this->variants = new VariantRepository( $database );
		$this->schedule = new ScheduleRegeneration( $this->variants, Plugin::get_instance()->conversion_queue );
	}

	public function tear_down() {
		$this->remove_legacy_schema();
		delete_option( ConflictReport::OPTION );
		parent::tear_down();
	}

	/**
	 * An attachment whose upload-time conversion is forgotten, like on a 1.x site.
	 *
	 * @return int
	 */
	private function attachment( $file = 'canola.jpg' ) {
		$id = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/' . $file );
		$this->variants->delete_for_attachment( $id );
		( new AttachmentRepository( new DatabaseManager(), $this->variants ) )->delete( $id );
		as_unschedule_all_actions( ConversionQueue::HOOK_PROCESS );

		return $id;
	}

	/**
	 * Store a finished 1.x row whose file exists.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $path          Path of the 1.x file relative to uploads.
	 * @param array  $fields        Row fields.
	 * @return string Absolute path of the 1.x file.
	 */
	private function legacy_row( $attachment_id, $path, array $fields = array() ) {
		$file = $this->make_legacy_file( $path );
		$this->variants->upsert(
			array_merge(
				array(
					'attachment_id'        => $attachment_id,
					'size_name'            => 'original',
					'format'               => 'webp',
					'status'               => VariantStatus::DONE,
					'naming'               => 'legacy',
					'source_relative_path' => get_post_meta( $attachment_id, '_wp_attached_file', true ),
					'relative_path'        => $path,
					'file_hash'            => hash_file( 'sha256', $file ),
				),
				$fields
			)
		);

		return $file;
	}

	private function row( $attachment_id, $size = 'original', $format = 'webp' ) {
		foreach ( $this->variants->get_for_attachment( $attachment_id ) as $row ) {
			if ( $size === $row['size_name'] && $format === $row['format'] ) {
				return $row;
			}
		}

		return null;
	}

	public function test_the_legacy_file_is_served_until_the_2_0_file_exists_and_then_deleted() {
		$id       = $this->attachment();
		$relative = get_post_meta( $id, '_wp_attached_file', true );
		$legacy   = preg_replace( '/\.jpg$/', '.webp', $relative );
		$old_file = $this->legacy_row( $id, $legacy );

		$result = $this->schedule->run_batch( 0, 10 );

		$this->assertTrue( $result->is_done() );
		$row = $this->row( $id );
		$this->assertSame( VariantStatus::PENDING, $row['status'] );
		$this->assertSame( 'v2', $row['naming'] );
		$this->assertNull( $row['relative_path'] );
		$this->assertSame( $legacy, $row['legacy_relative_path'] );
		$this->assertSame( $legacy, VariantRepository::servable_path( $row ), 'The 1.x file is served while the 2.0 file is not there.' );
		$this->assertFileExists( $old_file );
		$this->assertNotEmpty( as_get_scheduled_actions( array( 'hook' => ConversionQueue::HOOK_PROCESS, 'status' => ActionScheduler_Store::STATUS_PENDING ), 'ids' ) );

		Plugin::get_instance()->processor->run( $id );

		$row = $this->row( $id );
		$this->assertSame( VariantStatus::DONE, $row['status'] );
		$this->assertSame( $relative . '.webp', $row['relative_path'] );
		$this->assertNull( $row['legacy_relative_path'] );
		$this->assertFileDoesNotExist( $old_file );
		$this->assertFileExists( wp_upload_dir()['basedir'] . '/' . $relative . '.webp' );
		$this->assertSame( $relative . '.webp', VariantRepository::servable_path( $row ) );
	}

	public function test_a_failed_conversion_keeps_the_legacy_file_and_keeps_serving_it() {
		$id       = $this->attachment();
		$legacy   = preg_replace( '/\.jpg$/', '.webp', get_post_meta( $id, '_wp_attached_file', true ) );
		$old_file = $this->legacy_row( $id, $legacy );

		$this->schedule->run_batch( 0, 10 );
		add_filter( 'wp_image_editors', '__return_empty_array' );
		Plugin::get_instance()->processor->run( $id );
		remove_filter( 'wp_image_editors', '__return_empty_array' );

		$row = $this->row( $id );
		$this->assertSame( VariantStatus::FAILED, $row['status'] );
		$this->assertSame( $legacy, $row['legacy_relative_path'] );
		$this->assertSame( $legacy, VariantRepository::servable_path( $row ) );
		$this->assertFileExists( $old_file );
	}

	public function test_variants_that_are_no_longer_planned_are_removed_with_their_legacy_file() {
		$id       = $this->attachment();
		$png_file = $this->legacy_row( $id, preg_replace( '/\.jpg$/', '.png', get_post_meta( $id, '_wp_attached_file', true ) ) . '.old.png', array( 'format' => 'png' ) );

		$this->schedule->run_batch( 0, 10 );

		$this->assertNull( $this->row( $id, 'original', 'png' ), 'The row of a format that is not planned is gone.' );
		$this->assertFileDoesNotExist( $png_file );
	}

	public function test_a_legacy_file_that_belongs_to_another_attachment_is_not_deleted() {
		$a = $this->attachment();
		$b = $this->attachment( 'test-image.jpg' );

		$original = get_post_meta( $a, '_wp_attached_file', true );
		$this->variants->upsert(
			array(
				'attachment_id'        => $b,
				'size_name'            => 'original',
				'format'               => 'webp',
				'status'               => VariantStatus::DONE,
				'naming'               => 'legacy',
				'source_relative_path' => get_post_meta( $b, '_wp_attached_file', true ),
				'relative_path'        => $original,
			)
		);

		// Not detected beforehand: the retirement alone must not delete the file.
		$this->schedule->run_batch( 0, 10 );
		Plugin::get_instance()->processor->run( $b );

		$this->assertFileExists( get_attached_file( $a ) );
		$row = $this->row( $b );
		$this->assertSame( VariantStatus::DONE, $row['status'] );
		$this->assertNull( $row['legacy_relative_path'], 'The file of another attachment must not be served as a variant.' );
		$this->assertCount( 1, ( new ConflictReport() )->all() );
	}

	public function test_rows_marked_as_conflicts_are_not_regenerated() {
		$database = new DatabaseManager();
		$a        = $this->attachment();
		$b        = $this->attachment( 'test-image.jpg' );
		$this->variants->upsert(
			array(
				'attachment_id' => $b,
				'size_name'     => 'original',
				'format'        => 'webp',
				'status'        => VariantStatus::DONE,
				'naming'        => 'legacy',
				'relative_path' => get_post_meta( $a, '_wp_attached_file', true ),
			)
		);
		( new DetectCollisions( $this->variants, new AttachmentRepository( $database, $this->variants ), new LegacyPathGuard( $database, $this->variants ), new ConflictReport() ) )->run_batch( 0, 10 );

		$this->schedule->run_batch( 0, 10 );

		$row = $this->row( $b );
		$this->assertSame( VariantStatus::FAILED, $row['status'] );
		$this->assertSame( 'legacy', $row['naming'] );
		$this->assertFileExists( get_attached_file( $a ) );
	}

	public function test_the_retire_step_deletes_the_legacy_file_of_a_row_that_is_already_done() {
		$id       = $this->attachment();
		$relative = get_post_meta( $id, '_wp_attached_file', true );
		$old_file = $this->make_legacy_file( preg_replace( '/\.jpg$/', '.webp', $relative ) );

		$this->variants->upsert(
			array(
				'attachment_id'        => $id,
				'size_name'            => 'original',
				'format'               => 'webp',
				'status'               => VariantStatus::DONE,
				'source_relative_path' => $relative,
				'relative_path'        => $relative . '.webp',
				'legacy_relative_path' => preg_replace( '/\.jpg$/', '.webp', $relative ),
			)
		);

		$result = ( new RetireLegacyFiles( $this->variants, Plugin::get_instance()->cleanup ) )->run_batch( 0, 10 );

		$this->assertSame( 1, $result->get_counts()['retired'] );
		$this->assertFileDoesNotExist( $old_file );
		$this->assertNull( $this->row( $id )['legacy_relative_path'] );
	}

	public function test_replaced_rows_forget_their_deleted_legacy_file() {
		$id       = $this->attachment();
		$legacy   = preg_replace( '/\.jpg$/', '.webp', get_post_meta( $id, '_wp_attached_file', true ) );
		$old_file = $this->make_legacy_file( $legacy );

		$this->variants->upsert(
			array(
				'attachment_id'        => $id,
				'size_name'            => 'original',
				'format'               => 'webp',
				'source_relative_path' => 'elsewhere.jpg',
				'legacy_relative_path' => $legacy,
			)
		);

		Plugin::get_instance()->cleanup->cleanup_replaced_files( $id, array( $this->row( $id ) ) );

		$this->assertFileDoesNotExist( $old_file );
		$this->assertNull( $this->row( $id )['legacy_relative_path'], 'A row that stays must not point at a deleted file.' );
	}
}
