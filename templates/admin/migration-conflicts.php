<?php
/**
 * Files of TrustOptimize 1.x that collide with files of other attachments.
 *
 * @package TrustOptimize
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

// $trust_optimize_conflicts comes from Admin::display_admin_page().
if ( empty( $trust_optimize_conflicts ) ) {
	return;
}
?>

<div class="notice notice-warning inline trust-optimize-migration-conflicts">
	<h2><?php esc_html_e( 'Migration conflicts', 'trust-optimize' ); ?></h2>
	<p>
		<?php esc_html_e( 'The original may have been overwritten by TrustOptimize 1.x. Restore the file from a backup. The files below are not touched by the plugin.', 'trust-optimize' ); ?>
	</p>
	<table class="widefat striped">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Attachment', 'trust-optimize' ); ?></th>
				<th><?php esc_html_e( 'File', 'trust-optimize' ); ?></th>
				<th><?php esc_html_e( 'Shared with', 'trust-optimize' ); ?></th>
				<th><?php esc_html_e( 'Found', 'trust-optimize' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $trust_optimize_conflicts as $trust_optimize_conflict ) : ?>
				<tr>
					<td>
						<?php
						echo wp_kses(
							sprintf( '<a href="%s">#%d</a>', esc_url( get_edit_post_link( $trust_optimize_conflict['attachment_id'] ) ), (int) $trust_optimize_conflict['attachment_id'] ),
							array( 'a' => array( 'href' => array() ) )
						);
						?>
					</td>
					<td><code><?php echo esc_html( $trust_optimize_conflict['path'] ); ?></code></td>
					<td>
						<?php
						if ( $trust_optimize_conflict['conflicts_with'] ) {
							echo wp_kses(
								sprintf( '<a href="%s">#%d</a>', esc_url( get_edit_post_link( $trust_optimize_conflict['conflicts_with'] ) ), (int) $trust_optimize_conflict['conflicts_with'] ),
								array( 'a' => array( 'href' => array() ) )
							);
						} else {
							echo esc_html( 'hash_mismatch' === $trust_optimize_conflict['source'] ? __( 'the 1.x file was changed after the upgrade; it was not deleted and is not served', 'trust-optimize' ) : __( 'another variant', 'trust-optimize' ) );
						}
						?>
					</td>
					<td><?php echo esc_html( $trust_optimize_conflict['found_at'] ); ?> UTC</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
</div>
