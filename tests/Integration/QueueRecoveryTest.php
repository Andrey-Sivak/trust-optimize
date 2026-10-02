<?php
/**
 * Queued work is never lost after a run or a crashed worker (02.14).
 *
 * @package TrustOptimize\Tests
 */

use TrustOptimize\Capabilities\CapabilityService;
use TrustOptimize\Core\Plugin;
use TrustOptimize\Database\DatabaseManager;
use TrustOptimize\Domain\AttachmentState;
use TrustOptimize\Domain\VariantStatus;
use TrustOptimize\Queue\ConversionQueue;
use TrustOptimize\Storage\AttachmentRepository;
use TrustOptimize\Storage\VariantRepository;

/**
 * @covers \TrustOptimize\Processing\AttachmentProcessor::run
 * @covers \TrustOptimize\Storage\AttachmentRepository::mark_queued
 */
class QueueRecoveryTest extends WP_UnitTestCase {

	/**
	 * Variants.
	 *
	 * @var VariantRepository
	 */
	private $variants;

	/**
	 * Attachments.
	 *
	 * @var AttachmentRepository
	 */
	private $attachments;

	public function set_up() {
		parent::set_up();
		update_option( CapabilityService::OPTION, array( 'webp' => true, 'avif' => true ) );
		update_option( 'trust_optimize_options', array( 'convert_to_webp' => 1, 'convert_to_avif' => 0 ) );
		as_unschedule_all_actions( ConversionQueue::HOOK_PROCESS );

		$database          = new DatabaseManager();
		$this->variants    = new VariantRepository( $database );
		$this->attachments = new AttachmentRepository( $database, $this->variants );
	}

	private function pending_actions( $id ) {
		return as_get_scheduled_actions(
			array(
				'hook'   => ConversionQueue::HOOK_PROCESS,
				'status' => ActionScheduler_Store::STATUS_PENDING,
				'args'   => array( 'attachment_id' => $id ),
			),
			'ids'
		);
	}

	public function test_pending_rows_added_during_a_run_are_not_lost() {
		$id = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		as_unschedule_all_actions( ConversionQueue::HOOK_PROCESS );
		$source = $this->variants->get_for_attachment( $id )[0]['source_relative_path'];

		// While the first conversion runs, a regeneration adds another pending row.
		$injected = false;
		add_filter(
			'wp_image_editors',
			function ( $editors ) use ( &$injected, $id, $source ) {
				if ( ! $injected ) {
					$injected = true;
					$this->variants->upsert(
						array(
							'attachment_id'        => $id,
							'size_name'            => 'late',
							'format'               => 'webp',
							'source_relative_path' => $source,
						)
					);
				}

				return $editors;
			}
		);

		Plugin::get_instance()->conversion_queue->process( $id );

		$late = array_values(
			array_filter(
				$this->variants->get_for_attachment( $id ),
				static function ( $row ) {
					return 'late' === $row['size_name'];
				}
			)
		)[0];

		$this->assertTrue( VariantStatus::PENDING === $late['status'] ? ! empty( $this->pending_actions( $id ) ) : VariantStatus::DONE === $late['status'], 'A pending row must be either processed or backed by a queued action.' );

		ActionScheduler_QueueRunner::instance()->run();

		$statuses = array_unique( array_column( $this->variants->get_for_attachment( $id ), 'status' ) );
		$this->assertSame( array( VariantStatus::DONE ), $statuses );
		$this->assertSame( AttachmentState::OPTIMIZED, $this->attachments->get_state( $id ) );
	}

	public function test_a_crashed_worker_does_not_block_queueing() {
		$id = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		as_unschedule_all_actions( ConversionQueue::HOOK_PROCESS );
		$this->attachments->claim( $id );

		// A fresh claim blocks queueing.
		$this->assertFalse( Plugin::get_instance()->conversion_queue->enqueue( $id ) );

		// The worker died 20 minutes ago.
		global $wpdb;
		$tables = ( new DatabaseManager() )->get_plugin_table_names();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "UPDATE `{$tables['attachments']}` SET updated_at = %s WHERE attachment_id = %d", gmdate( 'Y-m-d H:i:s', time() - 1200 ), $id ) );

		$this->assertTrue( Plugin::get_instance()->conversion_queue->enqueue( $id ) );
		$this->assertCount( 1, $this->pending_actions( $id ) );

		ActionScheduler_QueueRunner::instance()->run();
		$this->assertSame( AttachmentState::OPTIMIZED, $this->attachments->get_state( $id ) );
	}
}
