<?php
/**
 * One pass over the content before core looks at its images: loads the variant data of
 * all attachments in one query and remembers the images that already sit in a <picture>.
 *
 * @package TrustOptimize
 */

namespace TrustOptimize\Frontend;

use TrustOptimize\Admin\Settings;
use TrustOptimize\Storage\VariantRepository;
use WP_HTML_Tag_Processor;

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
	 * The src attributes of images found inside a <picture>, as keys.
	 *
	 * @var bool[]
	 */
	private $in_picture = array();

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
	 * Remember an image that sits in a <picture> now, for the passes that follow.
	 *
	 * Block themes filter the whole template with wp_filter_content_tags() again, after the content and
	 * the featured image were wrapped, and that second pass must not wrap them once more.
	 *
	 * @param string $src Value of the src attribute.
	 */
	public function remember_picture( $src ) {
		$this->in_picture[ $src ] = true;
	}

	/**
	 * Whether an image with this src was found inside a <picture>, or wrapped by the plugin, so far.
	 *
	 * The same src outside a <picture> is skipped as well; the image only loses the optimization.
	 *
	 * @param string $src Value of the src attribute.
	 * @return bool
	 */
	public function is_inside_picture( $src ) {
		return isset( $this->in_picture[ $src ] );
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

		if ( $this->in_picture || false !== stripos( $content, '<picture' ) ) {
			$this->scan_pictures( $content );
		}

		return $content;
	}

	/**
	 * Sort the images of the content into those inside a <picture> and those outside of it.
	 *
	 * An image that is bare in this content is forgotten, even if an earlier content had the same
	 * src wrapped: the same image in several posts of an archive must be wrapped each time.
	 *
	 * @param string $content Post content.
	 */
	private function scan_pictures( $content ) {
		$processor = new WP_HTML_Tag_Processor( $content );
		$depth     = 0;
		$inside    = array();
		$outside   = array();

		while ( $processor->next_tag( array( 'tag_closers' => 'visit' ) ) ) {
			$tag = $processor->get_tag();

			if ( 'PICTURE' === $tag ) {
				$depth += $processor->is_tag_closer() ? -1 : 1;
				$depth  = max( 0, $depth );
			} elseif ( 'IMG' === $tag && is_string( $processor->get_attribute( 'src' ) ) ) {
				if ( $depth > 0 ) {
					$inside[ $processor->get_attribute( 'src' ) ] = true;
				} else {
					$outside[ $processor->get_attribute( 'src' ) ] = true;
				}
			}
		}

		$this->in_picture = array_diff_key( $this->in_picture, $outside ) + $inside;
	}
}
