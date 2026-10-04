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

trust_optimize_uninstall();

/**
 * Uninstall every site of a network, or the single site.
 */
function trust_optimize_uninstall() {
	if ( ! is_multisite() ) {
		trust_optimize_uninstall_site();
		return;
	}

	$per_page = 100;
	$offset   = 0;

	do {
		$site_ids = get_sites(
			array(
				'fields' => 'ids',
				'number' => $per_page,
				'offset' => $offset,
			)
		);

		foreach ( $site_ids as $site_id ) {
			switch_to_blog( $site_id );
			trust_optimize_uninstall_site();
			restore_current_blog();
		}

		$page_size = count( $site_ids );
		$offset   += $per_page;
	} while ( $page_size === $per_page );
}

/**
 * Uninstall the current site.
 *
 * Without the "remove data" flag only runtime data goes. With it the generated files are cleaned
 * up first, and the registry of those files is dropped only when nothing is left to clean: a
 * partial cleanup keeps the tables and options so that it can be finished later (M-3).
 */
function trust_optimize_uninstall_site() {
	\TrustOptimize\Queue\ConversionQueue::cancel_all_tasks();
	\TrustOptimize\Bulk\BulkProducer::cancel_all_tasks();
	\TrustOptimize\Queue\Maintenance::unschedule();
	trust_optimize_delete_runtime_transients();

	if ( ! (bool) ( new \TrustOptimize\Admin\Settings() )->get( 'remove_data_on_uninstall' ) ) {
		return;
	}

	$summary = trust_optimize_uninstall_cleanup_generated_files();

	if ( empty( $summary['done'] ) ) {
		trust_optimize_uninstall_log(
			'TrustOptimize uninstall cleanup stopped before all plugin-managed files were processed.',
			$summary
		);
	}

	if ( ! $summary['complete'] ) {
		update_option(
			'trust_optimize_pending_cleanup',
			array(
				'remaining' => (int) $summary['remaining'],
				'at'        => current_time( 'mysql', true ),
			),
			false
		);
		trust_optimize_uninstall_log(
			sprintf( 'TrustOptimize uninstall left %d generated files registered; the tables and options were kept.', (int) $summary['remaining'] )
		);
		return;
	}

	trust_optimize_drop_plugin_tables();
	trust_optimize_delete_plugin_options();
}

/**
 * Clean generated files recorded in the variants table.
 *
 * @return array Summary; 'remaining' tells how many rows still await file removal and 'complete' that nothing is left to clean.
 */
function trust_optimize_uninstall_cleanup_generated_files() {
	$database_manager = new \TrustOptimize\Database\DatabaseManager();

	if ( ! $database_manager->table_exists( $database_manager->get_plugin_table_names()['variants'] ) ) {
		return array(
			'done'      => true,
			'processed' => 0,
			'deleted'   => 0,
			'skipped'   => 0,
			'failed'    => 0,
			'errors'    => array(),
			'reason'    => 'variants_table_missing',
			'remaining' => 0,
			'complete'  => true,
		);
	}

	$variants    = new \TrustOptimize\Storage\VariantRepository( $database_manager );
	$attachments = new \TrustOptimize\Storage\AttachmentRepository( $database_manager, $variants );
	$cleanup     = new \TrustOptimize\Service\ImageCleanupService( $variants, $attachments );
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

	$summary['remaining'] = $variants->count_removable();
	$summary['complete']  = 0 === $summary['remaining'];

	return $summary;
}

/**
 * Drop TrustOptimize custom tables.
 */
function trust_optimize_drop_plugin_tables() {
	global $wpdb;

	$database_manager = new \TrustOptimize\Database\DatabaseManager();

	foreach ( $database_manager->get_plugin_table_names() as $table ) {
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
		// phpcs:enable
	}
}

/**
 * Delete every option of the plugin (trust_optimize_*), including the pending-cleanup note.
 */
function trust_optimize_delete_plugin_options() {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'trust_optimize_' ) . '%' ) );

	foreach ( $names as $name ) {
		delete_option( $name );
	}
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
 * Log what uninstall left behind. Unconditional: it happens once and the site owner needs to know.
 *
 * @param string $message Log message.
 * @param array  $context Structured context.
 */
function trust_optimize_uninstall_log( $message, array $context = array() ) {
	// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	error_log( $message . ' ' . wp_json_encode( $context ) );
}
