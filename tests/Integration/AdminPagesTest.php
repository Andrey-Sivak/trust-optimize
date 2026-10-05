<?php
/**
 * The pages of the plugin in the admin: menu, overview and bulk panel.
 *
 * @package TrustOptimize\Tests
 */

use TrustOptimize\Admin\Statistics;
use TrustOptimize\Capabilities\CapabilityService;
use TrustOptimize\Core\Plugin;

/**
 * @covers \TrustOptimize\Admin\Admin
 */
class AdminPagesTest extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		update_option( CapabilityService::OPTION, array( 'webp' => true, 'avif' => true ) );
		delete_transient( Statistics::TRANSIENT );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	private function overview() {
		ob_start();
		Plugin::get_instance()->admin->display_admin_page();

		return ob_get_clean();
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

	public function test_the_overview_has_native_tabs_and_four_cards() {
		$html = $this->overview();

		$this->assertSame( 1, substr_count( $html, 'nav-tab-wrapper' ) );
		$this->assertSame( 2, preg_match_all( '/class="nav-tab[ "]/', $html ) );
		$this->assertStringContainsString( 'href="#overview"', $html );
		$this->assertStringContainsString( 'href="#bulk"', $html );
		$this->assertSame( 4, substr_count( $html, 'class="trust-optimize-card"' ) );
		$this->assertStringNotContainsString( 'Welcome to TrustOptimize', $html );
		$this->assertStringNotContainsString( 'Cursor', $html );
		$this->assertStringNotContainsString( 'trust-optimize-logo', $html );
	}

	public function test_the_overview_offers_the_bulk_tab_only_while_images_are_not_optimized() {
		$this->assertStringNotContainsString( 'not optimized yet', $this->overview() );

		self::factory()->attachment->create( array( 'post_mime_type' => 'image/jpeg' ) );
		delete_transient( Statistics::TRANSIENT );

		$html = $this->overview();

		$this->assertStringContainsString( '1 image is not optimized yet.', $html );
		$this->assertStringContainsString( 'Optimize library', $html );
	}

	public function test_the_bulk_tab_lists_the_server_checks_and_the_actions() {
		$html = $this->overview();

		$this->assertStringContainsString( 'id="trust-optimize-tab-bulk"', $html );
		$this->assertStringContainsString( 'dashicons-yes-alt', $html );
		$this->assertStringContainsString( 'Action Scheduler', $html );
		$this->assertStringContainsString( 'data-action="sync"', $html );
		$this->assertStringContainsString( 'data-action="inventory"', $html );
		$this->assertStringContainsString( 'Remove optimized files', $html );
		$this->assertStringNotContainsString( 'Start Sync', $html );
		$this->assertMatchesRegularExpression( '/data-action="pause" hidden/', $html );
		$this->assertMatchesRegularExpression( '/data-action="resume" hidden/', $html );
		$this->assertMatchesRegularExpression( '/data-action="cancel" hidden/', $html );
	}
}
