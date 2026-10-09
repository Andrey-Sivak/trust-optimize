<?php
/**
 * Inventory with set-based queries (04.5).
 *
 * @package TrustOptimize\Tests
 */

use TrustOptimize\Bulk\BulkJob;
use TrustOptimize\Capabilities\CapabilityService;
use TrustOptimize\Core\Plugin;
use TrustOptimize\Database\DatabaseManager;
use TrustOptimize\Domain\AttachmentState;
use TrustOptimize\Domain\JobStatus;
use TrustOptimize\Queue\ConversionQueue;
use TrustOptimize\Storage\AttachmentRepository;
use TrustOptimize\Storage\VariantRepository;

/**
 * @covers \TrustOptimize\Bulk\Inventory
 * @covers \TrustOptimize\Storage\VariantRepository::count_outdated
 */
class InventoryTest extends WP_UnitTestCase {

	/**
	 * Calls of wp_image_editors since the last reset.
	 *
	 * @var int
	 */
	private $editor_calls = 0;

	/**
	 * Variants.
	 *
	 * @var VariantRepository
	 */
	private $variants;

	public function set_up() {
		parent::set_up();
		update_option( CapabilityService::OPTION, array( 'webp' => true, 'avif' => false ) );
		update_option( 'trust_optimize_options', array( 'convert_to_webp' => 1, 'convert_to_avif' => 0, 'webp_quality' => 80 ) );
		as_unschedule_all_actions( ConversionQueue::HOOK_PROCESS );

		$this->variants = new VariantRepository( new DatabaseManager() );
	}

	private function upload( $file = 'test-image.jpg' ) {
		return self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/' . $file );
	}

	public function test_the_summary_counts_images_by_mime_state_and_staleness() {
		$jpeg = $this->upload();
		self::factory()->attachment->create(
			array(
				'post_mime_type' => 'image/webp',
				'file'           => '2026/05/source.webp',
			)
		);
		ActionScheduler_QueueRunner::instance()->run();

		$summary = Plugin::get_instance()->inventory->summary();

		$this->assertSame( 2, $summary['total_images'] );
		$this->assertSame( 1, $summary['eligible_attachments'] );
		$this->assertSame( array( 'image/webp' => 1 ), $summary['unsupported_mime_types'] );
		$this->assertSame( 1, $summary['attachment_states'][ AttachmentState::OPTIMIZED ] );
		$this->assertGreaterThan( 0, $summary['variant_statuses']['done'] );
		$this->assertSame( 0, $summary['outdated_variants'] );

		update_option( 'trust_optimize_options', array( 'convert_to_webp' => 1, 'convert_to_avif' => 0, 'webp_quality' => 70 ) );
		$done = $summary['variant_statuses']['done'];
		$this->assertSame( $done, Plugin::get_instance()->inventory->summary()['outdated_variants'], 'Another quality makes every variant outdated.' );

		update_option( 'trust_optimize_options', array( 'convert_to_webp' => 0, 'convert_to_avif' => 0 ) );
		$this->assertSame( $done, Plugin::get_instance()->inventory->summary()['outdated_variants'], 'A format that is switched off is outdated.' );
		$this->assertNotEmpty( $this->variants->get_for_attachment( $jpeg ) );
	}

	public function test_enabled_formats_this_server_cannot_write_are_listed() {
		update_option( 'trust_optimize_options', array( 'convert_to_webp' => 1, 'convert_to_avif' => 1 ) );

		$this->assertSame( array( 'avif' ), Plugin::get_instance()->inventory->summary()['unsupported_output_formats'] );
	}

	public function test_the_inventory_job_walks_the_library_without_an_image_editor() {
		$this->upload();
		$missing = $this->upload();
		$big     = $this->upload();
		unlink( get_attached_file( $missing ) );
		$metadata = wp_get_attachment_metadata( $big );
		wp_update_attachment_metadata( $big, array_merge( $metadata, array( 'width' => 12000, 'height' => 12000 ) ) );

		add_filter(
			'wp_image_editors',
			function ( $editors ) {
				++$this->editor_calls;

				return $editors;
			}
		);

		$producer = Plugin::get_instance()->bulk_producer;
		$job      = $producer->launch( BulkJob::TYPE_INVENTORY );
		$producer->produce( $job->get_id() );
		$producer->produce( $job->get_id() );

		$job = ( new TrustOptimize\Bulk\BulkJobRepository( new DatabaseManager() ) )->get( $job->get_id() );

		$this->assertSame( JobStatus::COMPLETED, $job->get_status() );
		$this->assertSame( 0, $this->editor_calls, 'The inventory never asks for an image editor.' );
		$inventory = $job->get_settings_snapshot()['inventory'];
		$this->assertSame( 1, $inventory['missing_source_files']);
		$this->assertSame( 1, $inventory['oversized_attachments'] );
		$this->assertSame( 3, $inventory['eligible_attachments'] );
	}
}
