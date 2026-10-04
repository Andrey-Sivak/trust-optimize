<?php
/**
 * Uninstall of a single site (06.4).
 *
 * @package TrustOptimize\Tests
 */

require_once __DIR__ . '/uninstall-fixture.php';

use TrustOptimize\Capabilities\CapabilityService;
use TrustOptimize\Core\Plugin;

/**
 * @covers ::trust_optimize_uninstall_site
 * @covers ::trust_optimize_uninstall_cleanup_generated_files
 */
class UninstallTest extends WP_UnitTestCase {

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
		$this->relative_dir = 'uninstall-test-' . wp_generate_uuid4();
		update_option( CapabilityService::OPTION, array( 'webp' => true, 'avif' => false ) );
		update_option( 'trust_optimize_options', array( 'remove_data_on_uninstall' => 1 ) );
	}

	public function tear_down() {
		$this->release_error_log();
		global $wpdb;

		// Drop the data of the test before the table statements commit the transaction.
		$wpdb->query( 'ROLLBACK' );
		$this->restore_plugin_tables();

		foreach ( $this->attachment_ids as $id ) {
			wp_delete_attachment( $id, true );
		}

		foreach ( array( 'trust_optimize_options', 'trust_optimize_db_version', 'trust_optimize_pending_cleanup', CapabilityService::OPTION ) as $option ) {
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
		foreach ( array( 'attachments', 'variants', 'jobs' ) as $table ) {
			$this->assertFalse( $this->table_exists( $table ), $table );
		}
		foreach ( array( 'trust_optimize_options', 'trust_optimize_bulk_active', 'trust_optimize_pending_cleanup' ) as $option ) {
			$this->assertFalse( get_option( $option, false ), $option );
		}
		wp_cache_flush();
		$this->assertFalse( get_transient( 'trust_optimize_demo' ) );
	}

	public function test_the_original_of_an_attachment_survives_the_uninstall() {
		$a = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		$this->attachment_ids = array( $a );
		Plugin::get_instance()->cleanup->cleanup_attachment( $a );

		trust_optimize_uninstall_site();

		$this->assertFileExists( get_attached_file( $a ), 'The original of an attachment survives.' );
		$this->assertFalse( $this->table_exists( 'variants' ) );
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
	}
}
