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

// Get statistics (placeholder for now).
$trust_optimize_total_images            = 0;
$trust_optimize_optimized_images        = 0;
$trust_optimize_saved_bytes             = 0;
$trust_optimize_optimization_percentage = 0;
$trust_optimize_upload_dir              = wp_upload_dir();
$trust_optimize_uploads_writable        = ! empty( $trust_optimize_upload_dir['basedir'] ) && wp_is_writable( $trust_optimize_upload_dir['basedir'] );
$trust_optimize_disk_free               = ! empty( $trust_optimize_upload_dir['basedir'] ) ? disk_free_space( $trust_optimize_upload_dir['basedir'] ) : false;
$trust_optimize_eligibility             = class_exists( 'TrustOptimize\\Bulk\\EligibilityQuery' ) ? new \TrustOptimize\Bulk\EligibilityQuery() : null;
$trust_optimize_total_eligible          = $trust_optimize_eligibility ? $trust_optimize_eligibility->count_eligible_attachments() : 0;
$trust_optimize_profile_factory         = class_exists( 'TrustOptimize\\Service\\ImageProfileFactory' ) ? new \TrustOptimize\Service\ImageProfileFactory() : null;
$trust_optimize_webp_supported          = $trust_optimize_profile_factory ? $trust_optimize_profile_factory->is_output_format_supported( 'webp' ) : false;
$trust_optimize_avif_supported          = $trust_optimize_profile_factory ? $trust_optimize_profile_factory->is_output_format_supported( 'avif' ) : false;
?>

<div class="wrap trust-optimize-admin-wrap">
	<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

	<div class="trust-optimize-admin-container">
		<div class="trust-optimize-admin-header">
			<div class="trust-optimize-logo">
				<!-- Placeholder for logo -->
			</div>
			<div class="trust-optimize-version">
				<?php
				// Translators: %s is the plugin version number.
				echo esc_html( sprintf( __( 'Version %s', 'trust-optimize' ), TRUST_OPTIMIZE_VERSION ) );
				?>
			</div>
		</div>

		<div class="trust-optimize-dashboard">
			<div class="trust-optimize-stats-row">
				<div class="trust-optimize-stat-box">
					<h3><?php esc_html_e( 'Total Images', 'trust-optimize' ); ?></h3>
					<div
						class="trust-optimize-stat-value"><?php echo esc_html( number_format_i18n( $trust_optimize_total_images ) ); ?></div>
				</div>

				<div class="trust-optimize-stat-box">
					<h3><?php esc_html_e( 'Optimized Images', 'trust-optimize' ); ?></h3>
					<div
						class="trust-optimize-stat-value"><?php echo esc_html( number_format_i18n( $trust_optimize_optimized_images ) ); ?></div>
				</div>

				<div class="trust-optimize-stat-box">
					<h3><?php esc_html_e( 'Storage Saved', 'trust-optimize' ); ?></h3>
					<div class="trust-optimize-stat-value">
						<?php echo esc_html( size_format( $trust_optimize_saved_bytes, 1 ) ); ?>
					</div>
				</div>

				<div class="trust-optimize-stat-box">
					<h3><?php esc_html_e( 'Optimization Rate', 'trust-optimize' ); ?></h3>
					<div class="trust-optimize-stat-value">
						<?php echo esc_html( number_format( $trust_optimize_optimization_percentage, 1 ) . '%' ); ?>
					</div>
				</div>
			</div>

			<div class="trust-optimize-dashboard-tabs">
				<div class="trust-optimize-tab-nav">
					<a href="#overview" class="trust-optimize-tab-link active">
						<?php esc_html_e( 'Overview', 'trust-optimize' ); ?>
					</a>
					<a href="#media-library" class="trust-optimize-tab-link">
						<?php esc_html_e( 'Media Library', 'trust-optimize' ); ?>
					</a>
					<a href="#statistics" class="trust-optimize-tab-link">
						<?php esc_html_e( 'Statistics', 'trust-optimize' ); ?>
					</a>
				</div>

				<div class="trust-optimize-tab-content" id="overview">
					<div class="trust-optimize-overview-content">
						<h2><?php esc_html_e( 'Welcome to TrustOptimize', 'trust-optimize' ); ?></h2>
						<p>
							<?php esc_html_e( 'TrustOptimize helps you optimize your website\'s images for better performance and user experience.', 'trust-optimize' ); ?>
						</p>

						<div class="trust-optimize-actions">
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=trust-optimize-settings' ) ); ?>" class="button button-primary">
								<?php esc_html_e( 'Configure Settings', 'trust-optimize' ); ?>
							</a>

							<button type="button" class="button button-secondary trust-optimize-bulk-action" data-action="inventory">
								<?php esc_html_e( 'Analyze Media Library', 'trust-optimize' ); ?>
							</button>
						</div>
					</div>
				</div>

				<div class="trust-optimize-tab-content" id="media-library" style="display:none;">
					<h2><?php esc_html_e( 'Media Library Optimization', 'trust-optimize' ); ?></h2>
					<p><?php esc_html_e( 'Run resumable bulk jobs for existing image attachments. Opening this page does not start processing.', 'trust-optimize' ); ?></p>

					<div class="trust-optimize-bulk-panel">
						<h3><?php esc_html_e( 'Preflight diagnostics', 'trust-optimize' ); ?></h3>
						<ul class="trust-optimize-preflight">
							<li><?php esc_html_e( 'Eligible image attachments:', 'trust-optimize' ); ?> <strong><?php echo esc_html( number_format_i18n( $trust_optimize_total_eligible ) ); ?></strong></li>
							<li><?php esc_html_e( 'GD:', 'trust-optimize' ); ?> <strong><?php echo extension_loaded( 'gd' ) ? esc_html__( 'available', 'trust-optimize' ) : esc_html__( 'missing', 'trust-optimize' ); ?></strong></li>
							<li><?php esc_html_e( 'Imagick:', 'trust-optimize' ); ?> <strong><?php echo extension_loaded( 'imagick' ) ? esc_html__( 'available', 'trust-optimize' ) : esc_html__( 'missing', 'trust-optimize' ); ?></strong></li>
							<li><?php esc_html_e( 'WebP output:', 'trust-optimize' ); ?> <strong><?php echo $trust_optimize_webp_supported ? esc_html__( 'available', 'trust-optimize' ) : esc_html__( 'unsupported by WP editor', 'trust-optimize' ); ?></strong></li>
							<li><?php esc_html_e( 'AVIF output:', 'trust-optimize' ); ?> <strong><?php echo $trust_optimize_avif_supported ? esc_html__( 'available', 'trust-optimize' ) : esc_html__( 'unsupported by WP editor', 'trust-optimize' ); ?></strong></li>
							<li><?php esc_html_e( 'Uploads writable:', 'trust-optimize' ); ?> <strong><?php echo $trust_optimize_uploads_writable ? esc_html__( 'yes', 'trust-optimize' ) : esc_html__( 'no', 'trust-optimize' ); ?></strong></li>
							<li><?php esc_html_e( 'Action Scheduler:', 'trust-optimize' ); ?> <strong><?php echo function_exists( 'as_enqueue_async_action' ) ? esc_html__( 'available', 'trust-optimize' ) : esc_html__( 'missing', 'trust-optimize' ); ?></strong></li>
							<li><?php esc_html_e( 'Approx. free disk:', 'trust-optimize' ); ?> <strong><?php echo false !== $trust_optimize_disk_free ? esc_html( size_format( $trust_optimize_disk_free, 1 ) ) : esc_html__( 'unknown', 'trust-optimize' ); ?></strong></li>
						</ul>

						<div class="trust-optimize-bulk-actions">
							<button type="button" class="button trust-optimize-bulk-action" data-action="inventory"><?php esc_html_e( 'Analyze', 'trust-optimize' ); ?></button>
							<button type="button" class="button button-primary trust-optimize-bulk-action" data-action="sync"><?php esc_html_e( 'Start Sync', 'trust-optimize' ); ?></button>
							<button type="button" class="button button-secondary trust-optimize-bulk-action" data-action="remove"><?php esc_html_e( 'Remove Generated Files', 'trust-optimize' ); ?></button>
							<button type="button" class="button trust-optimize-bulk-control" data-action="pause"><?php esc_html_e( 'Pause', 'trust-optimize' ); ?></button>
							<button type="button" class="button trust-optimize-bulk-control" data-action="resume"><?php esc_html_e( 'Resume', 'trust-optimize' ); ?></button>
							<button type="button" class="button trust-optimize-bulk-control" data-action="cancel"><?php esc_html_e( 'Cancel', 'trust-optimize' ); ?></button>
						</div>

						<div class="trust-optimize-progress-wrap">
							<div class="trust-optimize-progress-bar" aria-hidden="true">
								<span style="width:0%"></span>
							</div>
							<p class="trust-optimize-bulk-status"><?php esc_html_e( 'No active bulk job.', 'trust-optimize' ); ?></p>
						</div>

						<table class="widefat striped trust-optimize-bulk-counters">
							<tbody>
								<tr><th><?php esc_html_e( 'Status', 'trust-optimize' ); ?></th><td data-field="status">—</td></tr>
								<tr><th><?php esc_html_e( 'Processed', 'trust-optimize' ); ?></th><td data-field="processed">0</td></tr>
								<tr><th><?php esc_html_e( 'Skipped', 'trust-optimize' ); ?></th><td data-field="skipped">0</td></tr>
								<tr><th><?php esc_html_e( 'Failed', 'trust-optimize' ); ?></th><td data-field="failed_count">0</td></tr>
								<tr><th><?php esc_html_e( 'Created', 'trust-optimize' ); ?></th><td data-field="created_count">0</td></tr>
								<tr><th><?php esc_html_e( 'Deleted', 'trust-optimize' ); ?></th><td data-field="deleted_count">0</td></tr>
								<tr><th><?php esc_html_e( 'Cursor', 'trust-optimize' ); ?></th><td data-field="cursor_id">0</td></tr>
								<tr><th><?php esc_html_e( 'Last error', 'trust-optimize' ); ?></th><td data-field="last_error">—</td></tr>
							</tbody>
						</table>
					</div>
				</div>

				<div class="trust-optimize-tab-content" id="statistics" style="display:none;">
					<h2><?php esc_html_e( 'Optimization Statistics', 'trust-optimize' ); ?></h2>
					<p><?php esc_html_e( 'This tab will show detailed statistics about your image optimization.', 'trust-optimize' ); ?></p>

					<!-- Placeholder for statistics -->
					<div class="trust-optimize-stats-placeholder">
						<p><?php esc_html_e( 'Statistics will be available after you optimize some images.', 'trust-optimize' ); ?></p>
					</div>
				</div>
			</div>
		</div>
	</div>
</div>
