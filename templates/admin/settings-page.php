<?php
/**
 * Template for the settings page
 *
 * @package TrustOptimize
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}
?>

<div class="wrap trust-optimize-settings-wrap">
	<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
	
	<?php settings_errors(); ?>
	
	<div class="trust-optimize-settings-container">
		<form method="post" action="options.php" id="trust-optimize-settings-form">
			<?php
			// Output security fields
			settings_fields( 'trust_optimize_settings' );

			// Output setting sections
			do_settings_sections( 'trust_optimize_settings' );

			// Submit button
			submit_button( __( 'Save Settings', 'trust-optimize' ) );
			?>
		</form>
		
		<!-- Reset Settings Form -->
		<form method="post" action="options.php" id="trust-optimize-reset-form" style="display:none;">
			<input type="hidden" name="trust_optimize_reset_settings" value="1">
			<?php wp_nonce_field( 'trust_optimize_reset_nonce', 'trust_optimize_reset_nonce' ); ?>
		</form>
		
		<p>
			<a href="#" id="trust-optimize-reset-settings" class="button button-secondary">
				<?php esc_html_e( 'Reset to Defaults', 'trust-optimize' ); ?>
			</a>
		</p>
	</div>
	
	<div class="trust-optimize-sidebar">
		<div class="trust-optimize-box">
			<h3><?php esc_html_e( 'About TrustOptimize', 'trust-optimize' ); ?></h3>
			<p>
				<?php esc_html_e( 'TrustOptimize is an advanced media optimization solution for WordPress.', 'trust-optimize' ); ?>
			</p>
			<p>
				<a href="https://example.com/trust-optimize-docs" target="_blank">
					<?php esc_html_e( 'Documentation', 'trust-optimize' ); ?>
				</a>
			</p>
		</div>
	</div>
</div>
