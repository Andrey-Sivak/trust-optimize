<?php
/**
 * Helpers that build a site in the state of schema 1.3.0.
 *
 * @package TrustOptimize\Tests
 */

use TrustOptimize\Database\DatabaseManager;

/**
 * Trait Legacy_Schema_Fixture
 *
 * For WP_UnitTestCase classes. The 1.x registry table must be a real table: table_exists()
 * does not see the temporary tables the test case creates for CREATE TABLE.
 */
trait Legacy_Schema_Fixture {

	/**
	 * Files created by the fixture, removed by remove_legacy_files().
	 *
	 * @var string[]
	 */
	private $legacy_files = array();

	/**
	 * Create the 1.x registry table.
	 *
	 * DDL commits the open test transaction: call it first in set_up(), before the test creates any data.
	 */
	private function install_legacy_table() {
		global $wpdb;

		$table = ( new DatabaseManager() )->get_plugin_table_names()['images'];

		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "CREATE TABLE `{$table}` (id bigint(20) unsigned NOT NULL AUTO_INCREMENT, attachment_id bigint(20) unsigned NOT NULL, metadata longtext NOT NULL, status varchar(20) NOT NULL DEFAULT 'completed', PRIMARY KEY  (id), UNIQUE KEY attachment_id (attachment_id))" );
	}

	/**
	 * Drop the 1.x registry table and the files of the fixture.
	 *
	 * Call it first in tear_down(): the data of the test is rolled back before DROP TABLE commits.
	 */
	private function remove_legacy_schema() {
		global $wpdb;

		$wpdb->query( 'ROLLBACK' );

		$table = ( new DatabaseManager() )->get_plugin_table_names()['images'];

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
		add_filter( 'query', array( $this, '_create_temporary_tables' ) );
		add_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		foreach ( $this->legacy_files as $file ) {
			if ( file_exists( $file ) ) {
				unlink( $file );
			}
		}
	}

	/**
	 * Create a file in the uploads directory.
	 *
	 * @param string $relative Path relative to uploads.
	 * @return string Absolute path.
	 */
	private function make_legacy_file( $relative ) {
		$path = wp_upload_dir()['basedir'] . '/' . $relative;
		wp_mkdir_p( dirname( $path ) );
		file_put_contents( $path, 'legacy ' . $relative ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$this->legacy_files[] = $path;

		return $path;
	}

	/**
	 * Write the 1.x manifest of an attachment.
	 *
	 * @param int     $attachment_id Attachment ID.
	 * @param array[] $variants      Entries with size_name, format and file (relative to uploads); relative_dir and file_hash are derived.
	 * @param bool    $create_files  Whether the files are created on disk.
	 */
	private function add_legacy_manifest( $attachment_id, array $variants, $create_files = true ) {
		global $wpdb;

		$table    = ( new DatabaseManager() )->get_plugin_table_names()['images'];
		$manifest = array();

		foreach ( $variants as $variant ) {
			$relative = $variant['file'];
			$path     = $create_files ? $this->make_legacy_file( $relative ) : '';

			$manifest[ $variant['size_name'] . ':' . $variant['format'] ] = array(
				'attachment_id' => $attachment_id,
				'size_name'     => $variant['size_name'],
				'format'        => $variant['format'],
				'mime_type'     => 'image/' . $variant['format'],
				'file'          => basename( $relative ),
				'relative_dir'  => '.' === dirname( $relative ) ? '' : dirname( $relative ),
				'file_hash'     => $path ? hash_file( 'sha256', $path ) : '',
			);
		}

		$wpdb->insert(
			$table,
			array(
				'attachment_id' => $attachment_id,
				'metadata'      => wp_json_encode( array( 'generated_variants' => $manifest ) ),
			)
		);
	}
}
