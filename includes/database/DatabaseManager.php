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
	const DB_VERSION = '1.3.0';

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

		$table_name      = $wpdb->prefix . 'trust_optimize_images';
		$jobs_table_name = $wpdb->prefix . 'trust_optimize_jobs';

		$sql = "CREATE TABLE {$table_name} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			attachment_id bigint(20) unsigned NOT NULL,
			metadata longtext NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'completed',
			total_tasks int unsigned NOT NULL DEFAULT 0,
			completed_tasks int unsigned NOT NULL DEFAULT 0,
			date_created datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			date_modified datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY attachment_id (attachment_id),
			KEY date_modified (date_modified),
			KEY status (status)
		) $charset_collate;";

		$jobs_sql = "CREATE TABLE {$jobs_table_name} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			type varchar(20) NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'pending',
			cursor_id bigint(20) unsigned NOT NULL DEFAULT 0,
			total int unsigned NOT NULL DEFAULT 0,
			processed int unsigned NOT NULL DEFAULT 0,
			skipped int unsigned NOT NULL DEFAULT 0,
			failed_count int unsigned NOT NULL DEFAULT 0,
			created_count int unsigned NOT NULL DEFAULT 0,
			deleted_count int unsigned NOT NULL DEFAULT 0,
			settings_snapshot longtext NULL,
			profile_hash varchar(64) NOT NULL DEFAULT '',
			last_error text NULL,
			started_at datetime NULL,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			finished_at datetime NULL,
			PRIMARY KEY  (id),
			KEY type_status (type, status),
			KEY status_updated (status, updated_at),
			KEY cursor_id (cursor_id)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
		dbDelta( $jobs_sql );
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

	/**
	 * Get TrustOptimize custom table names.
	 *
	 * @return array Custom table names keyed by logical table identifier.
	 */
	public function get_plugin_table_names() {
		return array(
			'images' => $this->get_table_name( 'trust_optimize_images' ),
			'jobs'   => $this->get_table_name( 'trust_optimize_jobs' ),
		);
	}

	/**
	 * Check whether a database table exists.
	 *
	 * @param string $table Full table name.
	 * @return bool True when the table exists.
	 */
	public function table_exists( $table ) {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$found = $wpdb->get_var(
			$wpdb->prepare(
				'SHOW TABLES LIKE %s',
				$wpdb->esc_like( $table )
			)
		);
		// phpcs:enable

		return $found === $table;
	}
}
