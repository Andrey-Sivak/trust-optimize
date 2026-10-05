<?php
/**
 * The admin menu of the plugin.
 *
 * @package TrustOptimize\Tests
 */

use TrustOptimize\Core\Plugin;

/**
 * @covers \TrustOptimize\Admin\Admin
 */
class AdminPagesTest extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function test_the_menu_has_an_image_icon_and_the_overview_before_the_settings() {
		global $menu, $submenu;

		$menu    = array();
		$submenu = array();
		Plugin::get_instance()->admin->add_admin_menu();

		$icons = wp_list_pluck( $menu, 6, 2 );

		$this->assertSame( 'dashicons-format-image', $icons['trust-optimize'] );
		$this->assertSame(
			array( 'trust-optimize', 'trust-optimize-settings' ),
			wp_list_pluck( $submenu['trust-optimize'], 2 )
		);
		$this->assertSame( array( 'Overview', 'Settings' ), wp_list_pluck( $submenu['trust-optimize'], 0 ) );
	}
}
