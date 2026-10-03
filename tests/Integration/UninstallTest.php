<?php
/**
 * Uninstall of a single site (06.4).
 *
 * @package TrustOptimize\Tests
 */

require_once __DIR__ . '/legacy-schema-fixture.php';
require_once __DIR__ . '/uninstall-fixture.php';

use TrustOptimize\Capabilities\CapabilityService;
use TrustOptimize\Core\Plugin;
use TrustOptimize\Migration\ConflictReport;

/**
 * @covers ::trust_optimize_uninstall_site
 * @covers ::trust_optimize_uninstall_cleanup_generated_files
 */
class UninstallTest extends WP_UnitTestCase {

	use Legacy_Schema_Fixture;
	use Uninstall_Fixture;

	/**
	 * Uploads directory of the test, relative to uploads.
	 *
	 * @var string
	 */
	private $relative_dir;

	/**
	 * Real attachments created by the test.
	 *
	 * @var int[]
	 */
	private $attachment_ids = array();

	/**
	 * Directory outside uploads created by a test.
	 *
	 * @var string|null
	 */
	private $outside_dir;

	public function set_up() {
		parent::set_up();
		$this->use_real_tables();
		$this->load_uninstall_functions();
		$this->capture_error_log();
		// An earlier uninstall test commits its leftovers (DROP TABLE ends the transaction).
		delete_option( ConflictReport::OPTION );
		delete_option( 'trust_optimize_uninstall_conflicts' );
		$this->relative_dir = 'uninstall-test-' . wp_generate_uuid4();
		update_option( CapabilityService::OPTION, array( 'webp' => true, 'avif' => false ) );
		update_option( 'trust_optimize_options', array( 'remove_data_on_uninstall' => 1 ) );
	}

	public function tear_down() {
		$this->release_error_log();
		$this->remove_legacy_schema();
		$this->restore_plugin_tables();

		foreach ( $this->attachment_ids as $id ) {
			wp_delete_attachment( $id, true );
		}

		foreach ( array( 'trust_optimize_options', 'trust_optimize_db_version', 'trust_optimize_pending_cleanup', 'trust_optimize_uninstall_conflicts', ConflictReport::OPTION, CapabilityService::OPTION ) as $option ) {
			delete_option( $option );
		}

		if ( $this->outside_dir ) {
			array_map( 'unlink', glob( $this->outside_dir . '/*' ) ?: array() );
			@rmdir( $this->outside_dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		}

		foreach ( glob( wp_upload_dir()['basedir'] . '/' . $this->relative_dir . '/*' ) ?: array() as $file ) {
			unlink( $file );
		}
		@rmdir( wp_upload_dir()['basedir'] . '/' . $this->relative_dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir

		parent::tear_down();
	}

	public function test_without_the_flag_only_runtime_data_is_removed() {
		update_option( 'trust_optimize_options', array( 'remove_data_on_uninstall' => 0 ) );
		set_transient( 'trust_optimize_demo', 1, HOUR_IN_SECONDS );
		$file = $this->add_variant_file( 3001, $this->relative_dir, 'a.jpg.webp' );

		trust_optimize_uninstall_site();

		wp_cache_flush();
		$this->assertFalse( get_transient( 'trust_optimize_demo' ) );
		$this->assertFileExists( $file );
		$this->assertTrue( $this->table_exists( 'variants' ) );
		$this->assertNotFalse( get_option( 'trust_optimize_options' ) );
	}

	public function test_a_partial_cleanup_keeps_the_tables_and_options() {
		$files = array(
			$this->add_variant_file( 3001, $this->relative_dir, 'a.jpg.webp' ),
			$this->add_variant_file( 3002, $this->relative_dir, 'b.jpg.webp' ),
			$this->add_variant_file( 3003, $this->relative_dir, 'c.jpg.webp' ),
		);
		add_filter( 'trust_optimize_uninstall_cleanup_max_records', static fn() => 1 );

		trust_optimize_uninstall_site();

		$this->assertTrue( $this->table_exists( 'variants' ), 'The registry survives an incomplete cleanup.' );
		$this->assertNotFalse( get_option( 'trust_optimize_options' ) );
		$this->assertSame( 2, get_option( 'trust_optimize_pending_cleanup' )['remaining'] );
		$this->assertStringContainsString( 'left 2 generated files', $this->logged() );
		$this->assertFileDoesNotExist( $files[0] );
		$this->assertFileExists( $files[1] );
		$this->assertFileExists( $files[2] );
	}

	public function test_a_full_pass_removes_files_tables_and_options() {
		$file = $this->add_variant_file( 3001, $this->relative_dir, 'a.jpg.webp' );
		$this->add_variant_file( 3002, $this->relative_dir, 'b.jpg.webp' );
		set_transient( 'trust_optimize_demo', 1, HOUR_IN_SECONDS );
		update_option( 'trust_optimize_bulk_active', 5 );

		trust_optimize_uninstall_site();

		$this->assertFileDoesNotExist( $file );
		foreach ( array( 'attachments', 'variants', 'jobs', 'images' ) as $table ) {
			$this->assertFalse( $this->table_exists( $table ), $table );
		}
		foreach ( array( 'trust_optimize_options', 'trust_optimize_bulk_active', 'trust_optimize_pending_cleanup', 'trust_optimize_uninstall_conflicts' ) as $option ) {
			$this->assertFalse( get_option( $option, false ), $option );
		}
		wp_cache_flush();
		$this->assertFalse( get_transient( 'trust_optimize_demo' ) );
	}

	public function test_the_original_of_another_attachment_is_not_deleted_and_the_conflict_is_reported() {
		$this->install_legacy_table();
		update_option( 'trust_optimize_options', array( 'remove_data_on_uninstall' => 1 ) );
		update_option( ConflictReport::OPTION, array( '1:2024/01/old.png' => array( 'attachment_id' => 1, 'path' => '2024/01/old.png', 'conflicts_with' => 0, 'source' => 'hash_mismatch', 'found_at' => '2026-01-01 00:00:00' ) ) );

		$a = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		$b = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/test-image.jpg' );
		$this->attachment_ids = array( $a, $b );
		Plugin::get_instance()->cleanup->cleanup_attachment( $a );
		Plugin::get_instance()->cleanup->cleanup_attachment( $b );

		$a_original = get_post_meta( $a, '_wp_attached_file', true );
		$this->add_legacy_manifest( $b, array( array( 'size_name' => 'original', 'format' => 'png', 'file' => $a_original ) ), false );

		trust_optimize_uninstall_site();

		$this->assertFileExists( get_attached_file( $a ), 'The original of another attachment survives.' );
		$this->assertFalse( $this->table_exists( 'variants' ) );
		$this->assertFalse( get_option( ConflictReport::OPTION, false ), 'The migration report moved into the uninstall report.' );

		$paths = array_column( get_option( 'trust_optimize_uninstall_conflicts' ), 'path' );
		$this->assertContains( $a_original, $paths );
		$this->assertContains( '2024/01/old.png', $paths );
		$this->assertStringContainsString( basename( $a_original ), $this->logged() );
	}

	public function test_a_row_outside_uploads_does_not_block_the_final_cleanup() {
		$inside    = $this->add_variant_file( 3001, $this->relative_dir, 'a.jpg.webp' );
		$outside   = dirname( wp_upload_dir()['basedir'] ) . '/outside-uploads-test';
		$this->outside_dir = $outside;
		wp_mkdir_p( $outside );
		file_put_contents( $outside . '/b.jpg.webp', 'not ours to delete' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		( new TrustOptimize\Storage\VariantRepository( new TrustOptimize\Database\DatabaseManager() ) )->upsert(
			array(
				'attachment_id'        => 3002,
				'size_name'            => 'b.jpg.webp',
				'format'               => 'webp',
				'status'               => TrustOptimize\Domain\VariantStatus::DONE,
				'source_relative_path' => '../outside-uploads-test/b.jpg',
				'relative_path'        => '../outside-uploads-test/b.jpg.webp',
				'file_hash'            => hash( 'sha256', 'not ours to delete' ),
			)
		);

		trust_optimize_uninstall_site();

		$this->assertFileDoesNotExist( $inside );
		$this->assertFileExists( $outside . '/b.jpg.webp', 'A file outside uploads is never touched.' );
		$this->assertFalse( $this->table_exists( 'variants' ), 'The registry is dropped: nothing is left that may be deleted.' );
		$this->assertFalse( get_option( 'trust_optimize_pending_cleanup', false ) );

		$report = get_option( 'trust_optimize_uninstall_conflicts' );
		$this->assertSame( array( '../outside-uploads-test/b.jpg.webp' ), array_column( $report, 'path' ) );
		$this->assertSame( array( 'outside_uploads' ), array_column( $report, 'source' ) );
		$this->assertStringContainsString( 'outside-uploads-test', $this->logged() );
	}

	public function test_uninstall_removes_empty_probe_directories_only() {
		$uploads = wp_upload_dir()['basedir'];
		$empty   = $uploads . '/trust-optimize-capability-uninstall';
		$full    = $uploads . '/trust-optimize-capability-uninstall-full';
		wp_mkdir_p( $empty );
		wp_mkdir_p( $full );
		file_put_contents( $full . '/probe.webp', 'x' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		trust_optimize_uninstall_site();

		$this->assertDirectoryDoesNotExist( $empty );
		$this->assertFileExists( $full . '/probe.webp' );
		$this->assertStringContainsString( 'uninstall-full', $this->logged() );

		unlink( $full . '/probe.webp' );
		rmdir( $full );
	}
}
