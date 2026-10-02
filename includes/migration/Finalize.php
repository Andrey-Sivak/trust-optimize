<?php
/**
 * Migration step: check the result and drop the 1.x registry table.
 *
 * @package TrustOptimize\Migration
 */

namespace TrustOptimize\Migration;

use TrustOptimize\Database\DatabaseManager;
use TrustOptimize\Storage\VariantRepository;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Drops the legacy table; its name comes from DatabaseManager.

/**
 * Class Finalize
 *
 * The 1.x registry is dropped when every row of it has been processed by the earlier steps and
 * no 1.x row is left except the ones parked as conflicts. Rows that wait for their regeneration
 * are fine: they are in the variants table. Otherwise the check is repeated an hour later; the
 * plugin keeps working meanwhile. The conflict report stays.
 */
class Finalize implements MigrationStep {

	/**
	 * Database manager.
	 *
	 * @var DatabaseManager
	 */
	private $database;

	/**
	 * Variant repository.
	 *
	 * @var VariantRepository
	 */
	private $variants;

	/**
	 * Constructor.
	 *
	 * @param DatabaseManager   $database Database manager.
	 * @param VariantRepository $variants Variant repository.
	 */
	public function __construct( DatabaseManager $database, VariantRepository $variants ) {
		$this->database = $database;
		$this->variants = $variants;
	}

	/**
	 * Step name.
	 *
	 * @return string
	 */
	public function name() {
		return 'finalize';
	}

	/**
	 * Drop the legacy table when nothing is left to migrate.
	 *
	 * @param int $cursor Unused.
	 * @param int $limit  Unused.
	 * @return BatchResult Counts: waiting (1.x rows not yet regenerated), dropped.
	 */
	public function run_batch( $cursor, $limit ) {
		global $wpdb;

		$waiting = $this->variants->count_unresolved_legacy_rows();

		if ( $waiting > 0 ) {
			return BatchResult::retry( $cursor, HOUR_IN_SECONDS, array( 'waiting' => $waiting ) );
		}

		$table = $this->database->get_plugin_table_names()['images'];

		$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );

		return BatchResult::finished( array( 'dropped' => 1 ) );
	}
}
