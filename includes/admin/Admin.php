<?php
/**
 * Admin interface class
 *
 * @package TrustOptimize
 */

namespace TrustOptimize\Admin;

use TrustOptimize\Database\ImageModel;

/**
 * Class Admin
 */
class Admin {

	/**
	 * Admin constructor.
	 */
	public function __construct() {
		// Hook into WordPress admin
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );

		// Add plugin action links
		add_filter( 'plugin_action_links_' . TRUST_OPTIMIZE_PLUGIN_BASENAME, array( $this, 'add_action_links' ) );

		// Add optimization status column to Media Library
		add_filter( 'manage_media_columns', array( $this, 'add_media_columns' ) );
		add_action( 'manage_media_custom_column', array( $this, 'render_media_column' ), 10, 2 );
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
			array( $this, 'validate_settings' )
		);

		add_settings_section(
			'trust_optimize_general_section',
			__( 'General Settings', 'trust-optimize' ),
			array( $this, 'render_general_section' ),
			'trust_optimize_settings'
		);

		add_settings_field(
			'enable_adaptive_images',
			__( 'Enable Adaptive Images', 'trust-optimize' ),
			array( $this, 'render_enable_adaptive_images_field' ),
			'trust_optimize_settings',
			'trust_optimize_general_section'
		);

		foreach ( array( 'webp_quality', 'avif_quality', 'jpeg_quality' ) as $quality_field ) {
			add_settings_field(
				$quality_field,
				$this->get_quality_label( $quality_field ),
				array( $this, 'render_quality_field' ),
				'trust_optimize_settings',
				'trust_optimize_general_section',
				array( 'key' => $quality_field )
			);
		}
	}

	/**
	 * Render the general settings section.
	 */
	public function render_general_section() {
		echo '<p>' . esc_html__( 'Configure general settings for TrustOptimize.', 'trust-optimize' ) . '</p>';
	}

	/**
	 * Render the enable adaptive images field.
	 */
	public function render_enable_adaptive_images_field() {
		$options = get_option( 'trust_optimize_options', array() );
		$enabled = isset( $options['enable_adaptive_images'] ) ? $options['enable_adaptive_images'] : 1;

		echo '<input type="checkbox" id="enable_adaptive_images" name="trust_optimize_options[enable_adaptive_images]" value="1" ' . checked( 1, $enabled, false ) . '>';
		echo '<label for="enable_adaptive_images">' . esc_html__( 'Enable adaptive images feature', 'trust-optimize' ) . '</label>';
	}

	/**
	 * Render a quality field.
	 *
	 * @param array $args Field args.
	 */
	public function render_quality_field( $args ) {
		$key      = isset( $args['key'] ) ? $args['key'] : '';
		$settings = new Settings();
		$options  = $settings->get_all();
		$defaults = $settings->get_defaults();
		$value    = isset( $options[ $key ] ) ? (int) $options[ $key ] : ( isset( $defaults[ $key ] ) ? (int) $defaults[ $key ] : 85 );

		printf(
			'<input type="number" min="1" max="100" id="%1$s" name="trust_optimize_options[%1$s]" value="%2$d" class="small-text">',
			esc_attr( $key ),
			(int) $value
		);
		echo '<p class="description">' . esc_html__( 'Lower values reduce file size and CPU work during bulk jobs; higher values preserve more detail but produce larger files.', 'trust-optimize' ) . '</p>';
	}

	/**
	 * Get label for a quality field.
	 *
	 * @param string $key Field key.
	 * @return string
	 */
	private function get_quality_label( $key ) {
		$labels = array(
			'webp_quality' => __( 'WebP Quality', 'trust-optimize' ),
			'avif_quality' => __( 'AVIF Quality', 'trust-optimize' ),
			'jpeg_quality' => __( 'JPEG Fallback Quality', 'trust-optimize' ),
		);

		return isset( $labels[ $key ] ) ? $labels[ $key ] : $key;
	}

	/**
	 * Validate settings before saving.
	 *
	 * @param array $input The input array to validate.
	 * @return array
	 */
	public function validate_settings( $input ) {
		$settings = new Settings();
		$existing = $settings->get_all();
		$output   = is_array( $existing ) ? $existing : array();

		// Validate enable_adaptive_images
		$output['enable_adaptive_images'] = isset( $input['enable_adaptive_images'] ) ? 1 : 0;

		foreach ( array( 'webp_quality', 'avif_quality', 'jpeg_quality', 'image_quality' ) as $quality_key ) {
			if ( ! isset( $input[ $quality_key ] ) ) {
				continue;
			}

			$output[ $quality_key ] = max( 1, min( 100, (int) $input[ $quality_key ] ) );
		}

		return $output;
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
				array( 'jquery', 'wp-api-fetch' ),
				TRUST_OPTIMIZE_VERSION,
				true
			);

			wp_localize_script(
				'trust-optimize-media-status',
				'trustOptimizeMedia',
				array(
					'restUrl' => rest_url( 'trust-optimize/v1/' ),
					'nonce'   => wp_create_nonce( 'wp_rest' ),
				)
			);
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

		$image_model = new ImageModel();
		$status_data = $image_model->get_status( $attachment_id );

		if ( ! $status_data ) {
			echo '<span class="trust-optimize-status" data-status="none">' . esc_html__( 'Not processed', 'trust-optimize' ) . '</span>';
			return;
		}

		$status    = $status_data['status'];
		$total     = (int) $status_data['total_tasks'];
		$completed = (int) $status_data['completed_tasks'];
		$progress  = $total > 0 ? round( ( $completed / $total ) * 100 ) : 100;

		switch ( $status ) {
			case 'completed':
				echo '<span class="trust-optimize-status" data-status="completed" style="color:#46b450;">'
					. '<span class="dashicons dashicons-yes-alt"></span> '
					. esc_html__( 'Optimized', 'trust-optimize' )
					. '</span>';
				break;

			case 'pending':
			case 'processing':
				echo '<span class="trust-optimize-status trust-optimize-polling" data-status="' . esc_attr( $status ) . '" data-attachment-id="' . esc_attr( $attachment_id ) . '" style="color:#f0b849;">'
					. '<span class="dashicons dashicons-update spin"></span> '
					. esc_html( sprintf( '%d%%', $progress ) )
					. '</span>';
				break;

			case 'failed':
				echo '<span class="trust-optimize-status" data-status="failed" style="color:#dc3232;">'
					. '<span class="dashicons dashicons-warning"></span> '
					. esc_html__( 'Failed', 'trust-optimize' )
					. '</span>';
				break;

			default:
				echo '<span class="trust-optimize-status" data-status="unknown">' . esc_html( $status ) . '</span>';
		}
	}
}
