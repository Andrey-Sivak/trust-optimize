<?php
/**
 * Files and rows of schema 1.x are never deleted unchecked (D-15).
 *
 * @package TrustOptimize\Tests
 */

use TrustOptimize\Capabilities\CapabilityService;
use TrustOptimize\Core\Plugin;
use TrustOptimize\Database\DatabaseManager;
use TrustOptimize\Domain\VariantStatus;
use TrustOptimize\Migration\ConflictReport;
use TrustOptimize\Storage\VariantRepository;

/**
 * @covers \TrustOptimize\Planning\VariantPlanner::reconcile
 * @covers \TrustOptimize\Service\ImageCleanupService
 * @covers \TrustOptimize\Service\LegacyPathGuard
 */
class LegacyProtectionTest extends WP_UnitTestCase {

	/**
	 * Variants.
	 *
	 * @var VariantRepository
	 */
	private $variants;

	/**
	 * Files created by a test, removed afterwards.
	 *
	 * @var string[]
	 */
	private $files = array();

	public function tear_down() {
		foreach ( $this->files as $file ) {
			if ( file_exists( $file ) ) {
				unlink( $file );
			}
		}
		delete_option( ConflictReport::OPTION );
		parent::tear_down();
	}

	public function set_up() {
		parent::set_up();
		delete_option( ConflictReport::OPTION );
		update_option( CapabilityService::OPTION, array( 'webp' => true, 'avif' => false ) );
		update_option( 'trust_optimize_options', array( 'convert_to_webp' => 1, 'convert_to_avif' => 0 ) );

		$this->variants = new VariantRepository( new DatabaseManager() );
	}

	/**
	 * A legacy row of attachment B whose path is the original file of attachment A.
	 *
	 * @return array{0:int,1:int,2:string} Attachment A, attachment B, the shared absolute path.
	 */
	private function colliding_attachments() {
		$a = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		$b = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/test-image.jpg' );

		$path = get_attached_file( $a );

		$this->variants->upsert(
			array(
				'attachment_id'        => $b,
				'size_name'            => 'original',
				'format'               => 'png',
				'status'               => VariantStatus::DONE,
				'naming'               => 'legacy',
				'source_relative_path' => get_post_meta( $b, '_wp_attached_file', true ),
				'relative_path'        => get_post_meta( $a, '_wp_attached_file', true ),
			)
		);

		return array( $a, $b, $path );
	}

	public function test_sync_of_an_attachment_keeps_its_legacy_rows_and_their_files() {
		list( , $b, $path ) = $this->colliding_attachments();

		Plugin::get_instance()->processor->sync( $b );

		$this->assertFileExists( $path );
		$legacy = array_values(
			array_filter(
				$this->variants->get_for_attachment( $b ),
				static function ( $row ) {
					return 'legacy' === $row['naming'];
				}
			)
		);
		$this->assertCount( 1, $legacy );
		$this->assertSame( VariantStatus::DONE, $legacy[0]['status'] );
	}

	/**
	 * Create a file in the uploads directory.
	 *
	 * @param string $relative Path relative to uploads.
	 * @return string Absolute path.
	 */
	private function make_file( $relative ) {
		$path = wp_upload_dir()['basedir'] . '/' . $relative;
		wp_mkdir_p( dirname( $path ) );
		file_put_contents( $path, 'x' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$this->files[] = $path;

		return $path;
	}

	/**
	 * Store a variant row.
	 *
	 * @param int   $attachment_id Attachment ID.
	 * @param array $fields        Row fields.
	 */
	private function row( $attachment_id, array $fields ) {
		$this->variants->upsert(
			array_merge(
				array(
					'attachment_id' => $attachment_id,
					'size_name'     => 'original',
					'format'        => 'png',
					'status'        => VariantStatus::DONE,
					'naming'        => 'legacy',
				),
				$fields
			)
		);
	}

	public function test_deleting_an_attachment_keeps_the_file_of_another_attachment() {
		list( $a, $b, $path ) = $this->colliding_attachments();

		wp_delete_attachment( $b, true );

		$this->assertFileExists( $path );
		$this->assertSame( array(), $this->variants->get_for_attachment( $b ), 'The row goes away with its attachment.' );

		$report = array_values( ( new ConflictReport() )->all() );
		$this->assertCount( 1, $report );
		$this->assertSame( $b, $report[0]['attachment_id'] );
		$this->assertSame( $a, $report[0]['conflicts_with'] );
		$this->assertSame( 'attachment_file', $report[0]['source'] );
	}

	public function test_removing_generated_files_keeps_the_file_of_another_attachment_and_parks_the_row() {
		list( $a, $b, $path ) = $this->colliding_attachments();

		Plugin::get_instance()->cleanup->cleanup_attachment( $b );

		$this->assertFileExists( $path );
		$rows = $this->variants->get_for_attachment( $b );
		$this->assertCount( 1, $rows );
		$this->assertSame( VariantStatus::FAILED, $rows[0]['status'] );
		$this->assertSame( 'legacy_conflict', $rows[0]['reason'] );
		$this->assertCount( 1, ( new ConflictReport() )->all() );
	}

	public function test_a_thumbnail_of_another_attachment_is_protected() {
		$a = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		$b = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/test-image.jpg' );

		$thumbnail = dirname( get_post_meta( $a, '_wp_attached_file', true ) ) . '/' . wp_get_attachment_metadata( $a )['sizes']['thumbnail']['file'];
		$this->assertFileExists( wp_upload_dir()['basedir'] . '/' . $thumbnail );
		$this->row( $b, array( 'relative_path' => $thumbnail ) );

		Plugin::get_instance()->cleanup->cleanup_attachment( $b );

		$this->assertFileExists( wp_upload_dir()['basedir'] . '/' . $thumbnail );
		$this->assertSame( 'legacy_conflict', $this->variants->get_for_attachment( $b )[0]['reason'] );
	}

	public function test_a_file_listed_by_two_attachments_is_not_deleted_by_either() {
		$path = $this->make_file( 'legacy-test/photo.webp' );
		$this->row( 801, array( 'format' => 'webp', 'relative_path' => 'legacy-test/photo.webp' ) );
		$this->row( 802, array( 'format' => 'webp', 'relative_path' => 'legacy-test/photo.webp' ) );

		Plugin::get_instance()->cleanup->cleanup_attachment( 801 );

		$this->assertFileExists( $path );
		$this->assertSame( VariantStatus::FAILED, $this->variants->get_for_attachment( 801 )[0]['status'] );
		$this->assertSame( VariantStatus::DONE, $this->variants->get_for_attachment( 802 )[0]['status'] );
	}

	public function test_a_file_without_a_conflict_is_deleted_with_its_row() {
		$path = $this->make_file( 'legacy-test/own.webp' );
		$this->row( 803, array( 'format' => 'webp', 'relative_path' => 'legacy-test/own.webp' ) );

		Plugin::get_instance()->cleanup->cleanup_attachment( 803 );

		$this->assertFileDoesNotExist( $path );
		$this->assertSame( array(), $this->variants->get_for_attachment( 803 ) );
		$this->assertSame( array(), ( new ConflictReport() )->all() );
	}

	public function test_the_old_file_of_a_regenerated_row_is_checked_too() {
		list( $a, $b, $path ) = $this->colliding_attachments();
		$new                  = $this->make_file( 'legacy-test/new.jpg.webp' );

		$this->variants->delete_for_attachment( $b );
		$this->row(
			$b,
			array(
				'format'               => 'webp',
				'naming'               => 'v2',
				'relative_path'        => 'legacy-test/new.jpg.webp',
				'legacy_relative_path' => get_post_meta( $a, '_wp_attached_file', true ),
			)
		);

		Plugin::get_instance()->cleanup->cleanup_attachment( $b );

		$this->assertFileExists( $path );
		$this->assertFileDoesNotExist( $new );
		$this->assertSame( array(), $this->variants->get_for_attachment( $b ), 'The 2.0 row is removed as a whole.' );
		$this->assertCount( 1, ( new ConflictReport() )->all() );
	}

	public function test_the_1x_registry_of_another_attachment_protects_a_file() {
		global $wpdb;

		$database = new DatabaseManager();
		$table    = $database->get_plugin_table_names()['images'];
		$path     = $this->make_file( 'legacy-test/registry.webp' );

		// A real table is needed: table_exists() does not see the temporary tables the test case creates.
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "CREATE TABLE IF NOT EXISTS `{$table}` (id bigint(20) unsigned NOT NULL AUTO_INCREMENT, attachment_id bigint(20) unsigned NOT NULL, metadata longtext NOT NULL, status varchar(20) NOT NULL DEFAULT 'completed', PRIMARY KEY  (id), UNIQUE KEY attachment_id (attachment_id))" );
		$wpdb->insert(
			$table,
			array(
				'attachment_id' => 805,
				'metadata'      => wp_json_encode( array( 'generated_variants' => array( 'original:webp' => array( 'format' => 'webp', 'file' => 'registry.webp', 'relative_dir' => 'legacy-test' ) ) ) ),
			)
		);
		$this->row( 804, array( 'format' => 'webp', 'relative_path' => 'legacy-test/registry.webp' ) );

		Plugin::get_instance()->cleanup->cleanup_attachment( 804 );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
		add_filter( 'query', array( $this, '_create_temporary_tables' ) );
		add_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		$this->assertFileExists( $path );
		$report = array_values( ( new ConflictReport() )->all() );
		$this->assertSame( 805, $report[0]['conflicts_with'] );
		$this->assertSame( 'legacy_registry', $report[0]['source'] );
	}
}
