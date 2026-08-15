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
	 * @return bool True when a WordPress image editor can save the target MIME.
	 */
	private function detect_output_format_support( $format ) {
		return $this->can_save_probe_image_as( 'image/' . $format, $format );
	}

	/**
	 * Check actual WordPress image editor save support for a target MIME type.
	 *
	 * Static editor capability checks can be overly optimistic for AVIF/WebP in
	 * some builds, and the default editor can be stricter than another available
	 * WordPress editor implementation. Bulk planning must match the conversion
	 * pipeline: a format is supported when at least one WordPress image editor can
	 * load a real source image and save exactly the requested target MIME.
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

		if ( ! $this->create_probe_jpeg( $source_path ) ) {
			return false;
		}

		$supported       = false;
		$implementations = $this->get_image_editor_implementations( $source_path );

		foreach ( $implementations as $implementation ) {
			if ( $this->can_editor_save_probe_as( $implementation, $source_path, $probe_dir, $mime_type, $format ) ) {
				$supported = true;
				break;
			}
		}

		if ( file_exists( $source_path ) ) {
			wp_delete_file( $source_path );
		}

		return $supported;
	}

	/**
	 * Get available image editor implementations in conversion preference order.
	 *
	 * @param string $source_path Source probe path.
	 * @return array Editor class names.
	 */
	private function get_image_editor_implementations( $source_path ) {
		$this->load_image_editor_classes();

		$implementations = array();
		$default_editor  = wp_get_image_editor( $source_path );

		if ( ! is_wp_error( $default_editor ) ) {
			$implementations[] = get_class( $default_editor );
		}

		$implementations = array_merge(
			$implementations,
			(array) apply_filters( 'wp_image_editors', array( 'WP_Image_Editor_Imagick', 'WP_Image_Editor_GD' ) ),
			array( 'WP_Image_Editor_Imagick', 'WP_Image_Editor_GD' )
		);

		return array_values( array_unique( array_filter( $implementations ) ) );
	}

	/**
	 * Load bundled WordPress image editor classes when probing outside admin.
	 *
	 * @return void
	 */
	private function load_image_editor_classes() {
		if ( defined( 'ABSPATH' ) ) {
			require_once ABSPATH . 'wp-includes/class-wp-image-editor.php';
			require_once ABSPATH . 'wp-includes/class-wp-image-editor-gd.php';
			require_once ABSPATH . 'wp-includes/class-wp-image-editor-imagick.php';
		}
	}

	/**
	 * Check one WordPress image editor implementation with a real save.
	 *
	 * @param string $implementation Editor class name.
	 * @param string $source_path    Source probe path.
	 * @param string $probe_dir      Probe directory.
	 * @param string $mime_type      Target MIME type.
	 * @param string $format         Target extension.
	 * @return bool True when the editor saves exactly the requested MIME.
	 */
	private function can_editor_save_probe_as( $implementation, $source_path, $probe_dir, $mime_type, $format ) {
		if ( ! class_exists( $implementation ) ) {
			return false;
		}

		if ( ! is_callable( array( $implementation, 'test' ) ) || ! call_user_func( array( $implementation, 'test' ) ) ) {
			return false;
		}

		if ( ! is_callable( array( $implementation, 'supports_mime_type' ) ) || ! call_user_func( array( $implementation, 'supports_mime_type' ), $mime_type ) ) {
			return false;
		}

		$editor = new $implementation( $source_path );
		$loaded = $editor->load();

		if ( is_wp_error( $loaded ) ) {
			return false;
		}

		$target_path = trailingslashit( $probe_dir ) . 'probe-' . wp_generate_uuid4() . '.' . $format;
		$saved       = $editor->save( $target_path, $mime_type );
		$supported   = ! is_wp_error( $saved )
			&& ! empty( $saved['path'] )
			&& file_exists( $saved['path'] )
			&& ! empty( $saved['mime-type'] )
			&& $mime_type === $saved['mime-type'];

		if ( ! is_wp_error( $saved ) && ! empty( $saved['path'] ) && file_exists( $saved['path'] ) ) {
			wp_delete_file( $saved['path'] );
		}

		if ( file_exists( $target_path ) ) {
			wp_delete_file( $target_path );
		}

		return $supported;
	}

	/**
	 * Create a valid JPEG probe image.
	 *
	 * @param string $source_path Source path.
	 * @return bool True when probe was created.
	 */
	private function create_probe_jpeg( $source_path ) {
		if ( function_exists( 'imagecreatetruecolor' ) && function_exists( 'imagejpeg' ) ) {
			$image = imagecreatetruecolor( 16, 16 );

			if ( false !== $image ) {
				$color = imagecolorallocate( $image, 120, 30, 200 );
				imagefilledrectangle( $image, 0, 0, 15, 15, $color );
				$created = imagejpeg( $image, $source_path, 90 );
				imagedestroy( $image );

				return (bool) $created && file_exists( $source_path );
			}
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents,WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		return false !== file_put_contents( $source_path, base64_decode( '/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////2wBDAf//////////////////////////////////////////////////////////////////////////////////////wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAX/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIQAxAAAAH/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEBAAEFAqf/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAEDAQE/ASP/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAECAQE/ASP/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEBAAY/Aqf/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEBAAE/ISP/2gAMAwEAAgADAAAAEP/EFBQRAQAAAAAAAAAAAAAAAAAAABD/2gAIAQMBAT8QH//EFBQRAQAAAAAAAAAAAAAAAAAAABD/2gAIAQIBAT8QH//EFBABAQAAAAAAAAAAAAAAAAAAABD/2gAIAQEAAT8QH//Z' ) )
			&& file_exists( $source_path );
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
