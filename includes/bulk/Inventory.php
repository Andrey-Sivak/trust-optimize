<?php
/**
 * Library inventory computed with set-based queries.
 *
 * @package TrustOptimize\Bulk
 */

namespace TrustOptimize\Bulk;

use TrustOptimize\Admin\Settings;
use TrustOptimize\Capabilities\CapabilityService;
use TrustOptimize\Planning\ImageLimits;
use TrustOptimize\Planning\VariantPlanner;
use TrustOptimize\Settings\OptimizationSettings;
use TrustOptimize\Storage\AttachmentRepository;
use TrustOptimize\Storage\VariantRepository;

/**
 * Class Inventory
 *
 * Counts come from SQL aggregates. Only the two facts the database does not hold, whether the
 * file exists and how large the image is, are read per attachment by the inventory job, and
 * without decoding anything.
 */
class Inventory {

	/**
	 * Eligibility query.
	 *
	 * @var EligibilityQuery
	 */
	private $eligibility;

	/**
	 * Attachment repository.
	 *
	 * @var AttachmentRepository
	 */
	private $attachments;

	/**
	 * Variant repository.
	 *
	 * @var VariantRepository
	 */
	private $variants;

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
	 * @param EligibilityQuery     $eligibility  Eligibility query.
	 * @param AttachmentRepository $attachments  Attachment repository.
	 * @param VariantRepository    $variants     Variant repository.
	 * @param Settings             $settings     Plugin settings.
	 * @param CapabilityService    $capabilities Capability service.
	 */
	public function __construct( EligibilityQuery $eligibility, AttachmentRepository $attachments, VariantRepository $variants, Settings $settings, CapabilityService $capabilities ) {
		$this->eligibility  = $eligibility;
		$this->attachments  = $attachments;
		$this->variants     = $variants;
		$this->settings     = $settings;
		$this->capabilities = $capabilities;
	}

	/**
	 * The aggregates.
	 *
	 * @return array total_images, eligible_attachments, unsupported_mime_types (per MIME), attachment_states,
	 *               variant_statuses, outdated_variants and unsupported_output_formats.
	 */
	public function summary() {
		$settings = OptimizationSettings::from_options( $this->settings, $this->capabilities );
		$by_mime  = $this->eligibility->count_images_by_mime();
		$eligible = array_intersect_key( $by_mime, array_flip( VariantPlanner::SOURCE_MIMES ) );

		return array(
			'total_images'               => array_sum( $by_mime ),
			'eligible_attachments'       => array_sum( $eligible ),
			'unsupported_mime_types'     => array_diff_key( $by_mime, $eligible ),
			'attachment_states'          => $this->attachments->count_states(),
			'variant_statuses'           => $this->variants->count_all_by_status(),
			'outdated_variants'          => $this->variants->count_outdated( $settings ),
			'unsupported_output_formats' => array_values( array_diff( $settings->enabled_formats(), $settings->plannable_formats() ) ),
		);
	}

	/**
	 * Check a batch of attachments: does the file exist, is the image too large to convert.
	 *
	 * @param int[] $attachment_ids Attachment IDs.
	 * @return int[] missing_source_files and oversized_attachments in the batch.
	 */
	public function inspect( array $attachment_ids ) {
		$counts = array(
			'missing_source_files'  => 0,
			'oversized_attachments' => 0,
		);

		foreach ( $attachment_ids as $attachment_id ) {
			$file = get_attached_file( $attachment_id );

			if ( ! $file || ! is_file( $file ) ) {
				++$counts['missing_source_files'];
				continue;
			}

			$metadata = wp_get_attachment_metadata( $attachment_id );

			if ( null !== ImageLimits::skip_reason( (int) ( $metadata['width'] ?? 0 ), (int) ( $metadata['height'] ?? 0 ) ) ) {
				++$counts['oversized_attachments'];
			}
		}

		return $counts;
	}
}
