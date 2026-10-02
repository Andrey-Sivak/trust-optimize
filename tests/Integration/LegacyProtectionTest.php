<?php
/**
 * Files and rows of schema 1.x are never deleted unchecked (D-15).
 *
 * @package TrustOptimize\Tests
 */

use TrustOptimize\Capabilities\CapabilityService;
use TrustOptimize\Core\Plugin;
use TrustOptimize\Database\DatabaseManager;
use TrustOptimize\Domain\VariantStatus;
use TrustOptimize\Storage\VariantRepository;

/**
 * @covers \TrustOptimize\Planning\VariantPlanner::reconcile
 */
class LegacyProtectionTest extends WP_UnitTestCase {

	/**
	 * Variants.
	 *
	 * @var VariantRepository
	 */
	private $variants;

	public function set_up() {
		parent::set_up();
		update_option( CapabilityService::OPTION, array( 'webp' => true, 'avif' => false ) );
		update_option( 'trust_optimize_options', array( 'convert_to_webp' => 1, 'convert_to_avif' => 0 ) );

		$this->variants = new VariantRepository( new DatabaseManager() );
	}

	/**
	 * A legacy row of attachment B whose path is the original file of attachment A.
	 *
	 * @return array{0:int,1:int,2:string} Attachment A, attachment B, the shared absolute path.
	 */
	private function colliding_attachments() {
		$a = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		$b = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/test-image.jpg' );

		$path = get_attached_file( $a );

		$this->variants->upsert(
			array(
				'attachment_id'        => $b,
				'size_name'            => 'original',
				'format'               => 'png',
				'status'               => VariantStatus::DONE,
				'naming'               => 'legacy',
				'source_relative_path' => get_post_meta( $b, '_wp_attached_file', true ),
				'relative_path'        => get_post_meta( $a, '_wp_attached_file', true ),
			)
		);

		return array( $a, $b, $path );
	}

	public function test_sync_of_an_attachment_keeps_its_legacy_rows_and_their_files() {
		list( , $b, $path ) = $this->colliding_attachments();

		Plugin::get_instance()->processor->sync( $b );

		$this->assertFileExists( $path );
		$legacy = array_values(
			array_filter(
				$this->variants->get_for_attachment( $b ),
				static function ( $row ) {
					return 'legacy' === $row['naming'];
				}
			)
		);
		$this->assertCount( 1, $legacy );
		$this->assertSame( VariantStatus::DONE, $legacy[0]['status'] );
	}
}
