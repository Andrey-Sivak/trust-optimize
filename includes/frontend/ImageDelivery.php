<?php
/**
 * Hooks the picture renderer into the image markup that core produces.
 *
 * @package TrustOptimize
 */

namespace TrustOptimize\Frontend;

use TrustOptimize\Admin\Settings;
use WP_HTML_Tag_Processor;

/**
 * Class ImageDelivery
 *
 * Content, widgets and everything else that goes through wp_filter_content_tags() is handled
 * by wp_content_img_tag; featured images and theme calls by wp_get_attachment_image. In both
 * cases core has already chosen srcset, sizes and the loading attributes.
 */
class ImageDelivery {

	/**
	 * Picture renderer.
	 *
	 * @var PictureRenderer
	 */
	private $renderer;

	/**
	 * Content pre-pass.
	 *
	 * @var ContentPrimer
	 */
	private $primer;

	/**
	 * Settings instance.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param PictureRenderer $renderer Picture renderer.
	 * @param ContentPrimer   $primer   Content pre-pass.
	 * @param Settings        $settings Settings instance.
	 */
	public function __construct( PictureRenderer $renderer, ContentPrimer $primer, Settings $settings ) {
		$this->renderer = $renderer;
		$this->primer   = $primer;
		$this->settings = $settings;
	}

	/**
	 * Register the frontend filters.
	 */
	public function register() {
		add_filter( 'wp_content_img_tag', array( $this, 'filter_content_img_tag' ), 10, 3 );
		add_filter( 'wp_get_attachment_image', array( $this, 'filter_attachment_image' ), 10, 5 );
	}

	/**
	 * Filter an <img> tag of the content.
	 *
	 * @param string $img_tag       Image tag.
	 * @param string $context       Where the tag comes from, for example "the_content".
	 * @param int    $attachment_id Attachment ID from the wp-image-ID class; 0 when unknown.
	 * @return string
	 */
	public function filter_content_img_tag( $img_tag, $context, $attachment_id ) {
		$attachment_id = (int) $attachment_id;

		if ( $attachment_id <= 0 || ! $this->should_render( $context, $attachment_id ) ) {
			return $img_tag;
		}

		$processor = new WP_HTML_Tag_Processor( $img_tag );
		if ( $processor->next_tag( 'img' ) && $this->primer->is_inside_picture( (string) $processor->get_attribute( 'src' ) ) ) {
			return $img_tag;
		}

		return $this->renderer->render( $img_tag, $attachment_id );
	}

	/**
	 * Filter the markup of wp_get_attachment_image().
	 *
	 * @param string       $html          Attachment image HTML markup.
	 * @param int          $attachment_id Image attachment ID.
	 * @param string|int[] $size          Requested image size.
	 * @param bool         $icon          Whether the image should be treated as an icon.
	 * @param string|array $attr          Attributes for the image markup.
	 * @return string
	 */
	public function filter_attachment_image( $html, $attachment_id, $size, $icon, $attr ) {
		if ( $icon || empty( $html ) || ! $this->should_render( 'wp_get_attachment_image', (int) $attachment_id ) ) {
			return $html;
		}

		return $this->renderer->render( $html, (int) $attachment_id );
	}

	/**
	 * Whether the delivery applies to the current request.
	 *
	 * @param string $context       Where the tag comes from.
	 * @param int    $attachment_id Attachment ID.
	 * @return bool
	 */
	private function should_render( $context, $attachment_id ) {
		$render = ! is_admin() && ! is_feed() && (bool) $this->settings->get( 'enable_adaptive_images', 1 );

		/**
		 * Filters whether an image is wrapped into <picture> with next-gen sources.
		 *
		 * @param bool   $render        Whether to render; false in the admin, in feeds and when the feature is off.
		 * @param string $context       Where the tag comes from: a content filter name or "wp_get_attachment_image".
		 * @param int    $attachment_id Attachment ID.
		 */
		return (bool) apply_filters( 'trust_optimize_should_render', $render, $context, $attachment_id );
	}
}
