<?php
/**
 * Schema creation and the version check.
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
	 * @return string|null Null when the column does not exist.
	 */
	private function column_type( $table, $column ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row( $wpdb->prepare( "SHOW COLUMNS FROM `{$table}` LIKE %s", $column ), ARRAY_A );

		return $row ? strtolower( (string) $row['Type'] ) : null;
	}

	public function test_new_tables_exist_with_expected_columns() {
		$database = new DatabaseManager();
		$tables   = $database->get_plugin_table_names();

		foreach ( array( 'attachments', 'variants', 'jobs' ) as $key ) {
			$this->assertTrue( $database->table_exists( $tables[ $key ] ), "Table {$key} is missing." );
		}

		$this->assertSame( 'varchar(32)', $this->column_type( $tables['attachments'], 'state' ) );
		$this->assertSame( 'varchar(32)', $this->column_type( $tables['variants'], 'status' ) );
		$this->assertSame( 'varchar(32)', $this->column_type( $tables['jobs'], 'status' ) );
	}

	public function test_the_variants_table_has_no_columns_of_earlier_formats() {
		$variants = ( new DatabaseManager() )->get_plugin_table_names()['variants'];

		$this->assertNull( $this->column_type( $variants, 'naming' ) );
		$this->assertNull( $this->column_type( $variants, 'legacy_relative_path' ) );
		$this->assertSame( 'varchar(255)', $this->column_type( $variants, 'relative_path' ) );
	}

	public function test_the_schema_is_created_from_scratch_at_version_1_0_0() {
		$database = new DatabaseManager();

		delete_option( 'trust_optimize_db_version' );
		$database->check_version();

		$this->assertSame( '1.0.0', DatabaseManager::DB_VERSION );
		$this->assertSame( '1.0.0', get_option( 'trust_optimize_db_version' ) );
		$this->assertSame( array( 'attachments', 'variants', 'jobs' ), array_keys( $database->get_plugin_table_names() ) );
	}

	public function test_a_current_schema_is_left_alone() {
		global $wpdb;

		$database = new DatabaseManager();
		$jobs     = $database->get_plugin_table_names()['jobs'];

		// Narrow a column (DDL commits implicitly): create_tables() would widen it again.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "ALTER TABLE `{$jobs}` MODIFY status varchar(20) NOT NULL DEFAULT 'pending'" );

		try {
			update_option( 'trust_optimize_db_version', DatabaseManager::DB_VERSION );
			$database->check_version();

			$this->assertSame( 'varchar(20)', $this->column_type( $jobs, 'status' ), 'The current version does not touch the tables.' );
		} finally {
			update_option( 'trust_optimize_db_version', '0.0.0' );
			$database->check_version();
		}

		$this->assertSame( 'varchar(32)', $this->column_type( $jobs, 'status' ) );
		$this->assertSame( DatabaseManager::DB_VERSION, get_option( 'trust_optimize_db_version' ) );
	}
}
