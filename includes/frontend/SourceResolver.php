<?php
/**
 * Finds the attachment of an image that has no wp-image-ID class.
 *
 * @package TrustOptimize
 */

namespace TrustOptimize\Frontend;

use TrustOptimize\Storage\VariantRepository;

/**
 * Class SourceResolver
 *
 * Only an exact match of the source path counts, so an image can never be mistaken for
 * the attachment of a similarly named file (banner-1920x600.jpg vs banner.jpg).
 */
class SourceResolver {

	/**
	 * Lifetime of a cached answer: a negative one must not hide variants that appear later.
	 */
	const CACHE_TTL = 300;

	/**
	 * Variant repository.
	 *
	 * @var VariantRepository
	 */
	private $variants;

	/**
	 * URL mapping.
	 *
	 * @var UploadsUrl
	 */
	private $urls;

	/**
	 * Constructor.
	 *
	 * @param VariantRepository $variants Variant repository.
	 * @param UploadsUrl        $urls     URL mapping.
	 */
	public function __construct( VariantRepository $variants, UploadsUrl $urls ) {
		$this->variants = $variants;
		$this->urls     = $urls;
	}

	/**
	 * Attachment ID of an image URL.
	 *
	 * @param string $url Image URL.
	 * @return int 0 when no single attachment has a variant generated from this file.
	 */
	public function attachment_id( $url ) {
		$path = $this->urls->relative_path( $url );
		if ( null === $path ) {
			return 0;
		}

		$key    = 'source_' . md5( $path );
		$cached = wp_cache_get( $key, VariantRepository::CACHE_GROUP );
		if ( false !== $cached ) {
			return (int) $cached;
		}

		$ids = array_unique( array_column( $this->variants->find_by_source_path( $path ), 'attachment_id' ) );
		$id  = 1 === count( $ids ) ? (int) reset( $ids ) : 0;

		wp_cache_set( $key, $id, VariantRepository::CACHE_GROUP, self::CACHE_TTL );

		return $id;
	}
}
