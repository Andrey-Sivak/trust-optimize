<?php
/**
 * Uninstall with a 1.x registry that was not migrated yet (03.7).
 *
 * @package TrustOptimize\Tests
 */

require_once __DIR__ . '/legacy-schema-fixture.php';

use TrustOptimize\Capabilities\CapabilityService;
use TrustOptimize\Core\Plugin;
use TrustOptimize\Migration\ConflictReport;

/**
 * @covers ::trust_optimize_uninstall_cleanup_generated_files
 * @covers ::trust_optimize_uninstall_import_legacy_registry
 */
class UninstallLegacyTest extends WP_UnitTestCase {

	use Legacy_Schema_Fixture;

	public function set_up() {
		parent::set_up();
		$this->install_legacy_table();
		delete_option( ConflictReport::OPTION );
		update_option( CapabilityService::OPTION, array( 'webp' => true, 'avif' => false ) );

		// Without the "remove data" option the file only defines its functions (and clears transients).
		if ( ! function_exists( 'trust_optimize_uninstall_cleanup_generated_files' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'trust-optimize/trust-optimize.php' );
			include TRUST_OPTIMIZE_PLUGIN_DIR . 'uninstall.php';
		}
	}

	public function tear_down() {
		$this->remove_legacy_schema();
		delete_option( ConflictReport::OPTION );
		parent::tear_down();
	}

	public function test_unmigrated_1x_files_are_cleaned_up_and_the_original_of_another_attachment_is_not() {
		$a = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		$b = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/test-image.jpg' );
		Plugin::get_instance()->cleanup->cleanup_attachment( $a );
		Plugin::get_instance()->cleanup->cleanup_attachment( $b );

		$a_original = get_post_meta( $a, '_wp_attached_file', true );
		$a_legacy   = preg_replace( '/\.jpg$/', '.webp', $a_original );

		$this->add_legacy_manifest(
			$a,
			array( array( 'size_name' => 'original', 'format' => 'webp', 'file' => $a_legacy ) )
		);
		// Scenario A of H-1: the 1.x variant of B has the name of the original of A.
		$this->add_legacy_manifest(
			$b,
			array( array( 'size_name' => 'original', 'format' => 'png', 'file' => $a_original ) ),
			false
		);

		$summary = trust_optimize_uninstall_cleanup_generated_files();

		$this->assertTrue( $summary['legacy_registry_done'] );
		$this->assertFileDoesNotExist( wp_upload_dir()['basedir'] . '/' . $a_legacy );
		$this->assertFileExists( get_attached_file( $a ), 'The original of another attachment survives the cleanup.' );
	}
}
