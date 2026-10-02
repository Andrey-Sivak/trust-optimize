<?php
/**
 * CapabilityService tests.
 *
 * @package TrustOptimize\Tests
 */

use TrustOptimize\Capabilities\CapabilityService;

/**
 * @covers \TrustOptimize\Capabilities\CapabilityService
 */
class CapabilityServiceTest extends WP_UnitTestCase {

	/**
	 * Service.
	 *
	 * @var CapabilityService
	 */
	private $service;

	public function set_up() {
		parent::set_up();
		delete_option( CapabilityService::OPTION );
		$this->service = new CapabilityService();
	}

	public function test_recheck_persists_result_and_environment() {
		$stored = $this->service->recheck();

		$this->assertSame( $stored, get_option( CapabilityService::OPTION ) );
		$this->assertArrayHasKey( 'webp', $stored );
		$this->assertArrayHasKey( 'avif', $stored );
		$this->assertArrayHasKey( 'checked_at', $stored );
		$this->assertSame( PHP_VERSION, $stored['env']['php'] );
		$this->assertSame( wp_image_editor_supports( array( 'mime_type' => 'image/webp', 'methods' => array( 'save' ) ) ) ? true : false, $stored['webp'] );
	}

	public function test_supports_reads_the_stored_value_without_touching_editors() {
		update_option( CapabilityService::OPTION, array( 'webp' => true, 'avif' => false ) );

		$calls = 0;
		add_filter(
			'wp_image_editors',
			function ( $editors ) use ( &$calls ) {
				++$calls;
				return $editors;
			}
		);

		$this->assertTrue( $this->service->supports( 'webp' ) );
		$this->assertFalse( $this->service->supports( 'avif' ) );
		$this->assertSame( 0, $calls, 'supports() must not ask for image editors once a result is stored.' );
	}

	public function test_supports_computes_once_when_nothing_is_stored() {
		$this->assertFalse( get_option( CapabilityService::OPTION ) );

		$this->service->supports( 'webp' );

		$this->assertIsArray( get_option( CapabilityService::OPTION ) );
	}

	public function test_downgrade_persists_unsupported_format_and_reason() {
		update_option( CapabilityService::OPTION, array( 'webp' => true, 'avif' => true ) );

		$this->service->downgrade( 'avif', 'encoder missing' );

		$this->assertFalse( $this->service->supports( 'avif' ) );
		$this->assertTrue( $this->service->supports( 'webp' ) );
		$this->assertSame( 'encoder missing', get_option( CapabilityService::OPTION )['downgraded']['avif'] );
	}

	public function test_maybe_recheck_runs_only_when_the_environment_changed() {
		$this->service->recheck();
		$stored            = get_option( CapabilityService::OPTION );
		$stored['avif']    = 'sentinel';
		update_option( CapabilityService::OPTION, $stored );

		$this->service->maybe_recheck();
		$this->assertSame( 'sentinel', get_option( CapabilityService::OPTION )['avif'], 'Unchanged environment must keep the stored value.' );

		$stored['env']['php'] = '1.0.0';
		update_option( CapabilityService::OPTION, $stored );

		$this->service->maybe_recheck();
		$this->assertNotSame( 'sentinel', get_option( CapabilityService::OPTION )['avif'] );
		$this->assertSame( PHP_VERSION, get_option( CapabilityService::OPTION )['env']['php'] );
	}
}
