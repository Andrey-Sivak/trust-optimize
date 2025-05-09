<?php
/**
 * Optimizer Interface
 *
 * @package TrustOptimize
 */

namespace TrustOptimize\Features\Optimization;

/**
 * Interface OptimizerInterface
 */
interface OptimizerInterface {

	/**
	 * Process content to optimize images.
	 *
	 * @param string $content The content to process.
	 * @return string
	 */
	public function process_content( $content );

	/**
	 * Check if this optimizer is enabled.
	 *
	 * @return bool
	 */
	public function is_enabled();

	/**
	 * Get optimizer statistics.
	 *
	 * @return array
	 */
	public function get_stats();
}
