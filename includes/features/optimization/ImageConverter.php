<?php
/**
 * Image Converter class
 *
 * @package TrustOptimize\Features\Optimization
 */

namespace TrustOptimize\Features\Optimization;

use TrustOptimize\Capabilities\CapabilityService;
use TrustOptimize\Domain\VariantStatus;
use TrustOptimize\Files\AtomicImageWriter;
use TrustOptimize\Naming\VariantNaming;
use TrustOptimize\Settings\OptimizationSettings;
use TrustOptimize\Storage\VariantRepository;
use TrustOptimize\Utils\UploadsPath;
use TrustOptimize\Value\OptimizeResult;

/**
 * Class ImageConverter
 *
 * Converts one pending variant row into an image file and records the outcome.
 */
class ImageConverter {

	/**
	 * Variant repository.
	 *
	 * @var VariantRepository
	 */
	private $variants;

	/**
	 * Atomic file writer.
	 *
	 * @var AtomicImageWriter
	 */
	private $writer;

	/**
	 * Capability service.
	 *
	 * @var CapabilityService
	 */
	private $capabilities;

	/**
	 * Constructor.
	 *
	 * @param VariantRepository $variants     Variant repository.
	 * @param AtomicImageWriter $writer       Atomic file writer.
	 * @param CapabilityService $capabilities Capability service.
	 */
	public function __construct( VariantRepository $variants, AtomicImageWriter $writer, CapabilityService $capabilities ) {
		$this->variants     = $variants;
		$this->writer       = $writer;
		$this->capabilities = $capabilities;
	}

	/**
	 * Convert one pending variant.
	 *
	 * The row moves pending -> processing -> done | skipped | failed. A variant that is
	 * not smaller than its source is not kept. A format that no editor can write
	 * is downgraded in the capability service.
	 *
	 * @param array                $variant_row Row from the variants table.
	 * @param OptimizationSettings $settings    Current settings (quality).
	 * @return OptimizeResult Success (done), skipped (reason in message; row_gone when the row was removed meanwhile) or failed (reason in message).
	 */
	public function convert( array $variant_row, OptimizationSettings $settings ) {
		$id     = (int) $variant_row['id'];
		$format = (string) $variant_row['format'];
		$mime   = 'image/' . $format;

		$source_relative = (string) $variant_row['source_relative_path'];
		$target_relative = VariantNaming::target_relative_path( $source_relative, $format );
		$target_path     = UploadsPath::absolute( $target_relative );

		// The row claims its target path before anything is written: a run killed between the rename
		// and the update of the row can then overwrite its own file next time instead of taking it for
		// a foreign one. A file that no row owns is not ours to claim; the writer refuses it.
		$reserve = null !== $target_path && ( ! file_exists( $target_path ) || $this->variants->owns( $target_relative ) );
		$claimed = $reserve && empty( $variant_row['relative_path'] );

		if ( ! $this->variants->transition( $id, VariantStatus::PENDING, VariantStatus::PROCESSING, $reserve ? array( 'relative_path' => $target_relative ) : array() ) ) {
			return OptimizeResult::skipped( 'not_pending' );
		}

		$source_path = UploadsPath::absolute( $source_relative );

		if ( null === $source_path || ! is_file( $source_path ) ) {
			return $this->fail( $id, 'missing_file', 'Source file is missing: ' . $source_relative, $claimed );
		}

		$source_size = (int) wp_filesize( $source_path );
		$quality     = $settings->quality_for( $format );
		$editor      = $this->get_editor_for_target_mime( $source_path, $mime );

		if ( is_wp_error( $editor ) ) {
			if ( 'trust_optimize_unsupported_target_mime' === $editor->get_error_code() ) {
				$this->capabilities->downgrade( $format, $editor->get_error_message() );

				return $this->fail( $id, 'unsupported_format', $editor->get_error_message(), $claimed );
			}

			return $this->fail( $id, 'no_editor', $editor->get_error_message(), $claimed );
		}

		$editor->set_quality( $quality );

		$saved = null === $target_path ? new \WP_Error( 'target_outside_uploads', 'Invalid target path.' ) : $this->writer->save( $editor, $target_path, $mime );

		if ( is_wp_error( $saved ) ) {
			return $this->fail( $id, $this->failure_reason( $saved ), $saved->get_error_message(), $claimed );
		}

		$file_size = (int) wp_filesize( $saved['path'] );

		if ( $file_size >= $source_size ) {
			$published = $this->variants->transition(
				$id,
				VariantStatus::PROCESSING,
				VariantStatus::SKIPPED,
				array(
					'relative_path'    => null,
					'file_hash'        => null,
					'quality'          => $quality,
					'file_size'        => $file_size,
					'source_file_size' => $source_size,
					'reason'           => 'not_smaller',
				)
			);
			$this->discard_unowned_file( $saved['path'], $target_relative );

			return OptimizeResult::skipped( $published ? 'not_smaller' : 'row_gone' );
		}

		$published = $this->variants->transition(
			$id,
			VariantStatus::PROCESSING,
			VariantStatus::DONE,
			array(
				'relative_path'    => $target_relative,
				'width'            => (int) ( $saved['width'] ?? 0 ),
				'height'           => (int) ( $saved['height'] ?? 0 ),
				'quality'          => $quality,
				'file_size'        => $file_size,
				'source_file_size' => $source_size,
				'file_hash'        => (string) hash_file( 'sha256', $saved['path'] ),
				'reason'           => null,
			)
		);

		// The row was removed while the file was being written: nothing may point at the file now.
		if ( ! $published ) {
			$this->discard_unowned_file( $saved['path'], $target_relative );

			return OptimizeResult::skipped( 'row_gone' );
		}

		return OptimizeResult::success( 'done', array( 'relative_path' => $target_relative ) );
	}

	/**
	 * Delete a file the converter has just written unless a variant row still points at it.
	 *
	 * @param string $path          Absolute path of the file.
	 * @param string $relative_path Path relative to uploads.
	 */
	private function discard_unowned_file( $path, $relative_path ) {
		if ( ! $this->variants->owns( $relative_path ) ) {
			wp_delete_file( $path );
		}
	}

	/**
	 * Mark a variant failed.
	 *
	 * @param int    $id      Variant row id.
	 * @param string $reason  Machine-readable reason.
	 * @param string $message Human-readable detail.
	 * @param bool   $release Whether to give up the path this run reserved for a row that had none.
	 * @return OptimizeResult
	 */
	private function fail( $id, $reason, $message, $release = false ) {
		$fields = array( 'reason' => $reason );

		if ( $release ) {
			$fields['relative_path'] = null;
		}

		$this->variants->transition( $id, VariantStatus::PROCESSING, VariantStatus::FAILED, $fields );

		return OptimizeResult::failed( $reason, array( $message ) );
	}

	/**
	 * Reason stored for a writer error.
	 *
	 * @param \WP_Error $error Writer error.
	 * @return string
	 */
	private function failure_reason( \WP_Error $error ) {
		$known = array( 'target_exists_foreign', 'target_outside_uploads', 'unexpected_output', 'rename_failed' );

		return in_array( $error->get_error_code(), $known, true ) ? $error->get_error_code() : 'save_failed';
	}

	/**
	 * Get an image editor that supports the requested output mime type.
	 *
	 * @param string $source_path Source image path.
	 * @param string $target_mime Required output mime type.
	 * @return \WP_Image_Editor|\WP_Error
	 */
	private function get_editor_for_target_mime( $source_path, $target_mime ) {
		$this->load_image_editor_classes();

		$editor = wp_get_image_editor( $source_path );

		if ( is_wp_error( $editor ) ) {
			return $editor;
		}

		$editor_class = get_class( $editor );

		if ( is_callable( array( $editor_class, 'supports_mime_type' ) )
			&& call_user_func( array( $editor_class, 'supports_mime_type' ), $target_mime ) ) {
			return $editor;
		}

		$implementations = array_merge(
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core filter.
			(array) apply_filters( 'wp_image_editors', array( 'WP_Image_Editor_Imagick', 'WP_Image_Editor_GD' ) ),
			array( 'WP_Image_Editor_Imagick', 'WP_Image_Editor_GD' )
		);
		$implementations = array_values( array_unique( array_filter( $implementations ) ) );
		$claimed         = false;

		foreach ( $implementations as $implementation ) {
			if ( $implementation === $editor_class ) {
				continue;
			}

			if ( ! class_exists( $implementation ) ) {
				continue;
			}

			if ( ! is_callable( array( $implementation, 'test' ) ) || ! call_user_func( array( $implementation, 'test' ) ) ) {
				continue;
			}

			if ( ! is_callable( array( $implementation, 'supports_mime_type' ) ) || ! call_user_func( array( $implementation, 'supports_mime_type' ), $target_mime ) ) {
				continue;
			}

			$claimed   = true;
			$candidate = new $implementation( $source_path );
			$loaded    = $candidate->load();

			if ( ! is_wp_error( $loaded ) ) {
				return $candidate;
			}
		}

		// An editor claims the MIME type but could not load this file: that is not a missing format.
		if ( $claimed ) {
			return new \WP_Error(
				'trust_optimize_editor_load_failed',
				sprintf( 'An image editor for %s could not load the source file.', $target_mime )
			);
		}

		return new \WP_Error(
			'trust_optimize_unsupported_target_mime',
			sprintf( 'No available image editor supports target mime type: %s', $target_mime )
		);
	}

	/**
	 * Load bundled WordPress image editor classes before explicit fallback checks.
	 *
	 * The function wp_get_image_editor() may load only the selected implementation. When the
	 * selected editor cannot save a target MIME, fallback implementations such as
	 * GD must be loaded before class_exists()/supports_mime_type() checks.
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
}
