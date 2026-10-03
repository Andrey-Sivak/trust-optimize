<?php
/**
 * Delivery of stored variants through the core image filters.
 *
 * @package TrustOptimize\Tests
 */

use TrustOptimize\Capabilities\CapabilityService;
use TrustOptimize\Domain\VariantStatus;
use TrustOptimize\Queue\ConversionQueue;

/**
 * @covers \TrustOptimize\Frontend\ImageDelivery
 * @covers \TrustOptimize\Frontend\ContentPrimer
 */
class ImageDeliveryTest extends WP_UnitTestCase {

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
		return apply_filters( 'the_content', '<img src="' . wp_get_attachment_url( $id ) . '" class="wp-image-' . $id . '" alt="x">' );
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
		$this->assertStringContainsString( '.jpg.webp 300w', $html, 'Candidates of the core srcset are offered.' );
		$this->assertStringNotContainsString( '.jpg.webp 150w', $html, 'Sizes outside the core srcset (other crop) are not offered.' );
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

	public function test_a_featured_image_gets_exactly_one_picture() {
		$id      = $this->upload();
		$post_id = self::factory()->post->create();
		set_post_thumbnail( $post_id, $id );
		ActionScheduler_QueueRunner::instance()->run();

		$html = get_the_post_thumbnail( $post_id, 'medium' );

		$this->assertSame( 1, substr_count( $html, '<picture>' ) );
		$this->assertSame( 1, substr_count( $html, '<img' ) );
		$this->assertStringContainsString( 'type="image/webp"', $html );
	}

	public function test_an_existing_picture_in_the_content_is_not_nested() {
		$id = $this->upload();
		ActionScheduler_QueueRunner::instance()->run();
		$img     = '<img src="' . wp_get_attachment_url( $id ) . '" class="wp-image-' . $id . '" alt="x">';
		$content = '<picture><source type="image/webp" srcset="a.webp">' . $img . '</picture>' . $img;

		$html = apply_filters( 'the_content', $content );

		$this->assertSame( 1, substr_count( $html, '<picture' ), 'Neither the image inside <picture> nor the identical one next to it is wrapped again.' );
	}

	public function test_a_photo_above_the_big_image_threshold_is_delivered_at_a_smaller_size() {
		$file  = get_temp_dir() . 'trust-optimize-big.jpg';
		$image = imagecreatetruecolor( 3000, 2000 );
		imagejpeg( $image, $file, 60 );
		$id = self::factory()->attachment->create_upload_object( $file );
		unlink( $file );
		ActionScheduler_QueueRunner::instance()->run();
		$large = wp_get_attachment_image_src( $id, 'large' );
		$this->assertStringContainsString( '-scaled', get_post_meta( $id, '_wp_attached_file', true ) );

		$html = apply_filters( 'the_content', '<img src="' . $large[0] . '" class="wp-image-' . $id . '" alt="x">' );

		$this->assertStringContainsString( '<picture>', $html );
		$this->assertStringContainsString( $large[0] . '.webp', $html );
	}

	public function test_the_filter_can_switch_the_delivery_off() {
		$id = $this->upload();
		ActionScheduler_QueueRunner::instance()->run();
		add_filter( 'trust_optimize_should_render', '__return_false' );

		$this->assertStringNotContainsString( '<picture', $this->render( $id ) );
		$this->assertStringNotContainsString( '<picture', wp_get_attachment_image( $id, 'medium' ) );
	}

	public function test_an_image_without_an_id_is_found_by_its_source_path_whatever_the_scheme() {
		$id = $this->upload();
		ActionScheduler_QueueRunner::instance()->run();
		$url = set_url_scheme( wp_get_attachment_url( $id ), 'https' );

		$html = apply_filters( 'the_content', '<img src="' . $url . '" alt="x">' );

		$this->assertStringContainsString( '<picture>', $html );
		$this->assertStringContainsString( 'srcset="' . $url . '.webp"', $html );
	}

	public function test_a_size_suffix_in_the_name_does_not_make_the_image_another_attachment() {
		$dir = get_temp_dir();
		copy( DIR_TESTDATA . '/images/canola.jpg', $dir . 'banner.jpg' );
		copy( DIR_TESTDATA . '/images/canola.jpg', $dir . 'banner-1920x600.jpg' );
		$plain = self::factory()->attachment->create_upload_object( $dir . 'banner.jpg' );
		$sized = self::factory()->attachment->create_upload_object( $dir . 'banner-1920x600.jpg' );
		unlink( $dir . 'banner.jpg' );
		unlink( $dir . 'banner-1920x600.jpg' );
		ActionScheduler_QueueRunner::instance()->run();
		$url = wp_get_attachment_url( $sized );
		$this->assertStringContainsString( 'banner-1920x600', $url );

		$html = apply_filters( 'the_content', '<img src="' . $url . '" alt="x">' );

		$this->assertStringContainsString( 'srcset="' . $url . '.webp"', $html );
		$this->assertStringNotContainsString( wp_get_attachment_url( $plain ) . '.webp', $html );
	}

	public function test_an_unknown_file_is_left_alone() {
		$html = apply_filters( 'the_content', '<img src="' . wp_upload_dir()['baseurl'] . '/2020/01/not-ours.jpg" alt="x">' );

		$this->assertStringNotContainsString( '<picture', $html );
	}
}
