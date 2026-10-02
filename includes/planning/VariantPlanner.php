<?php
/**
 * Decides which variants an attachment should have and reconciles them with the stored rows.
 *
 * @package TrustOptimize
 */

namespace TrustOptimize\Planning;

use TrustOptimize\Admin\Settings;
use TrustOptimize\Capabilities\CapabilityService;
use TrustOptimize\Domain\AttachmentState;
use TrustOptimize\Domain\VariantStatus;
use TrustOptimize\Settings\OptimizationSettings;
use TrustOptimize\Storage\AttachmentRepository;
use TrustOptimize\Storage\VariantRepository;
use TrustOptimize\Utils\UploadsPath;

/**
 * Class VariantPlanner
 */
class VariantPlanner {

	/**
	 * Source MIME types that are converted. WebP/AVIF sources get no variants (M-8).
	 *
	 * @var string[]
	 */
	const SOURCE_MIMES = array( 'image/jpeg', 'image/png' );

	/**
	 * Variant repository.
	 *
	 * @var VariantRepository
	 */
	private $variants;

	/**
	 * Attachment repository.
	 *
	 * @var AttachmentRepository
	 */
	private $attachments;

	/**
	 * Plugin settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Capability service.
	 *
	 * @var CapabilityService
	 */
	private $capabilities;

	/**
	 * Constructor.
	 *
	 * @param VariantRepository    $variants     Variant repository.
	 * @param AttachmentRepository $attachments  Attachment repository.
	 * @param Settings             $settings     Plugin settings.
	 * @param CapabilityService    $capabilities Capability service.
	 */
	public function __construct( VariantRepository $variants, AttachmentRepository $attachments, Settings $settings, CapabilityService $capabilities ) {
		$this->variants     = $variants;
		$this->attachments  = $attachments;
		$this->settings     = $settings;
		$this->capabilities = $capabilities;
	}

	/**
	 * Plan an attachment: store the rows it should have and report what to delete.
	 *
	 * New variants become pending, stale or failed ones are reset to pending, and rows
	 * of disabled formats or vanished sizes are returned for deletion.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return Plan
	 */
	public function plan( $attachment_id ) {
		$attachment_id = (int) $attachment_id;
		$existing      = $this->variants->get_for_attachment( $attachment_id );
		$settings      = OptimizationSettings::from_options( $this->settings, $this->capabilities );
		$source        = $this->eligible_source( $attachment_id );

		if ( is_string( $source ) ) {
			$this->attachments->set_state( $attachment_id, AttachmentState::SKIPPED, $source );

			return new Plan( $source, array(), $existing );
		}

		$desired = self::desired_variants( $source['relative_path'], $source['metadata'], $settings->formats(), $source['mime'] );
		$actions = self::reconcile( $existing, $desired, $settings );

		foreach ( $actions['insert'] as $row ) {
			$this->variants->upsert( $row + array( 'attachment_id' => $attachment_id ) );
		}

		foreach ( $actions['reset'] as $row ) {
			$this->variants->upsert(
				array(
					'attachment_id'        => $attachment_id,
					'size_name'            => $row['size_name'],
					'format'               => $row['format'],
					'status'               => VariantStatus::PENDING,
					'source_relative_path' => $row['source_relative_path'],
					'width'                => $row['width'],
					'height'               => $row['height'],
					'reason'               => null,
				)
			);
		}

		$doomed  = array_column( $actions['delete'], 'id' );
		$pending = array_values(
			array_filter(
				$this->variants->get_for_attachment( $attachment_id ),
				static function ( $row ) use ( $doomed ) {
					return VariantStatus::PENDING === $row['status'] && ! in_array( $row['id'], $doomed, true );
				}
			)
		);

		// With work to do the state is set by whoever queues or claims the attachment.
		if ( empty( $pending ) && AttachmentState::SKIPPED === $this->attachments->recompute( $attachment_id ) ) {
			$this->attachments->set_state( $attachment_id, AttachmentState::NONE );
		}

		return new Plan( null, $pending, $actions['delete'], $actions['replaced'] );
	}

	/**
	 * Read-only preflight for one attachment: what a sync would create or delete.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array Summary (eligible, missing_source_file, unsupported_mime_type, estimates, warnings, errors).
	 */
	public function inventory( $attachment_id ) {
		$attachment_id = (int) $attachment_id;
		$summary       = array(
			'attachment_id'                      => $attachment_id,
			'eligible'                           => false,
			'unsupported_mime_type'              => '',
			'missing_source_file'                => false,
			'already_optimized'                  => false,
			'outdated_profile'                   => false,
			'plugin_managed_variants'            => 0,
			'estimated_variants_to_create'       => 0,
			'estimated_stale_variants_to_delete' => 0,
			'unsupported_output_formats'         => array(),
			'warnings'                           => array(),
			'errors'                             => array(),
		);

		$existing = $this->variants->get_for_attachment( $attachment_id );
		$source   = $this->eligible_source( $attachment_id );

		$summary['plugin_managed_variants'] = count( $existing );

		if ( is_string( $source ) ) {
			if ( 'unsupported_mime' === $source ) {
				$summary['unsupported_mime_type'] = (string) get_post_mime_type( $attachment_id );
				$summary['warnings'][]            = $source;
			} else {
				$summary['missing_source_file'] = true;
				$summary['errors'][]            = 'missing_file';
			}

			return $summary;
		}

		if ( ! is_file( (string) get_attached_file( $attachment_id ) ) ) {
			$summary['missing_source_file'] = true;
			$summary['errors'][]            = 'missing_file';

			return $summary;
		}

		$summary['eligible'] = true;

		$settings = OptimizationSettings::from_options( $this->settings, $this->capabilities );
		foreach ( OptimizationSettings::FORMAT_OPTIONS as $format => $option ) {
			if ( (bool) $this->settings->get( $option, 1 ) && ! $this->capabilities->supports( $format ) ) {
				$summary['unsupported_output_formats'][] = $format;
			}
		}

		$actions = self::reconcile( $existing, self::desired_variants( $source['relative_path'], $source['metadata'], $settings->formats(), $source['mime'] ), $settings );

		$summary['estimated_variants_to_create']       = count( $actions['insert'] ) + count( $actions['reset'] );
		$summary['estimated_stale_variants_to_delete'] = count( $actions['delete'] );
		$summary['outdated_profile']                   = $summary['estimated_stale_variants_to_delete'] > 0;
		$summary['already_optimized']                  = 0 === $summary['estimated_variants_to_create'] && 0 === $summary['estimated_stale_variants_to_delete'];

		return $summary;
	}

	/**
	 * Variants an attachment should have.
	 *
	 * @param string   $original_relative_path Original (or -scaled) file relative to uploads.
	 * @param array    $metadata               Attachment metadata.
	 * @param string[] $formats                Formats to generate.
	 * @param string   $default_mime           MIME type assumed for sizes that do not state one.
	 * @return array[] Entries with size_name, format, source_relative_path, width, height.
	 */
	public static function desired_variants( $original_relative_path, array $metadata, array $formats, $default_mime = 'image/jpeg' ) {
		$sources = array(
			$original_relative_path => array(
				'size_name' => 'original',
				'width'     => (int) ( $metadata['width'] ?? 0 ),
				'height'    => (int) ( $metadata['height'] ?? 0 ),
			),
		);

		$directory = dirname( $original_relative_path );
		$directory = '.' === $directory ? '' : $directory . '/';

		foreach ( (array) ( $metadata['sizes'] ?? array() ) as $size_name => $size ) {
			if ( empty( $size['file'] ) || ! self::is_convertible_mime( $size['mime-type'] ?? $default_mime ) ) {
				continue;
			}

			$path = $directory . $size['file'];
			if ( isset( $sources[ $path ] ) ) {
				continue; // Several sizes may share one file.
			}

			$sources[ $path ] = array(
				'size_name' => (string) $size_name,
				'width'     => (int) ( $size['width'] ?? 0 ),
				'height'    => (int) ( $size['height'] ?? 0 ),
			);
		}

		$desired = array();
		foreach ( $sources as $path => $source ) {
			foreach ( $formats as $format ) {
				$desired[] = array(
					'size_name'            => $source['size_name'],
					'format'               => $format,
					'source_relative_path' => $path,
					'width'                => $source['width'],
					'height'               => $source['height'],
				);
			}
		}

		return $desired;
	}

	/**
	 * Compare stored rows with the desired variants.
	 *
	 * @param array[]              $existing Stored rows.
	 * @param array[]              $desired  Output of desired_variants().
	 * @param OptimizationSettings $settings Current settings.
	 * @return array{insert:array[],reset:array[],delete:array[],replaced:array[]}
	 */
	public static function reconcile( array $existing, array $desired, OptimizationSettings $settings ) {
		$result = array(
			'insert'   => array(),
			'reset'    => array(),
			'delete'   => array(),
			'replaced' => array(),
		);

		$by_key = array();
		foreach ( $existing as $row ) {
			$by_key[ $row['size_name'] . '|' . $row['format'] ] = $row;
		}

		foreach ( $desired as $want ) {
			$key = $want['size_name'] . '|' . $want['format'];

			if ( ! isset( $by_key[ $key ] ) ) {
				$result['insert'][] = $want;
				continue;
			}

			$row = $by_key[ $key ];
			unset( $by_key[ $key ] );

			if ( $row['source_relative_path'] !== $want['source_relative_path'] ) {
				$result['replaced'][] = $row;
				$result['reset'][]    = $want;
				continue;
			}

			$retry = VariantStatus::FAILED === $row['status'];
			$stale = in_array( $row['status'], array( VariantStatus::DONE, VariantStatus::SKIPPED ), true ) && $settings->is_stale( $row );

			if ( $retry || $stale ) {
				$result['reset'][] = $want;
			}
		}

		// What is left has no desired counterpart: format disabled or size gone.
		$result['delete'] = array_values( $by_key );

		return $result;
	}

	/**
	 * Whether a MIME type is converted.
	 *
	 * @param string $mime MIME type.
	 * @return bool
	 */
	private static function is_convertible_mime( $mime ) {
		return in_array( $mime, self::SOURCE_MIMES, true );
	}

	/**
	 * Source data of an attachment, or the reason it is not eligible.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array|string Array with relative_path and metadata, or a skip reason.
	 */
	private function eligible_source( $attachment_id ) {
		$mime = get_post_mime_type( $attachment_id );
		if ( ! self::is_convertible_mime( $mime ) ) {
			return 'unsupported_mime';
		}

		$file = get_attached_file( $attachment_id );
		if ( ! $file ) {
			return 'missing_file';
		}

		$relative = UploadsPath::relative( $file );
		if ( null === $relative ) {
			return 'outside_uploads';
		}

		$metadata = wp_get_attachment_metadata( $attachment_id );

		return array(
			'relative_path' => $relative,
			'metadata'      => is_array( $metadata ) ? $metadata : array(),
			'mime'          => $mime,
		);
	}
}
