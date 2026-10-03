<?php
/**
 * Admin notices that ask the site owner to do something or explain what the plugin does.
 *
 * @package TrustOptimize
 */

namespace TrustOptimize\Admin;

use TrustOptimize\Bulk\BulkJobRepository;
use TrustOptimize\Bulk\BulkProducer;
use TrustOptimize\Capabilities\CapabilityService;
use TrustOptimize\Domain\JobStatus;
use TrustOptimize\Migration\ConflictReport;
use TrustOptimize\Migration\MigrationRunner;

/**
 * Class Notices
 *
 * Shown to users who can manage options only. A notice can be hidden for the current user; the
 * hidden keys are kept in user meta, and a key changes when the event it describes changes, so a
 * new event shows again. The notice about a bulk job paused for lack of disk space cannot be
 * hidden: it has the button that resumes the job.
 */
class Notices {

	/**
	 * User meta that holds the hidden notice keys.
	 */
	const META = 'trust_optimize_dismissed_notices';

	/**
	 * Action of the dismiss link.
	 */
	const ACTION_DISMISS = 'trust_optimize_dismiss_notice';

	/**
	 * Action of the resume button.
	 */
	const ACTION_RESUME = 'trust_optimize_resume_bulk';

	/**
	 * Migration runner.
	 *
	 * @var MigrationRunner
	 */
	private $migration;

	/**
	 * Conflict report.
	 *
	 * @var ConflictReport
	 */
	private $conflicts;

	/**
	 * Bulk job repository.
	 *
	 * @var BulkJobRepository
	 */
	private $jobs;

	/**
	 * Bulk producer.
	 *
	 * @var BulkProducer
	 */
	private $producer;

	/**
	 * Capability service.
	 *
	 * @var CapabilityService
	 */
	private $capabilities;

	/**
	 * Constructor.
	 *
	 * @param MigrationRunner   $migration    Migration runner.
	 * @param ConflictReport    $conflicts    Conflict report.
	 * @param BulkJobRepository $jobs         Bulk job repository.
	 * @param BulkProducer      $producer     Bulk producer.
	 * @param CapabilityService $capabilities Capability service.
	 */
	public function __construct( MigrationRunner $migration, ConflictReport $conflicts, BulkJobRepository $jobs, BulkProducer $producer, CapabilityService $capabilities ) {
		$this->migration    = $migration;
		$this->conflicts    = $conflicts;
		$this->jobs         = $jobs;
		$this->producer     = $producer;
		$this->capabilities = $capabilities;
	}

	/**
	 * Register the hooks.
	 */
	public function register() {
		add_action( 'admin_notices', array( $this, 'render' ) );
		add_action( 'admin_post_' . self::ACTION_DISMISS, array( $this, 'handle_dismiss' ) );
		add_action( 'admin_post_' . self::ACTION_RESUME, array( $this, 'handle_resume' ) );
	}

	/**
	 * Print the notices.
	 */
	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$hidden = (array) get_user_meta( get_current_user_id(), self::META, true );

		foreach ( $this->collect() as $notice ) {
			if ( ! empty( $notice['dismissible'] ) && in_array( $notice['key'], $hidden, true ) ) {
				continue;
			}

			$actions = '';

			if ( ! empty( $notice['resume'] ) ) {
				$actions = sprintf(
					'<form method="post" action="%1$s" style="display:inline"><input type="hidden" name="action" value="%2$s">%3$s <button type="submit" class="button button-primary">%4$s</button></form>',
					esc_url( admin_url( 'admin-post.php' ) ),
					esc_attr( self::ACTION_RESUME ),
					wp_nonce_field( self::ACTION_RESUME, '_wpnonce', true, false ),
					esc_html__( 'Resume', 'trust-optimize' )
				);
			}

			if ( ! empty( $notice['dismissible'] ) ) {
				$url      = wp_nonce_url(
					add_query_arg(
						array(
							'action' => self::ACTION_DISMISS,
							'notice' => $notice['key'],
						),
						admin_url( 'admin-post.php' )
					),
					self::ACTION_DISMISS
				);
				$actions .= sprintf( ' <a href="%s">%s</a>', esc_url( $url ), esc_html__( 'Dismiss', 'trust-optimize' ) );
			}

			// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- The message and the actions are built from escaped parts above.
			printf(
				'<div class="notice notice-%1$s"><p><strong>TrustOptimize:</strong> %2$s</p>%3$s</div>',
				esc_attr( $notice['type'] ),
				$notice['message'],
				'' === $actions ? '' : '<p>' . $actions . '</p>'
			);
			// phpcs:enable
		}
	}

	/**
	 * Hide a notice for the current user.
	 */
	public function handle_dismiss() {
		check_admin_referer( self::ACTION_DISMISS );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'trust-optimize' ), '', array( 'response' => 403 ) );
		}

		$key = isset( $_GET['notice'] ) ? sanitize_text_field( wp_unslash( $_GET['notice'] ) ) : '';

		if ( '' !== $key ) {
			$hidden   = array_filter( (array) get_user_meta( get_current_user_id(), self::META, true ) );
			$hidden[] = $key;
			update_user_meta( get_current_user_id(), self::META, array_values( array_unique( $hidden ) ) );
		}

		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
		exit;
	}

	/**
	 * Continue the bulk job that was paused because the disk is nearly full.
	 */
	public function handle_resume() {
		check_admin_referer( self::ACTION_RESUME );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'trust-optimize' ), '', array( 'response' => 403 ) );
		}

		$job = $this->paused_for_disk();

		if ( $job ) {
			$this->producer->resume( $job['id'] );
		}

		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=trust-optimize' ) );
		exit;
	}

	/**
	 * The notices that apply now.
	 *
	 * @return array[] Each with key, type, message (limited HTML) and optionally dismissible and resume.
	 */
	private function collect() {
		$notices = array();

		if ( $this->migration->is_running() ) {
			list( $position, $total ) = $this->migration->get_progress();

			$notices[] = array(
				'key'         => 'migration',
				'type'        => 'info',
				'dismissible' => true,
				'message'     => esc_html(
					sprintf(
						/* translators: 1: current migration step, 2: number of steps. */
						__( 'Migrating data from the previous version in the background (step %1$d of %2$d). Optimized images keep being served meanwhile.', 'trust-optimize' ),
						$position,
						$total
					)
				),
			);
		}

		$conflicts = count( $this->conflicts->all() );

		if ( $conflicts > 0 && ! $this->is_dashboard() ) {
			$notices[] = array(
				'key'         => 'conflicts:' . $conflicts,
				'type'        => 'warning',
				'dismissible' => true,
				'message'     => sprintf(
					/* translators: 1: number of files, 2: link to the plugin page. */
					esc_html( _n( '%1$s file of the previous version is shared with another attachment and was not touched. Restore the original from a backup if it was overwritten. See %2$s.', '%1$s files of the previous version are shared with other attachments and were not touched. Restore the originals from a backup if they were overwritten. See %2$s.', $conflicts, 'trust-optimize' ) ),
					esc_html( number_format_i18n( $conflicts ) ),
					'<a href="' . esc_url( admin_url( 'admin.php?page=trust-optimize' ) ) . '">' . esc_html__( 'the list', 'trust-optimize' ) . '</a>'
				),
			);
		}

		$paused = $this->paused_for_disk();

		if ( $paused ) {
			$notices[] = array(
				'key'     => 'disk:' . $paused['id'],
				'type'    => 'warning',
				'resume'  => true,
				'message' => esc_html__( 'The bulk job is paused because the disk is almost full. Free some space, then resume the job.', 'trust-optimize' ),
			);
		}

		foreach ( $this->capabilities->downgrades() as $format => $note ) {
			$notices[] = array(
				'key'         => 'downgrade:' . $format . ':' . $note['at'],
				'type'        => 'warning',
				'dismissible' => true,
				'message'     => esc_html(
					sprintf(
						/* translators: 1: format name such as AVIF, 2: error reported by the image editor. */
						__( '%1$s files are no longer created: the image editor failed to write one (%2$s). Settings > "Re-check format support" tries again.', 'trust-optimize' ),
						strtoupper( $format ),
						$note['reason']
					)
				),
			);
		}

		return $notices;
	}

	/**
	 * The active bulk job if it is paused for lack of disk space.
	 *
	 * @return array|null
	 */
	private function paused_for_disk() {
		$job = $this->jobs->get_active_job();
		$job = $job ? $job->to_array() : null;

		if ( $job && JobStatus::PAUSED === $job['status'] && BulkProducer::REASON_LOW_DISK === $job['last_error'] ) {
			return $job;
		}

		return null;
	}

	/**
	 * Whether the current screen is the plugin page that lists the conflicts in full.
	 *
	 * @return bool
	 */
	private function is_dashboard() {
		$screen = get_current_screen();

		return $screen && 'toplevel_page_trust-optimize' === $screen->id;
	}
}
