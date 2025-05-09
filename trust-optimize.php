<?php
/**
 * TrustOptimize
 *
 * @package           TrustOptimize
 * @author            Andrii Sivak
 * @copyright         2025 Andrii Sivak
 * @license           GPL-2.0-or-later
 *
 * @wordpress-plugin
 * Plugin Name:       TrustOptimize
 * Plugin URI:        https://github.com/Andrey-Sivak/trust-optimize
 * Description:       Advanced media optimization for WordPress. Dynamically resizes images based on visitor's device and viewport.
 * Version:           1.0.0
 * Requires at least: 5.6
 * Requires PHP:      7.4
 * Author:            Andrii Sivak
 * Author URI:        https://github.com/Andrey-Sivak
 * Text Domain:       trust-optimize
 * Domain Path:       /languages
 * License:           GPL v2 or later
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 */

use TrustOptimize\API\RestController;
use TrustOptimize\Core\Plugin;

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

// Define plugin constants
define( 'TRUST_OPTIMIZE_VERSION', '1.0.0' );
define( 'TRUST_OPTIMIZE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'TRUST_OPTIMIZE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'TRUST_OPTIMIZE_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

// Use composer autoloader
if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
	require_once __DIR__ . '/vendor/autoload.php';
}

// Activation and deactivation hooks
register_activation_hook( __FILE__, 'trust_optimize_activate' );
register_deactivation_hook( __FILE__, 'trust_optimize_deactivate' );

/**
 * The code that runs during plugin activation.
 */
function trust_optimize_activate() {
	// Activation tasks like creating tables, setting default options, etc.
}

/**
 * The code that runs during plugin deactivation.
 */
function trust_optimize_deactivate() {
	// Deactivation tasks like cleaning up options, etc.
}

/**
 * Initialize the plugin.
 */
function trust_optimize_init() {
	// Load text domain for internationalization
	load_plugin_textdomain( 'trust-optimize', false, dirname( TRUST_OPTIMIZE_PLUGIN_BASENAME ) . '/languages' );

	// Check if the class exists before trying to use it
	if ( class_exists( 'TrustOptimize\\Core\\Plugin' ) ) {
		// Initialize the main plugin class
		$plugin = Plugin::get_instance();
		$plugin->init();
	} else {
		// Add admin notice if class doesn't exist
		add_action( 'admin_notices', 'trust_optimize_missing_class_notice' );
	}
}
add_action( 'plugins_loaded', 'trust_optimize_init' );

/**
 * Admin notice when main class is missing
 */
function trust_optimize_missing_class_notice() {
	echo '<div class="error"><p>';
	echo '<strong>TrustOptimize Error:</strong> Main plugin class not found. Please reinstall the plugin or contact support.';
	echo '</p></div>';
}

// If composer autoload isn't available or if it fails to load the class
if ( ! class_exists( 'TrustOptimize\\Core\\Plugin' ) ) {
	// Manually include the class files
	require_once TRUST_OPTIMIZE_PLUGIN_DIR . 'includes/core/Loader.php';
	require_once TRUST_OPTIMIZE_PLUGIN_DIR . 'includes/core/Plugin.php';
	require_once TRUST_OPTIMIZE_PLUGIN_DIR . 'includes/features/optimization/OptimizerInterface.php';
	require_once TRUST_OPTIMIZE_PLUGIN_DIR . 'includes/features/optimization/ImageProcessor.php';
}
