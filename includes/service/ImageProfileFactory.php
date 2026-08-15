<?php
/**
 * Image optimization profile factory.
 *
 * @package TrustOptimize\Service
 */

namespace TrustOptimize\Service;

use TrustOptimize\Admin\Settings;
use TrustOptimize\Value\ImageProfile;

/**
 * Class ImageProfileFactory
 */
class ImageProfileFactory {

	const CONVERSION_SCHEMA_VERSION = '1';

	/**
	 * Settings instance.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Per-request output format capability cache.
	 *
	 * @var array|null
	 */
	private static $output_format_capabilities = null;

	/**
	 * Constructor.
	 *
	 * @param Settings|null $settings Settings instance.
	 */
	public function __construct( Settings $settings = null ) {
		$this->settings = $settings ? $settings : new Settings();
	}

	/**
	 * Build an image profile for an attachment metadata payload.
	 *
	 * @param array $wp_metadata WordPress attachment metadata.
	 * @return ImageProfile
	 */
	public function from_wp_metadata( array $wp_metadata ) {
		$capabilities = $this->get_output_format_capabilities();

		return new ImageProfile(
			self::CONVERSION_SCHEMA_VERSION,
			$this->get_supported_enabled_formats( $capabilities ),
			$this->get_effective_quality(),
			array(
				'size_names'                 => $this->get_size_names( $wp_metadata ),
				'output_format_support'      => $capabilities,
				'unsupported_output_formats' => $this->get_unsupported_enabled_formats( $capabilities ),
			)
		);
	}

	/**
	 * Check whether the environment can create an output format.
	 *
	 * @param string $format Target format.
	 * @return bool True when WordPress can save the target MIME through an image editor.
	 */
	public function is_output_format_supported( $format ) {
		$capabilities = $this->get_output_format_capabilities();

		return ! empty( $capabilities[ $format ] );
	}

	/**
	 * Get enabled conversion formats.
	 *
	 * @return array
	 */
	private function get_requested_output_formats() {
		$formats = array();

		if ( (bool) $this->settings->get( 'convert_to_webp', 1 ) ) {
			$formats[] = 'webp';
		}

		if ( (bool) $this->settings->get( 'convert_to_avif', 1 ) ) {
			$formats[] = 'avif';
		}

		return $formats;
	}

	/**
	 * Get enabled output formats supported by this environment.
	 *
	 * @param array $capabilities Output format capability map.
	 * @return array
	 */
	private function get_supported_enabled_formats( array $capabilities ) {
		$formats = array();

		foreach ( $this->get_requested_output_formats() as $format ) {
			if ( ! empty( $capabilities[ $format ] ) ) {
				$formats[] = $format;
			}
		}

		return $formats;
	}

	/**
	 * Get enabled output formats unsupported by this environment.
	 *
	 * @param array $capabilities Output format capability map.
	 * @return array
	 */
	private function get_unsupported_enabled_formats( array $capabilities ) {
		$formats = array();

		foreach ( $this->get_requested_output_formats() as $format ) {
			if ( empty( $capabilities[ $format ] ) ) {
				$formats[] = $format;
			}
		}

		return $formats;
	}

	/**
	 * Get output format capability map.
	 *
	 * @return array Format support keyed by extension.
	 */
	private function get_output_format_capabilities() {
		if ( null !== self::$output_format_capabilities ) {
			return self::$output_format_capabilities;
		}

		$formats      = array( 'webp', 'avif', 'png' );
		$capabilities = array();

		foreach ( $formats as $format ) {
			$capabilities[ $format ] = $this->detect_output_format_support( $format );
		}

		self::$output_format_capabilities = $capabilities;

		return self::$output_format_capabilities;
	}

	/**
	 * Detect support for one output format through WordPress image editors.
	 *
	 * @param string $format Target format.
	 * @return bool True when a WordPress image editor declares target MIME save support.
	 */
	private function detect_output_format_support( $format ) {
		$mime_type       = 'image/' . $format;
		$implementations = array( 'WP_Image_Editor_Imagick', 'WP_Image_Editor_GD' );
		$supports_mime   = false;

		foreach ( $implementations as $implementation ) {
			if ( ! class_exists( $implementation ) ) {
				continue;
			}

			if ( is_callable( array( $implementation, 'test' ) ) && ! call_user_func( array( $implementation, 'test' ) ) ) {
				continue;
			}

			if ( is_callable( array( $implementation, 'supports_mime_type' ) ) && call_user_func( array( $implementation, 'supports_mime_type' ), $mime_type ) ) {
				$supports_mime = true;
				break;
			}
		}

		if ( ! $supports_mime ) {
			return false;
		}

		return $this->can_save_probe_image_as( $mime_type, $format );
	}

	/**
	 * Check actual WordPress image editor save support for a target MIME type.
	 *
	 * Static editor capability checks can be overly optimistic for AVIF/WebP in
	 * some builds. Bulk planning must only mark a format supported when the
	 * active editor stack can load a real source image and save that target MIME.
	 *
	 * @param string $mime_type Target MIME type.
	 * @param string $format    Target extension.
	 * @return bool True when a probe image can be saved as the requested MIME.
	 */
	private function can_save_probe_image_as( $mime_type, $format ) {
		if ( ! function_exists( 'wp_get_image_editor' ) ) {
			return false;
		}

		$upload_dir = wp_upload_dir();
		if ( empty( $upload_dir['basedir'] ) || ! is_writable( $upload_dir['basedir'] ) ) {
			return false;
		}

		$probe_dir = trailingslashit( $upload_dir['basedir'] ) . 'trust-optimize-capability';
		if ( ! wp_mkdir_p( $probe_dir ) ) {
			return false;
		}

		$source_path = trailingslashit( $probe_dir ) . 'probe-' . wp_generate_uuid4() . '.jpg';
		$target_path = trailingslashit( $probe_dir ) . 'probe-' . wp_generate_uuid4() . '.' . $format;

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents
		if ( false === file_put_contents( $source_path, $this->get_probe_jpeg_bytes() ) ) {
			return false;
		}

		$supported = false;
		$editor    = wp_get_image_editor( $source_path );

		if ( ! is_wp_error( $editor ) ) {
			$saved = $editor->save( $target_path, $mime_type );

			$supported = ! is_wp_error( $saved )
				&& ! empty( $saved['path'] )
				&& file_exists( $saved['path'] )
				&& ! empty( $saved['mime-type'] )
				&& $mime_type === $saved['mime-type'];

			if ( ! empty( $saved['path'] ) && file_exists( $saved['path'] ) ) {
				wp_delete_file( $saved['path'] );
			}
		}

		if ( file_exists( $source_path ) ) {
			wp_delete_file( $source_path );
		}

		if ( file_exists( $target_path ) ) {
			wp_delete_file( $target_path );
		}

		return $supported;
	}

	/**
	 * Get tiny JPEG probe image bytes.
	 *
	 * @return string Probe JPEG bytes.
	 */
	private function get_probe_jpeg_bytes() {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		return base64_decode( '/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////2wBDAf//////////////////////////////////////////////////////////////////////////////////////wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAX/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIQAxAAAAH/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEBAAEFAqf/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAEDAQE/ASP/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAECAQE/ASP/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEBAAY/Aqf/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEBAAE/ISP/2gAMAwEAAgADAAAAEP/EFBQRAQAAAAAAAAAAAAAAAAAAABD/2gAIAQMBAT8QH//EFBQRAQAAAAAAAAAAAAAAAAAAABD/2gAIAQIBAT8QH//EFBABAQAAAAAAAAAAAAAAAAAAABD/2gAIAQEAAT8QH//Z' );
	}

	/**
	 * Get effective quality settings keyed by target format.
	 *
	 * @return array
	 */
	private function get_effective_quality() {
		$options        = $this->settings->get_all();
		$legacy_quality = isset( $options['image_quality'] ) ? (int) $options['image_quality'] : 85;
		$quality        = array(
			'avif' => isset( $options['avif_quality'] ) ? (int) $options['avif_quality'] : min( $legacy_quality, 85 ),
			'jpeg' => isset( $options['jpeg_quality'] ) ? (int) $options['jpeg_quality'] : $legacy_quality,
			'webp' => isset( $options['webp_quality'] ) ? (int) $options['webp_quality'] : min( $legacy_quality, 90 ),
		);

		foreach ( $quality as $format => $value ) {
			$quality[ $format ] = max( 1, min( 100, (int) apply_filters( "trust_optimize_{$format}_quality", $value ) ) );
		}

		return $quality;
	}

	/**
	 * Get deterministic attachment size names from WordPress metadata.
	 *
	 * @param array $wp_metadata WordPress attachment metadata.
	 * @return array
	 */
	private function get_size_names( array $wp_metadata ) {
		$size_names = array( 'original' );

		if ( isset( $wp_metadata['sizes'] ) && is_array( $wp_metadata['sizes'] ) ) {
			$size_names = array_merge( $size_names, array_keys( $wp_metadata['sizes'] ) );
		}

		$size_names = array_values( array_unique( $size_names ) );
		sort( $size_names );

		return $size_names;
	}
}
