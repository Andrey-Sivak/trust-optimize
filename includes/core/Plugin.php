<?php
/**
 * The main plugin class
 *
 * @package TrustOptimize
 */

namespace TrustOptimize\Core;

use TrustOptimize\Admin\Admin;
use TrustOptimize\Features\Optimization\ImageProcessor;
use TrustOptimize\Features\Optimization\ImageConverter;
use TrustOptimize\Admin\Settings;
use TrustOptimize\Capabilities\CapabilityService;
use TrustOptimize\Database\DatabaseManager;
use TrustOptimize\Files\AtomicImageWriter;
use TrustOptimize\Planning\VariantPlanner;
use TrustOptimize\Processing\AttachmentProcessor;
use TrustOptimize\Storage\AttachmentRepository;
use TrustOptimize\Storage\VariantRepository;
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
	 * Cleanup service.
	 *
	 * @var ImageCleanupService
	 */
	public $cleanup;

	/**
	 * Capability service.
	 *
	 * @var CapabilityService
	 */
	public $capabilities;

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

		// Output format capabilities (persisted; re-checked when the environment changes)
		$this->capabilities = new CapabilityService();

		// Initialize image processor
		$this->image_processor = new ImageProcessor();

		// Storage, planning and processing (until 02.12 introduces the composition root)
		$variants              = new VariantRepository( $this->db_manager );
		$attachments           = new AttachmentRepository( $this->db_manager, $variants );
		$this->settings        = new Settings();
		$this->image_converter = new ImageConverter( $variants, new AtomicImageWriter( $variants ), $this->capabilities );
		$planner               = new VariantPlanner( $variants, $attachments, $this->settings, $this->capabilities );
		$processor             = new AttachmentProcessor( $attachments, $variants, $this->image_converter, $this->settings, $this->capabilities );

		$this->cleanup = new ImageCleanupService( $variants, $attachments );

		// Initialize conversion queue (registers Action Scheduler hooks)
		$this->conversion_queue = new ConversionQueue( $attachments, $processor, $planner );
		$this->conversion_queue->init();

		// Initialize bulk runner (registers self-chaining Action Scheduler hook)
		$this->bulk_runner = new BulkJobRunner( null, null, null, $this->cleanup );
		$this->bulk_runner->init();
	}

	/**
	 * Register all hooks.
	 */
	private function register_hooks() {
		// Re-check output format support when the PHP/WordPress/editor environment changed
		$this->loader->add_action( 'admin_init', $this->capabilities, 'maybe_recheck' );

		// Filter to replace image src with optimized version (frontend processing)
		$this->loader->add_filter( 'the_content', $this->image_processor, 'process_content', 999 );

		// Filter for post thumbnails (frontend processing)
		$this->loader->add_filter( 'post_thumbnail_html', $this->image_processor, 'process_thumbnail', 999 );

		// Filter for direct wp_get_attachment_image() output (frontend processing)
		$this->loader->add_filter( 'wp_get_attachment_image', $this->image_processor, 'process_attachment_image_html', 999, 5 );

		// Hook for cleaning up image data when an attachment is deleted
		$this->loader->add_action( 'delete_attachment', $this, 'clean_image_data', 10 );
	}

	/**
	 * Clean up image data when an attachment is deleted
	 *
	 * @param int $attachment_id The attachment ID being deleted.
	 */
	public function clean_image_data( $attachment_id ) {
		$this->cleanup->cleanup_attachment( $attachment_id );
	}
}
