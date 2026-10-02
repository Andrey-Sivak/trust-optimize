<?php
/**
 * The main plugin class: the composition root.
 *
 * @package TrustOptimize
 */

namespace TrustOptimize\Core;

use TrustOptimize\Admin\Admin;
use TrustOptimize\Admin\Settings;
use TrustOptimize\API\RestController;
use TrustOptimize\Bulk\BulkJobRepository;
use TrustOptimize\Bulk\BulkJobRunner;
use TrustOptimize\Bulk\EligibilityQuery;
use TrustOptimize\Capabilities\CapabilityService;
use TrustOptimize\CLI\Command;
use TrustOptimize\CLI\MigrationCommand;
use TrustOptimize\Database\DatabaseManager;
use TrustOptimize\Features\Optimization\ImageConverter;
use TrustOptimize\Features\Optimization\ImageProcessor;
use TrustOptimize\Files\AtomicImageWriter;
use TrustOptimize\Migration\ConflictReport;
use TrustOptimize\Migration\DetectCollisions;
use TrustOptimize\Migration\ImportLegacyManifest;
use TrustOptimize\Migration\MigrationRunner;
use TrustOptimize\Planning\VariantPlanner;
use TrustOptimize\Processing\AttachmentProcessor;
use TrustOptimize\Queue\ConversionQueue;
use TrustOptimize\Service\ImageCleanupService;
use TrustOptimize\Service\LegacyPathGuard;
use TrustOptimize\Storage\AttachmentRepository;
use TrustOptimize\Storage\VariantRepository;

/**
 * Class Plugin
 *
 * Creates every service once, hands dependencies over through constructors and
 * lets each component register its own hooks.
 */
class Plugin {

	/**
	 * The single instance of the class.
	 *
	 * @var Plugin|null
	 */
	protected static $instance = null;

	/**
	 * Admin interface.
	 *
	 * @var Admin
	 */
	public $admin;

	/**
	 * Variant planner.
	 *
	 * @var VariantPlanner
	 */
	public $planner;

	/**
	 * Attachment processor.
	 *
	 * @var AttachmentProcessor
	 */
	public $processor;

	/**
	 * Cleanup service.
	 *
	 * @var ImageCleanupService
	 */
	public $cleanup;

	/**
	 * Conversion queue.
	 *
	 * @var ConversionQueue
	 */
	public $conversion_queue;

	/**
	 * Bulk job runner.
	 *
	 * @var BulkJobRunner
	 */
	public $bulk_runner;

	/**
	 * REST controller.
	 *
	 * @var RestController
	 */
	public $rest_controller;

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
	 * Build the services and register their hooks.
	 */
	public function init() {
		$database     = new DatabaseManager();
		$settings     = new Settings();
		$capabilities = new CapabilityService();
		$variants     = new VariantRepository( $database );
		$attachments  = new AttachmentRepository( $database, $variants );
		$converter    = new ImageConverter( $variants, new AtomicImageWriter( $variants ), $capabilities );
		$jobs         = new BulkJobRepository( $database );
		$eligibility  = new EligibilityQuery( $variants );
		$conflicts    = new ConflictReport();
		$guard        = new LegacyPathGuard( $database, $variants );
		$migration    = new MigrationRunner(
			$database,
			array(
				new ImportLegacyManifest( $database, $variants, $attachments ),
				new DetectCollisions( $variants, $attachments, $guard, $conflicts ),
			)
		);

		$this->planner          = new VariantPlanner( $variants, $attachments, $settings, $capabilities );
		$this->cleanup          = new ImageCleanupService( $variants, $attachments, $guard, $conflicts );
		$this->processor        = new AttachmentProcessor( $attachments, $variants, $converter, $this->planner, $this->cleanup, $settings, $capabilities );
		$this->conversion_queue = new ConversionQueue( $attachments, $this->processor, $this->planner );
		$this->bulk_runner      = new BulkJobRunner( $jobs, $eligibility, $this->planner, $this->processor, $this->cleanup );
		$this->admin            = new Admin( $settings, $attachments, $eligibility, $capabilities, $conflicts );
		$this->rest_controller  = new RestController( $attachments, $variants, $this->processor, $this->cleanup, $jobs, $eligibility, $this->bulk_runner );

		foreach ( array(
			$database,
			$migration,
			$capabilities,
			new ImageProcessor( $variants, $settings ),
			$this->cleanup,
			$this->conversion_queue,
			$this->bulk_runner,
			$this->admin,
			$this->rest_controller,
		) as $component ) {
			$component->register();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'trust-optimize', new Command( $jobs, $eligibility, $this->bulk_runner, $this->processor, $this->cleanup, $migration ) );
			\WP_CLI::add_command( 'trust-optimize migration', new MigrationCommand( $conflicts ) );
		}
	}
}
