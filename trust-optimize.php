<?php
/**
 * TrustOptimize
 *
 * @package           TrustOptimize
 * @author            Andrii Sivak
 * @copyright         2026 Andrii Sivak
 * @license           GPL-2.0-or-later
 *
 * @wordpress-plugin
 * Plugin Name:       TrustOptimize
 * Plugin URI:        https://github.com/Andrey-Sivak/trust-optimize
 * Description:       Converts WordPress image sizes to WebP and AVIF and serves them through picture elements.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Author:            Andrii Sivak
 * Author URI:        https://github.com/Andrey-Sivak
 * Text Domain:       trust-optimize
 * License:           GPL v2 or later
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 */

use TrustOptimize\Admin\Settings;
use TrustOptimize\Capabilities\CapabilityService;
use TrustOptimize\Core\Plugin;
use TrustOptimize\Core\Requirements;
use TrustOptimize\Database\DatabaseManager;
use TrustOptimize\Queue\Lifecycle;

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

// Define plugin constants
define( 'TRUST_OPTIMIZE_VERSION', '1.0.0' );
define( 'TRUST_OPTIMIZE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'TRUST_OPTIMIZE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'TRUST_OPTIMIZE_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Admin notice shown when the plugin was installed without its composer dependencies.
 */
function trust_optimize_missing_dependencies_notice() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	echo '<div class="notice notice-error"><p>';
	echo '<strong>TrustOptimize:</strong> ';
	echo esc_html__( 'The plugin was installed without its dependencies and is disabled. Install the release archive, or run "composer install --no-dev" in the plugin directory.', 'trust-optimize' );
	echo '</p></div>';
}

// The plugin requires its composer dependencies (autoloader and Action Scheduler).
if (
	! file_exists( __DIR__ . '/vendor/autoload.php' )
	|| ! file_exists( __DIR__ . '/vendor/woocommerce/action-scheduler/action-scheduler.php' )
) {
	add_action( 'admin_notices', 'trust_optimize_missing_dependencies_notice' );
	return;
}

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/vendor/woocommerce/action-scheduler/action-scheduler.php';

// Activation and deactivation hooks
register_activation_hook( __FILE__, 'trust_optimize_activate' );
register_deactivation_hook( __FILE__, 'trust_optimize_deactivate' );

/**
 * The code that runs during plugin activation.
 */
function trust_optimize_activate() {
	if ( ! Requirements::is_current_database_supported() ) {
		deactivate_plugins( TRUST_OPTIMIZE_PLUGIN_BASENAME );
		wp_die(
			esc_html( Requirements::database_message() ),
			esc_html__( 'Plugin activation error', 'trust-optimize' ),
			array( 'back_link' => true )
		);
	}

	( new DatabaseManager() )->check_version();

	$capabilities = new CapabilityService();
	$capabilities->recheck();

	( new Settings() )->add_default_settings( $capabilities );
	Lifecycle::activate();
}

/**
 * The code that runs during plugin deactivation.
 */
function trust_optimize_deactivate() {
	Lifecycle::deactivate();
}

/**
 * Initialize the plugin.
 */
function trust_optimize_init() {
	Plugin::get_instance()->init();
}
add_action( 'plugins_loaded', 'trust_optimize_init' );
add_action( 'admin_notices', array( Requirements::class, 'maybe_show_admin_notice' ) );
