<?php
/**
 * Safe path resolution inside the WordPress uploads directory.
 *
 * @package TrustOptimize
 */

namespace TrustOptimize\Utils;

/**
 * Class UploadsPath
 */
class UploadsPath {

	/**
	 * Uploads base directory.
	 *
	 * @return string Normalized absolute path without trailing slash, or empty string.
	 */
	public static function basedir() {
		$upload_dir = wp_upload_dir();

		if ( empty( $upload_dir['basedir'] ) ) {
			return '';
		}

		return untrailingslashit( wp_normalize_path( $upload_dir['basedir'] ) );
	}

	/**
	 * Path relative to uploads, lexically.
	 *
	 * @param string $path Absolute path.
	 * @return string|null Relative path, or null when the path is not under uploads.
	 */
	public static function relative( $path ) {
		$base = self::basedir();
		$path = wp_normalize_path( (string) $path );

		if ( '' === $base || 0 !== strpos( $path, $base . '/' ) ) {
			return null;
		}

		return substr( $path, strlen( $base ) + 1 );
	}

	/**
	 * Resolve a file in a directory relative to uploads.
	 *
	 * @param string $relative_dir Directory relative to uploads ('' for the uploads root).
	 * @param string $file         File name without directory part.
	 * @return string|null Absolute path, or null for traversal, empty segments or absolute input.
	 */
	public static function resolve( $relative_dir, $file ) {
		$base = self::basedir();
		if ( '' === $base || ! self::is_safe_segment( $file ) ) {
			return null;
		}

		// Not wp_normalize_path(): it would silently collapse empty segments.
		$relative_dir = rtrim( str_replace( '\\', '/', (string) $relative_dir ), '/' );

		if ( '' === $relative_dir ) {
			return $base . '/' . $file;
		}

		if ( '/' === $relative_dir[0] || preg_match( '/^[A-Za-z]:/', $relative_dir ) ) {
			return null;
		}

		foreach ( explode( '/', $relative_dir ) as $segment ) {
			if ( ! self::is_safe_segment( $segment ) ) {
				return null;
			}
		}

		return $base . '/' . $relative_dir . '/' . $file;
	}

	/**
	 * Absolute path of a file given relative to uploads.
	 *
	 * @param string $relative_path Path such as "2026/05/photo.jpg".
	 * @return string|null Absolute path, or null when the input is unsafe.
	 */
	public static function absolute( $relative_path ) {
		$dir = dirname( $relative_path );

		return self::resolve( '.' === $dir ? '' : $dir, basename( $relative_path ) );
	}

	/**
	 * Resolve a stored variant record to an absolute path.
	 *
	 * Uses the recorded relative directory, or the directory of the attachment's original file.
	 *
	 * @param array $variant       Variant record with 'file' and optional 'relative_dir'.
	 * @param int   $attachment_id Attachment ID.
	 * @return string|null Absolute path or null.
	 */
	public static function resolve_variant( array $variant, $attachment_id ) {
		if ( empty( $variant['file'] ) ) {
			return null;
		}

		$file = basename( $variant['file'] );

		if ( isset( $variant['relative_dir'] ) && '' !== $variant['relative_dir'] ) {
			return self::resolve( $variant['relative_dir'], $file );
		}

		$attached_file = get_attached_file( $attachment_id );
		if ( $attached_file ) {
			return trailingslashit( dirname( $attached_file ) ) . $file;
		}

		return null;
	}

	/**
	 * Whether a path is inside uploads, following symlinks.
	 *
	 * The nearest existing ancestor directory is resolved with realpath(), so a symlink
	 * pointing outside uploads is rejected. A path whose directories do not exist yet
	 * is judged by its normalized form.
	 *
	 * @param string $path Absolute path.
	 * @return bool
	 */
	public static function is_inside( $path ) {
		$base = self::basedir();
		if ( '' === $base ) {
			return false;
		}

		$path = wp_normalize_path( (string) $path );
		if ( in_array( '..', explode( '/', $path ), true ) || 0 !== strpos( $path, $base . '/' ) ) {
			return false;
		}

		$real_base = realpath( $base );
		if ( false === $real_base ) {
			return true;
		}

		$dir = dirname( $path );
		while ( ! file_exists( $dir ) && dirname( $dir ) !== $dir ) {
			$dir = dirname( $dir );
		}

		$real_dir = realpath( $dir );
		if ( false === $real_dir ) {
			return false;
		}

		$real_base = wp_normalize_path( $real_base );
		$real_dir  = wp_normalize_path( $real_dir );

		return $real_dir === $real_base || 0 === strpos( $real_dir, $real_base . '/' );
	}

	/**
	 * Whether a path segment is a plain name.
	 *
	 * @param string $segment Path segment.
	 * @return bool
	 */
	private static function is_safe_segment( $segment ) {
		return is_string( $segment ) && '' !== $segment && '.' !== $segment && '..' !== $segment && false === strpbrk( $segment, '/\\' );
	}
}
