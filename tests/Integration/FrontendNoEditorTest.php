<?php
/**
 * The frontend must not touch image editors or the disk to decide what to serve.
 *
 * @package TrustOptimize\Tests
 */

use TrustOptimize\Capabilities\CapabilityService;

/**
 * @coversNothing
 */
class FrontendNoEditorTest extends WP_UnitTestCase {

	public function test_rendering_content_with_an_image_does_not_ask_for_editors_or_write_probes() {
		update_option( CapabilityService::OPTION, array( 'webp' => true, 'avif' => true ) );

		$attachment_id = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		$this->assertGreaterThan( 0, $attachment_id );

		$uploads_before = glob( wp_upload_dir()['basedir'] . '/trust-optimize-capability-*' ) ?: array();
		$calls          = 0;
		add_filter(
			'wp_image_editors',
			function ( $editors ) use ( &$calls ) {
				++$calls;
				return $editors;
			}
		);
		set_current_screen( 'front' );

		$html = apply_filters( 'the_content', wp_get_attachment_image( $attachment_id, 'medium' ) );

		$this->assertNotSame( '', $html );
		$this->assertSame( 0, $calls, 'Rendering must not request image editors.' );
		$this->assertSame( $uploads_before, glob( wp_upload_dir()['basedir'] . '/trust-optimize-capability-*' ) ?: array() );
	}
}
