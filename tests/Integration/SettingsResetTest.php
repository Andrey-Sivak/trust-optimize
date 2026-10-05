<?php
/**
 * The admin-post handlers of the settings page.
 *
 * @package TrustOptimize\Tests
 */

use TrustOptimize\Admin\Settings;
use TrustOptimize\Capabilities\CapabilityService;

/**
 * @covers \TrustOptimize\Admin\Admin
 */
class SettingsResetTest extends WP_UnitTestCase {

	/**
	 * Run a handler and return the redirect location.
	 *
	 * @param string $action Handler suffix.
	 * @return string
	 */
	private function request( $action ) {
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'trust_optimize_' . $action );
		$location             = '';

		add_filter(
			'wp_redirect',
			static function ( $url ) {
				throw new RuntimeException( $url );
			}
		);

		try {
			do_action( 'admin_post_trust_optimize_' . $action );
		} catch ( RuntimeException $e ) {
			$location = $e->getMessage();
		}

		return $location;
	}

	public function test_reset_restores_the_defaults() {
		update_option( CapabilityService::OPTION, array( 'webp' => true, 'avif' => true ) );
		update_option( 'trust_optimize_options', array( 'webp_quality' => 40, 'remove_data_on_uninstall' => 1, 'image_quality' => 90 ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$location = $this->request( 'reset' );

		$this->assertSame( ( new Settings() )->get_defaults(), get_option( 'trust_optimize_options' ) );
		$this->assertStringContainsString( 'trust_optimize_notice=reset', $location );
	}

	public function test_reset_turns_avif_on_only_where_the_server_supports_it() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		update_option( CapabilityService::OPTION, array( 'webp' => true, 'avif' => false ) );
		update_option( 'trust_optimize_options', array( 'convert_to_avif' => 1 ) );
		$this->request( 'reset' );
		$this->assertSame( 0, get_option( 'trust_optimize_options' )['convert_to_avif'], 'No AVIF writer: the reset must not enable it.' );
		$this->assertSame( 1, get_option( 'trust_optimize_options' )['convert_to_webp'] );

		update_option( CapabilityService::OPTION, array( 'webp' => true, 'avif' => true ) );
		$this->request( 'reset' );
		$this->assertSame( 1, get_option( 'trust_optimize_options' )['convert_to_avif'] );
	}

	public function test_reset_is_refused_without_manage_options() {
		update_option( 'trust_optimize_options', array( 'webp_quality' => 40 ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->expectException( WPDieException::class );

		try {
			$this->request( 'reset' );
		} finally {
			$this->assertSame( array( 'webp_quality' => 40 ), get_option( 'trust_optimize_options' ) );
		}
	}

	public function test_recheck_recomputes_the_stored_capabilities() {
		update_option( 'trust_optimize_capabilities', array( 'webp' => false, 'avif' => false ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$location = $this->request( 'recheck_capabilities' );

		$this->assertArrayHasKey( 'checked_at', get_option( 'trust_optimize_capabilities' ) );
		$this->assertStringContainsString( 'trust_optimize_notice=rechecked', $location );
	}
}
