<?php
/**
 * Admin interface class
 *
 * @package TrustOptimize
 */

namespace TrustOptimize\Admin;

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
	 * Validate settings before saving.
	 *
	 * @param array $input The input array to validate.
	 * @return array
	 */
	public function validate_settings( $input ) {
		$output = array();

		// Validate enable_adaptive_images
		$output['enable_adaptive_images'] = isset( $input['enable_adaptive_images'] ) ? 1 : 0;

		return $output;
	}

	/**
	 * Enqueue admin scripts and styles.
	 *
	 * @param string $hook The current admin page.
	 */
	public function enqueue_admin_scripts( $hook ) {
		// Only enqueue on our plugin pages
		if ( strpos( $hook, 'trust-optimize' ) === false ) {
			return;
		}

		wp_enqueue_style(
			'trust-optimize-admin',
			TRUST_OPTIMIZE_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			TRUST_OPTIMIZE_VERSION
		);

		wp_enqueue_script(
			'trust-optimize-admin',
			TRUST_OPTIMIZE_PLUGIN_URL . 'assets/js/admin.js',
			array( 'jquery' ),
			TRUST_OPTIMIZE_VERSION,
			true
		);
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
}
