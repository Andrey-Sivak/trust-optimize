<?php
/**
 * Template for the main admin dashboard page
 *
 * @package TrustOptimize
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

// Variables from Admin::display_admin_page(): total_eligible, webp_supported, avif_supported, stats, overdue (Site Health result) and max_pending, all prefixed with trust_optimize_.
$trust_optimize_upload_dir       = wp_upload_dir();
$trust_optimize_uploads_writable = ! empty( $trust_optimize_upload_dir['basedir'] ) && wp_is_writable( $trust_optimize_upload_dir['basedir'] );
$trust_optimize_disk_free        = ! empty( $trust_optimize_upload_dir['basedir'] ) ? disk_free_space( $trust_optimize_upload_dir['basedir'] ) : false;
$trust_optimize_unoptimized      = max( 0, $trust_optimize_stats['eligible'] - $trust_optimize_stats['optimized'] );

// Each check of the server: label, whether it is fine and the value shown next to it.
$trust_optimize_server_checks = array(
	array( __( 'GD', 'trust-optimize' ), extension_loaded( 'gd' ), extension_loaded( 'gd' ) ? __( 'available', 'trust-optimize' ) : __( 'missing', 'trust-optimize' ) ),
	array( __( 'Imagick', 'trust-optimize' ), extension_loaded( 'imagick' ), extension_loaded( 'imagick' ) ? __( 'available', 'trust-optimize' ) : __( 'missing', 'trust-optimize' ) ),
	array( __( 'WebP output', 'trust-optimize' ), $trust_optimize_webp_supported, $trust_optimize_webp_supported ? __( 'available', 'trust-optimize' ) : __( 'not supported by this server', 'trust-optimize' ) ),
	array( __( 'AVIF output', 'trust-optimize' ), $trust_optimize_avif_supported, $trust_optimize_avif_supported ? __( 'available', 'trust-optimize' ) : __( 'not supported by this server', 'trust-optimize' ) ),
	array( __( 'Uploads writable', 'trust-optimize' ), $trust_optimize_uploads_writable, $trust_optimize_uploads_writable ? __( 'yes', 'trust-optimize' ) : __( 'no', 'trust-optimize' ) ),
	array( __( 'Action Scheduler', 'trust-optimize' ), function_exists( 'as_enqueue_async_action' ), function_exists( 'as_enqueue_async_action' ) ? __( 'available', 'trust-optimize' ) : __( 'missing', 'trust-optimize' ) ),
	array( __( 'Free disk space', 'trust-optimize' ), false !== $trust_optimize_disk_free, false !== $trust_optimize_disk_free ? size_format( $trust_optimize_disk_free, 1 ) : __( 'unknown', 'trust-optimize' ) ),
);
?>

?>

<div class="wrap trust-optimize-wrap">
	<h1>
		<?php esc_html_e( 'TrustOptimize', 'trust-optimize' ); ?>
		<span class="trust-optimize-version">
			<?php
			/* translators: %s: plugin version number. */
			echo esc_html( sprintf( __( 'Version %s', 'trust-optimize' ), TRUST_OPTIMIZE_VERSION ) );
			?>
		</span>
	</h1>

	<nav class="nav-tab-wrapper trust-optimize-tabs" aria-label="<?php esc_attr_e( 'TrustOptimize sections', 'trust-optimize' ); ?>">
		<a href="#overview" class="nav-tab nav-tab-active" data-tab="overview"><?php esc_html_e( 'Overview', 'trust-optimize' ); ?></a>
		<a href="#bulk" class="nav-tab" data-tab="bulk"><?php esc_html_e( 'Bulk optimization', 'trust-optimize' ); ?></a>
	</nav>

	<div class="trust-optimize-panel" id="trust-optimize-tab-overview">
		<?php if ( $trust_optimize_unoptimized > 0 ) : ?>
			<div class="notice notice-info inline">
				<p>
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: number of images. */
							_n( '%s image is not optimized yet.', '%s images are not optimized yet.', $trust_optimize_unoptimized, 'trust-optimize' ),
							number_format_i18n( $trust_optimize_unoptimized )
						)
					);
					?>
					<a href="#bulk" class="button button-primary trust-optimize-open-tab" data-tab="bulk"><?php esc_html_e( 'Optimize library', 'trust-optimize' ); ?></a>
				</p>
			</div>
		<?php endif; ?>

		<div class="trust-optimize-cards">
			<div class="trust-optimize-card">
				<div class="trust-optimize-card-value"><?php echo esc_html( number_format_i18n( $trust_optimize_stats['total_images'] ) ); ?></div>
				<div class="trust-optimize-card-label"><?php esc_html_e( 'Total images', 'trust-optimize' ); ?></div>
			</div>
			<div class="trust-optimize-card">
				<div class="trust-optimize-card-value"><?php echo esc_html( number_format_i18n( $trust_optimize_stats['optimized'] ) ); ?></div>
				<div class="trust-optimize-card-label"><?php esc_html_e( 'Optimized', 'trust-optimize' ); ?></div>
			</div>
			<div class="trust-optimize-card">
				<div class="trust-optimize-card-value"><?php echo esc_html( size_format( $trust_optimize_stats['saved_bytes'], 1 ) ); ?></div>
				<div class="trust-optimize-card-label"><?php esc_html_e( 'Saved by WebP files', 'trust-optimize' ); ?></div>
			</div>
			<div class="trust-optimize-card">
				<div class="trust-optimize-card-value"><?php echo esc_html( number_format_i18n( $trust_optimize_stats['rate'], 1 ) . '%' ); ?></div>
				<div class="trust-optimize-card-label"><?php esc_html_e( 'Optimization rate', 'trust-optimize' ); ?></div>
			</div>
		</div>

		<h2><?php esc_html_e( 'Details', 'trust-optimize' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Counted from the plugin tables and refreshed at most every five minutes.', 'trust-optimize' ); ?></p>

		<table class="widefat striped trust-optimize-details">
			<tbody>
				<tr><th><?php esc_html_e( 'Images that can be optimized (JPEG, PNG)', 'trust-optimize' ); ?></th><td><?php echo esc_html( number_format_i18n( $trust_optimize_stats['eligible'] ) ); ?></td></tr>
				<tr><th><?php esc_html_e( 'Optimized', 'trust-optimize' ); ?></th><td><?php echo esc_html( number_format_i18n( $trust_optimize_stats['optimized'] ) ); ?></td></tr>
				<tr><th><?php esc_html_e( 'Partially optimized', 'trust-optimize' ); ?></th><td><?php echo esc_html( number_format_i18n( $trust_optimize_stats['partial'] ) ); ?></td></tr>
				<tr><th><?php esc_html_e( 'Waiting or in progress', 'trust-optimize' ); ?></th><td><?php echo esc_html( number_format_i18n( $trust_optimize_stats['queued'] ) ); ?></td></tr>
				<tr><th><?php esc_html_e( 'Failed', 'trust-optimize' ); ?></th><td><?php echo esc_html( number_format_i18n( $trust_optimize_stats['failed'] ) ); ?></td></tr>
				<tr><th><?php esc_html_e( 'Skipped', 'trust-optimize' ); ?></th><td><?php echo esc_html( number_format_i18n( $trust_optimize_stats['skipped'] ) ); ?></td></tr>
				<tr><th><?php esc_html_e( 'Outdated variants (settings changed)', 'trust-optimize' ); ?></th><td><?php echo esc_html( number_format_i18n( $trust_optimize_stats['outdated'] ) ); ?></td></tr>
				<tr><th><?php esc_html_e( 'Storage saved (WebP files only)', 'trust-optimize' ); ?></th><td><?php echo esc_html( size_format( $trust_optimize_stats['saved_bytes'], 1 ) ); ?></td></tr>
			</tbody>
		</table>
	</div>

	<div class="trust-optimize-panel" id="trust-optimize-tab-bulk" hidden>
		<?php if ( 'good' !== $trust_optimize_overdue['status'] ) : ?>
			<div class="notice notice-warning inline">
				<p><strong><?php echo esc_html( $trust_optimize_overdue['label'] ); ?></strong></p>
				<?php echo wp_kses_post( $trust_optimize_overdue['description'] ); ?>
			</div>
		<?php endif; ?>

		<h2><?php esc_html_e( 'Server', 'trust-optimize' ); ?></h2>
		<ul class="trust-optimize-server">
			<?php foreach ( $trust_optimize_server_checks as $trust_optimize_check ) : ?>
				<li class="<?php echo $trust_optimize_check[1] ? 'is-ok' : 'is-warning'; ?>">
					<span class="dashicons <?php echo $trust_optimize_check[1] ? 'dashicons-yes-alt' : 'dashicons-warning'; ?>" aria-hidden="true"></span>
					<strong><?php echo esc_html( $trust_optimize_check[0] ); ?></strong>
					<span><?php echo esc_html( $trust_optimize_check[2] ); ?></span>
				</li>
			<?php endforeach; ?>
		</ul>

		<h2><?php esc_html_e( 'Optimize the library', 'trust-optimize' ); ?></h2>
		<p>
			<?php
			echo esc_html(
				sprintf(
					/* translators: %s: number of images. */
					_n( '%s image can be optimized.', '%s images can be optimized.', $trust_optimize_total_eligible, 'trust-optimize' ),
					number_format_i18n( $trust_optimize_total_eligible )
				)
			);
			?>
			<?php esc_html_e( 'Opening this page does not start processing.', 'trust-optimize' ); ?>
		</p>

		<div class="trust-optimize-bulk-actions">
			<button type="button" class="button button-primary trust-optimize-bulk-action" data-action="sync"><?php esc_html_e( 'Optimize library', 'trust-optimize' ); ?></button>
			<button type="button" class="button trust-optimize-bulk-action" data-action="inventory"><?php esc_html_e( 'Analyze library', 'trust-optimize' ); ?></button>
			<button type="button" class="button trust-optimize-bulk-control" data-action="pause" hidden><?php esc_html_e( 'Pause', 'trust-optimize' ); ?></button>
			<button type="button" class="button trust-optimize-bulk-control" data-action="resume" hidden><?php esc_html_e( 'Resume', 'trust-optimize' ); ?></button>
			<button type="button" class="button trust-optimize-bulk-control" data-action="cancel" hidden><?php esc_html_e( 'Cancel', 'trust-optimize' ); ?></button>
		</div>
		<p class="description">
			<?php
			/* translators: %d: most attachments waiting on the queue. */
			echo esc_html( sprintf( __( 'Pause and Cancel stop adding new attachments to the queue; attachments already queued (up to %d) are still processed.', 'trust-optimize' ), $trust_optimize_max_pending ) );
			?>
		</p>

		<div class="trust-optimize-progress-wrap">
			<div class="trust-optimize-progress-bar" aria-hidden="true">
				<span></span>
			</div>
			<p class="trust-optimize-bulk-status" aria-live="polite"><?php esc_html_e( 'No bulk job is running.', 'trust-optimize' ); ?></p>
		</div>

		<table class="widefat striped trust-optimize-bulk-counters" hidden>
			<tbody>
				<tr data-types="sync remove inventory"><th><?php esc_html_e( 'Processed', 'trust-optimize' ); ?></th><td data-field="processed">0</td></tr>
				<tr data-types="sync"><th><?php esc_html_e( 'Optimized', 'trust-optimize' ); ?></th><td data-field="created_count">0</td></tr>
				<tr data-types="sync"><th><?php esc_html_e( 'Skipped', 'trust-optimize' ); ?></th><td data-field="skipped">0</td></tr>
				<tr data-types="remove"><th><?php esc_html_e( 'Cleaned up', 'trust-optimize' ); ?></th><td data-field="deleted_count">0</td></tr>
				<tr data-types="sync remove"><th><?php esc_html_e( 'Failed', 'trust-optimize' ); ?></th><td data-field="failed_count">0</td></tr>
				<tr class="trust-optimize-last-error" hidden><th><?php esc_html_e( 'Last error', 'trust-optimize' ); ?></th><td data-field="last_error"></td></tr>
			</tbody>
		</table>

		<div class="trust-optimize-remove">
			<h2><?php esc_html_e( 'Remove optimized files', 'trust-optimize' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Before deleting the plugin with "Remove data on uninstall" enabled, remove the optimized files here and wait until the job finishes. Originals and WordPress thumbnails are never removed.', 'trust-optimize' ); ?></p>
			<button type="button" class="button button-link-delete trust-optimize-bulk-action" data-action="remove"><?php esc_html_e( 'Remove optimized files', 'trust-optimize' ); ?></button>
		</div>
	</div>
</div>
