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
		$this->assertStringNotContainsString( '?>', $html );
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

	private function settings_page() {
		$GLOBALS['title'] = 'Settings';

		Plugin::get_instance()->admin->register_settings();

		ob_start();
		Plugin::get_instance()->admin->display_settings_page();

		return ob_get_clean();
	}

	public function test_the_settings_are_grouped_in_four_sections() {
		$html = $this->settings_page();

		$this->assertSame( 4, substr_count( $html, '<h2>' ) );

		foreach ( array( 'Delivery', 'Formats and quality', 'Limits', 'Uninstall' ) as $title ) {
			$this->assertStringContainsString( '<h2>' . $title . '</h2>', $html );
		}

		$this->assertStringNotContainsString( 'General Settings', $html );
		$this->assertStringNotContainsString( 'About TrustOptimize', $html );
		$this->assertStringNotContainsString( '?>', $html );
	}

	public function test_formats_are_named_without_uppercasing_them() {
		$html = $this->settings_page();

		$this->assertStringContainsString( 'Create WebP', $html );
		$this->assertStringContainsString( 'WebP quality', $html );
		$this->assertStringContainsString( 'AVIF quality', $html );
		$this->assertStringNotContainsString( 'WEBP', $html );
	}

	public function test_the_image_limit_is_entered_in_megapixels() {
		update_option( 'trust_optimize_options', array( 'max_pixels' => 50000000 ) );

		$html = $this->settings_page();

		$this->assertMatchesRegularExpression( '/name="trust_optimize_options\[max_megapixels\]"\s+value="50"/', $html );
		$this->assertStringNotContainsString( 'trust_optimize_options[max_pixels]', $html );
	}

	public function test_reset_and_format_check_sit_next_to_the_fields_they_belong_to() {
		$html = $this->settings_page();

		$this->assertMatchesRegularExpression( '/<button[^>]+form="trust-optimize-recheck-form"/', $html );
		$this->assertMatchesRegularExpression( '/<button[^>]+form="trust-optimize-reset-form"/', $html );
		$this->assertStringNotContainsString( 'Default quality for new installs', $html );
	}
}
