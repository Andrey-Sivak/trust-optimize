<?php
/**
 * The guard looks paths up instead of loading directories (03.9).
 *
 * @package TrustOptimize\Tests
 */

use TrustOptimize\Database\DatabaseManager;
use TrustOptimize\Service\LegacyPathGuard;
use TrustOptimize\Storage\VariantRepository;

/**
 * @covers \TrustOptimize\Service\LegacyPathGuard
 */
class LegacyPathGuardScaleTest extends WP_UnitTestCase {

	/**
	 * Guard.
	 *
	 * @var LegacyPathGuard
	 */
	private $guard;

	public function set_up() {
		parent::set_up();
		$database    = new DatabaseManager();
		$this->guard = new LegacyPathGuard( $database, new VariantRepository( $database ) );
	}

	/**
	 * Flat uploads: every attachment lives in the root directory.
	 *
	 * @param int $count Number of attachments.
	 * @return int[]
	 */
	private function flat_library( $count ) {
		$ids = array();
		for ( $i = 0; $i < $count; $i++ ) {
			$id = self::factory()->attachment->create( array( 'post_mime_type' => 'image/jpeg' ) );
			update_post_meta( $id, '_wp_attached_file', "flat-{$i}.jpg" );
			update_post_meta( $id, '_wp_attachment_metadata', array( 'file' => "flat-{$i}.jpg", 'sizes' => array( 'thumbnail' => array( 'file' => "flat-{$i}-150x150.jpg" ) ) ) );
			$ids[] = $id;
		}

		return $ids;
	}

	public function test_a_lookup_in_a_large_flat_library_is_bounded() {
		$ids = $this->flat_library( 320 );

		$metadata_reads = 0;
		add_filter(
			'wp_get_attachment_metadata',
			static function ( $data ) use ( &$metadata_reads ) {
				++$metadata_reads;
				return $data;
			}
		);
		wp_cache_flush();

		global $wpdb;
		$before    = $wpdb->num_queries;
		$conflicts = $this->guard->find_conflicts( 999999, array( 'nobody.png' ) );
		$queries   = $wpdb->num_queries - $before;

		$this->assertSame( array(), $conflicts );
		$this->assertLessThanOrEqual( 3, $queries );
		$this->assertSame( 0, $metadata_reads, 'Attachments that do not match are not loaded.' );
		$this->assertCount( 320, $ids );
	}

	public function test_the_original_a_size_and_the_original_image_are_still_found() {
		$ids = $this->flat_library( 5 );
		update_post_meta( $ids[1], '_wp_attached_file', 'big-scaled.jpg' );
		update_post_meta( $ids[1], '_wp_attachment_metadata', array( 'file' => 'big-scaled.jpg', 'original_image' => 'big.jpg', 'sizes' => array() ) );

		$found = $this->guard->find_conflicts(
			999999,
			array( 'flat-0.jpg', 'flat-2-150x150.jpg', 'big.jpg', 'sub/flat-3.jpg', 'flat-4-150x150.png' )
		);

		$this->assertSame( array( 'flat-0.jpg', 'flat-2-150x150.jpg', 'big.jpg' ), array_keys( $found ), 'A path in another directory or with another name is not a conflict.' );
		$this->assertSame( $ids[1], $found['big.jpg']['attachment_id'] );
		$this->assertSame( $ids[2], $found['flat-2-150x150.jpg']['attachment_id'] );
	}

	public function test_batch_mode_keeps_the_answers_of_paths() {
		$this->flat_library( 3 );
		global $wpdb;

		$this->guard->begin_batch();
		$this->guard->find_conflicts( 999999, array( 'flat-1.jpg' ) );
		$before = $wpdb->num_queries;
		$again  = $this->guard->find_conflicts( 999999, array( 'flat-1.jpg' ) );
		$this->guard->end_batch();

		$this->assertArrayHasKey( 'flat-1.jpg', $again );
		$this->assertLessThanOrEqual( 2, $wpdb->num_queries - $before, 'Only the variant and registry lookups are repeated.' );
	}
}
