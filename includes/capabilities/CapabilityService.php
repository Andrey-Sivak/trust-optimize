<?php
/**
 * Persisted knowledge of which output formats this site can write.
 *
 * @package TrustOptimize
 */

namespace TrustOptimize\Capabilities;

/**
 * Class CapabilityService
 *
 * Support comes from wp_image_editor_supports() and is stored in an option together
 * with a fingerprint of the environment. Nothing is written to disk. A format is
 * downgraded when a real conversion proves the static answer wrong.
 */
class CapabilityService {

	/**
	 * Option that stores the result.
	 */
	const OPTION = 'trust_optimize_capabilities';

	/**
	 * Output formats the plugin can create.
	 *
	 * @var string[]
	 */
	const FORMATS = array( 'webp', 'avif' );

	/**
	 * Re-check support when the environment changed.
	 */
	public function register() {
		add_action( 'admin_init', array( $this, 'maybe_recheck' ) );
	}

	/**
	 * Whether a format can be written.
	 *
	 * Reads the stored result only; it is computed once if missing (normally at activation).
	 *
	 * @param string $format Format extension.
	 * @return bool
	 */
	public function supports( $format ) {
		$stored = get_option( self::OPTION );

		if ( ! is_array( $stored ) ) {
			$stored = $this->recheck();
		}

		if ( empty( $stored[ $format ] ) ) {
			return false;
		}

		// A downgrade only counts in the environment that recorded it (CLI and FPM may differ).
		// The fingerprint is computed only when a downgrade exists, so normal requests stay cheap.
		return ! isset( $stored['downgraded'][ $format ] ) || $stored['downgraded'][ $format ]['env'] !== $this->fingerprint();
	}

	/**
	 * Formats that a failed conversion switched off in this environment.
	 *
	 * @return array[] Note keyed by format: reason, env and at (UTC).
	 */
	public function downgrades() {
		$stored = get_option( self::OPTION );
		$notes  = is_array( $stored ) ? (array) ( $stored['downgraded'] ?? array() ) : array();

		if ( empty( $notes ) ) {
			return array();
		}

		$env = $this->fingerprint();

		return array_filter( $notes, static fn( $note ) => $note['env'] === $env );
	}

	/**
	 * Recompute and store support for every format.
	 *
	 * @return array Stored value.
	 */
	public function recheck() {
		$stored = array(
			'checked_at' => current_time( 'mysql', true ),
			'env'        => $this->fingerprint(),
		);

		foreach ( self::FORMATS as $format ) {
			$stored[ $format ] = $this->detect( $format );
		}

		update_option( self::OPTION, $stored, false );

		return $stored;
	}

	/**
	 * Recompute only when the environment changed since the last check.
	 */
	public function maybe_recheck() {
		$stored = get_option( self::OPTION );

		if ( ! is_array( $stored ) || ( $stored['env'] ?? null ) !== $this->fingerprint() ) {
			$this->recheck();
		}
	}

	/**
	 * Record that no image editor of this environment can write a format.
	 *
	 * The note carries the environment fingerprint and is ignored by any other environment,
	 * so a PHP-CLI worker without AVIF cannot switch AVIF off for PHP-FPM.
	 *
	 * @param string $format Format extension.
	 * @param string $reason Why (usually the editor's error message).
	 */
	public function downgrade( $format, $reason ) {
		$stored = get_option( self::OPTION );

		if ( ! is_array( $stored ) ) {
			$stored = $this->recheck();
		}

		$stored['downgraded'][ $format ] = array(
			'reason' => mb_substr( (string) $reason, 0, 255, 'UTF-8' ),
			'env'    => $this->fingerprint(),
			'at'     => current_time( 'mysql', true ),
		);

		update_option( self::OPTION, $stored, false );
	}

	/**
	 * Static answer of WordPress for one format.
	 *
	 * @param string $format Format extension.
	 * @return bool
	 */
	private function detect( $format ) {
		return (bool) wp_image_editor_supports(
			array(
				'mime_type' => 'image/' . $format,
				'methods'   => array( 'save' ),
			)
		);
	}

	/**
	 * Description of everything that decides editor support.
	 *
	 * @return array
	 */
	private function fingerprint() {
		global $wp_version;

		$gd      = function_exists( 'gd_info' ) ? gd_info() : array();
		$imagick = '';
		if ( class_exists( 'Imagick' ) ) {
			$version = \Imagick::getVersion();
			$imagick = $version['versionString'] ?? '';
		}

		return array(
			'php'     => PHP_VERSION,
			'sapi'    => PHP_SAPI,
			'wp'      => $wp_version,
			'gd'      => $gd['GD Version'] ?? '',
			'gd_webp' => ! empty( $gd['WebP Support'] ),
			'gd_avif' => ! empty( $gd['AVIF Support'] ),
			'imagick' => $imagick,
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core filter.
			'editors' => array_values( (array) apply_filters( 'wp_image_editors', array( 'WP_Image_Editor_Imagick', 'WP_Image_Editor_GD' ) ) ),
		);
	}
}
