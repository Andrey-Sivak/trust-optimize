<?php
/**
 * Maps image URLs to paths relative to the uploads directory.
 *
 * @package TrustOptimize
 */

namespace TrustOptimize\Frontend;

/**
 * Class UploadsUrl
 */
class UploadsUrl {

	/**
	 * Where uploads are served from, computed on first use.
	 *
	 * @var array{prefix:string,hosts:string[]}|null
	 */
	private $location = null;

	/**
	 * Path of an image URL relative to uploads.
	 *
	 * The scheme is ignored and percent-encoding is undone. The host must be the uploads host or one of the hosts from the
	 * trust_optimize_cdn_hosts filter, and the path after the host must mirror the uploads URL.
	 *
	 * @param string $url Image URL.
	 * @return string|null Null when the URL does not point into uploads.
	 */
	public function relative_path( $url ) {
		$location = $this->location();
		$parts    = wp_parse_url( $url );

		if ( empty( $parts['path'] ) || ( ! empty( $parts['host'] ) && ! in_array( strtolower( $parts['host'] ), $location['hosts'], true ) ) ) {
			return null;
		}

		// The path of a URL may be percent-encoded (a space is %20), the stored paths are not.
		return 0 === strpos( $parts['path'], $location['prefix'] ) ? rawurldecode( substr( $parts['path'], strlen( $location['prefix'] ) ) ) : null;
	}

	/**
	 * URL path prefix of uploads (with a trailing slash) and the accepted hosts in lower case.
	 *
	 * @return array{prefix:string,hosts:string[]}
	 */
	private function location() {
		if ( null === $this->location ) {
			$base = wp_parse_url( wp_upload_dir( null, false )['baseurl'] );

			/**
			 * Hosts that serve the uploads directory besides the site itself, for example a CDN.
			 *
			 * The path after the host must mirror the uploads URL.
			 *
			 * @param string[] $hosts Host names.
			 */
			$cdn_hosts = (array) apply_filters( 'trust_optimize_cdn_hosts', array() );

			$this->location = array(
				'prefix' => trailingslashit( $base['path'] ?? '' ),
				'hosts'  => array_map( 'strtolower', array_merge( array( $base['host'] ?? '' ), $cdn_hosts ) ),
			);
		}

		return $this->location;
	}
}
