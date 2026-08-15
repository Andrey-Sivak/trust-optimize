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

$trust_optimize_cleanup_summary = trust_optimize_uninstall_cleanup_generated_files();

if ( empty( $trust_optimize_cleanup_summary['done'] ) ) {
	trust_optimize_uninstall_log(
		'TrustOptimize uninstall cleanup stopped before all plugin-managed files were processed.',
		$trust_optimize_cleanup_summary
	);
}

trust_optimize_drop_plugin_tables();
trust_optimize_delete_plugin_options();

/**
 * Clean generated files from plugin manifest records.
 */
function trust_optimize_uninstall_cleanup_generated_files() {
	$database_manager = new \TrustOptimize\Database\DatabaseManager();
	$table            = $database_manager->get_table_name( 'trust_optimize_images' );

	if ( ! $database_manager->table_exists( $table ) ) {
		return array(
			'done'      => true,
			'processed' => 0,
			'deleted'   => 0,
			'skipped'   => 0,
			'failed'    => 0,
			'errors'    => array(),
			'reason'    => 'images_table_missing',
		);
	}

	$cleanup     = new \TrustOptimize\Service\ImageCleanupService();
	$batch_size  = (int) apply_filters( 'trust_optimize_uninstall_cleanup_batch_size', 100 );
	$max_records = (int) apply_filters( 'trust_optimize_uninstall_cleanup_max_records', 5000 );
	$max_seconds = (float) apply_filters( 'trust_optimize_uninstall_cleanup_max_seconds', 20 );
	$started_at  = microtime( true );
	$cursor_id   = 0;
	$summary     = array(
		'done'        => false,
		'cursor_id'   => 0,
		'processed'   => 0,
		'deleted'     => 0,
		'skipped'     => 0,
		'failed'      => 0,
		'errors'      => array(),
		'batch_size'  => max( 1, $batch_size ),
		'max_records' => max( 1, $max_records ),
		'max_seconds' => max( 1, $max_seconds ),
	);

	while ( $summary['processed'] < $summary['max_records'] ) {
		if ( ( microtime( true ) - $started_at ) >= $summary['max_seconds'] ) {
			$summary['reason'] = 'time_limit_reached';
			break;
		}

		$remaining = $summary['max_records'] - $summary['processed'];
		$limit     = min( $summary['batch_size'], $remaining );
		$batch     = $cleanup->cleanup_managed_records_batch( $cursor_id, $limit );

		$cursor_id             = (int) $batch['cursor_id'];
		$summary['cursor_id']  = $cursor_id;
		$summary['processed'] += (int) $batch['processed'];
		$summary['deleted']   += (int) $batch['deleted'];
		$summary['skipped']   += (int) $batch['skipped'];
		$summary['failed']    += (int) $batch['failed'];
		$summary['errors']     = array_merge( $summary['errors'], $batch['errors'] );

		if ( ! empty( $batch['done'] ) ) {
			$summary['done']   = true;
			$summary['reason'] = 'completed';
			break;
		}

		if ( 0 === (int) $batch['processed'] ) {
			$summary['done']   = true;
			$summary['reason'] = 'empty_batch';
			break;
		}
	}

	if ( ! $summary['done'] && empty( $summary['reason'] ) ) {
		$summary['reason'] = 'record_limit_reached';
	}

	return $summary;
}

/**
 * Drop TrustOptimize custom tables.
 */
function trust_optimize_drop_plugin_tables() {
	global $wpdb;

	$database_manager = new \TrustOptimize\Database\DatabaseManager();
	$tables           = $database_manager->get_plugin_table_names();

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

/**
 * Log uninstall cleanup diagnostics when a bounded cleanup cannot finish.
 *
 * @param string $message Log message.
 * @param array  $context Structured context.
 */
function trust_optimize_uninstall_log( $message, array $context = array() ) {
	if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
		return;
	}

	// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	error_log( $message . ' ' . wp_json_encode( $context ) );
}
