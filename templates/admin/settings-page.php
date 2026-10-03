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

	<?php
	$trust_optimize_notices = array(
		'rechecked' => __( 'Format support was checked again.', 'trust-optimize' ),
		'reset'     => __( 'Settings were reset to defaults.', 'trust-optimize' ),
	);
	$trust_optimize_notice  = isset( $_GET['trust_optimize_notice'] ) ? sanitize_key( wp_unslash( $_GET['trust_optimize_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( isset( $trust_optimize_notices[ $trust_optimize_notice ] ) ) :
		?>
		<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $trust_optimize_notices[ $trust_optimize_notice ] ); ?></p></div>
	<?php endif; ?>

	<div class="trust-optimize-settings-container">
		<div class="notice notice-info inline">
			<p>
				<?php esc_html_e( 'Default quality for new installs is tuned for practical bulk optimization: WebP 85 and AVIF 80. Lower quality usually means smaller files and faster bulk jobs; higher quality increases file size and processing cost.', 'trust-optimize' ); ?>
			</p>
			<p>
				<?php esc_html_e( 'Existing saved quality settings are preserved until you change them or reset settings to defaults.', 'trust-optimize' ); ?>
			</p>
		</div>

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

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="trust_optimize_recheck_capabilities">
			<?php wp_nonce_field( 'trust_optimize_recheck_capabilities' ); ?>
			<?php submit_button( __( 'Re-check format support', 'trust-optimize' ), 'secondary', 'submit', false ); ?>
		</form>

		<!-- Reset Settings Form -->
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="trust-optimize-reset-form" style="display:none;">
			<input type="hidden" name="action" value="trust_optimize_reset">
			<?php wp_nonce_field( 'trust_optimize_reset' ); ?>
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
