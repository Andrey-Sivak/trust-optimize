<?php
/**
 * Database Manager class
 *
 * @package TrustOptimize
 */

namespace TrustOptimize\Database;

/**
 * Class DatabaseManager
 * Manages custom database operations for the plugin
 */
class DatabaseManager {

	/**
	 * Current database version
	 */
	const DB_VERSION = '1.0.0';

	/**
	 * Initialize the database manager
	 */
	public function init() {
		// Check if tables need to be created or updated
		add_action( 'plugins_loaded', array( $this, 'check_version' ), 20 );
	}

	/**
	 * Check database version and update if necessary
	 */
	public function check_version() {
		$db_version = get_option( 'trust_optimize_db_version', '0.0.0' );

		if ( version_compare( $db_version, self::DB_VERSION, '<' ) ) {
			$this->create_tables();
			update_option( 'trust_optimize_db_version', self::DB_VERSION );
		}
	}

	/**
	 * Create plugin database tables
	 */
	public function create_tables() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		$table_name = $wpdb->prefix . 'trust_optimize_images';

		$sql = "CREATE TABLE {$table_name} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			attachment_id bigint(20) unsigned NOT NULL,
			metadata longtext NOT NULL,
			date_created datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			date_modified datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY attachment_id (attachment_id),
			KEY date_modified (date_modified)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Get table name with prefix
	 *
	 * @param string $table Base table name without prefix.
	 * @return string Full table name with prefix
	 */
	public function get_table_name( $table ) {
		global $wpdb;
		return $wpdb->prefix . $table;
	}
}
