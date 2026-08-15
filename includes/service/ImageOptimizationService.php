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

		foreach ( $size_names as $size_name ) {
			foreach ( $strategies as $strategy ) {
				$variants[] = array(
					'attachment_id' => (int) $attachment_id,
					'size_name'     => $size_name,
					'target_format' => $strategy['target_format'],
					'target_mime'   => $strategy['target_mime'],
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
		$profile = $this->profile_factory->from_wp_metadata( $metadata );

		$this->image_model->save( $attachment_id, $this->image_model->create_base_metadata( $metadata ) );
		$this->image_model->update_profile_hash( $attachment_id, $profile->get_hash() );

		$variants = $this->plan_variants( $attachment_id, $profile );
		if ( empty( $variants ) ) {
			$this->image_model->update_status( $attachment_id, 'completed' );
			return OptimizeResult::skipped( 'no_variants' );
		}

		$this->image_model->update_status( $attachment_id, 'pending', count( $variants ) );

		$queue = new ConversionQueue( $this->get_converter() );
		$queue->schedule_conversions( $attachment_id, $this->get_unique_size_names( $variants ), $this->get_unique_strategies( $variants ) );

		return OptimizeResult::success(
			'scheduled',
			array(
				'total_tasks'  => count( $variants ),
				'profile_hash' => $profile->get_hash(),
			)
		);
	}

	/**
	 * Synchronously optimize an attachment.
	 *
	 * @param int   $attachment_id Attachment ID.
	 * @param array $args          Optional args.
	 * @return OptimizeResult
	 */
	public function sync_attachment( $attachment_id, array $args = array() ) {
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

		$profile  = isset( $args['profile'] ) && $args['profile'] instanceof ImageProfile ? $args['profile'] : $this->profile_factory->from_wp_metadata( $metadata );
		$variants = $this->plan_variants( $attachment_id, $profile );

		if ( empty( $variants ) ) {
			$this->image_model->update_status( $attachment_id, 'completed' );
			return OptimizeResult::skipped( 'no_variants' );
		}

		$errors    = array();
		$completed = 0;

		$this->image_model->save( $attachment_id, $this->image_model->create_base_metadata( $metadata ) );
		$this->image_model->update_profile_hash( $attachment_id, $profile->get_hash() );
		$this->image_model->update_status( $attachment_id, 'processing', count( $variants ) );

		foreach ( $variants as $variant ) {
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
		}

		if ( empty( $errors ) ) {
			return OptimizeResult::success(
				'completed',
				array(
					'completed'    => $completed,
					'profile_hash' => $profile->get_hash(),
				)
			);
		}

		if ( $completed > 0 ) {
			return OptimizeResult::partial(
				'completed_with_errors',
				$errors,
				array(
					'completed'    => $completed,
					'failed'       => count( $errors ),
					'profile_hash' => $profile->get_hash(),
				)
			);
		}

		$this->image_model->update_status( $attachment_id, 'failed' );
		return OptimizeResult::failed( 'conversion_failed', $errors );
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
			return array(
				array(
					'target_format' => 'png',
					'target_mime'   => 'image/png',
				),
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

		return $strategies;
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
	 * Get unique size names from variant plan.
	 *
	 * @param array $variants Variant plan records.
	 * @return array Size names.
	 */
	private function get_unique_size_names( array $variants ) {
		return array_values( array_unique( wp_list_pluck( $variants, 'size_name' ) ) );
	}

	/**
	 * Get unique conversion strategies from variant plan.
	 *
	 * @param array $variants Variant plan records.
	 * @return array Conversion strategies.
	 */
	private function get_unique_strategies( array $variants ) {
		$strategies = array();

		foreach ( $variants as $variant ) {
			$key = $variant['target_format'] . ':' . $variant['target_mime'];

			if ( isset( $strategies[ $key ] ) ) {
				continue;
			}

			$strategies[ $key ] = array(
				'target_format' => $variant['target_format'],
				'target_mime'   => $variant['target_mime'],
			);
		}

		return array_values( $strategies );
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
}
