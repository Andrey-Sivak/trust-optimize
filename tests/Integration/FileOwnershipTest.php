<?php
/**
 * FileOwnership tests.
 *
 * @package TrustOptimize\Tests
 */

use TrustOptimize\Database\DatabaseManager;
use TrustOptimize\Domain\VariantStatus;
use TrustOptimize\Files\FileOwnership;
use TrustOptimize\Storage\VariantRepository;

/**
 * @covers \TrustOptimize\Files\FileOwnership
 */
class FileOwnershipTest extends WP_UnitTestCase {

	/**
	 * Variants.
	 *
	 * @var VariantRepository
	 */
	private $variants;

	/**
	 * Service.
	 *
	 * @var FileOwnership
	 */
	private $ownership;

	public function set_up() {
		parent::set_up();
		$this->variants  = new VariantRepository( new DatabaseManager() );
		$this->ownership = new FileOwnership( $this->variants );
	}

	/**
	 * Create a variant row pointing at a path.
	 */
	private function variant( $attachment_id, $relative_path ) {
		return $this->variants->upsert(
			array(
				'attachment_id'        => $attachment_id,
				'size_name'            => 'original',
				'format'               => 'webp',
				'status'               => VariantStatus::DONE,
				'source_relative_path' => 'ownership/photo.jpg',
				'relative_path'        => $relative_path,
			)
		);
	}

	public function test_a_variant_path_is_shared_only_with_a_row_of_another_attachment() {
		$this->variant( 2001, 'ownership/photo.jpg.webp' );

		$this->assertFalse( $this->ownership->shared_with_other_variant( 2001, 'ownership/photo.jpg.webp' ), 'Its own row is not another owner.' );
		$this->assertFalse( $this->ownership->shared_with_other_variant( 2001, 'ownership/other.jpg.webp' ) );

		$this->variant( 2002, 'ownership/photo.jpg.webp' );

		$this->assertTrue( $this->ownership->shared_with_other_variant( 2001, 'ownership/photo.jpg.webp' ) );
		$this->assertTrue( $this->ownership->shared_with_other_variant( 2002, 'ownership/photo.jpg.webp' ) );
	}

	public function test_an_empty_path_is_never_shared_nor_an_attachment_file() {
		$this->variant( 2003, 'ownership/a.jpg.webp' );

		$this->assertFalse( $this->ownership->shared_with_other_variant( 2004, '' ) );
		$this->assertFalse( $this->ownership->is_attachment_file( '' ) );
	}

	public function test_a_path_is_an_attachment_file_by_exact_match_only() {
		$id = self::factory()->post->create(
			array(
				'post_type'  => 'attachment',
				'meta_input' => array( '_wp_attached_file' => 'ownership/photo.jpg.webp' ),
			)
		);

		$this->assertTrue( $this->ownership->is_attachment_file( 'ownership/photo.jpg.webp' ) );
		$this->assertFalse( $this->ownership->is_attachment_file( 'ownership/photo.jpg' ), 'A different name is not the same file.' );
		$this->assertFalse( $this->ownership->is_attachment_file( 'ownership/photo.jpg.web' ) );
		$this->assertFalse( $this->ownership->is_attachment_file( 'ownership/photo.jpg.webp', $id ), 'The attachment itself is excluded.' );
	}

	public function test_the_file_of_several_attachments_belongs_to_the_others_when_one_is_excluded() {
		$first  = self::factory()->post->create(
			array(
				'post_type'  => 'attachment',
				'meta_input' => array( '_wp_attached_file' => 'ownership/shared.jpg' ),
			)
		);
		$second = self::factory()->post->create(
			array(
				'post_type'  => 'attachment',
				'meta_input' => array( '_wp_attached_file' => 'ownership/shared.jpg' ),
			)
		);

		$this->assertTrue( $this->ownership->is_attachment_file( 'ownership/shared.jpg', $first ) );
		$this->assertTrue( $this->ownership->is_attachment_file( 'ownership/shared.jpg', $second ) );

		wp_delete_post( $second, true );

		$this->assertFalse( $this->ownership->is_attachment_file( 'ownership/shared.jpg', $first ) );
	}
}
