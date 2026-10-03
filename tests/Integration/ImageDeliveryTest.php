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

	private function delivery() {
		$variants = new TrustOptimize\Storage\VariantRepository( new TrustOptimize\Database\DatabaseManager() );
		$urls     = new TrustOptimize\Frontend\UploadsUrl();
		$settings = new TrustOptimize\Admin\Settings();

		return new TrustOptimize\Frontend\ImageDelivery(
			new TrustOptimize\Frontend\PictureRenderer( $variants, $urls ),
			new TrustOptimize\Frontend\ContentPrimer( $variants, $settings ),
			new TrustOptimize\Frontend\SourceResolver( $variants, $urls ),
			$settings
		);
	}

	private function deliver( $id, $attrs ) {
		return $this->delivery()->filter_content_img_tag( '<img src="' . wp_get_attachment_url( $id ) . '" class="wp-image-' . $id . '" alt="x"' . $attrs . '>', 'the_content', $id );
	}

	private function with_force_lazy( $value ) {
		update_option( 'trust_optimize_options', array( 'convert_to_webp' => 1, 'convert_to_avif' => 0, 'enable_adaptive_images' => 1, 'force_lazy' => $value ) );
	}

	public function test_loading_attributes_are_not_touched_by_default() {
		$id = $this->upload();
		ActionScheduler_QueueRunner::instance()->run();

		foreach ( array( '', ' loading="eager"', ' fetchpriority="high"', ' decoding="sync"' ) as $attrs ) {
			$html = $this->deliver( $id, $attrs );
			$this->assertStringContainsString( '<picture>', $html );
			$this->assertStringContainsString( ' alt="x"' . $attrs . '></picture>', $html );
		}
	}

	public function test_force_lazy_adds_loading_only_when_it_is_missing_and_the_image_is_not_the_lcp_candidate() {
		$id = $this->upload();
		ActionScheduler_QueueRunner::instance()->run();
		$this->with_force_lazy( 1 );

		$this->assertStringContainsString( '<img loading="lazy" src=', $this->deliver( $id, '' ) );
		$this->assertStringContainsString( ' loading="eager"></picture>', $this->deliver( $id, ' loading="eager"' ) );
		$lcp = $this->deliver( $id, ' fetchpriority="high"' );
		$this->assertStringContainsString( '<picture>', $lcp );
		$this->assertStringNotContainsString( 'loading=', $lcp, 'The LCP image is never made lazy.' );
	}

	public function test_the_attributes_filter_overrides_and_removes_attributes() {
		$id = $this->upload();
		ActionScheduler_QueueRunner::instance()->run();
		$this->with_force_lazy( 1 );
		$seen = array();
		add_filter(
			'trust_optimize_img_attributes',
			static function ( $attrs, $attachment_id, $context ) use ( &$seen ) {
				$seen = array( $attrs, $attachment_id, $context );
				return array( 'loading' => 'eager', 'decoding' => null );
			},
			10,
			3
		);

		$html = $this->deliver( $id, ' decoding="async"' );

		$this->assertSame( array( array( 'loading' => 'lazy' ), $id, 'the_content' ), $seen );
		$this->assertStringContainsString( ' loading="eager"', $html );
		$this->assertStringNotContainsString( 'decoding', $html );
	}

	public function test_core_attributes_of_wp_get_attachment_image_are_kept() {
		$id = $this->upload();
		ActionScheduler_QueueRunner::instance()->run();

		$html = wp_get_attachment_image( $id, 'large', false, array( 'loading' => 'eager', 'fetchpriority' => 'high' ) );

		$this->assertStringContainsString( '<picture>', $html );
		$this->assertStringContainsString( 'loading="eager"', $html );
		$this->assertStringContainsString( 'fetchpriority="high"', $html );
	}

	public function test_markup_outside_the_images_is_identical_with_and_without_the_plugin() {
		$id  = $this->upload();
		$img = '<img src="' . wp_get_attachment_url( $id ) . '" class="wp-image-' . $id . '" alt="Caf&eacute; &amp; \'bar\'">';
		ActionScheduler_QueueRunner::instance()->run();
		$content = "<section class=\"a\"><h2>Заголовок &amp; <em>текст</em></h2>\n<p>one<br/>two {$img}</p>\n"
			. "<!-- comment --><script>var a = '<b>' + \"</b>\";</script><table><tr><td>1</td></tr></table>\n"
			. "<figure class=\"wp-block-image\">{$img}<figcaption>c</figcaption></figure><p>&nbsp;<custom-tag data-x='1'>x</custom-tag></p></section>";

		$with = apply_filters( 'the_content', $content );
		add_filter( 'trust_optimize_should_render', '__return_false' );
		$without = apply_filters( 'the_content', $content );

		$this->assertSame( 2, substr_count( $with, '<picture>' ) );
		$this->assertSame( $without, preg_replace( '#<picture>(?:<source [^>]*>)+|</picture>#', '', $with ) );
	}
}
