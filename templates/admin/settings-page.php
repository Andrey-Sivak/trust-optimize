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

<div class="wrap trust-optimize-wrap">
	<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

	<p class="trust-optimize-subtitle">
		<a href="https://github.com/Andrey-Sivak/trust-optimize#readme" target="_blank" rel="noopener"><?php esc_html_e( 'Documentation', 'trust-optimize' ); ?></a>
	</p>

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

	<form method="post" action="options.php" id="trust-optimize-settings-form">
		<?php
		// Output security fields
		settings_fields( 'trust_optimize_settings' );

		// Output setting sections
		do_settings_sections( 'trust_optimize_settings' );
		?>

		<p class="submit">
			<?php submit_button( __( 'Save Settings', 'trust-optimize' ), 'primary', 'submit', false ); ?>
			<button type="submit" form="trust-optimize-reset-form" id="trust-optimize-reset-settings" class="button"><?php esc_html_e( 'Reset to Defaults', 'trust-optimize' ); ?></button>
		</p>
	</form>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="trust-optimize-recheck-form">
		<input type="hidden" name="action" value="trust_optimize_recheck_capabilities">
		<?php wp_nonce_field( 'trust_optimize_recheck_capabilities' ); ?>
	</form>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="trust-optimize-reset-form">
		<input type="hidden" name="action" value="trust_optimize_reset">
		<?php wp_nonce_field( 'trust_optimize_reset' ); ?>
	</form>
</div>
