<?php
/**
 * The warning in the plugin list.
 *
 * @package TrustOptimize\Tests
 */

require_once __DIR__ . '/uninstall-fixture.php';

use TrustOptimize\Core\Plugin;

/**
 * @covers \TrustOptimize\Admin\Admin::render_uninstall_warning
 */
class UninstallWarningTest extends WP_UnitTestCase {

	use Uninstall_Fixture;

	public function tear_down() {
		foreach ( glob( wp_upload_dir()['basedir'] . '/warning-test/*' ) ?: array() as $file ) {
			unlink( $file );
		}
		parent::tear_down();
	}

	/**
	 * Output of the plugin-row hook.
	 *
	 * @return string
	 */
	private function render() {
		require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
		require_once ABSPATH . 'wp-admin/includes/list-table.php';
		set_current_screen( 'plugins' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		ob_start();
		do_action( 'after_plugin_row_' . TRUST_OPTIMIZE_PLUGIN_BASENAME );

		return ob_get_clean();
	}

	public function test_warns_when_data_removal_is_on_and_files_are_registered() {
		update_option( 'trust_optimize_options', array( 'remove_data_on_uninstall' => 1 ) );
		$this->add_variant_file( 4001, 'warning-test', 'a.jpg.webp' );
		$this->add_variant_file( 4002, 'warning-test', 'b.jpg.webp' );

		$html = $this->render();

		$this->assertStringContainsString( 'notice-warning', $html );
		$this->assertStringContainsString( '2 generated files', $html );
	}

	public function test_is_silent_without_the_flag_or_without_files() {
		update_option( 'trust_optimize_options', array( 'remove_data_on_uninstall' => 0 ) );
		$this->add_variant_file( 4001, 'warning-test', 'a.jpg.webp' );
		$this->assertSame( '', $this->render() );

		update_option( 'trust_optimize_options', array( 'remove_data_on_uninstall' => 1 ) );
		Plugin::get_instance()->cleanup->cleanup_attachment( 4001 );
		$this->assertSame( '', $this->render() );
	}
}
