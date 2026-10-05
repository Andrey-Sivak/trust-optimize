<?php
/**
 * Bulk jobs: a producer hands attachments to the queue, progress is derived (04.3).
 *
 * @package TrustOptimize\Tests
 */

use TrustOptimize\Bulk\BulkJob;
use TrustOptimize\Bulk\BulkJobRepository;
use TrustOptimize\Bulk\BulkProducer;
use TrustOptimize\Bulk\JobProgress;
use TrustOptimize\Capabilities\CapabilityService;
use TrustOptimize\Core\Plugin;
use TrustOptimize\Database\DatabaseManager;
use TrustOptimize\Domain\AttachmentState;
use TrustOptimize\Domain\JobStatus;
use TrustOptimize\Domain\VariantStatus;
use TrustOptimize\Queue\ConversionQueue;
use TrustOptimize\Storage\AttachmentRepository;
use TrustOptimize\Storage\VariantRepository;

/**
 * @covers \TrustOptimize\Bulk\BulkProducer
 * @covers \TrustOptimize\Bulk\BulkJobRepository
 * @covers \TrustOptimize\Bulk\JobProgress
 */
class BulkProducerTest extends WP_UnitTestCase {

	/**
	 * Producer.
	 *
	 * @var BulkProducer
	 */
	private $producer;

	/**
	 * Jobs.
	 *
	 * @var BulkJobRepository
	 */
	private $jobs;

	/**
	 * Attachments.
	 *
	 * @var AttachmentRepository
	 */
	private $attachments;

	/**
	 * Variants.
	 *
	 * @var VariantRepository
	 */
	private $variants;

	/**
	 * Directory of the uploaded fixtures with a distinctive name.
	 *
	 * @var string[]
	 */
	private $temp_files = array();

	public function set_up() {
		parent::set_up();
		update_option( CapabilityService::OPTION, array( 'webp' => true, 'avif' => false ) );
		update_option( 'trust_optimize_options', array( 'convert_to_webp' => 1, 'convert_to_avif' => 0 ) );
		as_unschedule_all_actions( ConversionQueue::HOOK_PROCESS );
		as_unschedule_all_actions( BulkProducer::HOOK_PRODUCE );

		// Uploads must not queue themselves: the job is what puts them on the queue.
		remove_filter( 'wp_generate_attachment_metadata', array( Plugin::get_instance()->conversion_queue, 'handle_new_metadata' ), 20 );

		$database          = new DatabaseManager();
		$this->variants    = new VariantRepository( $database );
		$this->attachments = new AttachmentRepository( $database, $this->variants );
		$this->jobs        = new BulkJobRepository( $database );
		$this->producer    = Plugin::get_instance()->bulk_producer;
	}

	public function tear_down() {
		add_filter( 'wp_generate_attachment_metadata', array( Plugin::get_instance()->conversion_queue, 'handle_new_metadata' ), 20, 2 );

		foreach ( $this->temp_files as $file ) {
			if ( file_exists( $file ) ) {
				unlink( $file );
			}
		}

		parent::tear_down();
	}

	/**
	 * Upload a small image, optionally under a given file name.
	 *
	 * @param string $name File name, or empty for the fixture's own.
	 * @return int
	 */
	private function upload( $name = '' ) {
		$source = DIR_TESTDATA . '/images/test-image.jpg';

		if ( '' !== $name ) {
			$copy = get_temp_dir() . $name;
			copy( $source, $copy );
			$this->temp_files[] = $copy;
			$source             = $copy;
		}

		return self::factory()->attachment->create_upload_object( $source );
	}

	/**
	 * Let Action Scheduler and the producer work until the job is finished.
	 *
	 * Producer runs that wait for the queue are scheduled in the future, so the callback is called directly.
	 *
	 * @param int $job_id Job ID.
	 * @return BulkJob
	 */
	private function drive( $job_id ) {
		for ( $round = 0; $round < 15; $round++ ) {
			ActionScheduler_QueueRunner::instance()->run();

			if ( ! in_array( $this->jobs->get( $job_id )->get_status(), JobStatus::active(), true ) ) {
				break;
			}

			$this->producer->produce( $job_id );
		}

		return $this->jobs->get( $job_id );
	}

	private function pending( $hook ) {
		return as_get_scheduled_actions(
			array(
				'hook'   => $hook,
				'status' => ActionScheduler_Store::STATUS_PENDING,
			),
			'ids'
		);
	}

	public function test_one_crashing_attachment_does_not_stop_the_job() {
		$good = array();
		for ( $i = 0; $i < 29; $i++ ) {
			$good[] = $this->upload();
			if ( 14 === $i ) {
				$poison = $this->upload( 'poison.jpg' );
			}
		}

		add_filter(
			'image_editor_output_format',
			static function ( $formats, $filename ) {
				if ( false !== strpos( basename( $filename ), 'poison' ) ) {
					// Action Scheduler turns E_USER_ERROR into an exception too; trigger_error( E_USER_ERROR ) is deprecated in PHP 8.4.
					throw new RuntimeException( 'The image editor crashed.' );
				}

				return $formats;
			},
			10,
			2
		);

		$job = $this->producer->launch( BulkJob::TYPE_SYNC );
		$this->assertInstanceOf( BulkJob::class, $job );
		$this->assertSame( 30, $job->get_total() );

		$job = $this->drive( $job->get_id() );

		$this->assertSame( JobStatus::COMPLETED_WITH_ERRORS, $job->get_status() );
		foreach ( $good as $id ) {
			$this->assertSame( AttachmentState::OPTIMIZED, $this->attachments->get_state( $id ), "Attachment {$id} must be optimized." );
		}
		$this->assertSame( AttachmentState::FAILED, $this->attachments->get_state( $poison ) );
		$this->assertSame( 'exception', $this->attachments->get( $poison )['reason'] );

		$data = ( new JobProgress( $this->attachments, new TrustOptimize\Bulk\EligibilityQuery( $this->variants ) ) )->describe( $job );
		$this->assertSame( 30, $data['processed'] );
		$this->assertSame( 1, $data['failed_count'] );
		$this->assertSame( 29, $data['created_count'] );
	}

	public function test_a_second_job_cannot_be_created_while_one_is_active() {
		$first = $this->producer->launch( BulkJob::TYPE_SYNC );

		$this->assertInstanceOf( BulkJob::class, $first );
		$this->assertFalse( $this->producer->launch( BulkJob::TYPE_SYNC ) );
		$this->assertFalse( $this->jobs->create( BulkJob::TYPE_REMOVE, array( 'x' => 1 ), 0 ) );

		$this->producer->cancel( $first->get_id() );

		$this->assertInstanceOf( BulkJob::class, $this->producer->launch( BulkJob::TYPE_SYNC ), 'A finished job releases the library.' );
	}

	public function test_a_mutex_left_behind_by_a_crashed_request_is_taken_over() {
		global $wpdb;

		// A request that died between taking the mutex and inserting the job.
		$wpdb->insert(
			$wpdb->options,
			array(
				'option_name'  => BulkJobRepository::ACTIVE_OPTION,
				'option_value' => (string) ( time() - 1000 ),
				'autoload'     => 'no',
			)
		);

		$this->assertInstanceOf( BulkJob::class, $this->jobs->create( BulkJob::TYPE_SYNC, array( 'x' => 1 ), 0 ) );

		// A request that is taking the mutex right now keeps it.
		$this->jobs->cancel( $this->jobs->get_latest_job()->get_id() );
		$wpdb->insert(
			$wpdb->options,
			array(
				'option_name'  => BulkJobRepository::ACTIVE_OPTION,
				'option_value' => (string) time(),
				'autoload'     => 'no',
			)
		);

		$this->assertFalse( $this->jobs->create( BulkJob::TYPE_SYNC, array( 'x' => 1 ), 0 ) );
	}

	public function test_deleting_an_attachment_cancels_only_its_own_task() {
		$a = $this->upload();
		$b = $this->upload();
		$c = $this->upload();

		$job = $this->producer->launch( BulkJob::TYPE_SYNC );
		$this->producer->produce( $job->get_id() );

		$this->assertCount( 3, $this->pending( ConversionQueue::HOOK_PROCESS ) );

		wp_delete_attachment( $b, true );

		$this->assertCount( 2, $this->pending( ConversionQueue::HOOK_PROCESS ) );
		$this->assertNotEmpty( $this->pending( BulkProducer::HOOK_PRODUCE ), 'The job itself goes on.' );

		$job = $this->drive( $job->get_id() );

		$this->assertSame( JobStatus::COMPLETED, $job->get_status() );
		$this->assertSame( AttachmentState::OPTIMIZED, $this->attachments->get_state( $a ) );
		$this->assertSame( AttachmentState::OPTIMIZED, $this->attachments->get_state( $c ) );
	}

	public function test_the_producer_waits_while_too_many_tasks_are_pending() {
		$ids = array( $this->upload(), $this->upload(), $this->upload() );
		$job = $this->producer->launch( BulkJob::TYPE_SYNC );
		as_unschedule_all_actions( BulkProducer::HOOK_PRODUCE );

		Plugin::get_instance()->conversion_queue->enqueue( $ids[0] );
		Plugin::get_instance()->conversion_queue->enqueue( $ids[1] );
		add_filter(
			'trust_optimize_bulk_max_pending',
			static function () {
				return 1;
			}
		);

		$this->producer->produce( $job->get_id() );

		$this->assertSame( array(), $this->attachments->count_states_for_job( $job->get_id() ), 'Nothing is handed over.' );
		$this->assertSame( 0, $this->jobs->get( $job->get_id() )->get_cursor_id() );
		$next = as_next_scheduled_action( BulkProducer::HOOK_PRODUCE, array( 'job_id' => $job->get_id() ), ConversionQueue::GROUP );
		$this->assertGreaterThan( time() + BulkProducer::WAIT_SECONDS - 5, $next );
		$this->assertSame( JobStatus::RUNNING, $this->jobs->get( $job->get_id() )->get_status() );
	}

	public function test_a_paused_job_is_not_produced_and_resumes() {
		$id  = $this->upload();
		$job = $this->producer->launch( BulkJob::TYPE_SYNC );

		$this->assertTrue( $this->producer->pause( $job->get_id() ) );
		$this->assertSame( array(), $this->pending( BulkProducer::HOOK_PRODUCE ) );
		$this->producer->produce( $job->get_id() );
		$this->assertSame( AttachmentState::NONE, $this->attachments->get_state( $id ) );

		$this->assertTrue( $this->producer->resume( $job->get_id() ) );
		$job = $this->drive( $job->get_id() );

		$this->assertSame( JobStatus::COMPLETED, $job->get_status() );
		$this->assertSame( AttachmentState::OPTIMIZED, $this->attachments->get_state( $id ) );
	}

	public function test_a_finished_job_is_not_revived_by_resume_pause_or_cancel() {
		$this->upload();
		$job = $this->drive( $this->producer->launch( BulkJob::TYPE_SYNC )->get_id() );
		$this->assertSame( JobStatus::COMPLETED, $job->get_status() );

		$this->assertFalse( $this->producer->resume( $job->get_id() ) );
		$this->assertFalse( $this->producer->pause( $job->get_id() ) );
		$this->assertFalse( $this->producer->cancel( $job->get_id() ) );
		$this->assertSame( JobStatus::COMPLETED, $this->jobs->get( $job->get_id() )->get_status() );
	}

	public function test_only_jpeg_and_png_attachments_are_visited() {
		$jpeg = $this->upload();
		$gif  = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/test-image.gif' );

		$job = $this->drive( $this->producer->launch( BulkJob::TYPE_SYNC )->get_id() );

		$this->assertSame( 1, $job->get_total() );
		$this->assertSame( AttachmentState::OPTIMIZED, $this->attachments->get_state( $jpeg ) );
		$this->assertSame( AttachmentState::NONE, $this->attachments->get_state( $gif ) );
	}

	public function test_remove_job_deletes_variants_through_the_cleanup_service() {
		$a = $this->upload();
		$b = $this->upload();
		$this->drive( $this->producer->launch( BulkJob::TYPE_SYNC )->get_id() );
		$this->assertNotEmpty( $this->variants->get_for_attachment( $a ) );

		$job = $this->drive( $this->producer->launch( BulkJob::TYPE_REMOVE )->get_id() );

		$this->assertSame( JobStatus::COMPLETED, $job->get_status() );
		$this->assertSame( array(), $this->variants->get_for_attachment( $a ) );
		$this->assertSame( array(), $this->variants->get_for_attachment( $b ) );
	}

	public function test_remove_job_keeps_a_file_that_another_attachment_still_needs() {
		$name = 'bulk-shared-' . wp_generate_uuid4() . '.jpg.webp';
		$file = wp_upload_dir()['basedir'] . '/' . $name;
		file_put_contents( $file, 'shared variant' );
		$this->temp_files[] = $file;

		foreach ( array( 3001, 3002 ) as $attachment_id ) {
			$this->variants->upsert(
				array(
					'attachment_id'        => $attachment_id,
					'size_name'            => 'original',
					'format'               => 'webp',
					'status'               => VariantStatus::DONE,
					'source_relative_path' => 'bulk-shared.jpg',
					'relative_path'        => $name,
					'file_hash'            => hash( 'sha256', 'shared variant' ),
				)
			);
		}

		// One attachment per run: the first run removes only 3001.
		add_filter(
			'trust_optimize_bulk_batch_size',
			static function () {
				return 1;
			}
		);
		$job = $this->producer->launch( BulkJob::TYPE_REMOVE );
		$this->producer->produce( $job->get_id() );

		$this->assertSame( array(), $this->variants->get_for_attachment( 3001 ) );
		$this->assertCount( 1, $this->variants->get_servable_for_attachment( 3002 ) );
		$this->assertFileExists( $file, 'The file is still the variant of another attachment.' );

		$job = $this->drive( $job->get_id() );

		$this->assertSame( JobStatus::COMPLETED, $job->get_status() );
		$this->assertSame( array(), $this->variants->get_for_attachment( 3002 ) );
		$this->assertFileDoesNotExist( $file, 'The last owner deletes the file.' );
	}

	public function test_remove_job_does_not_touch_the_original_of_another_attachment() {
		$original = $this->upload();
		$file     = get_attached_file( $original );
		$checksum = hash_file( 'sha256', $file );
		$this->variants->upsert(
			array(
				'attachment_id'        => 3010,
				'size_name'            => 'original',
				'format'               => 'webp',
				'status'               => VariantStatus::DONE,
				'source_relative_path' => 'elsewhere.jpg',
				'relative_path'        => get_post_meta( $original, '_wp_attached_file', true ),
				'file_hash'            => $checksum,
			)
		);

		$job = $this->drive( $this->producer->launch( BulkJob::TYPE_REMOVE )->get_id() );

		$this->assertSame( JobStatus::COMPLETED, $job->get_status() );
		$this->assertSame( array(), $this->variants->get_for_attachment( 3010 ) );
		$this->assertSame( $checksum, hash_file( 'sha256', $file ), 'The original of another attachment is intact.' );
	}

	public function test_inventory_job_walks_the_library_and_completes() {
		$this->upload();
		$this->upload();

		$job = $this->drive( $this->producer->launch( BulkJob::TYPE_INVENTORY )->get_id() );

		$this->assertSame( JobStatus::COMPLETED, $job->get_status() );
		$this->assertSame( 2, ( new JobProgress( $this->attachments, new TrustOptimize\Bulk\EligibilityQuery( $this->variants ) ) )->describe( $job )['processed'] );
	}

	public function test_the_job_stores_the_settings_it_was_created_with() {
		$job = $this->producer->launch( BulkJob::TYPE_SYNC );

		$this->assertSame( array( 'webp' ), $job->get_settings_snapshot()['enabled'] );
		$this->assertArrayHasKey( 'quality', $job->get_settings_snapshot() );
	}

	public function test_the_job_pauses_when_the_disk_is_nearly_full() {
		$id  = $this->upload();
		$job = $this->producer->launch( BulkJob::TYPE_SYNC );
		add_filter(
			'trust_optimize_min_free_disk_bytes',
			static function () {
				return PHP_INT_MAX;
			}
		);

		$this->producer->produce( $job->get_id() );

		$job = $this->jobs->get( $job->get_id() );
		$this->assertSame( JobStatus::PAUSED, $job->get_status() );
		$this->assertSame( 'low_disk_space', $job->to_array()['last_error'] );
		$this->assertSame( AttachmentState::NONE, $this->attachments->get_state( $id ) );
	}

	public function test_an_oversized_image_is_skipped_and_the_job_goes_on() {
		$big      = $this->upload();
		$small    = $this->upload();
		$metadata = wp_get_attachment_metadata( $big );
		wp_update_attachment_metadata( $big, array_merge( $metadata, array( 'width' => 12000, 'height' => 12000 ) ) );

		$job = $this->drive( $this->producer->launch( BulkJob::TYPE_SYNC )->get_id() );

		$this->assertSame( JobStatus::COMPLETED, $job->get_status() );
		$this->assertSame( 'too_large', $this->attachments->get( $big )['reason'] );
		$this->assertSame( AttachmentState::SKIPPED, $this->attachments->get_state( $big ) );
		$this->assertSame( AttachmentState::OPTIMIZED, $this->attachments->get_state( $small ) );
	}
}
