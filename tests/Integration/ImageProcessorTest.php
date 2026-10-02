<?php
/**
 * Frontend rendering from stored variants.
 *
 * @package TrustOptimize\Tests
 */

use TrustOptimize\Capabilities\CapabilityService;
use TrustOptimize\Domain\VariantStatus;
use TrustOptimize\Queue\ConversionQueue;

/**
 * @covers \TrustOptimize\Features\Optimization\ImageProcessor
 */
class ImageProcessorTest extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		update_option( CapabilityService::OPTION, array( 'webp' => true, 'avif' => true ) );
		update_option( 'trust_optimize_options', array( 'convert_to_webp' => 1, 'convert_to_avif' => 0, 'enable_adaptive_images' => 1 ) );
		as_unschedule_all_actions( ConversionQueue::HOOK_PROCESS );
		set_current_screen( 'front' );
	}

	private function upload() {
		return self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
	}

	private function render( $id ) {
		return apply_filters( 'the_content', '<img src="' . wp_get_attachment_url( $id ) . '" alt="x">' );
	}

	public function test_without_finished_variants_the_markup_is_left_alone() {
		$id = $this->upload();

		$html = $this->render( $id );

		$this->assertStringNotContainsString( '<picture', $html );
		$this->assertStringContainsString( '<img', $html );
	}

	public function test_optimized_attachment_gets_picture_with_variant_urls() {
		$id = $this->upload();
		ActionScheduler_QueueRunner::instance()->run();

		$html = $this->render( $id );

		$this->assertStringContainsString( '<picture>', $html );
		$this->assertStringContainsString( 'type="image/webp"', $html );
		$this->assertMatchesRegularExpression( '#srcset="[^"]*/canola(-\d+)?\.jpg\.webp 640w#', $html );
		$this->assertStringContainsString( '.jpg.webp 150w', $html, 'Every finished size is offered.' );
		$this->assertStringNotContainsString( 'image/avif', $html, 'Formats without finished variants are not offered.' );
	}

	public function test_variants_that_are_not_done_are_not_served() {
		$id = $this->upload();
		ActionScheduler_QueueRunner::instance()->run();
		$variants = new TrustOptimize\Storage\VariantRepository( new TrustOptimize\Database\DatabaseManager() );
		foreach ( $variants->get_for_attachment( $id ) as $row ) {
			$variants->transition( $row['id'], VariantStatus::DONE, VariantStatus::PENDING );
		}

		$this->assertStringNotContainsString( '<picture', $this->render( $id ) );
	}
}
