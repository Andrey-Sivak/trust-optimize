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
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=trust-optimize-settings' ) ); ?>"
							   class="button button-primary">
								<?php esc_html_e( 'Configure Settings', 'trust-optimize' ); ?>
							</a>

							<a href="#" class="button button-secondary trust-optimize-analyze-button">
								<?php esc_html_e( 'Analyze Media Library', 'trust-optimize' ); ?>
							</a>
						</div>
					</div>
				</div>

				<div class="trust-optimize-tab-content" id="media-library" style="display:none;">
					<h2><?php esc_html_e( 'Media Library Optimization', 'trust-optimize' ); ?></h2>
					<p><?php esc_html_e( 'This tab will show your media library images and their optimization status.', 'trust-optimize' ); ?></p>

					<!-- Placeholder for media library table -->
					<table class="widefat trust-optimize-media-table">
						<thead>
						<tr>
							<th><?php esc_html_e( 'Image', 'trust-optimize' ); ?></th>
							<th><?php esc_html_e( 'File', 'trust-optimize' ); ?></th>
							<th><?php esc_html_e( 'Original Size', 'trust-optimize' ); ?></th>
							<th><?php esc_html_e( 'Optimized Size', 'trust-optimize' ); ?></th>
							<th><?php esc_html_e( 'Savings', 'trust-optimize' ); ?></th>
							<th><?php esc_html_e( 'Status', 'trust-optimize' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'trust-optimize' ); ?></th>
						</tr>
						</thead>
						<tbody>
						<tr>
							<td colspan="7"><?php esc_html_e( 'No data available yet.', 'trust-optimize' ); ?></td>
						</tr>
						</tbody>
					</table>
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
