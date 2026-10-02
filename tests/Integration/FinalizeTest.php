<?php
/**
 * Finalization of the migration: the 1.x registry table is dropped (03.7).
 *
 * @package TrustOptimize\Tests
 */

require_once __DIR__ . '/legacy-schema-fixture.php';

use TrustOptimize\Database\DatabaseManager;
use TrustOptimize\Domain\VariantStatus;
use TrustOptimize\Migration\Finalize;
use TrustOptimize\Storage\VariantRepository;

/**
 * @covers \TrustOptimize\Migration\Finalize
 */
class FinalizeTest extends WP_UnitTestCase {

	use Legacy_Schema_Fixture;

	/**
	 * Database manager.
	 *
	 * @var DatabaseManager
	 */
	private $database;

	/**
	 * Variants.
	 *
	 * @var VariantRepository
	 */
	private $variants;

	public function set_up() {
		parent::set_up();
		$this->install_legacy_table();

		$this->database = new DatabaseManager();
		$this->variants = new VariantRepository( $this->database );
	}

	public function tear_down() {
		$this->remove_legacy_schema();
		parent::tear_down();
	}

	private function row( $attachment_id, array $fields ) {
		$this->variants->upsert(
			array_merge(
				array(
					'attachment_id' => $attachment_id,
					'size_name'     => 'original',
					'format'        => 'webp',
				),
				$fields
			)
		);
	}

	public function test_the_table_is_kept_while_a_1x_row_is_neither_regenerated_nor_a_conflict() {
		$this->row( 951, array( 'naming' => 'legacy', 'status' => VariantStatus::DONE ) );

		$result = ( new Finalize( $this->database, $this->variants ) )->run_batch( 0, 10 );

		$this->assertFalse( $result->is_done() );
		$this->assertSame( HOUR_IN_SECONDS, $result->get_delay(), 'The check is repeated at most once an hour.' );
		$this->assertSame( 1, $result->get_counts()['waiting'] );
		$this->assertTrue( $this->database->table_exists( $this->database->get_plugin_table_names()['images'] ) );
	}

	public function test_the_table_is_dropped_when_only_conflicts_and_rows_waiting_for_regeneration_are_left() {
		$this->row( 952, array( 'naming' => 'legacy', 'status' => VariantStatus::FAILED, 'reason' => 'legacy_conflict' ) );
		$this->row( 953, array( 'naming' => 'v2', 'status' => VariantStatus::PENDING, 'legacy_relative_path' => 'legacy-test/a.webp' ) );

		$result = ( new Finalize( $this->database, $this->variants ) )->run_batch( 0, 10 );

		$this->assertTrue( $result->is_done() );
		$this->assertFalse( $this->database->table_exists( $this->database->get_plugin_table_names()['images'] ) );

		// DROP TABLE committed the rows above; remove them for real.
		$this->variants->delete_for_attachment( 952 );
		$this->variants->delete_for_attachment( 953 );
		$GLOBALS['wpdb']->query( 'COMMIT' );
	}
}
