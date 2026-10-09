<?php
/**
 * Optimization statistics for the dashboard.
 *
 * @package TrustOptimize
 */

namespace TrustOptimize\Admin;

use TrustOptimize\Bulk\Inventory;
use TrustOptimize\Domain\AttachmentState;
use TrustOptimize\Frontend\PictureRenderer;
use TrustOptimize\Queue\ConversionQueue;
use TrustOptimize\Storage\VariantRepository;

/**
 * Class Statistics
 *
 * Numbers come from the plugin tables. They are cached for five minutes and dropped when a
 * conversion task finishes or the settings change.
 */
class Statistics {

	/**
	 * Transient that holds the numbers.
	 */
	const TRANSIENT = 'trust_optimize_stats_by_format';

	/**
	 * Inventory.
	 *
	 * @var Inventory
	 */
	private $inventory;

	/**
	 * Variant repository.
	 *
	 * @var VariantRepository
	 */
	private $variants;

	/**
	 * Constructor.
	 *
	 * @param Inventory         $inventory Inventory.
	 * @param VariantRepository $variants  Variant repository.
	 */
	public function __construct( Inventory $inventory, VariantRepository $variants ) {
		$this->inventory = $inventory;
		$this->variants  = $variants;
	}

	/**
	 * Drop the cache when the numbers change.
	 */
	public function register() {
		add_action( 'action_scheduler_after_execute', array( $this, 'on_action_executed' ), 10, 2 );
		add_action( 'update_option_trust_optimize_options', array( $this, 'flush' ) );
	}

	/**
	 * A task of the plugin finished: its attachment changed.
	 *
	 * @param int                     $action_id Action ID.
	 * @param \ActionScheduler_Action $action  Action.
	 */
	public function on_action_executed( $action_id, $action ) {
		if ( ConversionQueue::GROUP === $action->get_group() ) {
			$this->flush();
		}
	}

	/**
	 * Forget the cached numbers.
	 */
	public function flush() {
		delete_transient( self::TRANSIENT );
	}

	/**
	 * The numbers.
	 *
	 * @return array total_images, eligible, optimized, partial, failed, skipped, queued, outdated, saved_bytes_by_format (bytes saved per format, AVIF first as browsers receive it) and rate (percent of eligible images that are optimized).
	 */
	public function get() {
		$cached = get_transient( self::TRANSIENT );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$summary = $this->inventory->summary();
		$states  = $summary['attachment_states'];
		$stats   = array(
			'total_images'          => (int) $summary['total_images'],
			'eligible'              => (int) $summary['eligible_attachments'],
			'optimized'             => $states[ AttachmentState::OPTIMIZED ] ?? 0,
			'partial'               => $states[ AttachmentState::PARTIAL ] ?? 0,
			'failed'                => $states[ AttachmentState::FAILED ] ?? 0,
			'skipped'               => $states[ AttachmentState::SKIPPED ] ?? 0,
			'queued'                => ( $states[ AttachmentState::QUEUED ] ?? 0 ) + ( $states[ AttachmentState::PROCESSING ] ?? 0 ),
			'outdated'              => (int) $summary['outdated_variants'],
			'saved_bytes_by_format' => array(),
		);

		foreach ( array_keys( PictureRenderer::FORMATS ) as $format ) {
			$stats['saved_bytes_by_format'][ $format ] = $this->variants->sum_saved_bytes( $format );
		}

		$stats['rate'] = $stats['eligible'] > 0 ? round( 100 * $stats['optimized'] / $stats['eligible'], 1 ) : 0;

		set_transient( self::TRANSIENT, $stats, 5 * MINUTE_IN_SECONDS );

		return $stats;
	}
}
