<?php
/**
 * The main plugin class
 *
 * @package TrustOptimize
 */

namespace TrustOptimize\Core;

use TrustOptimize\Admin\Admin;
use TrustOptimize\Frontend\Frontend;
use TrustOptimize\Features\Optimization\ImageProcessor;
use TrustOptimize\Features\Optimization\ImageConverter;
use TrustOptimize\Admin\Settings;

/**
 * Class Plugin
 */
class Plugin {

	/**
	 * The single instance of the class.
	 *
	 * @var Plugin|null
	 */
	protected static $instance = null;

	/**
	 * The loader that's responsible for maintaining and registering all hooks.
	 *
	 * @var Loader
	 */
	protected $loader;

	/**
	 * Admin class instance.
	 *
	 * @var Admin
	 */
	public $admin;

	/**
	 * Frontend class instance.
	 *
	 * @var Frontend
	 */
	public $frontend;

	/**
	 * Image processor instance.
	 *
	 * @var ImageProcessor
	 */
	public $image_processor;

	/**
	 * Image converter instance.
	 *
	 * @var ImageConverter
	 */
	public $image_converter;

	/**
	 * Settings class instance.
	 *
	 * @var Settings
	 */
	public $settings;

	/**
	 * Plugin constructor.
	 */
	public function __construct() {
		$this->loader = new Loader();
	}

	/**
	 * Get the single instance of the plugin.
	 *
	 * @return Plugin
	 */
	public static function get_instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Initialize the plugin.
	 */
	public function init() {
		// Load dependencies
		$this->load_dependencies();

		// Register hooks
		$this->register_hooks();

		// Run the loader to register all hooks with WordPress
		$this->loader->run();
	}

	/**
	 * Load the required dependencies.
	 */
	private function load_dependencies() {
		// Initialize admin class
		$this->admin = new Admin();

		// Initialize frontend class
		$this->frontend = new Frontend();

		// Initialize image processor
		$this->image_processor = new ImageProcessor();

		// Initialize image converter
		$this->image_converter = new ImageConverter();

		// Initialize settings
		$this->settings = new Settings();
	}

	/**
	 * Register all hooks.
	 */
	private function register_hooks() {
		// Filter to replace image src with optimized version (frontend processing)
		$this->loader->add_filter( 'the_content', $this->image_processor, 'process_content', 999 );

		// Filter for post thumbnails (frontend processing)
		$this->loader->add_filter( 'post_thumbnail_html', $this->image_processor, 'process_thumbnail', 999 );

		// Hook for generating WebP on image upload (backend conversion)
		$this->loader->add_filter( 'wp_generate_attachment_metadata', $this->image_converter, 'generate_webp_on_upload', 10, 2 );
	}
}
