<?php
/**
 * TrustOptimize uninstall handler.
 *
 * @package TrustOptimize
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Without the autoloader nothing can be cleaned up safely: leave data and files untouched.
if ( ! file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
	return;
}

require_once __DIR__ . '/vendor/autoload.php';

\TrustOptimize\Queue\ConversionQueue::cancel_all_tasks();
\TrustOptimize\Bulk\BulkProducer::cancel_all_tasks();
\TrustOptimize\Queue\Maintenance::unschedule();
trust_optimize_delete_runtime_transients();

$trust_optimize_remove_data = (bool) ( new \TrustOptimize\Admin\Settings() )->get( 'remove_data_on_uninstall' );

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

// A registry of schema 1.x that could not be imported keeps its table: it is the only record of those files.
trust_optimize_drop_plugin_tables( ! empty( $trust_optimize_cleanup_summary['legacy_registry_done'] ) );
trust_optimize_delete_plugin_options();

/**
 * Clean generated files recorded in the variants table.
 *
 * The 1.x registry, if the migration did not finish, is imported first, so its files are cleaned up under the same rules.
 *
 * @return array Summary; 'legacy_registry_done' tells whether no 1.x registry is left unprocessed.
 */
function trust_optimize_uninstall_cleanup_generated_files() {
	$database_manager = new \TrustOptimize\Database\DatabaseManager();
	$tables           = $database_manager->get_plugin_table_names();

	if ( ! $database_manager->table_exists( $tables['variants'] ) ) {
		return array(
			'done'                 => true,
			'processed'            => 0,
			'deleted'              => 0,
			'skipped'              => 0,
			'failed'               => 0,
			'errors'               => array(),
			'reason'               => 'variants_table_missing',
			'legacy_registry_done' => ! $database_manager->table_exists( $tables['images'] ),
		);
	}

	$variants    = new \TrustOptimize\Storage\VariantRepository( $database_manager );
	$attachments = new \TrustOptimize\Storage\AttachmentRepository( $database_manager, $variants );
	$cleanup     = new \TrustOptimize\Service\ImageCleanupService( $variants, $attachments, new \TrustOptimize\Service\LegacyPathGuard( $database_manager, $variants ), new \TrustOptimize\Migration\ConflictReport() );
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

	$summary['legacy_registry_done'] = trust_optimize_uninstall_import_legacy_registry( new \TrustOptimize\Migration\ImportLegacyManifest( $database_manager, $variants, $attachments ), $database_manager, $summary['max_seconds'] );

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
 * Import what is left of the 1.x registry into the variants table.
 *
 * @param \TrustOptimize\Migration\ImportLegacyManifest $step             Import step.
 * @param \TrustOptimize\Database\DatabaseManager       $database_manager Database manager.
 * @param float                                         $max_seconds      Time budget.
 * @return bool True when nothing is left to import (or there is no registry).
 */
function trust_optimize_uninstall_import_legacy_registry( $step, $database_manager, $max_seconds ) {
	if ( ! $database_manager->table_exists( $database_manager->get_plugin_table_names()['images'] ) ) {
		return true;
	}

	$started_at = microtime( true );
	$cursor     = 0;

	do {
		if ( ( microtime( true ) - $started_at ) >= max( 1, $max_seconds ) ) {
			return false;
		}

		$result = $step->run_batch( $cursor, 50 );
		$cursor = $result->get_cursor();
	} while ( ! $result->is_done() );

	return true;
}

/**
 * Drop TrustOptimize custom tables.
 *
 * @param bool $drop_legacy Whether the 1.x registry table is dropped too.
 */
function trust_optimize_drop_plugin_tables( $drop_legacy ) {
	global $wpdb;

	$database_manager = new \TrustOptimize\Database\DatabaseManager();
	$tables           = $database_manager->get_plugin_table_names();

	if ( ! $drop_legacy ) {
		unset( $tables['images'] );
	}

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
	delete_option( 'trust_optimize_migration' );
	delete_option( 'trust_optimize_migration_conflicts' );
	delete_option( 'trust_optimize_capabilities' );
	delete_option( \TrustOptimize\Admin\Settings::LEGACY_REMOVE_DATA_OPTION );
	delete_option( 'trust_optimize_bulk_active' );
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
