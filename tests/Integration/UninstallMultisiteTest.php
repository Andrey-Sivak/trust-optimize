<?php
/**
 * Uninstall of every site of a network (06.4).
 *
 * @package TrustOptimize\Tests
 */

require_once __DIR__ . '/uninstall-fixture.php';

/**
 * @group ms-required
 * @covers ::trust_optimize_uninstall
 */
class UninstallMultisiteTest extends WP_UnitTestCase {

	use Uninstall_Fixture;

	/**
	 * Sites created by the test.
	 *
	 * @var int[]
	 */
	private $blog_ids = array();

	public function set_up() {
		parent::set_up();

		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Needs WP_MULTISITE=1.' );
		}

		$this->use_real_tables();
		$this->load_uninstall_functions();
		$this->capture_error_log();
	}

	public function tear_down() {
		global $wpdb;

		$wpdb->query( 'ROLLBACK' );
		$this->release_error_log();
		$this->restore_plugin_tables();
		delete_option( 'trust_optimize_options' );
		foreach ( $this->blog_ids as $blog_id ) {
			switch_to_blog( $blog_id );
			$this->restore_plugin_tables();
			delete_option( 'trust_optimize_options' );
			restore_current_blog();
		}
		parent::tear_down();
	}

	public function test_every_site_is_cleaned_with_its_own_flag_and_tables() {
		$this->blog_ids = array(
			self::factory()->blog->create(),
			self::factory()->blog->create(),
		);

		// Creating the tables commits the open transaction, so every site gets its tables before any data.
		foreach ( $this->blog_ids as $blog_id ) {
			switch_to_blog( $blog_id );
			$this->restore_plugin_tables();
			restore_current_blog();
		}

		$files = array();
		foreach ( $this->blog_ids as $index => $blog_id ) {
			switch_to_blog( $blog_id );
			update_option( 'trust_optimize_options', array( 'remove_data_on_uninstall' => 0 === $index ? 1 : 0 ) );
			$files[ $blog_id ] = $this->add_variant_file( 10, 'multisite-test', 'a.jpg.webp' );
			restore_current_blog();
		}

		trust_optimize_uninstall();

		list( $removing, $keeping ) = $this->blog_ids;

		switch_to_blog( $removing );
		$this->assertFileDoesNotExist( $files[ $removing ] );
		$this->assertFalse( $this->table_exists( 'variants' ) );
		$this->assertFalse( get_option( 'trust_optimize_options', false ) );
		restore_current_blog();

		switch_to_blog( $keeping );
		$this->assertFileExists( $files[ $keeping ] );
		$this->assertTrue( $this->table_exists( 'variants' ), 'A site without the flag keeps its data.' );
		unlink( $files[ $keeping ] );
		restore_current_blog();
	}
}
