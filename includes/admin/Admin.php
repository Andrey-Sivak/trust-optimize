<?php
/**
 * Admin interface class
 *
 * @package TrustOptimize
 */

namespace TrustOptimize\Admin;

use TrustOptimize\Bulk\EligibilityQuery;
use TrustOptimize\Capabilities\CapabilityService;
use TrustOptimize\Bulk\BulkProducer;
use TrustOptimize\Domain\AttachmentState;
use TrustOptimize\Health\SiteHealth;
use TrustOptimize\Settings\OptimizationSettings;
use TrustOptimize\Storage\AttachmentRepository;
use TrustOptimize\Storage\VariantRepository;

/**
 * Class Admin
 */
class Admin {

	/**
	 * Plugin settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Attachment repository.
	 *
	 * @var AttachmentRepository
	 */
	private $attachments;

	/**
	 * Eligibility query.
	 *
	 * @var EligibilityQuery
	 */
	private $eligibility;

	/**
	 * Capability service.
	 *
	 * @var CapabilityService
	 */
	private $capabilities;

	/**
	 * Variant repository.
	 *
	 * @var VariantRepository
	 */
	private $variants;

	/**
	 * Statistics.
	 *
	 * @var Statistics
	 */
	private $statistics;

	/**
	 * States of the attachments on the current media library page, keyed by ID (empty elsewhere).
	 *
	 * @var string[]
	 */
	private $states = array();

	/**
	 * Site Health tests.
	 *
	 * @var SiteHealth
	 */
	private $health;

	/**
	 * Admin constructor.
	 *
	 * @param Settings             $settings     Plugin settings.
	 * @param AttachmentRepository $attachments  Attachment repository.
	 * @param EligibilityQuery     $eligibility  Eligibility query.
	 * @param CapabilityService    $capabilities Capability service.
	 * @param VariantRepository    $variants     Variant repository.
	 * @param Statistics           $statistics   Statistics.
	 * @param SiteHealth           $health       Site Health tests.
	 */
	public function __construct( Settings $settings, AttachmentRepository $attachments, EligibilityQuery $eligibility, CapabilityService $capabilities, VariantRepository $variants, Statistics $statistics, SiteHealth $health ) {
		$this->settings     = $settings;
		$this->attachments  = $attachments;
		$this->eligibility  = $eligibility;
		$this->capabilities = $capabilities;
		$this->variants     = $variants;
		$this->statistics   = $statistics;
		$this->health       = $health;
	}

	/**
	 * Register the admin hooks.
	 */
	public function register() {
		// Hook into WordPress admin
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_post_trust_optimize_recheck_capabilities', array( $this, 'handle_recheck_capabilities' ) );
		add_action( 'admin_post_trust_optimize_reset', array( $this, 'handle_reset' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );

		// Add plugin action links
		add_filter( 'plugin_action_links_' . TRUST_OPTIMIZE_PLUGIN_BASENAME, array( $this, 'add_action_links' ) );
		add_action( 'after_plugin_row_' . TRUST_OPTIMIZE_PLUGIN_BASENAME, array( $this, 'render_uninstall_warning' ) );

		// Add optimization status column to Media Library
		add_filter( 'manage_media_columns', array( $this, 'add_media_columns' ) );
		add_action( 'manage_media_custom_column', array( $this, 'render_media_column' ), 10, 2 );
		add_filter( 'the_posts', array( $this, 'prime_media_states' ), 10, 2 );
	}

	/**
	 * Add menu items to WordPress admin.
	 */
	public function add_admin_menu() {
		add_menu_page(
			__( 'TrustOptimize', 'trust-optimize' ),
			__( 'TrustOptimize', 'trust-optimize' ),
			'manage_options',
			'trust-optimize',
			array( $this, 'display_admin_page' ),
			'dashicons-visibility',
			30
		);

		add_submenu_page(
			'trust-optimize',
			__( 'Settings', 'trust-optimize' ),
			__( 'Settings', 'trust-optimize' ),
			'manage_options',
			'trust-optimize-settings',
			array( $this, 'display_settings_page' )
		);
	}

	/**
	 * Display the main admin page.
	 */
	public function display_admin_page() {
		$trust_optimize_total_eligible = $this->eligibility->count_eligible_attachments();
		$trust_optimize_webp_supported = $this->capabilities->supports( 'webp' );
		$trust_optimize_avif_supported = $this->capabilities->supports( 'avif' );
		$trust_optimize_stats          = $this->statistics->get();
		$trust_optimize_overdue        = $this->health->test_overdue_tasks();
		$trust_optimize_max_pending    = BulkProducer::max_pending();

		require_once TRUST_OPTIMIZE_PLUGIN_DIR . 'templates/admin/admin-page.php';
	}

	/**
	 * Display the settings page.
	 */
	public function display_settings_page() {
		require_once TRUST_OPTIMIZE_PLUGIN_DIR . 'templates/admin/settings-page.php';
	}

	/**
	 * Register plugin settings.
	 */
	public function register_settings() {
		register_setting(
			'trust_optimize_settings',
			'trust_optimize_options',
			array( 'sanitize_callback' => array( $this->settings, 'sanitize' ) )
		);

		add_settings_section(
			'trust_optimize_general_section',
			__( 'General Settings', 'trust-optimize' ),
			array( $this, 'render_general_section' ),
			'trust_optimize_settings'
		);

		foreach ( $this->get_fields() as $key => $field ) {
			add_settings_field(
				$key,
				$field['label'],
				array( $this, 'render_field' ),
				'trust_optimize_settings',
				'trust_optimize_general_section',
				array_merge(
					$field,
					array(
						'key'       => $key,
						'label_for' => $key,
					)
				)
			);
		}
	}

	/**
	 * The settings shown on the page.
	 *
	 * @return array[] Field definitions keyed by option key.
	 */
	private function get_fields() {
		$fields = array(
			'enable_adaptive_images' => array(
				'label'       => __( 'Serve optimized images', 'trust-optimize' ),
				'type'        => 'checkbox',
				'description' => __( 'Wrap images in a picture element that offers the generated WebP and AVIF files.', 'trust-optimize' ),
			),
		);

		foreach ( array_keys( OptimizationSettings::FORMAT_OPTIONS ) as $format ) {
			$fields[ 'convert_to_' . $format ] = array(
				/* translators: %s: format name, e.g. WebP. */
				'label'       => sprintf( __( 'Create %s', 'trust-optimize' ), strtoupper( $format ) ),
				'type'        => 'checkbox',
				'disabled'    => ! $this->capabilities->supports( $format ),
				'description' => $this->capabilities->supports( $format )
					? ''
					/* translators: %s: format name, e.g. WebP. */
					: sprintf( __( 'This server cannot write %s files.', 'trust-optimize' ), strtoupper( $format ) ),
			);
		}

		foreach ( Settings::QUALITY_KEYS as $key ) {
			$fields[ $key ] = array(
				/* translators: %s: format name, e.g. WebP. */
				'label'       => sprintf( __( '%s quality', 'trust-optimize' ), strtoupper( strtok( $key, '_' ) ) ),
				'type'        => 'number',
				'min'         => 1,
				'max'         => 100,
				'description' => __( 'From 1 to 100. Lower values give smaller files; a changed value applies to images converted from now on.', 'trust-optimize' ),
			);
		}

		return $fields + array(
			'force_lazy'               => array(
				'label'       => __( 'Force lazy loading', 'trust-optimize' ),
				'type'        => 'checkbox',
				'description' => __( 'Add loading="lazy" to images that have no loading attribute. Off by default so that the largest image of a page is not delayed.', 'trust-optimize' ),
			),
			'max_pixels'               => array(
				'label'       => __( 'Largest image to convert (pixels)', 'trust-optimize' ),
				'type'        => 'number',
				'min'         => 1,
				'description' => __( 'Width times height. Larger images are skipped to protect the server memory.', 'trust-optimize' ),
			),
			'min_free_disk'            => array(
				'label'       => __( 'Minimum free disk space (MB)', 'trust-optimize' ),
				'type'        => 'number',
				'min'         => 0,
				'description' => __( 'Conversion pauses below this value. 0 keeps the automatic value: 1 GB or 5% of the disk, whichever is larger.', 'trust-optimize' ),
			),
			'remove_data_on_uninstall' => array(
				'label'       => __( 'Remove data on uninstall', 'trust-optimize' ),
				'type'        => 'checkbox',
				'description' => __( 'Delete the generated files and the plugin data when the plugin is deleted. Originals are never deleted.', 'trust-optimize' ),
			),
		);
	}

	/**
	 * Render the general settings section.
	 */
	public function render_general_section() {
		echo '<p>' . esc_html__( 'Configure general settings for TrustOptimize.', 'trust-optimize' ) . '</p>';
	}

	/**
	 * Render one settings field.
	 *
	 * A disabled checkbox is not submitted, so its stored value is carried in a hidden input.
	 *
	 * @param array $args Field definition with the option key.
	 */
	public function render_field( $args ) {
		$key   = $args['key'];
		$value = $this->settings->get( $key );
		$name  = 'trust_optimize_options[' . $key . ']';

		if ( 'checkbox' === $args['type'] ) {
			$disabled = ! empty( $args['disabled'] );

			if ( $disabled ) {
				printf( '<input type="hidden" name="%s" value="%d">', esc_attr( $name ), (int) $value );
			}

			printf(
				'<input type="checkbox" id="%1$s" name="%2$s" value="1" %3$s %4$s>',
				esc_attr( $key ),
				esc_attr( $name ),
				checked( 1, (int) $value, false ),
				disabled( $disabled, true, false )
			);
		} else {
			printf(
				'<input type="number" id="%1$s" name="%2$s" value="%3$d" min="%4$d" %5$s class="regular-text">',
				esc_attr( $key ),
				esc_attr( $name ),
				(int) $value,
				(int) $args['min'],
				isset( $args['max'] ) ? 'max="' . (int) $args['max'] . '"' : ''
			);
		}

		if ( '' !== $args['description'] ) {
			echo '<p class="description">' . esc_html( $args['description'] ) . '</p>';
		}
	}

	/**
	 * Re-detect the supported formats and return to the settings page.
	 */
	public function handle_recheck_capabilities() {
		$this->authorize( 'trust_optimize_recheck_capabilities' );

		$this->capabilities->recheck();

		$this->redirect_to_settings( 'rechecked' );
	}

	/**
	 * Restore the default settings and return to the settings page.
	 */
	public function handle_reset() {
		$this->authorize( 'trust_optimize_reset' );

		$this->settings->reset();

		$this->redirect_to_settings( 'reset' );
	}

	/**
	 * Check the nonce and the capability of an admin-post request.
	 *
	 * @param string $action Nonce action.
	 */
	private function authorize( $action ) {
		check_admin_referer( $action );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'trust-optimize' ), '', array( 'response' => 403 ) );
		}
	}

	/**
	 * Go back to the settings page with a notice.
	 *
	 * @param string $notice Notice key, see templates/admin/settings-page.php.
	 */
	private function redirect_to_settings( $notice ) {
		wp_safe_redirect( admin_url( 'admin.php?page=trust-optimize-settings&trust_optimize_notice=' . $notice ) );
		exit;
	}

	/**
	 * Enqueue admin scripts and styles.
	 *
	 * @param string $hook The current admin page.
	 */
	public function enqueue_admin_scripts( $hook ) {
		// Only enqueue on our plugin pages
		if ( strpos( $hook, 'trust-optimize' ) !== false ) {
			wp_enqueue_style(
				'trust-optimize-admin',
				TRUST_OPTIMIZE_PLUGIN_URL . 'assets/css/admin.css',
				array(),
				TRUST_OPTIMIZE_VERSION
			);

			wp_enqueue_script(
				'trust-optimize-admin',
				TRUST_OPTIMIZE_PLUGIN_URL . 'assets/js/admin.js',
				array( 'jquery', 'wp-api-fetch' ),
				TRUST_OPTIMIZE_VERSION,
				true
			);

			wp_localize_script(
				'trust-optimize-admin',
				'trustOptimizeAdmin',
				array(
					'restUrl' => rest_url( 'trust-optimize/v1/' ),
					'nonce'   => wp_create_nonce( 'wp_rest' ),
					'i18n'    => array(
						'finishing'     => __( 'Finishing…', 'trust-optimize' ),
						'confirmReset'  => __( 'Are you sure you want to reset all settings to defaults?', 'trust-optimize' ),
						'confirmRemove' => __( 'Remove all TrustOptimize-generated files? Originals and WordPress thumbnails will be preserved.', 'trust-optimize' ),
						'confirmCancel' => __( 'Cancel the active bulk job? Already processed files will not be rolled back.', 'trust-optimize' ),
					),
				)
			);
		}

		// Enqueue media status polling script on the Media Library page
		if ( 'upload.php' === $hook ) {
			wp_enqueue_script(
				'trust-optimize-media-status',
				TRUST_OPTIMIZE_PLUGIN_URL . 'assets/js/media-status.js',
				array( 'wp-api-fetch' ),
				TRUST_OPTIMIZE_VERSION,
				true
			);

			wp_localize_script( 'trust-optimize-media-status', 'trustOptimizeMedia', array( 'states' => $this->get_state_presentation() ) );
		}
	}

	/**
	 * Add plugin action links.
	 *
	 * @param array $links The existing action links.
	 * @return array
	 */
	public function add_action_links( $links ) {
		$plugin_links = array(
			'<a href="' . admin_url( 'admin.php?page=trust-optimize-settings' ) . '">' . __( 'Settings', 'trust-optimize' ) . '</a>',
		);

		return array_merge( $plugin_links, $links );
	}

	/**
	 * Warn in the plugin list that deleting the plugin would keep its data.
	 *
	 * Shown when the plugin is set to remove its data on uninstall but generated files are still registered:
	 * uninstall removes files only within its time limit and otherwise keeps the registry (M-3).
	 */
	public function render_uninstall_warning() {
		if ( ! current_user_can( 'manage_options' ) || ! $this->settings->get( 'remove_data_on_uninstall' ) ) {
			return;
		}

		$remaining = $this->variants->count_removable();

		if ( 0 === $remaining ) {
			return;
		}

		printf(
			'<tr class="plugin-update-tr active"><td colspan="%1$d" class="plugin-update colspanchange"><div class="update-message notice inline notice-warning notice-alt"><p>%2$s</p></div></td></tr>',
			(int) _get_list_table( 'WP_Plugins_List_Table' )->get_column_count(),
			wp_kses(
				sprintf(
					/* translators: 1: number of generated files, 2: link to the plugin page. */
					_n(
						'%1$s generated file is still registered. Before deleting the plugin, remove it on the %2$s page; otherwise deleting the plugin may leave it and the plugin data in place.',
						'%1$s generated files are still registered. Before deleting the plugin, remove them on the %2$s page; otherwise deleting the plugin may leave them and the plugin data in place.',
						$remaining,
						'trust-optimize'
					),
					number_format_i18n( $remaining ),
					'<a href="' . esc_url( admin_url( 'admin.php?page=trust-optimize#media-library' ) ) . '">' . esc_html__( 'TrustOptimize', 'trust-optimize' ) . '</a>'
				),
				array( 'a' => array( 'href' => array() ) )
			)
		);
	}

	/**
	 * Add custom column to the Media Library list table.
	 *
	 * @param array $columns The existing columns.
	 * @return array
	 */
	public function add_media_columns( $columns ) {
		$columns['trust_optimize_status'] = __( 'Optimization', 'trust-optimize' );
		return $columns;
	}

	/**
	 * Load the states of the attachments on the media library page in one query.
	 *
	 * @param \WP_Post[] $posts Posts of the query.
	 * @param \WP_Query  $query The query.
	 * @return \WP_Post[]
	 */
	public function prime_media_states( $posts, $query ) {
		if ( ! $query->is_main_query() || ! is_admin() || 'upload.php' !== ( $GLOBALS['pagenow'] ?? '' ) ) {
			return $posts;
		}

		$this->states = $this->attachments->get_states( wp_list_pluck( (array) $posts, 'ID' ) );

		return $posts;
	}

	/**
	 * How each attachment state is shown, for the media column and for its polling script.
	 *
	 * @return array[] Label, dashicon classes (empty for none) and colour per AttachmentState.
	 */
	private function get_state_presentation() {
		$waiting = array(
			'label' => __( 'In progress', 'trust-optimize' ),
			'icon'  => 'dashicons-update spin',
			'color' => '#f0b849',
		);

		return array(
			AttachmentState::NONE       => array(
				'label' => __( 'Not processed', 'trust-optimize' ),
				'icon'  => '',
				'color' => '',
			),
			AttachmentState::SKIPPED    => array(
				'label' => __( 'Not processed', 'trust-optimize' ),
				'icon'  => '',
				'color' => '',
			),
			AttachmentState::QUEUED     => $waiting,
			AttachmentState::PROCESSING => $waiting,
			AttachmentState::OPTIMIZED  => array(
				'label' => __( 'Optimized', 'trust-optimize' ),
				'icon'  => 'dashicons-yes-alt',
				'color' => '#46b450',
			),
			AttachmentState::PARTIAL    => array(
				'label' => __( 'Partially optimized', 'trust-optimize' ),
				'icon'  => 'dashicons-warning',
				'color' => '#dba617',
			),
			AttachmentState::FAILED     => array(
				'label' => __( 'Failed', 'trust-optimize' ),
				'icon'  => 'dashicons-warning',
				'color' => '#dc3232',
			),
		);
	}

	/**
	 * State of an attachment: from the preloaded page when there is one.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string AttachmentState.
	 */
	private function state_of( $attachment_id ) {
		return $this->states[ $attachment_id ] ?? $this->attachments->get_state( $attachment_id );
	}

	/**
	 * Render the content of the custom media column.
	 *
	 * @param string $column_name The column name.
	 * @param int    $attachment_id The attachment ID.
	 */
	public function render_media_column( $column_name, $attachment_id ) {
		if ( 'trust_optimize_status' !== $column_name ) {
			return;
		}

		// Only show for image attachments
		if ( ! wp_attachment_is_image( $attachment_id ) ) {
			echo '<span class="dashicons dashicons-minus" title="' . esc_attr__( 'Not an image', 'trust-optimize' ) . '"></span>';
			return;
		}

		$state        = $this->state_of( $attachment_id );
		$presentation = $this->get_state_presentation()[ $state ] ?? $this->get_state_presentation()[ AttachmentState::NONE ];
		$polling      = in_array( $state, array( AttachmentState::QUEUED, AttachmentState::PROCESSING ), true );

		$class = $polling ? 'trust-optimize-status trust-optimize-polling' : 'trust-optimize-status';
		$style = '' === $presentation['color'] ? '' : ' style="color:' . esc_attr( $presentation['color'] ) . ';"';
		$icon  = '' === $presentation['icon'] ? '' : '<span class="dashicons ' . esc_attr( $presentation['icon'] ) . '"></span> ';

		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- $style and $icon are escaped above.
		printf(
			'<span class="%1$s" data-status="%2$s" data-attachment-id="%3$d"%4$s>%5$s%6$s</span>',
			esc_attr( $class ),
			esc_attr( $state ),
			(int) $attachment_id,
			$style,
			$icon,
			esc_html( $presentation['label'] )
		);
		// phpcs:enable
	}
}
