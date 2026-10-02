<?php
/**
 * ImageCleanupService tests.
 *
 * @package TrustOptimize\Tests
 */

use TrustOptimize\Database\DatabaseManager;
use TrustOptimize\Domain\VariantStatus;
use TrustOptimize\Service\ImageCleanupService;
use TrustOptimize\Storage\AttachmentRepository;
use TrustOptimize\Storage\VariantRepository;

/**
 * @covers \TrustOptimize\Service\ImageCleanupService
 */
class ImageCleanupServiceTest extends WP_UnitTestCase {

	/**
	 * Uploads sub directory used by the test.
	 *
	 * @var string
	 */
	private $dir;

	/**
	 * Its relative path.
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
	 * Attachments.
	 *
	 * @var AttachmentRepository
	 */
	private $attachments;

	/**
	 * Service.
	 *
	 * @var ImageCleanupService
	 */
	private $cleanup;

	public function set_up() {
		parent::set_up();
		$this->relative_dir = 'cleanup-test-' . wp_generate_uuid4();
		$this->dir          = wp_upload_dir()['basedir'] . '/' . $this->relative_dir;
		wp_mkdir_p( $this->dir );

		$database          = new DatabaseManager();
		$this->variants    = new VariantRepository( $database );
		$this->attachments = new AttachmentRepository( $database, $this->variants );
		$this->cleanup     = new ImageCleanupService( $this->variants, $this->attachments );
	}

	public function tear_down() {
		chmod( $this->dir, 0755 );
		foreach ( glob( $this->dir . '/*' ) ?: array() as $file ) {
			unlink( $file );
		}
		rmdir( $this->dir );
		parent::tear_down();
	}

	/**
	 * Create a variant file and its done row.
	 */
	private function variant( $attachment_id, $name, $format = 'webp', $content = 'variant bytes' ) {
		file_put_contents( $this->dir . '/' . $name, $content );

		return $this->variants->upsert(
			array(
				'attachment_id'        => $attachment_id,
				'size_name'            => $name,
				'format'               => $format,
				'status'               => VariantStatus::DONE,
				'source_relative_path' => $this->relative_dir . '/source.jpg',
				'relative_path'        => $this->relative_dir . '/' . $name,
				'file_hash'            => hash( 'sha256', $content ),
			)
		);
	}

	public function test_deletes_owned_files_and_rows_but_not_anything_else() {
		$this->variant( 1001, 'a.jpg.webp' );
		file_put_contents( $this->dir . '/a.jpg', 'original' );
		file_put_contents( $this->dir . '/a.webp', 'users own file' );

		$result = $this->cleanup->cleanup_attachment( 1001 );

		$this->assertTrue( $result->is_success() );
		$this->assertFileDoesNotExist( $this->dir . '/a.jpg.webp' );
		$this->assertSame( 'original', file_get_contents( $this->dir . '/a.jpg' ) );
		$this->assertSame( 'users own file', file_get_contents( $this->dir . '/a.webp' ) );
		$this->assertSame( array(), $this->variants->get_for_attachment( 1001 ) );
		$this->assertNull( $this->attachments->get( 1001 ) );
	}

	public function test_a_file_that_cannot_be_deleted_keeps_its_row_marked_failed() {
		$id = $this->variant( 1002, 'b.jpg.webp' );
		chmod( $this->dir, 0555 );
		if ( wp_is_writable( $this->dir ) ) {
			$this->markTestSkipped( 'Running as a user that ignores directory permissions.' );
		}

		$result = $this->cleanup->cleanup_attachment( 1002 );

		$this->assertTrue( $result->is_failed() );
		$this->assertFileExists( $this->dir . '/b.jpg.webp' );
		$rows = $this->variants->get_for_attachment( 1002 );
		$this->assertCount( 1, $rows );
		$this->assertSame( $id, $rows[0]['id'] );
		$this->assertSame( VariantStatus::FAILED, $rows[0]['status'] );
		$this->assertSame( 'delete_failed', $rows[0]['reason'] );
	}

	public function test_a_modified_file_is_left_alone_and_no_longer_owned() {
		$this->variant( 1003, 'c.jpg.webp' );
		file_put_contents( $this->dir . '/c.jpg.webp', 'someone replaced this' );

		$result = $this->cleanup->cleanup_attachment( 1003 );

		$this->assertSame( 'hash_mismatch', $result->get_data()['skipped'][0]['reason'] );
		$this->assertSame( 'someone replaced this', file_get_contents( $this->dir . '/c.jpg.webp' ) );
		$this->assertFalse( $this->variants->owns( $this->relative_dir . '/c.jpg.webp' ), 'The row must not keep claiming a file it no longer owns.' );
	}

	public function test_never_deletes_the_original_or_sizes_of_the_attachment() {
		$id   = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		$file = get_attached_file( $id );
		$this->variants->upsert(
			array(
				'attachment_id'        => $id,
				'size_name'            => 'original',
				'format'               => 'webp',
				'status'               => VariantStatus::DONE,
				'source_relative_path' => 'x.jpg',
				'relative_path'        => ltrim( substr( $file, strlen( wp_upload_dir()['basedir'] ) ), '/' ),
			)
		);

		$result = $this->cleanup->cleanup_attachment( $id );

		$this->assertSame( 'protected_file', $result->get_data()['skipped'][0]['reason'] );
		$this->assertFileExists( $file );
	}

	public function test_missing_file_still_removes_the_row() {
		$this->variant( 1004, 'd.jpg.webp' );
		unlink( $this->dir . '/d.jpg.webp' );

		$result = $this->cleanup->cleanup_attachment( 1004 );

		$this->assertSame( 'missing_file', $result->get_data()['skipped'][0]['reason'] );
		$this->assertSame( array(), $this->variants->get_for_attachment( 1004 ) );
	}

	public function test_cleanup_variants_removes_only_the_given_rows() {
		$keep = $this->variant( 1005, 'e1.jpg.webp' );
		$this->variant( 1005, 'e2.jpg.webp' );
		$rows = array_values(
			array_filter(
				$this->variants->get_for_attachment( 1005 ),
				static function ( $row ) {
					return 'e2.jpg.webp' === $row['size_name'];
				}
			)
		);

		$this->cleanup->cleanup_variants( 1005, $rows );

		$this->assertFileExists( $this->dir . '/e1.jpg.webp' );
		$this->assertFileDoesNotExist( $this->dir . '/e2.jpg.webp' );
		$this->assertSame( array( $keep ), array_column( $this->variants->get_for_attachment( 1005 ), 'id' ) );
	}

	public function test_replaced_files_are_removed_without_touching_rows() {
		$id = $this->variant( 1006, 'f.jpg.webp' );
		$old = $this->variants->get_for_attachment( 1006 );

		$this->cleanup->cleanup_replaced_files( 1006, $old );

		$this->assertFileDoesNotExist( $this->dir . '/f.jpg.webp' );
		$this->assertSame( array( $id ), array_column( $this->variants->get_for_attachment( 1006 ), 'id' ) );
	}

	public function test_deleting_the_attachment_removes_variants_files_and_rows() {
		$id = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		$this->variants->upsert(
			array(
				'attachment_id'        => $id,
				'size_name'            => 'original',
				'format'               => 'webp',
				'status'               => VariantStatus::DONE,
				'source_relative_path' => 'x.jpg',
				'relative_path'        => $this->relative_dir . '/g.jpg.webp',
			)
		);
		file_put_contents( $this->dir . '/g.jpg.webp', 'x' );
		// The recorded hash is absent, so the file is deleted on the strength of ownership alone.

		wp_delete_attachment( $id, true );

		$this->assertFileDoesNotExist( $this->dir . '/g.jpg.webp' );
		$this->assertSame( array(), $this->variants->get_for_attachment( $id ) );
	}

	public function test_batch_cleanup_walks_attachments_in_order() {
		$this->variant( 1010, 'h1.jpg.webp' );
		$this->variant( 1011, 'h2.jpg.webp' );

		$first = $this->cleanup->cleanup_managed_records_batch( 1009, 1 );
		$this->assertFalse( $first['done'] );
		$this->assertSame( 1010, $first['cursor_id'] );

		$second = $this->cleanup->cleanup_managed_records_batch( $first['cursor_id'], 5 );
		$this->assertTrue( $second['done'] );
		$this->assertFileDoesNotExist( $this->dir . '/h2.jpg.webp' );
	}
}
