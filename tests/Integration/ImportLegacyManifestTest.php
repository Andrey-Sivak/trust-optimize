<?php
/**
 * Import of the 1.x manifest into the variants table (03.2).
 *
 * @package TrustOptimize\Tests
 */

require_once __DIR__ . '/legacy-schema-fixture.php';

use TrustOptimize\Capabilities\CapabilityService;
use TrustOptimize\Database\DatabaseManager;
use TrustOptimize\Domain\AttachmentState;
use TrustOptimize\Domain\VariantStatus;
use TrustOptimize\Migration\ImportLegacyManifest;
use TrustOptimize\Storage\AttachmentRepository;
use TrustOptimize\Storage\VariantRepository;

/**
 * @covers \TrustOptimize\Migration\ImportLegacyManifest
 * @covers \TrustOptimize\Migration\LegacyManifest
 */
class ImportLegacyManifestTest extends WP_UnitTestCase {

	use Legacy_Schema_Fixture;

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

	/**
	 * Step under test.
	 *
	 * @var ImportLegacyManifest
	 */
	private $step;

	public function set_up() {
		parent::set_up();
		update_option( CapabilityService::OPTION, array( 'webp' => true, 'avif' => false ) );
		update_option( 'trust_optimize_options', array( 'convert_to_webp' => 1, 'convert_to_avif' => 0 ) );

		$database          = new DatabaseManager();
		$this->variants    = new VariantRepository( $database );
		$this->attachments = new AttachmentRepository( $database, $this->variants );
		$this->step        = new ImportLegacyManifest( $database, $this->variants, $this->attachments );
		$this->install_legacy_table();
	}

	public function tear_down() {
		$this->remove_legacy_schema();
		parent::tear_down();
	}

	/**
	 * An attachment with its upload-time conversion forgotten, like a 1.x site before the upgrade.
	 *
	 * @return int
	 */
	private function attachment() {
		$id = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		$this->variants->delete_for_attachment( $id );
		$this->attachments->delete( $id );

		return $id;
	}

	private function rows( $id ) {
		$rows = array();
		foreach ( $this->variants->get_for_attachment( $id ) as $row ) {
			$rows[ $row['size_name'] . '|' . $row['format'] ] = $row;
		}

		return $rows;
	}

	public function test_variants_with_files_become_done_legacy_rows() {
		$id        = $this->attachment();
		$relative  = get_post_meta( $id, '_wp_attached_file', true );
		$thumbnail = dirname( $relative ) . '/' . wp_get_attachment_metadata( $id )['sizes']['thumbnail']['file'];
		$legacy_a  = preg_replace( '/\.jpg$/', '.webp', $relative );
		$legacy_b  = preg_replace( '/\.jpg$/', '.webp', $thumbnail );

		$this->add_legacy_manifest(
			$id,
			array(
				array( 'size_name' => 'original', 'format' => 'webp', 'file' => $legacy_a ),
				array( 'size_name' => 'thumbnail', 'format' => 'webp', 'file' => $legacy_b ),
			)
		);

		$result = $this->step->run_batch( 0, 10 );

		$this->assertTrue( $result->is_done() );
		$this->assertSame( 2, $result->get_counts()['imported'] );

		$rows = $this->rows( $id );
		$this->assertCount( 2, $rows );
		$this->assertSame( VariantStatus::DONE, $rows['original|webp']['status'] );
		$this->assertSame( 'legacy', $rows['original|webp']['naming'] );
		$this->assertSame( $legacy_a, $rows['original|webp']['relative_path'] );
		$this->assertSame( $relative, $rows['original|webp']['source_relative_path'] );
		$this->assertSame( hash_file( 'sha256', wp_upload_dir()['basedir'] . '/' . $legacy_a ), $rows['original|webp']['file_hash'] );
		$this->assertSame( 0, $rows['original|webp']['quality'], 'The quality of a 1.x file is unknown, so it counts as outdated.' );
		$this->assertSame( $thumbnail, $rows['thumbnail|webp']['source_relative_path'] );
		$this->assertSame( 150, $rows['thumbnail|webp']['width'] );
		$this->assertSame( AttachmentState::OPTIMIZED, $this->attachments->get_state( $id ) );
		$this->assertSame( $legacy_a, VariantRepository::servable_path( $rows['original|webp'] ) );
	}

	public function test_variants_without_files_and_attachments_that_are_gone_are_counted_not_imported() {
		$id       = $this->attachment();
		$relative = get_post_meta( $id, '_wp_attached_file', true );

		$this->add_legacy_manifest( $id, array( array( 'size_name' => 'original', 'format' => 'webp', 'file' => preg_replace( '/\.jpg$/', '.webp', $relative ) ) ), false );
		$this->add_legacy_manifest( 999999, array( array( 'size_name' => 'original', 'format' => 'webp', 'file' => 'legacy-test/gone.webp' ) ) );

		$result = $this->step->run_batch( 0, 10 );

		$this->assertSame( 1, $result->get_counts()['missing'] );
		$this->assertSame( 1, $result->get_counts()['orphaned'] );
		$this->assertSame( array(), $this->variants->get_for_attachment( $id ) );
		$this->assertSame( array(), $this->variants->get_for_attachment( 999999 ) );
	}

	public function test_an_existing_2_0_row_only_learns_about_the_legacy_file() {
		$id       = $this->attachment();
		$relative = get_post_meta( $id, '_wp_attached_file', true );
		$legacy   = preg_replace( '/\.jpg$/', '.webp', $relative );

		$this->variants->upsert(
			array(
				'attachment_id'        => $id,
				'size_name'            => 'original',
				'format'               => 'webp',
				'source_relative_path' => $relative,
			)
		);
		$this->add_legacy_manifest( $id, array( array( 'size_name' => 'original', 'format' => 'webp', 'file' => $legacy ) ) );

		$this->step->run_batch( 0, 10 );

		$row = $this->rows( $id )['original|webp'];
		$this->assertSame( VariantStatus::PENDING, $row['status'] );
		$this->assertSame( 'v2', $row['naming'] );
		$this->assertNull( $row['relative_path'] );
		$this->assertSame( $legacy, $row['legacy_relative_path'] );
		$this->assertSame( $legacy, VariantRepository::servable_path( $row ), 'The 1.x file is served until the 2.0 file exists.' );
	}

	public function test_batches_continue_from_the_cursor_and_a_second_run_changes_nothing() {
		$ids = array( $this->attachment(), $this->attachment() );

		foreach ( $ids as $id ) {
			$relative = get_post_meta( $id, '_wp_attached_file', true );
			$this->add_legacy_manifest( $id, array( array( 'size_name' => 'original', 'format' => 'webp', 'file' => preg_replace( '/\.jpg$/', '.webp', $relative ) ) ) );
		}

		$first = $this->step->run_batch( 0, 1 );
		$this->assertFalse( $first->is_done() );
		$this->assertSame( $ids[0], $first->get_cursor() );
		$this->assertCount( 1, $this->variants->get_for_attachment( $ids[0] ) );
		$this->assertSame( array(), $this->variants->get_for_attachment( $ids[1] ) );

		$second = $this->step->run_batch( $first->get_cursor(), 1 );
		$this->assertSame( $ids[1], $second->get_cursor() );
		$this->assertTrue( $this->step->run_batch( $second->get_cursor(), 1 )->is_done() );

		$before = $this->variants->get_for_attachment( $ids[0] );
		$this->step->run_batch( 0, 10 );
		$after = $this->variants->get_for_attachment( $ids[0] );

		$this->assertCount( 1, $after );
		$this->assertSame( $before[0]['id'], $after[0]['id'] );
		$this->assertSame( $before[0]['file_hash'], $after[0]['file_hash'] );
	}

	public function test_a_site_without_the_legacy_table_has_nothing_to_import() {
		$this->remove_legacy_schema();

		$this->assertTrue( $this->step->run_batch( 0, 10 )->is_done() );
	}
}
