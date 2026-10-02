<?php
/**
 * VariantRepository tests.
 *
 * @package TrustOptimize\Tests
 */

use TrustOptimize\Database\DatabaseManager;
use TrustOptimize\Domain\VariantStatus;
use TrustOptimize\Storage\VariantRepository;

/**
 * @covers \TrustOptimize\Storage\VariantRepository
 */
class VariantRepositoryTest extends WP_UnitTestCase {

	/**
	 * Repository under test.
	 *
	 * @var VariantRepository
	 */
	private $repo;

	public function set_up() {
		parent::set_up();
		$this->repo = new VariantRepository( new DatabaseManager() );
	}

	private function variant( array $overrides = array() ) {
		return array_merge(
			array(
				'attachment_id'        => 501,
				'size_name'            => 'original',
				'format'               => 'webp',
				'source_relative_path' => '2026/05/photo.jpg',
			),
			$overrides
		);
	}

	public function test_upsert_is_idempotent_and_keeps_unspecified_columns() {
		$id = $this->repo->upsert( $this->variant() );
		$this->assertGreaterThan( 0, $id );

		$again = $this->repo->upsert(
			array(
				'attachment_id' => 501,
				'size_name'     => 'original',
				'format'        => 'webp',
				'quality'       => 80,
			)
		);

		$this->assertSame( $id, $again );
		$rows = $this->repo->get_for_attachment( 501 );
		$this->assertCount( 1, $rows );
		$this->assertSame( 80, $rows[0]['quality'] );
		$this->assertSame( '2026/05/photo.jpg', $rows[0]['source_relative_path'] );
		$this->assertSame( VariantStatus::PENDING, $rows[0]['status'] );
		$this->assertNull( $rows[0]['relative_path'] );
		$this->assertSame( 'v2', $rows[0]['naming'] );
	}

	public function test_cas_transition_succeeds_exactly_once() {
		$id = $this->repo->upsert( $this->variant() );

		$this->assertTrue( $this->repo->transition( $id, VariantStatus::PENDING, VariantStatus::PROCESSING ) );
		$this->assertFalse( $this->repo->transition( $id, VariantStatus::PENDING, VariantStatus::PROCESSING ) );

		$this->assertTrue(
			$this->repo->transition(
				$id,
				VariantStatus::PROCESSING,
				VariantStatus::DONE,
				array(
					'relative_path' => '2026/05/photo.jpg.webp',
					'file_size'     => 1234,
				)
			)
		);

		$row = $this->repo->get_for_attachment( 501 )[0];
		$this->assertSame( VariantStatus::DONE, $row['status'] );
		$this->assertSame( '2026/05/photo.jpg.webp', $row['relative_path'] );
		$this->assertSame( 1234, $row['file_size'] );
	}

	public function test_servable_rows_are_batched_and_cache_is_invalidated_on_write() {
		$a = $this->repo->upsert( $this->variant( array( 'attachment_id' => 601 ) ) );
		$this->repo->upsert( $this->variant( array( 'attachment_id' => 601, 'format' => 'avif' ) ) );
		$this->repo->upsert( $this->variant( array( 'attachment_id' => 602 ) ) );

		$this->assertSame(
			array( 601 => array(), 602 => array(), 603 => array() ),
			$this->repo->get_servable_for_attachments( array( 601, 602, 603 ) )
		);

		$this->repo->transition( $a, VariantStatus::PENDING, VariantStatus::DONE, array( 'relative_path' => '2026/05/photo.jpg.webp' ) );

		$done = $this->repo->get_servable_for_attachments( array( 601, 602 ) );
		$this->assertCount( 1, $done[601] );
		$this->assertSame( 'webp', $done[601][0]['format'] );
		$this->assertSame( array(), $done[602] );
		$this->assertCount( 1, $this->repo->get_servable_for_attachment( 601 ) );
	}

	public function test_owns_and_find_by_source_path() {
		$this->repo->upsert(
			$this->variant(
				array(
					'status'        => VariantStatus::DONE,
					'relative_path' => '2026/05/photo.jpg.webp',
				)
			)
		);

		$this->assertTrue( $this->repo->owns( '2026/05/photo.jpg.webp' ) );
		$this->assertFalse( $this->repo->owns( '2026/05/photo.webp' ) );
		$this->assertCount( 1, $this->repo->find_by_source_path( '2026/05/photo.jpg' ) );
		$this->assertSame( array(), $this->repo->find_by_source_path( '2026/05/other.jpg' ) );
	}

	public function test_servable_path_prefers_the_finished_file_and_falls_back_to_the_legacy_one() {
		$this->assertSame( 'a.jpg.webp', VariantRepository::servable_path( array( 'status' => VariantStatus::DONE, 'relative_path' => 'a.jpg.webp', 'legacy_relative_path' => 'a.webp' ) ) );
		$this->assertSame( 'a.webp', VariantRepository::servable_path( array( 'status' => VariantStatus::PENDING, 'relative_path' => null, 'legacy_relative_path' => 'a.webp' ) ) );
		$this->assertSame( 'a.webp', VariantRepository::servable_path( array( 'status' => VariantStatus::FAILED, 'relative_path' => null, 'legacy_relative_path' => 'a.webp' ) ) );
		$this->assertNull( VariantRepository::servable_path( array( 'status' => VariantStatus::PENDING, 'relative_path' => 'a.jpg.webp', 'legacy_relative_path' => null ) ) );
		$this->assertNull( VariantRepository::servable_path( array( 'status' => VariantStatus::FAILED, 'relative_path' => null, 'legacy_relative_path' => '' ) ) );
	}

	public function test_servable_rows_include_variants_that_only_have_a_legacy_file() {
		$this->repo->upsert( $this->variant( array( 'attachment_id' => 701, 'legacy_relative_path' => '2026/05/photo.webp' ) ) );
		$this->repo->upsert( $this->variant( array( 'attachment_id' => 701, 'format' => 'avif' ) ) );

		$rows = $this->repo->get_servable_for_attachment( 701 );

		$this->assertCount( 1, $rows );
		$this->assertSame( 'webp', $rows[0]['format'] );
	}

	public function test_owns_covers_the_legacy_path() {
		$this->repo->upsert( $this->variant( array( 'legacy_relative_path' => '2026/05/photo.webp' ) ) );

		$this->assertTrue( $this->repo->owns( '2026/05/photo.webp' ) );
	}

	public function test_delete_and_count_by_status() {
		$a = $this->repo->upsert( $this->variant() );
		$this->repo->upsert( $this->variant( array( 'format' => 'avif', 'status' => VariantStatus::FAILED ) ) );

		$this->assertSame(
			array(
				VariantStatus::PENDING => 1,
				VariantStatus::FAILED  => 1,
			),
			$this->repo->count_by_status( 501 )
		);

		$this->assertTrue( $this->repo->delete( $a ) );
		$this->assertCount( 1, $this->repo->get_for_attachment( 501 ) );

		$this->repo->delete_for_attachment( 501 );
		$this->assertSame( array(), $this->repo->get_for_attachment( 501 ) );
	}
}
