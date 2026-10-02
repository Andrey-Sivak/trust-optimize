<?php
/**
 * Runtime requirements that WordPress plugin headers cannot express.
 *
 * @package TrustOptimize
 */

namespace TrustOptimize\Core;

/**
 * Class Requirements
 */
class Requirements {

	/**
	 * Minimum MySQL version.
	 */
	const MIN_MYSQL = '5.7';

	/**
	 * Minimum MariaDB version.
	 */
	const MIN_MARIADB = '10.3';

	/**
	 * Whether a database server version string meets the minimum.
	 *
	 * Unrecognised strings are accepted: refusing to run on an unknown fork is worse than trying.
	 *
	 * @param string $server_info Value of wpdb::db_server_info(), e.g. "5.5.5-10.6.18-MariaDB".
	 * @return bool
	 */
	public static function is_database_supported( $server_info ) {
		$server_info = (string) $server_info;

		// MariaDB reports a fake "5.5.5-" prefix to old clients.
		$is_mariadb  = 0 === strpos( $server_info, '5.5.5-' ) || false !== stripos( $server_info, 'MariaDB' );
		$server_info = preg_replace( '/^5\.5\.5-/', '', $server_info );

		if ( ! preg_match( '/^\d+\.\d+(\.\d+)?/', $server_info, $match ) ) {
			return true;
		}

		return version_compare( $match[0], $is_mariadb ? self::MIN_MARIADB : self::MIN_MYSQL, '>=' );
	}

	/**
	 * Whether the current site database meets the minimum.
	 *
	 * @return bool
	 */
	public static function is_current_database_supported() {
		global $wpdb;

		return self::is_database_supported( $wpdb->db_server_info() );
	}

	/**
	 * Message describing the database requirement.
	 *
	 * @return string
	 */
	public static function database_message() {
		return sprintf(
			/* translators: 1: minimum MySQL version, 2: minimum MariaDB version. */
			__( 'TrustOptimize requires MySQL %1$s or higher, or MariaDB %2$s or higher.', 'trust-optimize' ),
			self::MIN_MYSQL,
			self::MIN_MARIADB
		);
	}

	/**
	 * Show an admin notice when the database was downgraded below the minimum.
	 */
	public static function maybe_show_admin_notice() {
		if ( ! current_user_can( 'activate_plugins' ) || self::is_current_database_supported() ) {
			return;
		}

		printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( self::database_message() ) );
	}
}
