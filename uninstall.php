<?php
/**
 * TrustOptimize uninstall handler.
 *
 * @package TrustOptimize
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
	require_once __DIR__ . '/vendor/autoload.php';
}

require_once __DIR__ . '/includes/value/OperationResult.php';
require_once __DIR__ . '/includes/value/DeleteResult.php';
require_once __DIR__ . '/includes/database/DatabaseManager.php';
require_once __DIR__ . '/includes/database/models/ImageModel.php';
require_once __DIR__ . '/includes/queue/ConversionQueue.php';
require_once __DIR__ . '/includes/service/ImageCleanupService.php';
require_once __DIR__ . '/includes/bulk/BulkJobRunner.php';

\TrustOptimize\Queue\ConversionQueue::cancel_all_tasks();
\TrustOptimize\Bulk\BulkJobRunner::cancel_all_ticks();
trust_optimize_delete_runtime_transients();

$trust_optimize_remove_data = (bool) get_option( 'trust_optimize_remove_data_on_uninstall', false );

if ( ! $trust_optimize_remove_data ) {
	return;
}

trust_optimize_uninstall_cleanup_generated_files();
trust_optimize_drop_plugin_tables();
trust_optimize_delete_plugin_options();

/**
 * Clean generated files from plugin manifest records.
 */
function trust_optimize_uninstall_cleanup_generated_files() {
	global $wpdb;

	$database_manager = new \TrustOptimize\Database\DatabaseManager();
	$table            = $database_manager->get_table_name( 'trust_optimize_images' );

	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$attachment_ids = $wpdb->get_col( "SELECT attachment_id FROM {$table}" );
	// phpcs:enable

	$cleanup = new \TrustOptimize\Service\ImageCleanupService();

	foreach ( $attachment_ids as $attachment_id ) {
		$cleanup->cleanup_attachment( (int) $attachment_id );
	}
}

/**
 * Drop TrustOptimize custom tables.
 */
function trust_optimize_drop_plugin_tables() {
	global $wpdb;

	$database_manager = new \TrustOptimize\Database\DatabaseManager();
	$tables           = array(
		$database_manager->get_table_name( 'trust_optimize_images' ),
		$database_manager->get_table_name( 'trust_optimize_jobs' ),
	);

	foreach ( $tables as $table ) {
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
		// phpcs:enable
	}
}

/**
 * Delete plugin options.
 */
function trust_optimize_delete_plugin_options() {
	delete_option( 'trust_optimize_options' );
	delete_option( 'trust_optimize_db_version' );
	delete_option( 'trust_optimize_preflight' );
	delete_option( 'trust_optimize_remove_data_on_uninstall' );
}

/**
 * Delete runtime transients.
 */
function trust_optimize_delete_runtime_transients() {
	global $wpdb;

	$transient_pattern = $wpdb->esc_like( '_transient_trust_optimize_' ) . '%';
	$timeout_pattern   = $wpdb->esc_like( '_transient_timeout_trust_optimize_' ) . '%';

	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$transient_pattern,
			$timeout_pattern
		)
	);
	// phpcs:enable
}
