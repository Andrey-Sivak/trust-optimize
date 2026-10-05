<?php
/**
 * ImageConverter tests (regressions H-1, H-2, M-1, M-8, M-13).
 *
 * @package TrustOptimize\Tests
 */

use TrustOptimize\Capabilities\CapabilityService;
use TrustOptimize\Database\DatabaseManager;
use TrustOptimize\Domain\VariantStatus;
use TrustOptimize\Features\Optimization\ImageConverter;
use TrustOptimize\Files\AtomicImageWriter;
use TrustOptimize\Files\FileOwnership;
use TrustOptimize\Settings\OptimizationSettings;
use TrustOptimize\Storage\VariantRepository;

/**
 * @covers \TrustOptimize\Features\Optimization\ImageConverter
 */
class ImageConverterTest extends WP_UnitTestCase {

	/**
	 * Uploads sub directory used by the test.
	 *
	 * @var string
	 */
	private $dir;

	/**
	 * Its path relative to uploads.
	 *
	 * @var string
	 */
	private $relative_dir;

	/**
	 * Variants.
	 *
	 * @var VariantRepository
	 */
	private $variants;

	/**
	 * Converter.
	 *
	 * @var ImageConverter
	 */
	private $converter;

	/**
	 * Capabilities.
	 *
	 * @var CapabilityService
	 */
	private $capabilities;

	public function set_up() {
		parent::set_up();
		$this->relative_dir = 'converter-test-' . wp_generate_uuid4();
		$this->dir          = wp_upload_dir()['basedir'] . '/' . $this->relative_dir;
		wp_mkdir_p( $this->dir );

		update_option( CapabilityService::OPTION, array( 'webp' => true, 'avif' => true ) );
		$this->capabilities = new CapabilityService();
		$this->variants     = new VariantRepository( new DatabaseManager() );
		$this->converter    = new ImageConverter( $this->variants, new AtomicImageWriter( $this->variants, new FileOwnership( $this->variants ) ), $this->capabilities );
	}

	public function tear_down() {
		foreach ( glob( $this->dir . '/*' ) ?: array() as $file ) {
			unlink( $file );
		}
		rmdir( $this->dir );
		parent::tear_down();
	}

	private function settings( $webp_quality = 80 ) {
		return new OptimizationSettings( array( 'webp', 'avif' ), array( 'webp', 'avif' ), array( 'webp' => $webp_quality, 'avif' => 60 ) );
	}

	/**
	 * Create a source file with a fast-compressing gradient and a pending row for it.
	 */
	private function source( $name, $type = 'jpg', $attachment_id = 901, $format = 'webp', $size_name = 'original' ) {
		$path = $this->dir . '/' . $name;
		$im   = imagecreatetruecolor( 200, 120 );
		for ( $x = 0; $x < 200; $x += 10 ) {
			imagefilledrectangle( $im, $x, 0, $x + 9, 119, imagecolorallocate( $im, $x, 255 - $x, 90 ) );
		}
		'png' === $type ? imagepng( $im, $path, 0 ) : imagejpeg( $im, $path, 95 );

		$id = $this->variants->upsert(
			array(
				'attachment_id'        => $attachment_id,
				'size_name'            => $size_name,
				'format'               => $format,
				'source_relative_path' => $this->relative_dir . '/' . $name,
			)
		);

		return $this->variants->get_for_attachment( $attachment_id )[ array_search( $id, array_column( $this->variants->get_for_attachment( $attachment_id ), 'id' ), true ) ];
	}

	public function test_converts_to_done_and_records_the_outcome() {
		$row = $this->source( 'photo.jpg' );

		$result = $this->converter->convert( $row, $this->settings() );

		$this->assertTrue( $result->is_success(), wp_json_encode( $result->to_array() ) );
		$done = $this->variants->get_servable_for_attachment( 901 )[0];
		$this->assertSame( $this->relative_dir . '/photo.jpg.webp', $done['relative_path'] );
		$this->assertSame( 80, $done['quality'] );
		$this->assertGreaterThan( 0, $done['file_size'] );
		$this->assertGreaterThan( $done['file_size'], $done['source_file_size'] );
		$this->assertSame( hash_file( 'sha256', $this->dir . '/photo.jpg.webp' ), $done['file_hash'] );
		$this->assertSame( 200, $done['width'] );
	}

	/**
	 * Regression H-1: same stem in one directory must not overwrite anything.
	 */
	public function test_same_stem_files_do_not_overwrite_each_other_or_originals() {
		$jpg  = $this->source( 'photo.jpg', 'jpg', 902 );
		$png  = $this->source( 'photo.png', 'png', 903 );
		$logo = $this->source( 'logo.png', 'png', 904 );
		file_put_contents( $this->dir . '/logo.webp', 'the editor made this logo.webp by hand' );
		$before = array_map( 'md5_file', glob( $this->dir . '/*' ) );

		$this->assertTrue( $this->converter->convert( $jpg, $this->settings() )->is_success() );
		$this->assertTrue( $this->converter->convert( $png, $this->settings() )->is_success() );
		$this->assertTrue( $this->converter->convert( $logo, $this->settings() )->is_success() );

		$this->assertSame( 'the editor made this logo.webp by hand', file_get_contents( $this->dir . '/logo.webp' ) );
		$this->assertFileExists( $this->dir . '/photo.jpg.webp' );
		$this->assertFileExists( $this->dir . '/photo.png.webp' );
		$this->assertFileExists( $this->dir . '/logo.png.webp' );
		foreach ( array( 'photo.jpg', 'photo.png', 'logo.png' ) as $original ) {
			$this->assertContains( md5_file( $this->dir . '/' . $original ), $before, "{$original} changed" );
		}
	}

	public function test_variant_that_is_not_smaller_is_removed_and_skipped() {
		$path = $this->dir . '/noise.jpg';
		$im   = imagecreatetruecolor( 64, 64 );
		for ( $x = 0; $x < 64; $x++ ) {
			for ( $y = 0; $y < 64; $y++ ) {
				imagesetpixel( $im, $x, $y, imagecolorallocate( $im, wp_rand( 0, 255 ), wp_rand( 0, 255 ), wp_rand( 0, 255 ) ) );
			}
		}
		imagejpeg( $im, $path, 5 );
		$this->variants->upsert(
			array(
				'attachment_id'        => 905,
				'size_name'            => 'original',
				'format'               => 'webp',
				'source_relative_path' => $this->relative_dir . '/noise.jpg',
			)
		);
		$row = $this->variants->get_for_attachment( 905 )[0];

		$result = $this->converter->convert( $row, $this->settings( 100 ) );

		$this->assertTrue( $result->is_skipped() );
		$after = $this->variants->get_for_attachment( 905 )[0];
		$this->assertSame( VariantStatus::SKIPPED, $after['status'] );
		$this->assertSame( 'not_smaller', $after['reason'] );
		$this->assertNull( $after['relative_path'] );
		$this->assertFileDoesNotExist( $this->dir . '/noise.jpg.webp' );
	}

	public function test_missing_source_fails_with_reason() {
		$row = $this->source( 'gone.jpg', 'jpg', 906 );
		unlink( $this->dir . '/gone.jpg' );

		$result = $this->converter->convert( $row, $this->settings() );

		$this->assertTrue( $result->is_failed() );
		$this->assertSame( 'missing_file', $this->variants->get_for_attachment( 906 )[0]['reason'] );
	}

	public function test_a_foreign_file_at_the_target_fails_without_touching_it() {
		$row = $this->source( 'photo.jpg', 'jpg', 907 );
		file_put_contents( $this->dir . '/photo.jpg.webp', 'foreign' );

		$result = $this->converter->convert( $row, $this->settings() );

		$this->assertTrue( $result->is_failed() );
		$this->assertSame( 'target_exists_foreign', $this->variants->get_for_attachment( 907 )[0]['reason'] );
		$this->assertSame( 'foreign', file_get_contents( $this->dir . '/photo.jpg.webp' ) );
		$this->assertNull( $this->variants->get_for_attachment( 907 )[0]['relative_path'], 'A foreign file is not claimed by reserving its path.' );
	}

	/**
	 * A converter whose writer runs a callback right after the file is saved.
	 *
	 * @param callable $after_save Called once the file is in place, before the row is updated.
	 */
	private function converter_acting_after_save( callable $after_save ) {
		$writer = new class( $this->variants, new FileOwnership( $this->variants ), $after_save ) extends AtomicImageWriter {
			private $after_save;

			public function __construct( VariantRepository $variants, FileOwnership $ownership, callable $after_save ) {
				parent::__construct( $variants, $ownership );
				$this->after_save = $after_save;
			}

			public function save( WP_Image_Editor $editor, $target_path, $mime ) {
				$saved = parent::save( $editor, $target_path, $mime );
				( $this->after_save )();

				return $saved;
			}
		};

		return new ImageConverter( $this->variants, $writer, $this->capabilities );
	}

	/**
	 * A converter that drops the given variant row right after the file is saved,
	 * as a cleanup running in another request would.
	 *
	 * @param int $row_id Row to delete.
	 */
	private function converter_losing_the_row( $row_id ) {
		return $this->converter_acting_after_save(
			function () use ( $row_id ) {
				$this->variants->delete( $row_id );
			}
		);
	}

	public function test_a_variant_whose_row_was_removed_meanwhile_is_discarded() {
		$row = $this->source( 'photo.jpg', 'jpg', 910 );

		$result = $this->converter_losing_the_row( $row['id'] )->convert( $row, $this->settings() );

		$this->assertTrue( $result->is_skipped(), wp_json_encode( $result->to_array() ) );
		$this->assertSame( 'row_gone', $result->get_message() );
		$this->assertFileDoesNotExist( $this->dir . '/photo.jpg.webp', 'No file is left without a row.' );
		$this->assertSame( array(), $this->variants->get_for_attachment( 910 ) );
	}

	public function test_a_file_another_row_owns_is_kept_when_the_row_was_removed_meanwhile() {
		$this->source( 'photo.jpg', 'jpg', 911 );
		$other = $this->variants->upsert(
			array(
				'attachment_id'        => 912,
				'size_name'            => 'original',
				'format'               => 'webp',
				'status'               => VariantStatus::DONE,
				'source_relative_path' => $this->relative_dir . '/photo.jpg',
				'relative_path'        => $this->relative_dir . '/photo.jpg.webp',
			)
		);
		file_put_contents( $this->dir . '/photo.jpg.webp', 'variant of the other attachment' );
		$row = $this->variants->get_for_attachment( 911 )[0];

		$result = $this->converter_losing_the_row( $row['id'] )->convert( $row, $this->settings() );

		$this->assertSame( 'row_gone', $result->get_message() );
		$this->assertFileExists( $this->dir . '/photo.jpg.webp', 'The other row still points at the file.' );
		$this->assertSame( array( $other ), array_column( $this->variants->get_for_attachment( 912 ), 'id' ) );
	}

	public function test_a_not_smaller_outcome_reports_the_row_gone_too() {
		$path = $this->dir . '/noise.jpg';
		$im   = imagecreatetruecolor( 64, 64 );
		for ( $x = 0; $x < 64; $x++ ) {
			for ( $y = 0; $y < 64; $y++ ) {
				imagesetpixel( $im, $x, $y, imagecolorallocate( $im, wp_rand( 0, 255 ), wp_rand( 0, 255 ), wp_rand( 0, 255 ) ) );
			}
		}
		imagejpeg( $im, $path, 5 );
		$this->variants->upsert(
			array(
				'attachment_id'        => 913,
				'size_name'            => 'original',
				'format'               => 'webp',
				'source_relative_path' => $this->relative_dir . '/noise.jpg',
			)
		);
		$row = $this->variants->get_for_attachment( 913 )[0];

		$result = $this->converter_losing_the_row( $row['id'] )->convert( $row, $this->settings( 100 ) );

		$this->assertSame( 'row_gone', $result->get_message() );
		$this->assertFileDoesNotExist( $this->dir . '/noise.jpg.webp' );
	}

	public function test_a_run_killed_after_the_rename_is_recovered_by_the_next_conversion() {
		$row     = $this->source( 'photo.jpg', 'jpg', 920 );
		$dying   = $this->converter_acting_after_save(
			static function () {
				throw new RuntimeException( 'killed between the rename and the row update' );
			}
		);
		$target  = $this->dir . '/photo.jpg.webp';

		try {
			$dying->convert( $row, $this->settings() );
			$this->fail( 'The run was expected to die.' );
		} catch ( RuntimeException $killed ) {
			$this->assertFileExists( $target );
		}

		$crashed = $this->variants->get_for_attachment( 920 )[0];
		$this->assertSame( VariantStatus::PROCESSING, $crashed['status'] );
		$this->assertSame( $this->relative_dir . '/photo.jpg.webp', $crashed['relative_path'], 'The path is reserved for the row.' );
		$this->assertSame( array(), $this->variants->get_servable_for_attachment( 920 ), 'A reserved path is not served.' );

		// What AttachmentProcessor::run() does with a row left in processing by a crashed worker.
		$this->variants->transition( $crashed['id'], VariantStatus::PROCESSING, VariantStatus::PENDING );
		$result = $this->converter->convert( $this->variants->get_for_attachment( 920 )[0], $this->settings() );

		$this->assertTrue( $result->is_success(), wp_json_encode( $result->to_array() ) );
		$done = $this->variants->get_servable_for_attachment( 920 )[0];
		$this->assertSame( hash_file( 'sha256', $target ), $done['file_hash'] );
	}

	public function test_a_failed_conversion_releases_the_path_it_reserved() {
		$row = $this->source( 'gone.jpg', 'jpg', 921 );
		unlink( $this->dir . '/gone.jpg' );

		$this->assertTrue( $this->converter->convert( $row, $this->settings() )->is_failed() );

		$after = $this->variants->get_for_attachment( 921 )[0];
		$this->assertSame( VariantStatus::FAILED, $after['status'] );
		$this->assertNull( $after['relative_path'], 'A failed row must not keep claiming a path nothing was written to.' );
	}

	public function test_unsupported_format_downgrades_the_capability() {
		// No editor can write "image/zzz", so the capability must be downgraded.
		update_option( CapabilityService::OPTION, array( 'webp' => true, 'zzz' => true ) );
		$row = $this->source( 'photo.jpg', 'jpg', 908, 'zzz' );

		$result = $this->converter->convert( $row, $this->settings() );

		$this->assertTrue( $result->is_failed() );
		$this->assertSame( 'unsupported_format', $this->variants->get_for_attachment( 908 )[0]['reason'] );
		$this->assertFalse( $this->capabilities->supports( 'zzz' ) );
		$this->assertTrue( $this->capabilities->supports( 'webp' ), 'Only the failing format is downgraded.' );
	}

	public function test_a_variant_that_is_not_pending_is_left_alone() {
		$row = $this->source( 'photo.jpg', 'jpg', 909 );
		$this->variants->transition( $row['id'], VariantStatus::PENDING, VariantStatus::PROCESSING );

		$result = $this->converter->convert( $row, $this->settings() );

		$this->assertTrue( $result->is_skipped() );
		$this->assertFileDoesNotExist( $this->dir . '/photo.jpg.webp' );
	}

	public function test_attachment_metadata_is_not_modified() {
		$id       = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		$before   = wp_get_attachment_metadata( $id );
		$relative = get_post_meta( $id, '_wp_attached_file', true );
		$this->variants->upsert(
			array(
				'attachment_id'        => $id,
				'size_name'            => 'original',
				'format'               => 'webp',
				'source_relative_path' => $relative,
			)
		);

		$this->assertTrue( $this->converter->convert( $this->variants->get_for_attachment( $id )[0], $this->settings() )->is_success() );

		$this->assertSame( $before, wp_get_attachment_metadata( $id ) );
	}
}
