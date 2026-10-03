<?php
/**
 * Loads variant data for every attachment of a content string in one query.
 *
 * @package TrustOptimize
 */

namespace TrustOptimize\Frontend;

use TrustOptimize\Admin\Settings;
use TrustOptimize\Storage\VariantRepository;

/**
 * Class ContentPrimer
 */
class ContentPrimer {

	/**
	 * Runs before wp_filter_content_tags() (priority 12), which asks for each image in turn.
	 */
	const PRIORITY = 11;

	/**
	 * Variant repository.
	 *
	 * @var VariantRepository
	 */
	private $variants;

	/**
	 * Settings instance.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param VariantRepository $variants Variant repository.
	 * @param Settings          $settings Settings instance.
	 */
	public function __construct( VariantRepository $variants, Settings $settings ) {
		$this->variants = $variants;
		$this->settings = $settings;
	}

	/**
	 * Register the frontend filters.
	 */
	public function register() {
		add_filter( 'the_content', array( $this, 'prime' ), self::PRIORITY );
	}

	/**
	 * Prime the variant cache for the attachments mentioned in the content.
	 *
	 * @param string $content Post content.
	 * @return string Unchanged content.
	 */
	public function prime( $content ) {
		if ( is_admin() || ! $this->settings->get( 'enable_adaptive_images', 1 ) || ! is_string( $content ) ) {
			return $content;
		}

		if ( preg_match_all( '/wp-image-(\d+)/', $content, $matches ) ) {
			$this->variants->prime( array_map( 'intval', $matches[1] ) );
		}

		return $content;
	}
}
