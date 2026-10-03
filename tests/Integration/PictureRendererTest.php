<?php
/**
 * <picture> markup built from the srcset and sizes of core.
 *
 * @package TrustOptimize\Tests
 */

use TrustOptimize\Database\DatabaseManager;
use TrustOptimize\Domain\VariantStatus;
use TrustOptimize\Frontend\PictureRenderer;
use TrustOptimize\Frontend\UploadsUrl;
use TrustOptimize\Storage\VariantRepository;

/**
 * @covers \TrustOptimize\Frontend\PictureRenderer
 */
class PictureRendererTest extends WP_UnitTestCase {

	const ID = 4242;

	/**
	 * @var VariantRepository
	 */
	private $variants;

	/**
	 * @var PictureRenderer
	 */
	private $renderer;

	/**
	 * @var string
	 */
	private $base;

	public function set_up() {
		parent::set_up();
		$this->variants = new VariantRepository( new DatabaseManager() );
		$this->renderer = new PictureRenderer( $this->variants, new UploadsUrl() );
		$this->base     = wp_upload_dir()['baseurl'] . '/2026/05';
	}

	private function add( $source, $format = 'webp', $status = VariantStatus::DONE, $legacy = null ) {
		$this->variants->upsert(
			array(
				'attachment_id'        => self::ID,
				'size_name'            => $source . $format,
				'format'               => $format,
				'status'               => $status,
				'source_relative_path' => '2026/05/' . $source,
				'relative_path'        => VariantStatus::DONE === $status ? '2026/05/' . $source . '.' . $format : null,
				'legacy_relative_path' => $legacy,
			)
		);
	}

	private function img( $src, $srcset = '', $sizes = '', $extra = '' ) {
		return '<img src="' . $src . '"' . ( '' !== $srcset ? ' srcset="' . $srcset . '"' : '' ) . ( '' !== $sizes ? ' sizes="' . $sizes . '"' : '' ) . ' alt="x"' . $extra . '>';
	}

	public function test_sources_repeat_the_descriptors_and_sizes_of_core() {
		foreach ( array( 'a.jpg', 'a-300x200.jpg', 'a-150x100.jpg' ) as $file ) {
			$this->add( $file );
		}
		$img = $this->img(
			"{$this->base}/a.jpg",
			"{$this->base}/a.jpg 1024w, {$this->base}/a-300x200.jpg 300w, {$this->base}/a-150x100.jpg 150w",
			'(max-width: 1024px) 100vw, 1024px'
		);

		$this->assertSame(
			'<picture><source type="image/webp" srcset="' . "{$this->base}/a.jpg.webp 1024w, {$this->base}/a-300x200.jpg.webp 300w, {$this->base}/a-150x100.jpg.webp 150w"
			. '" sizes="(max-width: 1024px) 100vw, 1024px">' . $img . '</picture>',
			$this->renderer->render( $img, self::ID )
		);
	}

	public function test_a_square_thumbnail_offers_only_its_own_candidate() {
		foreach ( array( 'a.jpg', 'a-300x200.jpg', 'a-150x150.jpg' ) as $file ) {
			$this->add( $file );
		}
		$img = $this->img( "{$this->base}/a-150x150.jpg", "{$this->base}/a-150x150.jpg 150w", '(max-width: 150px) 100vw, 150px' );

		$out = $this->renderer->render( $img, self::ID );

		$this->assertStringContainsString( 'srcset="' . $this->base . '/a-150x150.jpg.webp 150w" sizes="(max-width: 150px) 100vw, 150px"', $out );
		$this->assertStringNotContainsString( '300w', $out );
	}

	public function test_auto_sizes_are_kept() {
		$this->add( 'a.jpg' );
		$img = $this->img( "{$this->base}/a.jpg", "{$this->base}/a.jpg 640w", 'auto, (max-width: 640px) 100vw, 640px' );

		$this->assertStringContainsString( 'sizes="auto, (max-width: 640px) 100vw, 640px"', $this->renderer->render( $img, self::ID ) );
	}

	public function test_the_img_is_kept_byte_for_byte() {
		$this->add( 'a.jpg' );
		$img = $this->img( "{$this->base}/a.jpg", "{$this->base}/a.jpg 640w", '640px', ' class="wp-image-' . self::ID . ' alignleft" width="640" height="480" loading="eager" fetchpriority="high"' );

		$out = $this->renderer->render( $img, self::ID );

		$this->assertStringEndsWith( $img . '</picture>', $out );
	}

	public function test_a_cdn_host_stays_in_the_variant_urls() {
		add_filter(
			'trust_optimize_cdn_hosts',
			static function () {
				return array( 'cdn.example.net' );
			}
		);
		$this->add( 'a.jpg' );
		$cdn = str_replace( '//example.org', '//cdn.example.net', $this->base );
		$img = $this->img( "{$cdn}/a.jpg", "{$cdn}/a.jpg 640w" );

		$this->assertStringContainsString( 'srcset="' . $cdn . '/a.jpg.webp 640w"', $this->renderer->render( $img, self::ID ) );
	}

	public function test_an_unknown_host_is_not_rewritten() {
		$this->add( 'a.jpg' );
		$other = str_replace( '//example.org', '//other.example.net', $this->base );
		$img   = $this->img( "{$other}/a.jpg", "{$other}/a.jpg 640w" );

		$this->assertSame( $img, $this->renderer->render( $img, self::ID ) );
	}

	public function test_a_format_without_a_variant_for_src_is_skipped() {
		$this->add( 'a-300x200.jpg', 'webp' );
		$this->add( 'a.jpg', 'avif' );
		$this->add( 'a-300x200.jpg', 'avif' );
		$img = $this->img( "{$this->base}/a.jpg", "{$this->base}/a.jpg 1024w, {$this->base}/a-300x200.jpg 300w" );

		$out = $this->renderer->render( $img, self::ID );

		$this->assertStringContainsString( 'image/avif', $out );
		$this->assertStringNotContainsString( 'image/webp', $out );
	}

	public function test_avif_comes_before_webp() {
		$this->add( 'a.jpg', 'webp' );
		$this->add( 'a.jpg', 'avif' );
		$out = $this->renderer->render( $this->img( "{$this->base}/a.jpg" ), self::ID );

		$this->assertLessThan( strpos( $out, 'image/webp' ), strpos( $out, 'image/avif' ) );
	}

	public function test_without_srcset_the_src_is_the_only_candidate() {
		$this->add( 'a.jpg' );
		$img = $this->img( "{$this->base}/a.jpg" );

		$this->assertSame(
			'<picture><source type="image/webp" srcset="' . $this->base . '/a.jpg.webp">' . $img . '</picture>',
			$this->renderer->render( $img, self::ID )
		);
	}

	public function test_a_pending_row_serves_its_legacy_file_until_it_is_done() {
		$this->add( 'a.jpg', 'webp', VariantStatus::PENDING, '2026/05/a.webp' );
		$img = $this->img( "{$this->base}/a.jpg" );

		$this->assertStringContainsString( 'srcset="' . $this->base . '/a.webp"', $this->renderer->render( $img, self::ID ) );

		$this->add( 'a.jpg', 'webp', VariantStatus::DONE, '2026/05/a.webp' );

		$this->assertStringContainsString( 'srcset="' . $this->base . '/a.jpg.webp"', $this->renderer->render( $img, self::ID ) );
	}

	public function test_a_failed_row_serves_its_legacy_file_only() {
		$this->add( 'a.jpg', 'webp', VariantStatus::FAILED, '2026/05/a.webp' );
		$this->add( 'b.jpg', 'webp', VariantStatus::FAILED );

		$this->assertStringContainsString( $this->base . '/a.webp', $this->renderer->render( $this->img( "{$this->base}/a.jpg" ), self::ID ) );
		$this->assertSame( $this->img( "{$this->base}/b.jpg" ), $this->renderer->render( $this->img( "{$this->base}/b.jpg" ), self::ID ) );
	}

	public function test_an_attachment_without_variants_and_a_data_url_are_left_alone() {
		$this->assertSame( $this->img( "{$this->base}/a.jpg" ), $this->renderer->render( $this->img( "{$this->base}/a.jpg" ), self::ID ) );
		$this->add( 'a.jpg' );
		$data = $this->img( 'data:image/gif;base64,R0lGOD' );
		$this->assertSame( $data, $this->renderer->render( $data, self::ID ) );
	}
}
