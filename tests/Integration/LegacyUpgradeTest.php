<?php
/**
 * End-to-end upgrade of a site from schema 1.3.0 (03.8).
 *
 * @package TrustOptimize\Tests
 */

require_once __DIR__ . '/legacy-schema-fixture.php';

use TrustOptimize\Bulk\BulkJobRepository;
use TrustOptimize\Capabilities\CapabilityService;
use TrustOptimize\Core\Plugin;
use TrustOptimize\Database\DatabaseManager;
use TrustOptimize\Domain\AttachmentState;
use TrustOptimize\Domain\VariantStatus;
use TrustOptimize\Migration\BatchResult;
use TrustOptimize\Migration\CleanupLegacyRuntime;
use TrustOptimize\Migration\ConflictReport;
use TrustOptimize\Migration\DetectCollisions;
use TrustOptimize\Migration\Finalize;
use TrustOptimize\Migration\ImportLegacyManifest;
use TrustOptimize\Migration\MigrationRunner;
use TrustOptimize\Migration\MigrationStep;
use TrustOptimize\Migration\RetireLegacyFiles;
use TrustOptimize\Migration\ScheduleRegeneration;
use TrustOptimize\Migration\StripAttachmentMetadata;
use TrustOptimize\Queue\ConversionQueue;
use TrustOptimize\Service\LegacyPathGuard;
use TrustOptimize\Storage\AttachmentRepository;
use TrustOptimize\Storage\VariantRepository;

/**
 * Runs every step twice: after the real batch the "process dies" before its cursor is saved,
 * so the whole batch is executed again from the previous cursor.
 */
class Replaying_Migration_Step implements MigrationStep {

	/**
	 * Real step.
	 *
	 * @var MigrationStep
	 */
	private $step;

	/**
	 * Cursors that were already interrupted.
	 *
	 * @var array
	 */
	private $interrupted = array();

	public function __construct( MigrationStep $step ) {
		$this->step = $step;
	}

	public function name() {
		return $this->step->name();
	}

	public function run_batch( $cursor, $limit ) {
		$result = $this->step->run_batch( $cursor, $limit );

		if ( ! isset( $this->interrupted[ $cursor ] ) ) {
			$this->interrupted[ $cursor ] = true;
			throw new RuntimeException( 'interrupted after the work of ' . $this->step->name() . ' at ' . $cursor );
		}

		return $result;
	}
}

/**
 * @coversNothing
 */
class LegacyUpgradeTest extends WP_UnitTestCase {

	use Legacy_Schema_Fixture;

	/**
	 * Fixture of the site: attachment IDs and files.
	 *
	 * @var array
	 */
	private $site = array();

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
		$this->install_legacy_table();
		delete_option( ConflictReport::OPTION );
		delete_option( MigrationRunner::OPTION );
		update_option( CapabilityService::OPTION, array( 'webp' => true, 'avif' => false ) );
		update_option( 'trust_optimize_options', array( 'convert_to_webp' => 1, 'convert_to_avif' => 0 ) );

		$database          = new DatabaseManager();
		$this->variants    = new VariantRepository( $database );
		$this->attachments = new AttachmentRepository( $database, $this->variants );
	}

	public function tear_down() {
		// The migration drops a table, which commits the test transaction: remove what the test created.
		foreach ( $this->site['ids'] ?? array() as $id ) {
			wp_delete_attachment( $id, true );
		}
		delete_option( ConflictReport::OPTION );
		delete_option( MigrationRunner::OPTION );
		delete_option( 'trust_optimize_preflight' );
		$GLOBALS['wpdb']->query( 'COMMIT' );

		$this->remove_legacy_schema();
		parent::tear_down();
	}

	/**
	 * Upload an image and put the attachment back into the state of a 1.3.0 site (no 2.0 rows, no tasks).
	 *
	 * @param string $file Test image.
	 * @return int
	 */
	private function upload( $file ) {
		$id = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/' . $file );
		$this->assertGreaterThan( 0, $id, $file );

		$this->variants->delete_for_attachment( $id );
		$this->attachments->delete( $id );
		as_unschedule_all_actions( ConversionQueue::HOOK_PROCESS );

		$this->site['ids'][] = $id;

		return $id;
	}

	/**
	 * Path relative to uploads of the original (key "original") or of a size.
	 *
	 * @param int    $id   Attachment ID.
	 * @param string $size Size name.
	 * @return string|null
	 */
	private function source_path( $id, $size = 'original' ) {
		$original = get_post_meta( $id, '_wp_attached_file', true );
		$dir      = '.' === dirname( $original ) ? '' : dirname( $original ) . '/';

		if ( 'original' === $size ) {
			return $original;
		}

		$metadata = wp_get_attachment_metadata( $id );

		return isset( $metadata['sizes'][ $size ] ) ? $dir . $metadata['sizes'][ $size ]['file'] : null;
	}

	/**
	 * 1.x name of the variant of a source file: the extension is replaced.
	 *
	 * @param string $source Source path.
	 * @param string $format Format.
	 * @return string
	 */
	private function legacy_name( $source, $format ) {
		return preg_replace( '/\.[A-Za-z0-9]+$/', '.' . $format, $source );
	}

	/**
	 * Build a site as 1.3.0 left it.
	 */
	private function build_site() {
		$this->site = array(
			'ids'       => array(),
			'plain'     => array(),
			'missing'   => array(),
			'originals' => array(),
		);

		foreach ( array( '33772.jpg', '2004-07-22-DSC_0007.jpg', '2004-07-22-DSC_0008.jpg', 'a2-small.jpg', 'sugarloaf-mountain.jpg', 'test-image.jpg', 'test-image-3.jpg', 'gradient-square.jpg', 'test-image-grayscale.jpg', 'test-image-iptc.jpg', 'test-square-150.jpg', 'test-image.png', 'transparent.png', 'codeispoetry.png', 'one-blue-pixel-100x100.png' ) as $index => $file ) {
			$id      = $this->upload( $file );
			$entries = array();

			foreach ( array( 'original', 'thumbnail' ) as $size ) {
				$source = $this->source_path( $id, $size );
				if ( $source ) {
					$entries[ $size ] = array(
						'size_name' => $size,
						'format'    => 'webp',
						'file'      => $this->legacy_name( $source, 'webp' ),
					);
				}
			}

			// Two attachments list an original variant whose file is gone.
			if ( in_array( $index, array( 3, 4 ), true ) ) {
				$this->add_legacy_manifest( $id, array( $entries['original'] ), false );
				$this->site['missing'][ $id ] = $entries['original'];
				unset( $entries['original'] );
				if ( $entries ) {
					$this->add_legacy_manifest( $id, array_values( $entries ) );
				}
			} else {
				$this->add_legacy_manifest( $id, array_values( $entries ) );
			}

			$metadata                             = wp_get_attachment_metadata( $id );
			$metadata['trust_optimize_converted'] = array( 'original_webp' => array( 'file' => 'x.webp' ) );
			foreach ( array_keys( (array) ( $metadata['sizes'] ?? array() ) ) as $size_name ) {
				$metadata['sizes'][ $size_name ]['trust_optimize_converted'] = array( 'webp' => array( 'file' => 'y.webp' ) );
			}
			update_post_meta( $id, '_wp_attachment_metadata', $metadata );

			$this->site['plain'][ $id ]      = $entries;
			$this->site['originals'][ $id ] = wp_upload_dir()['basedir'] . '/' . $this->source_path( $id );
		}

		$plain = array_keys( $this->site['plain'] );

		// A WebP source: its PNG variant has a name of its own, nobody else's.
		$own                 = $this->upload( 'test-image.webp' );
		$this->site['webp']  = $own;
		$this->site['own_png'] = $this->legacy_name( $this->source_path( $own ), 'png' );
		$this->add_legacy_manifest( $own, array( array( 'size_name' => 'original', 'format' => 'png', 'file' => $this->site['own_png'] ) ) );

		// Scenario A of H-1: PNG variants of a WebP source carry the names of the original and of a thumbnail of other attachments.
		$collides                     = $this->upload( 'test-image-rotated-90cw.webp' );
		$this->site['collides']       = $collides;
		$this->site['victim_original'] = $this->source_path( $plain[0] );
		$this->site['victim_thumb']    = $this->source_path( $plain[0], 'thumbnail' ) ?? $this->source_path( $plain[1], 'thumbnail' );
		$this->assertNotNull( $this->site['victim_thumb'], 'The fixture needs an attachment with a thumbnail.' );
		$this->add_legacy_manifest(
			$collides,
			array(
				array( 'size_name' => 'original', 'format' => 'png', 'file' => $this->site['victim_original'] ),
				array( 'size_name' => 'thumbnail', 'format' => 'png', 'file' => $this->site['victim_thumb'] ),
			),
			false
		);

		// Scenario B of H-1: photo.jpg and photo.png both produced photo.webp.
		$this->site['shared'] = 'legacy-test/shared.webp';
		$this->site['twins']  = array( $this->upload( 'canola.jpg' ), $this->upload( 'test-image-4.png' ) );
		$this->add_legacy_manifest( $this->site['twins'][0], array( array( 'size_name' => 'original', 'format' => 'webp', 'file' => $this->site['shared'] ) ) );
		$this->add_legacy_manifest( $this->site['twins'][1], array( array( 'size_name' => 'original', 'format' => 'webp', 'file' => $this->site['shared'] ) ), false );

		// Pending 1.x tasks: one for an attachment that has no converted variant at all, one for a converted one, one for a deleted one.
		$this->site['unconverted'] = $this->upload( 'test-image-1-100x100.jpg' );
		$tasks                     = array( $this->site['unconverted'], $plain[2], 999998 );
		foreach ( $tasks as $position => $task_id ) {
			as_enqueue_async_action(
				CleanupLegacyRuntime::LEGACY_TASK_HOOK,
				$position < 2 ? array( array( 'attachment_id' => $task_id, 'size_name' => 'original', 'target_format' => 'webp', 'target_mime' => 'image/webp' ) ) : array( $task_id ),
				ConversionQueue::GROUP
			);
		}

		// A registry row of an attachment that was deleted.
		$this->add_legacy_manifest( 999997, array( array( 'size_name' => 'original', 'format' => 'webp', 'file' => 'legacy-test/orphan.webp' ) ) );

		update_option( 'trust_optimize_preflight', array( 'old' => true ) );
		set_transient( 'trust_optimize_formats_' . $plain[0], array( 'webp' ), HOUR_IN_SECONDS );

		// A bulk job of 1.x that is running: its ticks, lock and status throttle.
		global $wpdb;
		$wpdb->insert(
			( new DatabaseManager() )->get_table_name( 'trust_optimize_jobs' ),
			array(
				'type'              => 'sync',
				'status'            => 'running',
				'settings_snapshot' => '[]',
				'updated_at'        => current_time( 'mysql' ),
			)
		);
		$this->site['legacy_job'] = (int) $wpdb->insert_id;
		as_enqueue_async_action( CleanupLegacyRuntime::LEGACY_BULK_HOOK, array( $this->site['legacy_job'] ), ConversionQueue::GROUP );
		update_option( CleanupLegacyRuntime::LEGACY_BULK_LOCK_PREFIX . $this->site['legacy_job'], time() );
		set_transient( CleanupLegacyRuntime::LEGACY_BULK_STATUS_PREFIX . $this->site['legacy_job'], 1, HOUR_IN_SECONDS );

		// Every file of an attachment (originals and sizes) must survive the migration.
		foreach ( $this->site['ids'] as $id ) {
			$this->site['protected'][ $id ] = array( wp_upload_dir()['basedir'] . '/' . $this->source_path( $id ) );
			foreach ( array_keys( (array) ( wp_get_attachment_metadata( $id )['sizes'] ?? array() ) ) as $size ) {
				$this->site['protected'][ $id ][] = wp_upload_dir()['basedir'] . '/' . $this->source_path( $id, $size );
			}
		}
	}

	/**
	 * Run the Action Scheduler queue until the migration has finished and nothing is pending.
	 *
	 * @param callable|null $between Called after every run; returns true when finished.
	 */
	private function run_until_migrated( $between = null ) {
		for ( $i = 0; $i < 300; $i++ ) {
			ActionScheduler_QueueRunner::instance()->run();

			$state = get_option( MigrationRunner::OPTION );
			if ( $state && ! empty( $state['finished_at'] ) ) {
				ActionScheduler_QueueRunner::instance()->run();
				return;
			}
		}

		$this->fail( 'The migration did not finish: ' . wp_json_encode( get_option( MigrationRunner::OPTION ) ) );
	}

	/**
	 * Rows of an attachment keyed by "size|format".
	 *
	 * @param int $id Attachment ID.
	 * @return array[]
	 */
	private function rows( $id ) {
		$rows = array();
		foreach ( $this->variants->get_for_attachment( $id ) as $row ) {
			$rows[ $row['size_name'] . '|' . $row['format'] ] = $row;
		}

		return $rows;
	}

	private function absolute( $relative ) {
		return wp_upload_dir()['basedir'] . '/' . $relative;
	}

	/**
	 * Check every principle of the migration on the migrated site.
	 */
	private function assert_site_migrated() {
		$state = get_option( MigrationRunner::OPTION );
		$this->assertSame( array(), $state['errors'] ?? array(), 'No step failed.' );
		$this->assertSame( '1.3.0', $state['from'] );

		// 1. No file of any attachment was deleted.
		foreach ( $this->site['protected'] as $id => $files ) {
			foreach ( $files as $file ) {
				$this->assertFileExists( $file, "A file of attachment {$id} was deleted." );
			}
		}

		// 2. Attachments with 1.x variants: served from the 1.x file until the 2.0 file is done, then only the 2.0 file.
		foreach ( $this->site['plain'] as $id => $entries ) {
			$rows = $this->rows( $id );
			$this->assertNotSame( array(), $rows );

			foreach ( $entries as $size => $entry ) {
				$row    = $rows[ $entry['size_name'] . '|webp' ] ?? null;
				$legacy = $this->absolute( $entry['file'] );
				$this->assertNotNull( $row, "{$id} {$size}" );
				$this->assertSame( 'v2', $row['naming'] );
				$this->assertNotNull( VariantRepository::servable_path( $row ), "{$id} {$size} is served" );

				if ( VariantStatus::DONE === $row['status'] ) {
					$this->assertSame( $this->source_path( $id, $size ) . '.webp', $row['relative_path'] );
					$this->assertFileExists( $this->absolute( $row['relative_path'] ) );
					$this->assertNull( $row['legacy_relative_path'] );
					$this->assertFileDoesNotExist( $legacy, "The 1.x file of {$id} {$size} is retired." );
				} else {
					$this->assertContains( $row['status'], array( VariantStatus::SKIPPED, VariantStatus::FAILED ) );
					$this->assertFileExists( $this->absolute( VariantRepository::servable_path( $row ) ), 'A variant that could not be regenerated keeps its 1.x file.' );
				}
			}

			$this->assertSame( AttachmentState::OPTIMIZED, $this->attachments->get_state( $id ), "Attachment {$id}" );
		}

		// 3. A manifest entry whose file is missing creates no 1.x row; the planner makes a 2.0 row instead.
		foreach ( $this->site['missing'] as $id => $entry ) {
			$row = $this->rows( $id )['original|webp'];
			$this->assertNull( $row['legacy_relative_path'] );
			$this->assertSame( 'v2', $row['naming'] );
		}

		// 4. H-1 scenario A: nothing was deleted, the rows are parked and reported.
		$collides = $this->rows( $this->site['collides'] );
		foreach ( array( 'original|png', 'thumbnail|png' ) as $key ) {
			$this->assertSame( VariantStatus::FAILED, $collides[ $key ]['status'], $key );
			$this->assertSame( 'legacy_conflict', $collides[ $key ]['reason'] );
			$this->assertSame( 'legacy', $collides[ $key ]['naming'] );
		}

		// 5. H-1 scenario B: both attachments claim the file; it stays and neither is served from it.
		$this->assertFileExists( $this->absolute( $this->site['shared'] ) );
		foreach ( $this->site['twins'] as $id ) {
			$row = $this->rows( $id )['original|webp'];
			$this->assertSame( VariantStatus::FAILED, $row['status'] );
			$this->assertSame( 'legacy_conflict', $row['reason'] );
		}

		$report = array_values( ( new ConflictReport() )->all() );
		$this->assertSame( 4, count( $report ) );
		$this->assertEqualsCanonicalizing(
			array( $this->site['collides'], $this->site['collides'], $this->site['twins'][0], $this->site['twins'][1] ),
			array_column( $report, 'attachment_id' )
		);

		// 6. The PNG variant of a WebP source with a name of its own is removed (D-11).
		$this->assertSame( array(), $this->rows( $this->site['webp'] ) );
		$this->assertFileDoesNotExist( $this->absolute( $this->site['own_png'] ) );

		// 7. The attachment that only had a pending 1.x task was optimized.
		$unconverted = $this->rows( $this->site['unconverted'] );
		$this->assertNotEmpty( $unconverted );
		foreach ( $unconverted as $row ) {
			$this->assertContains( $row['status'], array( VariantStatus::DONE, VariantStatus::SKIPPED ) );
		}

		// 8. Queue, options, transients and metadata of 1.x are gone.
		$this->assertSame( array(), as_get_scheduled_actions( array( 'hook' => CleanupLegacyRuntime::LEGACY_TASK_HOOK, 'status' => ActionScheduler_Store::STATUS_PENDING ), 'ids' ) );
		$this->assertSame( array(), as_get_scheduled_actions( array( 'hook' => ConversionQueue::HOOK_PROCESS, 'status' => ActionScheduler_Store::STATUS_PENDING ), 'ids' ) );
		$this->assertFalse( get_option( 'trust_optimize_preflight' ) );
		$this->assertFalse( get_transient( 'trust_optimize_formats_' . array_key_first( $this->site['plain'] ) ) );
		$this->assertSame( array(), as_get_scheduled_actions( array( 'hook' => CleanupLegacyRuntime::LEGACY_BULK_HOOK, 'status' => ActionScheduler_Store::STATUS_PENDING ), 'ids' ) );
		$this->assertFalse( get_option( CleanupLegacyRuntime::LEGACY_BULK_LOCK_PREFIX . $this->site['legacy_job'] ) );
		$this->assertFalse( get_transient( CleanupLegacyRuntime::LEGACY_BULK_STATUS_PREFIX . $this->site['legacy_job'] ) );
		$closed = ( new BulkJobRepository( new DatabaseManager() ) )->get( $this->site['legacy_job'] );
		$this->assertSame( 'cancelled', $closed->get_status() );
		$this->assertSame( 'superseded by 2.0', $closed->to_array()['last_error'] );
		foreach ( array_keys( $this->site['plain'] ) as $id ) {
			$metadata = wp_get_attachment_metadata( $id );
			$this->assertArrayNotHasKey( 'trust_optimize_converted', $metadata );
			foreach ( $metadata['sizes'] as $size ) {
				$this->assertArrayNotHasKey( 'trust_optimize_converted', $size );
			}
		}

		// 9. The registry is dropped, the migration is finished and the report stays.
		$database = new DatabaseManager();
		$this->assertFalse( $database->table_exists( $database->get_plugin_table_names()['images'] ) );
		$this->assertNotEmpty( get_option( MigrationRunner::OPTION )['finished_at'] );
		$this->assertCount( 4, ( new ConflictReport() )->all() );
	}

	public function test_a_site_is_migrated_from_schema_1_3_0() {
		$this->build_site();

		// The upgrade: the schema check finds 1.3.0 and starts the migration.
		update_option( 'trust_optimize_db_version', '1.3.0' );
		( new DatabaseManager() )->check_version();

		$this->assertTrue( get_option( MigrationRunner::OPTION ) && empty( get_option( MigrationRunner::OPTION )['finished_at'] ), 'The migration started.' );

		$this->run_until_migrated();
		$this->assert_site_migrated();
	}

	public function test_the_migration_survives_an_interruption_of_every_batch_of_every_step() {
		$this->build_site();

		$database    = new DatabaseManager();
		$plugin      = Plugin::get_instance();
		$conflicts   = new ConflictReport();
		$guard       = new LegacyPathGuard( $database, $this->variants );
		$steps       = array(
			new ImportLegacyManifest( $database, $this->variants, $this->attachments ),
			new DetectCollisions( $this->variants, $this->attachments, $guard, $conflicts ),
			new ScheduleRegeneration( $this->variants, $plugin->conversion_queue, new ConflictReport() ),
			new RetireLegacyFiles( $this->variants, $plugin->cleanup ),
			new StripAttachmentMetadata( $database ),
			new CleanupLegacyRuntime( $plugin->conversion_queue, new BulkJobRepository( $database ) ),
			new Finalize( $database, $this->variants ),
		);
		$runner      = new MigrationRunner( $database, array_map( static fn( $step ) => new Replaying_Migration_Step( $step ), $steps ) );
		$interrupted = 0;

		// This runner replaces the one of the plugin for the test.
		remove_all_actions( MigrationRunner::HOOK_MIGRATE );
		$runner->start( '1.3.0', DatabaseManager::DB_VERSION );

		for ( $i = 0; $i < 500 && $runner->is_running(); $i++ ) {
			$state = $runner->run_batch( 3 );
			$interrupted += (int) ( $runner->get_delay() > 0 );
			ActionScheduler_QueueRunner::instance()->run();
		}

		$this->assertFalse( $runner->is_running(), wp_json_encode( $runner->get_state() ) );
		$this->assertGreaterThan( 10, $interrupted, 'Every batch of every step was interrupted once.' );

		// Interruptions are recorded; the result must be the same as without them.
		$state           = get_option( MigrationRunner::OPTION );
		$state['errors'] = array();
		update_option( MigrationRunner::OPTION, $state );

		$this->assert_site_migrated();
	}

	public function test_images_are_served_all_the_way_through_the_migration() {
		update_option( 'trust_optimize_options', array( 'convert_to_webp' => 1, 'convert_to_avif' => 0, 'enable_adaptive_images' => 1 ) );
		set_current_screen( 'front' );

		$database = new DatabaseManager();
		$plugin   = Plugin::get_instance();
		$id       = $this->upload( 'canola.jpg' );
		$source   = $this->source_path( $id );
		$legacy   = $this->legacy_name( $source, 'webp' );
		$render   = static function () use ( $id ) {
			return apply_filters( 'the_content', '<img src="' . wp_get_attachment_url( $id ) . '" alt="x">' );
		};
		$this->add_legacy_manifest( $id, array( array( 'size_name' => 'original', 'format' => 'webp', 'file' => $legacy ) ) );

		$this->assertStringNotContainsString( '<picture', $render(), 'Before the import nothing is known about the 1.x file.' );

		( new ImportLegacyManifest( $database, $this->variants, $this->attachments ) )->run_batch( 0, 10 );
		$this->assertStringContainsString( basename( $legacy ) . ' 640w', $render(), 'After the import the 1.x file is served.' );

		( new DetectCollisions( $this->variants, $this->attachments, new LegacyPathGuard( $database, $this->variants ), new ConflictReport() ) )->run_batch( 0, 10 );
		( new ScheduleRegeneration( $this->variants, $plugin->conversion_queue, new ConflictReport() ) )->run_batch( 0, 10 );
		$this->assertSame( VariantStatus::PENDING, $this->rows( $id )['original|webp']['status'] );
		$this->assertStringContainsString( basename( $legacy ) . ' 640w', $render(), 'While the 2.0 file is not there, the 1.x file is still served.' );

		ActionScheduler_QueueRunner::instance()->run();
		$html = $render();
		$this->assertStringContainsString( basename( $source ) . '.webp 640w', $html, 'Then the 2.0 file is served.' );
		$this->assertStringNotContainsString( basename( $legacy ) . ' 640w', $html );
		$this->assertFileDoesNotExist( $this->absolute( $legacy ) );
	}
}
