<?php
/**
 * Schema 2.0.0 creation and upgrade from 1.3.0.
 *
 * @package TrustOptimize\Tests
 */

use TrustOptimize\Database\DatabaseManager;

/**
 * @covers \TrustOptimize\Database\DatabaseManager
 */
class DatabaseUpgradeTest extends WP_UnitTestCase {

	/**
	 * Column type as reported by MySQL, e.g. "varchar(32)".
	 *
	 * @param string $table  Table name.
	 * @param string $column Column name.
	 * @return string
	 */
	private function column_type( $table, $column ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row( $wpdb->prepare( "SHOW COLUMNS FROM `{$table}` LIKE %s", $column ), ARRAY_A );

		return strtolower( (string) $row['Type'] );
	}

	public function test_new_tables_exist_with_expected_columns() {
		$database = new DatabaseManager();
		$tables   = $database->get_plugin_table_names();

		foreach ( array( 'attachments', 'variants', 'jobs', 'images' ) as $key ) {
			$this->assertTrue( $database->table_exists( $tables[ $key ] ), "Table {$key} is missing." );
		}

		$this->assertSame( 'varchar(32)', $this->column_type( $tables['attachments'], 'state' ) );
		$this->assertSame( 'varchar(32)', $this->column_type( $tables['variants'], 'status' ) );
		$this->assertSame( 'varchar(32)', $this->column_type( $tables['jobs'], 'status' ) );
	}

	public function test_upgrade_from_1_3_0_widens_jobs_status_and_keeps_legacy_rows() {
		global $wpdb;

		$database = new DatabaseManager();
		$tables   = $database->get_plugin_table_names();

		// Put the jobs table back into its 1.3.0 shape (DDL commits implicitly).
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "ALTER TABLE `{$tables['jobs']}` MODIFY status varchar(20) NOT NULL DEFAULT 'pending'" );
		$this->assertSame( 'varchar(20)', $this->column_type( $tables['jobs'], 'status' ) );

		// The upgrade commits implicitly, so the legacy row must be removed by hand.
		$wpdb->delete( $tables['images'], array( 'attachment_id' => 987654 ) );
		$wpdb->insert(
			$tables['images'],
			array(
				'attachment_id' => 987654,
				'metadata'      => '{}',
				'status'        => 'completed',
			)
		);

		update_option( 'trust_optimize_db_version', '1.3.0' );
		$database->check_version();

		$this->assertSame( DatabaseManager::DB_VERSION, get_option( 'trust_optimize_db_version' ) );
		$this->assertSame( 'varchar(32)', $this->column_type( $tables['jobs'], 'status' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$legacy_rows = (string) $wpdb->get_var( "SELECT COUNT(*) FROM `{$tables['images']}` WHERE attachment_id = 987654" );
		$wpdb->delete( $tables['images'], array( 'attachment_id' => 987654 ) );

		$this->assertSame( '1', $legacy_rows );
	}
}
