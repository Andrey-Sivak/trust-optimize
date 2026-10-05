<?php
/**
 * AtomicImageWriter tests.
 *
 * @package TrustOptimize\Tests
 */

use TrustOptimize\Database\DatabaseManager;
use TrustOptimize\Files\AtomicImageWriter;
use TrustOptimize\Files\FileOwnership;
use TrustOptimize\Storage\VariantRepository;

/**
 * @covers \TrustOptimize\Files\AtomicImageWriter
 */
class AtomicImageWriterTest extends WP_UnitTestCase {

	/**
	 * Directory under uploads used by the test.
	 *
	 * @var string
	 */
	private $dir;

	/**
	 * Variants.
	 *
	 * @var VariantRepository
	 */
	private $variants;

	/**
	 * Writer.
	 *
	 * @var AtomicImageWriter
	 */
	private $writer;

	public function set_up() {
		parent::set_up();
		$this->dir = trailingslashit( wp_upload_dir()['basedir'] ) . 'writer-test-' . wp_generate_uuid4();
		wp_mkdir_p( $this->dir );

		$this->variants = new VariantRepository( new DatabaseManager() );
		$this->writer   = new AtomicImageWriter( $this->variants, new FileOwnership( $this->variants ) );
	}

	public function tear_down() {
		foreach ( glob( $this->dir . '/*' ) ?: array() as $file ) {
			unlink( $file );
		}
		rmdir( $this->dir );
		parent::tear_down();
	}

	private function make_jpeg( $name ) {
		$path = $this->dir . '/' . $name;
		$im   = imagecreatetruecolor( 40, 30 );
		imagejpeg( $im, $path );

		return $path;
	}

	private function relative( $path ) {
		return ltrim( substr( $path, strlen( wp_upload_dir()['basedir'] ) ), '/' );
	}

	private function leftovers() {
		return glob( $this->dir . '/*.tmp-*' ) ?: array();
	}

	public function test_saves_through_temp_file_into_target() {
		$source = $this->make_jpeg( 'photo.jpg' );
		$editor = wp_get_image_editor( $source );
		$target = $this->dir . '/photo.jpg.webp';

		$result = $this->writer->save( $editor, $target, 'image/webp' );

		$this->assertIsArray( $result );
		$this->assertSame( $target, $result['path'] );
		$this->assertSame( 'photo.jpg.webp', $result['file'] );
		$this->assertSame( 'image/webp', $result['mime-type'] );
		$this->assertFileExists( $target );
		$this->assertSame( array(), $this->leftovers() );
	}

	public function test_same_stem_sources_get_distinct_targets_and_originals_stay_intact() {
		$jpg  = $this->make_jpeg( 'photo.jpg' );
		$png  = $this->dir . '/photo.png';
		$im   = imagecreatetruecolor( 20, 20 );
		imagepng( $im, $png );
		$before = array( md5_file( $jpg ), md5_file( $png ) );

		$this->assertIsArray( $this->writer->save( wp_get_image_editor( $jpg ), $this->dir . '/photo.jpg.webp', 'image/webp' ) );
		$this->assertIsArray( $this->writer->save( wp_get_image_editor( $png ), $this->dir . '/photo.png.webp', 'image/webp' ) );

		$this->assertSame( $before, array( md5_file( $jpg ), md5_file( $png ) ) );
		$this->assertFileExists( $this->dir . '/photo.jpg.webp' );
		$this->assertFileExists( $this->dir . '/photo.png.webp' );
	}

	public function test_refuses_to_overwrite_a_foreign_file() {
		$source = $this->make_jpeg( 'photo.jpg' );
		$target = $this->dir . '/photo.jpg.webp';
		file_put_contents( $target, 'user file' );

		$result = $this->writer->save( wp_get_image_editor( $source ), $target, 'image/webp' );

		$this->assertWPError( $result );
		$this->assertSame( 'target_exists_foreign', $result->get_error_code() );
		$this->assertSame( 'user file', file_get_contents( $target ) );
		$this->assertSame( array(), $this->leftovers() );
	}

	public function test_overwrites_a_file_owned_by_a_variant_row() {
		$source = $this->make_jpeg( 'photo.jpg' );
		$target = $this->dir . '/photo.jpg.webp';
		file_put_contents( $target, 'old variant' );
		$this->variants->upsert(
			array(
				'attachment_id' => 801,
				'size_name'     => 'original',
				'format'        => 'webp',
				'relative_path' => $this->relative( $target ),
			)
		);

		$this->assertIsArray( $this->writer->save( wp_get_image_editor( $source ), $target, 'image/webp' ) );
		$this->assertNotSame( 'old variant', file_get_contents( $target ) );
	}

	public function test_rejects_target_outside_uploads() {
		$editor = wp_get_image_editor( $this->make_jpeg( 'photo.jpg' ) );

		$result = $this->writer->save( $editor, sys_get_temp_dir() . '/escape.jpg.webp', 'image/webp' );

		$this->assertWPError( $result );
		$this->assertSame( 'target_outside_uploads', $result->get_error_code() );
	}

	public function test_failed_save_leaves_no_temp_file() {
		$editor = $this->getMockBuilder( WP_Image_Editor::class )->disableOriginalConstructor()->getMockForAbstractClass();
		$editor->method( 'save' )->willReturnCallback(
			function ( $path ) {
				file_put_contents( $path, 'partial' );
				return new WP_Error( 'image_save_error', 'boom' );
			}
		);

		$result = $this->writer->save( $editor, $this->dir . '/photo.jpg.webp', 'image/webp' );

		$this->assertWPError( $result );
		$this->assertSame( array(), $this->leftovers() );
		$this->assertFileDoesNotExist( $this->dir . '/photo.jpg.webp' );
	}

	public function test_unexpected_mime_is_rejected_and_cleaned_up() {
		$editor = $this->getMockBuilder( WP_Image_Editor::class )->disableOriginalConstructor()->getMockForAbstractClass();
		$editor->method( 'save' )->willReturnCallback(
			function ( $path ) {
				file_put_contents( $path, 'jpeg data' );
				return array(
					'path'      => $path,
					'mime-type' => 'image/jpeg',
				);
			}
		);

		$result = $this->writer->save( $editor, $this->dir . '/photo.jpg.webp', 'image/webp' );

		$this->assertWPError( $result );
		$this->assertSame( 'unexpected_output', $result->get_error_code() );
		$this->assertSame( array(), glob( $this->dir . '/*' ) ?: array() );
	}
}
