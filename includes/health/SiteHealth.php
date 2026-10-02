<?php
/**
 * Site Health tests for background processing.
 *
 * @package TrustOptimize
 */

namespace TrustOptimize\Health;

use TrustOptimize\Capabilities\CapabilityService;
use TrustOptimize\Queue\ConversionQueue;
use TrustOptimize\Utils\DiskSpace;

/**
 * Class SiteHealth
 */
class SiteHealth {

	/**
	 * Age in seconds after which a pending task counts as overdue.
	 */
	const OVERDUE_SECONDS = 600;

	/**
	 * Capability service.
	 *
	 * @var CapabilityService
	 */
	private $capabilities;

	/**
	 * Constructor.
	 *
	 * @param CapabilityService $capabilities Capability service.
	 */
	public function __construct( CapabilityService $capabilities ) {
		$this->capabilities = $capabilities;
	}

	/**
	 * Add the tests to Site Health.
	 */
	public function register() {
		add_filter( 'site_status_tests', array( $this, 'add_tests' ) );
	}

	/**
	 * Filter callback: append the direct tests.
	 *
	 * @param array $tests Registered tests.
	 * @return array
	 */
	public function add_tests( $tests ) {
		$tests['direct']['trust_optimize_overdue_tasks'] = array(
			'label' => __( 'TrustOptimize background tasks', 'trust-optimize' ),
			'test'  => array( $this, 'test_overdue_tasks' ),
		);
		$tests['direct']['trust_optimize_formats']       = array(
			'label' => __( 'TrustOptimize image formats', 'trust-optimize' ),
			'test'  => array( $this, 'test_formats' ),
		);
		$tests['direct']['trust_optimize_disk_space']    = array(
			'label' => __( 'TrustOptimize free disk space', 'trust-optimize' ),
			'test'  => array( $this, 'test_disk_space' ),
		);

		return $tests;
	}

	/**
	 * Test: pending tasks that should have run long ago.
	 *
	 * @return array
	 */
	public function test_overdue_tasks() {
		$overdue = function_exists( 'as_get_scheduled_actions' ) ? as_get_scheduled_actions(
			array(
				'status'       => \ActionScheduler_Store::STATUS_PENDING,
				'group'        => ConversionQueue::GROUP,
				'date'         => time() - self::OVERDUE_SECONDS,
				'date_compare' => '<',
				'per_page'     => 1,
				'orderby'      => 'none',
			),
			'ids'
		) : array();

		if ( empty( $overdue ) ) {
			return $this->result(
				'trust_optimize_overdue_tasks',
				'good',
				__( 'TrustOptimize background tasks run on time', 'trust-optimize' ),
				__( 'No image conversion task has been waiting for longer than ten minutes.', 'trust-optimize' )
			);
		}

		return $this->result(
			'trust_optimize_overdue_tasks',
			'recommended',
			__( 'TrustOptimize background tasks are overdue', 'trust-optimize' ),
			__( 'Image conversion tasks have been waiting for longer than ten minutes. WP-Cron is probably not running reliably on this site. Run the queue from the system cron with "wp action-scheduler run", or set DISABLE_WP_CRON to true and call wp-cron.php from the system cron every minute.', 'trust-optimize' )
		);
	}

	/**
	 * Test: which output formats this server can write.
	 *
	 * @return array
	 */
	public function test_formats() {
		$supported = array_values(
			array_filter(
				CapabilityService::FORMATS,
				array( $this->capabilities, 'supports' )
			)
		);

		if ( ! in_array( 'webp', $supported, true ) ) {
			return $this->result(
				'trust_optimize_formats',
				'recommended',
				__( 'This server cannot create WebP images', 'trust-optimize' ),
				__( 'Neither GD nor Imagick can write WebP here, so TrustOptimize has nothing to serve. Ask your host to enable WebP support in PHP.', 'trust-optimize' )
			);
		}

		return $this->result(
			'trust_optimize_formats',
			'good',
			__( 'This server can create modern image formats', 'trust-optimize' ),
			sprintf(
				/* translators: %s: comma-separated list of formats. */
				__( 'Supported output formats: %s.', 'trust-optimize' ),
				strtoupper( implode( ', ', $supported ) )
			)
		);
	}

	/**
	 * Test: free space on the uploads volume.
	 *
	 * @return array
	 */
	public function test_disk_space() {
		if ( DiskSpace::is_low() ) {
			return $this->result(
				'trust_optimize_disk_space',
				'recommended',
				__( 'The uploads directory is running out of disk space', 'trust-optimize' ),
				sprintf(
					/* translators: 1: free space, 2: required free space. */
					__( 'Only %1$s are free, TrustOptimize pauses bulk conversion below %2$s.', 'trust-optimize' ),
					size_format( (int) DiskSpace::free() ),
					size_format( DiskSpace::minimum_free() )
				)
			);
		}

		return $this->result(
			'trust_optimize_disk_space',
			'good',
			__( 'The uploads directory has enough free disk space', 'trust-optimize' ),
			__( 'Image conversion has room to write new files.', 'trust-optimize' )
		);
	}

	/**
	 * Build a Site Health result.
	 *
	 * @param string $test        Test name.
	 * @param string $status      good, recommended or critical.
	 * @param string $label       Result headline.
	 * @param string $description Explanation.
	 * @return array
	 */
	private function result( $test, $status, $label, $description ) {
		return array(
			'label'       => $label,
			'status'      => $status,
			'badge'       => array(
				'label' => __( 'Performance', 'trust-optimize' ),
				'color' => 'blue',
			),
			'description' => '<p>' . esc_html( $description ) . '</p>',
			'actions'     => '',
			'test'        => $test,
		);
	}
}
