<?php
/**
 * Reusable single-attachment optimization service.
 *
 * @package TrustOptimize\Service
 */

namespace TrustOptimize\Service;

use TrustOptimize\Admin\Settings;
use TrustOptimize\Database\ImageModel;
use TrustOptimize\Features\Optimization\ImageConverter;
use TrustOptimize\Queue\ConversionQueue;
use TrustOptimize\Value\CapabilityCheck;
use TrustOptimize\Value\ImageProfile;
use TrustOptimize\Value\OptimizeResult;

/**
 * Class ImageOptimizationService
 */
class ImageOptimizationService {

	/**
	 * Image converter instance.
	 *
	 * @var ImageConverter|null
	 */
	private $converter;

	/**
	 * Image model instance.
	 *
	 * @var ImageModel
	 */
	private $image_model;

	/**
	 * Image profile factory instance.
	 *
	 * @var ImageProfileFactory
	 */
	private $profile_factory;

	/**
	 * Constructor.
	 *
	 * @param ImageConverter|null      $converter       Image converter instance.
	 * @param ImageModel|null          $image_model     Image model instance.
	 * @param ImageProfileFactory|null $profile_factory Image profile factory instance.
	 */
	public function __construct( ImageConverter $converter = null, ImageModel $image_model = null, ImageProfileFactory $profile_factory = null ) {
		$this->converter       = $converter;
		$this->image_model     = $image_model ? $image_model : new ImageModel();
		$this->profile_factory = $profile_factory ? $profile_factory : new ImageProfileFactory( new Settings() );
	}

	/**
	 * Check whether an attachment can be optimized.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return CapabilityCheck
	 */
	public function can_optimize( $attachment_id ) {
		$file_path = get_attached_file( $attachment_id );

		if ( ! $file_path || ! file_exists( $file_path ) ) {
			return CapabilityCheck::denied( array( CapabilityCheck::REASON_MISSING_FILE ) );
		}

		$file_type = wp_check_filetype( $file_path );
		$mime_type = isset( $file_type['type'] ) ? $file_type['type'] : '';

		if ( ! in_array( $mime_type, array( 'image/jpeg', 'image/png', 'image/webp', 'image/avif' ), true ) ) {
			return CapabilityCheck::denied(
				array( CapabilityCheck::REASON_UNSUPPORTED_MIME ),
				array( 'mime_type' => $mime_type )
			);
		}

		$editor = wp_get_image_editor( $file_path );
		if ( is_wp_error( $editor ) ) {
			return CapabilityCheck::denied(
				array( CapabilityCheck::REASON_NO_EDITOR ),
				array( 'message' => $editor->get_error_message() )
			);
		}

		return CapabilityCheck::allowed(
			array(
				'file_path' => $file_path,
				'mime_type' => $mime_type,
			)
		);
	}

	/**
	 * Plan desired generated variants for an attachment/profile.
	 *
	 * @param int          $attachment_id Attachment ID.
	 * @param ImageProfile $profile       Current optimization profile.
	 * @return array Variant plan records.
	 */
	public function plan_variants( $attachment_id, ImageProfile $profile ) {
		$file_path = get_attached_file( $attachment_id );

		if ( ! $file_path ) {
			return array();
		}

		$file_type  = wp_check_filetype( $file_path );
		$mime_type  = isset( $file_type['type'] ) ? $file_type['type'] : '';
		$strategies = $this->get_conversion_strategies( $mime_type, $profile );

		if ( empty( $strategies ) ) {
			return array();
		}

		$metadata   = wp_get_attachment_metadata( $attachment_id );
		$size_names = $this->get_size_names( is_array( $metadata ) ? $metadata : array() );
		$variants   = array();

		$quality = $profile->get_quality();

		foreach ( $size_names as $size_name ) {
			foreach ( $strategies as $strategy ) {
				$target_format = $strategy['target_format'];

				$variants[] = array(
					'attachment_id' => (int) $attachment_id,
					'size_name'     => $size_name,
					'target_format' => $target_format,
					'target_mime'   => $strategy['target_mime'],
					'quality'       => isset( $quality[ $target_format ] ) ? (int) $quality[ $target_format ] : null,
					'source_path'   => $this->get_source_path_for_size( $file_path, is_array( $metadata ) ? $metadata : array(), $size_name ),
					'size_info'     => $this->get_size_info( is_array( $metadata ) ? $metadata : array(), $size_name ),
					'profile_hash'  => $profile->get_hash(),
				);
			}
		}

		return $variants;
	}

	/**
	 * Schedule async optimization for an attachment.
	 *
	 * This preserves upload-hook behavior: conversion work is queued, not run
	 * inside the upload request.
	 *
	 * @param int   $attachment_id Attachment ID.
	 * @param array $metadata      WordPress attachment metadata.
	 * @return OptimizeResult
	 */
	public function schedule_attachment_async( $attachment_id, array $metadata ) {
		$profile             = $this->profile_factory->from_wp_metadata( $metadata );
		$unsupported_formats = $this->get_unsupported_output_formats( $profile );

		$this->image_model->save( $attachment_id, $this->image_model->create_base_metadata( $metadata ) );
		$this->image_model->update_profile_hash( $attachment_id, $profile->get_hash() );

		$variants = $this->plan_variants( $attachment_id, $profile );
		if ( empty( $variants ) ) {
			$this->image_model->update_status( $attachment_id, 'completed' );
			return OptimizeResult::skipped(
				empty( $unsupported_formats ) ? 'no_variants' : 'unsupported_output_formats',
				array(
					'unsupported_output_formats' => $unsupported_formats,
					'profile_hash'               => $profile->get_hash(),
				)
			);
		}

		$this->image_model->update_status( $attachment_id, 'pending', count( $variants ) );

		$queue = new ConversionQueue( $this->get_converter() );
		$queue->schedule_variants( $attachment_id, $variants );

		return OptimizeResult::success(
			'scheduled',
			array(
				'total_tasks'                => count( $variants ),
				'profile_hash'               => $profile->get_hash(),
				'unsupported_output_formats' => $unsupported_formats,
			)
		);
	}

	/**
	 * Optimize one attachment through the shared synchronous pipeline.
	 *
	 * This is the canonical single-image path used by REST, WP-CLI and bulk.
	 *
	 * @param int   $attachment_id Attachment ID.
	 * @param array $args          Optional args.
	 * @return OptimizeResult
	 */
	public function optimize_attachment( $attachment_id, array $args = array() ) {
		$capability = $this->can_optimize( $attachment_id );

		if ( ! $capability->is_allowed() ) {
			$reasons = $capability->get_reasons();
			$reason  = ! empty( $reasons ) ? reset( $reasons ) : 'not_allowed';

			if ( CapabilityCheck::REASON_UNSUPPORTED_MIME === $reason ) {
				return OptimizeResult::skipped( $reason, $capability->get_data() );
			}

			return OptimizeResult::failed( $reason, array(), $capability->get_data() );
		}

		$metadata = wp_get_attachment_metadata( $attachment_id );
		if ( ! $metadata || ! is_array( $metadata ) ) {
			return OptimizeResult::failed( 'missing_metadata' );
		}

		$image_data = $this->image_model->get_by_attachment_id( $attachment_id );
		if ( ! $image_data ) {
			$this->image_model->save( $attachment_id, $this->image_model->create_base_metadata( $metadata ) );
		}

		$profile             = isset( $args['profile'] ) && $args['profile'] instanceof ImageProfile ? $args['profile'] : $this->profile_factory->from_wp_metadata( $metadata );
		$unsupported_formats = $this->get_unsupported_output_formats( $profile );
		$desired_variants    = $this->map_variants_by_key( $this->plan_variants( $attachment_id, $profile ), 'target_format' );
		$actual_variants     = $this->map_variants_by_key( $this->image_model->get_generated_variants( $attachment_id ), 'format' );

		$delete_keys      = array_diff( array_keys( $actual_variants ), array_keys( $desired_variants ) );
		$delete_variants  = $this->filter_variants_by_keys( $actual_variants, $delete_keys );
		$errors           = array();
		$completed        = 0;
		$skipped          = 0;
		$deleted          = 0;
		$pending_variants = array();

		$this->image_model->update_profile_hash( $attachment_id, $profile->get_hash() );

		if ( ! empty( $delete_variants ) ) {
			$cleanup_result = $this->get_cleanup_service()->cleanup_variants( $attachment_id, $delete_variants );
			$cleanup_data   = $cleanup_result->get_data();

			$deleted = isset( $cleanup_data['deleted'] ) && is_array( $cleanup_data['deleted'] ) ? count( $cleanup_data['deleted'] ) : 0;

			if ( $cleanup_result->is_failed() || $cleanup_result->is_partial() ) {
				$errors[] = array(
					'operation' => 'delete_no_longer_desired',
					'errors'    => $cleanup_result->get_errors(),
				);
			} else {
				$this->image_model->remove_generated_variants( $attachment_id, $delete_keys );
			}
		}

		if ( empty( $desired_variants ) ) {
			$message = empty( $unsupported_formats ) ? 'no_desired_variants' : 'unsupported_output_formats';
			$status  = empty( $errors ) ? 'skipped' : 'completed_with_errors';
			$this->image_model->update_status( $attachment_id, $status );

			if ( empty( $errors ) ) {
				return OptimizeResult::skipped(
					$message,
					array(
						'completed'                  => 0,
						'skipped'                    => 0,
						'failed'                     => 0,
						'processed'                  => 0,
						'deleted'                    => $deleted,
						'profile_hash'               => $profile->get_hash(),
						'unsupported_output_formats' => $unsupported_formats,
					)
				);
			}

			return OptimizeResult::partial(
				'completed_with_errors',
				$errors,
				array(
					'completed'                  => 0,
					'skipped'                    => 0,
					'failed'                     => count( $errors ),
					'processed'                  => count( $errors ),
					'deleted'                    => $deleted,
					'profile_hash'               => $profile->get_hash(),
					'unsupported_output_formats' => $unsupported_formats,
				)
			);
		}

		foreach ( $desired_variants as $key => $variant ) {
			$actual = isset( $actual_variants[ $key ] ) ? $actual_variants[ $key ] : null;

			if ( $actual && ! $this->image_model->is_variant_stale( $actual, $profile->get_hash() ) && $this->variant_file_exists( $actual, $attachment_id ) ) {
				++$skipped;
				continue;
			}

			$pending_variants[ $key ] = $variant;
		}

		$this->image_model->update_status( $attachment_id, 'processing', count( $pending_variants ) );

		if ( empty( $pending_variants ) ) {
			if ( empty( $errors ) ) {
				$this->image_model->update_status( $attachment_id, 'completed' );
				return OptimizeResult::success(
					'completed',
					array(
						'completed'                  => 0,
						'skipped'                    => $skipped,
						'failed'                     => 0,
						'processed'                  => $skipped,
						'deleted'                    => $deleted,
						'profile_hash'               => $profile->get_hash(),
						'unsupported_output_formats' => $unsupported_formats,
					)
				);
			}

			$this->image_model->update_status( $attachment_id, 'completed_with_errors' );
			return OptimizeResult::partial(
				'completed_with_errors',
				$errors,
				array(
					'completed'                  => 0,
					'skipped'                    => $skipped,
					'failed'                     => count( $errors ),
					'processed'                  => $skipped + count( $errors ),
					'deleted'                    => $deleted,
					'profile_hash'               => $profile->get_hash(),
					'unsupported_output_formats' => $unsupported_formats,
				)
			);
		}

		foreach ( $pending_variants as $variant ) {
			$result = $this->get_converter()->convert_single_size(
				$attachment_id,
				$variant['size_name'],
				$variant['target_format'],
				$variant['target_mime']
			);

			if ( $result ) {
				++$completed;
				$this->image_model->increment_completed_tasks( $attachment_id );
				continue;
			}

			$errors[] = $variant;
			$message  = sprintf(
				'Synchronous conversion failed for attachment %d, size "%s", format "%s".',
				$attachment_id,
				$variant['size_name'],
				$variant['target_format']
			);

			$this->image_model->record_failed_task( $attachment_id, $variant['size_name'], $variant['target_format'], $variant['target_mime'], $message );
		}

		if ( empty( $errors ) ) {
			$this->image_model->update_status( $attachment_id, 'completed' );
			return OptimizeResult::success(
				'completed',
				array(
					'completed'                  => $completed,
					'skipped'                    => $skipped,
					'failed'                     => 0,
					'processed'                  => $completed + $skipped,
					'deleted'                    => $deleted,
					'profile_hash'               => $profile->get_hash(),
					'unsupported_output_formats' => $unsupported_formats,
				)
			);
		}

		if ( $completed > 0 || $skipped > 0 || $deleted > 0 ) {
			$this->image_model->update_status( $attachment_id, 'completed_with_errors' );
			return OptimizeResult::partial(
				'completed_with_errors',
				$errors,
				array(
					'completed'                  => $completed,
					'skipped'                    => $skipped,
					'deleted'                    => $deleted,
					'failed'                     => count( $errors ),
					'processed'                  => $completed + $skipped + count( $errors ),
					'profile_hash'               => $profile->get_hash(),
					'unsupported_output_formats' => $unsupported_formats,
				)
			);
		}

		$this->image_model->update_status( $attachment_id, 'failed' );
		return OptimizeResult::failed(
			'conversion_failed',
			$errors,
			array(
				'completed'                  => $completed,
				'skipped'                    => $skipped,
				'failed'                     => count( $errors ),
				'processed'                  => $completed + $skipped + count( $errors ),
				'deleted'                    => $deleted,
				'profile_hash'               => $profile->get_hash(),
				'unsupported_output_formats' => $unsupported_formats,
			)
		);
	}

	/**
	 * Backward-compatible alias for the canonical single-attachment pipeline.
	 *
	 * @param int   $attachment_id Attachment ID.
	 * @param array $args          Optional args.
	 * @return OptimizeResult
	 */
	public function sync_attachment( $attachment_id, array $args = array() ) {
		return $this->optimize_attachment( $attachment_id, $args );
	}

	/**
	 * Build inventory/preflight data for one attachment without mutating files.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return OptimizeResult
	 */
	public function inventory_attachment( $attachment_id ) {
		$summary = array(
			'attachment_id'                      => (int) $attachment_id,
			'eligible'                           => false,
			'unsupported_mime_type'              => '',
			'missing_source_file'                => false,
			'no_editor'                          => false,
			'already_optimized'                  => false,
			'outdated_profile'                   => false,
			'plugin_managed_variants'            => 0,
			'estimated_variants_to_create'       => 0,
			'estimated_stale_variants_to_delete' => 0,
			'unsupported_output_formats'         => array(),
			'warnings'                           => array(),
			'errors'                             => array(),
		);

		$capability = $this->can_optimize( $attachment_id );
		$metadata   = wp_get_attachment_metadata( $attachment_id );

		if ( ! $capability->is_allowed() ) {
			$reasons = $capability->get_reasons();

			if ( in_array( CapabilityCheck::REASON_MISSING_FILE, $reasons, true ) ) {
				$summary['missing_source_file'] = true;
				$summary['errors'][]            = CapabilityCheck::REASON_MISSING_FILE;
			}

			if ( in_array( CapabilityCheck::REASON_UNSUPPORTED_MIME, $reasons, true ) ) {
				$data                             = $capability->get_data();
				$summary['unsupported_mime_type'] = isset( $data['mime_type'] ) ? $data['mime_type'] : '';
				$summary['warnings'][]            = CapabilityCheck::REASON_UNSUPPORTED_MIME;
			}

			if ( in_array( CapabilityCheck::REASON_NO_EDITOR, $reasons, true ) ) {
				$summary['no_editor'] = true;
				$summary['errors'][]  = CapabilityCheck::REASON_NO_EDITOR;
			}

			return OptimizeResult::skipped( 'not_eligible', $summary );
		}

		if ( ! $metadata || ! is_array( $metadata ) ) {
			$summary['errors'][] = 'missing_metadata';
			return OptimizeResult::failed( 'missing_metadata', array(), $summary );
		}

		$summary['eligible'] = true;

		$profile                               = $this->profile_factory->from_wp_metadata( $metadata );
		$summary['unsupported_output_formats'] = $this->get_unsupported_output_formats( $profile );
		$desired_variants                      = $this->map_variants_by_key( $this->plan_variants( $attachment_id, $profile ), 'target_format' );
		$actual_variants                       = $this->map_variants_by_key( $this->image_model->get_generated_variants( $attachment_id ), 'format' );

		$summary['plugin_managed_variants'] = count( $actual_variants );

		foreach ( $desired_variants as $key => $variant ) {
			$actual = isset( $actual_variants[ $key ] ) ? $actual_variants[ $key ] : null;

			if ( ! $actual || $this->image_model->is_variant_stale( $actual, $profile->get_hash() ) || ! $this->variant_file_exists( $actual, $attachment_id ) ) {
				++$summary['estimated_variants_to_create'];
			}
		}

		foreach ( $actual_variants as $key => $variant ) {
			if ( ! isset( $desired_variants[ $key ] ) || $this->image_model->is_variant_stale( $variant, $profile->get_hash() ) ) {
				++$summary['estimated_stale_variants_to_delete'];
			}
		}

		$summary['outdated_profile']  = $summary['estimated_stale_variants_to_delete'] > 0;
		$summary['already_optimized'] = 0 === $summary['estimated_variants_to_create'] && 0 === $summary['estimated_stale_variants_to_delete'];

		return OptimizeResult::success( 'inventory_checked', $summary );
	}

	/**
	 * Convert one queued variant and update image task state consistently.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $size_name     Attachment size name.
	 * @param string $target_format Target format.
	 * @param string $target_mime   Target MIME type.
	 * @return OptimizeResult
	 */
	public function process_variant_conversion( $attachment_id, $size_name, $target_format, $target_mime ) {
		if ( empty( $attachment_id ) || empty( $size_name ) || empty( $target_format ) || empty( $target_mime ) ) {
			return OptimizeResult::failed( 'invalid_variant_payload' );
		}

		$result = $this->get_converter()->convert_single_size( $attachment_id, $size_name, $target_format, $target_mime );

		if ( $result ) {
			$this->image_model->increment_completed_tasks( $attachment_id );
			return OptimizeResult::success(
				'variant_converted',
				array(
					'attachment_id' => (int) $attachment_id,
					'size_name'     => $size_name,
					'target_format' => $target_format,
				)
			);
		}

		$message = sprintf(
			'Background conversion failed for attachment %d, size "%s", format "%s".',
			$attachment_id,
			$size_name,
			$target_format
		);

		$this->image_model->record_failed_task( $attachment_id, $size_name, $target_format, $target_mime, $message );

		return OptimizeResult::failed(
			'variant_conversion_failed',
			array(
				array(
					'attachment_id' => (int) $attachment_id,
					'size_name'     => $size_name,
					'target_format' => $target_format,
					'target_mime'   => $target_mime,
					'message'       => $message,
				),
			)
		);
	}

	/**
	 * Map variants by size/format key.
	 *
	 * @param array  $variants    Variant records.
	 * @param string $format_key  Field containing the format value.
	 * @return array Variants keyed by "size:format".
	 */
	private function map_variants_by_key( array $variants, $format_key ) {
		$mapped = array();

		foreach ( $variants as $variant ) {
			if ( empty( $variant['size_name'] ) || empty( $variant[ $format_key ] ) ) {
				continue;
			}

			$mapped[ $variant['size_name'] . ':' . $variant[ $format_key ] ] = $variant;
		}

		return $mapped;
	}

	/**
	 * Filter mapped variants by keys.
	 *
	 * @param array $variants Variant records keyed by "size:format".
	 * @param array $keys     Keys to include.
	 * @return array Filtered variant records.
	 */
	private function filter_variants_by_keys( array $variants, array $keys ) {
		$filtered = array();

		foreach ( $keys as $key ) {
			if ( isset( $variants[ $key ] ) ) {
				$filtered[] = $variants[ $key ];
			}
		}

		return $filtered;
	}

	/**
	 * Check whether a manifest variant file exists inside uploads.
	 *
	 * @param array $variant       Generated variant manifest record.
	 * @param int   $attachment_id Attachment ID.
	 * @return bool
	 */
	private function variant_file_exists( array $variant, $attachment_id ) {
		$path = $this->resolve_variant_path( $variant, $attachment_id );

		return '' !== $path && $this->is_inside_uploads( $path ) && file_exists( $path );
	}

	/**
	 * Resolve a manifest variant to an absolute path.
	 *
	 * @param array $variant       Generated variant manifest record.
	 * @param int   $attachment_id Attachment ID.
	 * @return string Absolute target path or empty string.
	 */
	private function resolve_variant_path( array $variant, $attachment_id ) {
		if ( empty( $variant['file'] ) ) {
			return '';
		}

		$upload_dir = wp_upload_dir();
		if ( empty( $upload_dir['basedir'] ) ) {
			return '';
		}

		$file = basename( $variant['file'] );

		if ( isset( $variant['relative_dir'] ) && '' !== $variant['relative_dir'] ) {
			return trailingslashit( $upload_dir['basedir'] ) . trim( $variant['relative_dir'], '/' ) . '/' . $file;
		}

		$attached_file = get_attached_file( $attachment_id );
		if ( $attached_file ) {
			return trailingslashit( dirname( $attached_file ) ) . $file;
		}

		return '';
	}

	/**
	 * Check whether a path is inside uploads basedir.
	 *
	 * @param string $path Absolute path.
	 * @return bool
	 */
	private function is_inside_uploads( $path ) {
		$upload_dir = wp_upload_dir();
		if ( empty( $upload_dir['basedir'] ) ) {
			return false;
		}

		$base = untrailingslashit( wp_normalize_path( $upload_dir['basedir'] ) );
		$path = wp_normalize_path( $path );

		return $path === $base || 0 === strpos( $path, trailingslashit( $base ) );
	}

	/**
	 * Get conversion strategies for a source MIME type and profile.
	 *
	 * @param string       $mime_type Source MIME type.
	 * @param ImageProfile $profile   Current optimization profile.
	 * @return array Conversion strategies.
	 */
	private function get_conversion_strategies( $mime_type, ImageProfile $profile ) {
		if ( in_array( $mime_type, array( 'image/webp', 'image/avif' ), true ) ) {
			return $this->filter_supported_strategies(
				array(
					array(
						'target_format' => 'png',
						'target_mime'   => 'image/png',
					),
				)
			);
		}

		if ( ! in_array( $mime_type, array( 'image/jpeg', 'image/png' ), true ) ) {
			return array();
		}

		$strategies = array();
		foreach ( $profile->get_formats() as $format ) {
			$strategies[] = array(
				'target_format' => $format,
				'target_mime'   => 'image/' . $format,
			);
		}

		return $this->filter_supported_strategies( $strategies );
	}

	/**
	 * Remove strategies unsupported by the current image editor stack.
	 *
	 * @param array $strategies Conversion strategies.
	 * @return array Supported conversion strategies.
	 */
	private function filter_supported_strategies( array $strategies ) {
		$supported = array();

		foreach ( $strategies as $strategy ) {
			if ( empty( $strategy['target_format'] ) || ! $this->profile_factory->is_output_format_supported( $strategy['target_format'] ) ) {
				continue;
			}

			$supported[] = $strategy;
		}

		return $supported;
	}

	/**
	 * Get unsupported output formats recorded in the profile.
	 *
	 * @param ImageProfile $profile Image optimization profile.
	 * @return array Unsupported requested output formats.
	 */
	private function get_unsupported_output_formats( ImageProfile $profile ) {
		$options = $profile->get_options();

		if ( empty( $options['unsupported_output_formats'] ) || ! is_array( $options['unsupported_output_formats'] ) ) {
			return array();
		}

		return array_values( array_unique( $options['unsupported_output_formats'] ) );
	}

	/**
	 * Get attachment size names from metadata.
	 *
	 * @param array $metadata WordPress attachment metadata.
	 * @return array Size names.
	 */
	private function get_size_names( array $metadata ) {
		$size_names = array( 'original' );

		if ( isset( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
			$size_names = array_merge( $size_names, array_keys( $metadata['sizes'] ) );
		}

		return array_values( array_unique( $size_names ) );
	}

	/**
	 * Get source path for a planned image size.
	 *
	 * @param string $file_path Original attached file path.
	 * @param array  $metadata  WordPress attachment metadata.
	 * @param string $size_name Attachment size name.
	 * @return string Source path or empty string.
	 */
	private function get_source_path_for_size( $file_path, array $metadata, $size_name ) {
		if ( 'original' === $size_name ) {
			return $file_path;
		}

		if ( empty( $metadata['sizes'][ $size_name ]['file'] ) ) {
			return '';
		}

		return trailingslashit( dirname( $file_path ) ) . $metadata['sizes'][ $size_name ]['file'];
	}

	/**
	 * Get metadata for a planned image size.
	 *
	 * @param array  $metadata  WordPress attachment metadata.
	 * @param string $size_name Attachment size name.
	 * @return array Size info for queue payload/debugging.
	 */
	private function get_size_info( array $metadata, $size_name ) {
		if ( 'original' === $size_name ) {
			return array(
				'width'  => isset( $metadata['width'] ) ? (int) $metadata['width'] : 0,
				'height' => isset( $metadata['height'] ) ? (int) $metadata['height'] : 0,
				'file'   => isset( $metadata['file'] ) ? basename( $metadata['file'] ) : '',
			);
		}

		if ( isset( $metadata['sizes'][ $size_name ] ) && is_array( $metadata['sizes'][ $size_name ] ) ) {
			return $metadata['sizes'][ $size_name ];
		}

		return array();
	}

	/**
	 * Get image converter instance.
	 *
	 * @return ImageConverter
	 */
	private function get_converter() {
		if ( ! $this->converter ) {
			$this->converter = new ImageConverter();
		}

		return $this->converter;
	}

	/**
	 * Get cleanup service instance.
	 *
	 * @return ImageCleanupService
	 */
	private function get_cleanup_service() {
		return new ImageCleanupService( $this->image_model );
	}
}
