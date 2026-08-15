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
use TrustOptimize\Database\DatabaseManager;
use TrustOptimize\Queue\ConversionQueue;
use TrustOptimize\Service\ImageCleanupService;
use TrustOptimize\Bulk\BulkJobRunner;

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
	 * Database manager instance.
	 *
	 * @var DatabaseManager
	 */
	public $db_manager;

	/**
	 * Conversion queue instance.
	 *
	 * @var ConversionQueue
	 */
	public $conversion_queue;

	/**
	 * Bulk job runner instance.
	 *
	 * @var BulkJobRunner
	 */
	public $bulk_runner;

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
		// Initialize database manager
		$this->db_manager = new DatabaseManager();
		$this->db_manager->init();

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

		// Initialize conversion queue (registers Action Scheduler hook)
		$this->conversion_queue = new ConversionQueue( $this->image_converter );
		$this->conversion_queue->init();

		// Initialize bulk runner (registers self-chaining Action Scheduler hook)
		$this->bulk_runner = new BulkJobRunner();
		$this->bulk_runner->init();
	}

	/**
	 * Register all hooks.
	 */
	private function register_hooks() {
		// Filter to replace image src with optimized version (frontend processing)
		$this->loader->add_filter( 'the_content', $this->image_processor, 'process_content', 999 );

		// Filter for post thumbnails (frontend processing)
		$this->loader->add_filter( 'post_thumbnail_html', $this->image_processor, 'process_thumbnail', 999 );

		// Filter for direct wp_get_attachment_image() output (frontend processing)
		$this->loader->add_filter( 'wp_get_attachment_image', $this->image_processor, 'process_attachment_image_html', 999, 5 );

		// Hook for generating WebP on image upload (backend conversion)
		$this->loader->add_filter( 'wp_generate_attachment_metadata', $this->image_converter, 'handle_image_upload', 10, 2 );

		// Hook for add WebP support
		$this->loader->add_filter( 'upload_mimes', $this, 'allow_modern_image_uploads' );

		// Hook for cleaning up image data when an attachment is deleted
		$this->loader->add_action( 'delete_attachment', $this, 'clean_image_data', 10 );
	}

	/**
	 * Allow modern image formats for uploads.
	 *
	 * @param array $mime_types The list of MIME types.
	 * @return array
	 */
	public function allow_modern_image_uploads( $mime_types ) {
		$mime_types['webp'] = 'image/webp';
		$mime_types['avif'] = 'image/avif';
		return $mime_types;
	}

	/**
	 * Clean up image data when an attachment is deleted
	 *
	 * @param int $attachment_id The attachment ID being deleted.
	 */
	public function clean_image_data( $attachment_id ) {
		$cleanup_service = new ImageCleanupService();
		$cleanup_service->cleanup_attachment( $attachment_id );
	}
}
