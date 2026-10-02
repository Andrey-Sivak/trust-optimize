<?php
/**
 * Removal of the 1.x keys from attachment metadata (03.5).
 *
 * @package TrustOptimize\Tests
 */

require_once __DIR__ . '/legacy-schema-fixture.php';

use TrustOptimize\Database\DatabaseManager;
use TrustOptimize\Migration\StripAttachmentMetadata;

/**
 * @covers \TrustOptimize\Migration\StripAttachmentMetadata
 */
class StripAttachmentMetadataTest extends WP_UnitTestCase {

	use Legacy_Schema_Fixture;

	public function set_up() {
		parent::set_up();
		$this->install_legacy_table();
	}

	public function tear_down() {
		$this->remove_legacy_schema();
		parent::tear_down();
	}

	public function test_legacy_keys_are_removed_and_everything_else_is_kept() {
		$id       = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		$original = wp_get_attachment_metadata( $id );
		$dirty    = $original;

		$dirty['trust_optimize_converted']                         = array( 'original_webp' => array( 'file' => 'a.webp' ) );
		$dirty['sizes']['thumbnail']['trust_optimize_converted'] = array( 'webp' => array( 'file' => 'b.webp' ) );
		update_post_meta( $id, '_wp_attachment_metadata', $dirty );
		$this->add_legacy_manifest( $id, array(), false );

		$updates = 0;
		add_filter(
			'wp_update_attachment_metadata',
			static function ( $data ) use ( &$updates ) {
				++$updates;
				return $data;
			}
		);

		$result = ( new StripAttachmentMetadata( new DatabaseManager() ) )->run_batch( 0, 10 );

		$this->assertTrue( $result->is_done() );
		$this->assertSame( 1, $result->get_counts()['stripped'] );
		$this->assertSame( $original, wp_get_attachment_metadata( $id ) );
		$this->assertSame( 0, $updates, 'The change must not wake plugins that listen to metadata updates.' );

		$again = ( new StripAttachmentMetadata( new DatabaseManager() ) )->run_batch( 0, 10 );
		$this->assertSame( 0, $again->get_counts()['stripped'], 'A second run changes nothing.' );
	}

	public function test_batches_continue_from_the_cursor() {
		$ids = array();
		foreach ( array( 'canola.jpg', 'test-image.jpg' ) as $file ) {
			$ids[] = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/' . $file );
		}
		foreach ( $ids as $id ) {
			$this->add_legacy_manifest( $id, array(), false );
		}
		$step = new StripAttachmentMetadata( new DatabaseManager() );

		$first = $step->run_batch( 0, 1 );

		$this->assertFalse( $first->is_done() );
		$this->assertSame( $ids[0], $first->get_cursor() );
		$this->assertTrue( $step->run_batch( $step->run_batch( $first->get_cursor(), 1 )->get_cursor(), 1 )->is_done() );
	}
}
