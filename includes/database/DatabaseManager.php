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
	const DB_VERSION = '2.0.0';

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

		$jobs_table_name = $wpdb->prefix . 'trust_optimize_jobs';
		$attachments     = $wpdb->prefix . 'trust_optimize_attachments';
		$variants        = $wpdb->prefix . 'trust_optimize_variants';

		$jobs_sql = "CREATE TABLE {$jobs_table_name} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			type varchar(20) NOT NULL,
			status varchar(32) NOT NULL DEFAULT 'pending',
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

		$attachments_sql = "CREATE TABLE {$attachments} (
			attachment_id bigint(20) unsigned NOT NULL,
			state varchar(32) NOT NULL DEFAULT 'none',
			reason varchar(64) NULL,
			attempts tinyint unsigned NOT NULL DEFAULT 0,
			job_id bigint(20) unsigned NULL,
			last_error varchar(255) NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (attachment_id),
			KEY state (state),
			KEY job_state (job_id,state)
		) $charset_collate;";

		$variants_sql = "CREATE TABLE {$variants} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			attachment_id bigint(20) unsigned NOT NULL,
			size_name varchar(100) NOT NULL,
			format varchar(10) NOT NULL,
			status varchar(32) NOT NULL DEFAULT 'pending',
			naming varchar(16) NOT NULL DEFAULT 'v2',
			source_relative_path varchar(255) NOT NULL DEFAULT '',
			relative_path varchar(255) NULL,
			width int unsigned NOT NULL DEFAULT 0,
			height int unsigned NOT NULL DEFAULT 0,
			quality tinyint unsigned NOT NULL DEFAULT 0,
			file_size bigint(20) unsigned NOT NULL DEFAULT 0,
			source_file_size bigint(20) unsigned NOT NULL DEFAULT 0,
			file_hash char(64) NULL,
			reason varchar(64) NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY attachment_size_format (attachment_id,size_name,format),
			KEY status (status),
			KEY source_path (source_relative_path(191)),
			KEY relative_path (relative_path(191))
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $jobs_sql );
		dbDelta( $attachments_sql );
		dbDelta( $variants_sql );
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
			'attachments' => $this->get_table_name( 'trust_optimize_attachments' ),
			'variants'    => $this->get_table_name( 'trust_optimize_variants' ),
			'jobs'        => $this->get_table_name( 'trust_optimize_jobs' ),
			// Legacy 1.x storage: no longer created, read by the migration to 2.0 and dropped on uninstall.
			'images'      => $this->get_table_name( 'trust_optimize_images' ),
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
