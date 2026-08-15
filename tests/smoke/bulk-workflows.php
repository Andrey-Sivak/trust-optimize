<?php
/**
 * Smoke checks for TrustOptimize bulk optimization workflows.
 *
 * Run from a real WordPress install:
 *
 *     wp eval-file wp-content/plugins/trust-optimize/tests/smoke/bulk-workflows.php --allow-root
 *
 * The script creates temporary media records/files prefixed with
 * "trust-optimize-smoke-", exercises public service/REST/bulk paths, and
 * removes only the records/files it created.
 *
 * @package TrustOptimize\Tests\Smoke
 */

use TrustOptimize\Admin\Settings;
use TrustOptimize\API\RestController;
use TrustOptimize\Bulk\BulkJob;
use TrustOptimize\Bulk\BulkJobRepository;
use TrustOptimize\Bulk\BulkJobRunner;
use TrustOptimize\Database\DatabaseManager;
use TrustOptimize\Database\ImageModel;
use TrustOptimize\Service\ImageCleanupService;
use TrustOptimize\Service\ImageOptimizationService;
use TrustOptimize\Service\ImageProfileFactory;

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "This smoke check must run inside WordPress.\n" );
	exit( 1 );
}

$trust_optimize_smoke = new class() {

	/**
	 * Created attachment IDs.
	 *
	 * @var array
	 */
	private $attachments = array();

	/**
	 * Created extra files.
	 *
	 * @var array
	 */
	private $files = array();

	/**
	 * Original plugin options.
	 *
	 * @var mixed
	 */
	private $original_options;

	/**
	 * Created temporary admin user ID.
	 *
	 * @var int
	 */
	private $created_user_id = 0;

	/**
	 * Run all smoke checks.
	 */
	public function run() {
		$this->original_options = get_option( 'trust_optimize_options', null );

		try {
			$this->assert_plugin_active();
			$this->cleanup_existing_fixtures();
			$this->ensure_admin_user();
			$this->ensure_tables_exist();
			$this->set_smoke_options();

			$jpeg_id    = $this->create_image_attachment( 'jpg', 'image/jpeg' );
			$second_id  = $this->create_image_attachment( 'jpg', 'image/jpeg' );
			$missing_id = $this->create_missing_source_attachment();
			$svg_id     = $this->create_unsupported_mime_attachment();

			$this->check_single_sync( $jpeg_id );
			$this->check_cleanup_preserves_original( $jpeg_id );
			$this->check_single_remove( $jpeg_id );
			$this->check_missing_source_file( $missing_id );
			$this->check_unsupported_mime( $svg_id );
			$this->check_output_format_capabilities( $second_id );
			$this->check_unsupported_format_setting_does_not_change_hash( $second_id );
			$this->check_wp_cli_remove_synopsis();
			$this->check_rest_endpoints( $second_id );
			$this->check_bulk_inventory_and_sync();
			$this->check_reprocess_after_profile_change( $second_id );

			$this->pass( 'TrustOptimize smoke checks completed.' );
		} catch ( Exception $exception ) {
			$this->fail( $exception->getMessage() );
		} finally {
			$this->cleanup();
		}
	}

	/**
	 * Assert that the plugin is active and classes are loaded.
	 */
	private function assert_plugin_active() {
		if ( ! defined( 'TRUST_OPTIMIZE_VERSION' ) || ! class_exists( ImageOptimizationService::class ) ) {
			throw new Exception( 'TrustOptimize is not active or core classes are unavailable.' );
		}

		$this->pass( 'Plugin activation/classes available.' );
	}

	/**
	 * Ensure an administrator context for REST permission checks.
	 */
	private function ensure_admin_user() {
		$admins = get_users(
			array(
				'role'   => 'administrator',
				'number' => 1,
				'fields' => 'ID',
			)
		);

		if ( ! empty( $admins ) ) {
			wp_set_current_user( (int) $admins[0] );
			return;
		}

		$user_id = wp_insert_user(
			array(
				'user_login' => 'trust-optimize-smoke-admin-' . time(),
				'user_pass'  => wp_generate_password( 24, true ),
				'user_email' => 'trust-optimize-smoke@example.test',
				'role'       => 'administrator',
			)
		);

		if ( is_wp_error( $user_id ) ) {
			throw new Exception( 'Unable to create temporary admin user: ' . $user_id->get_error_message() );
		}

		$this->created_user_id = (int) $user_id;
		wp_set_current_user( $this->created_user_id );
	}

	/**
	 * Assert custom tables exist.
	 */
	private function ensure_tables_exist() {
		$manager = new DatabaseManager();

		foreach ( $manager->get_plugin_table_names() as $key => $table ) {
			if ( ! $manager->table_exists( $table ) ) {
				throw new Exception( sprintf( 'Expected TrustOptimize %s table to exist: %s', $key, $table ) );
			}
		}

		$this->pass( 'Plugin tables exist after activation.' );
	}

	/**
	 * Set deterministic smoke options without permanently changing the site.
	 */
	private function set_smoke_options() {
		$settings = new Settings();
		$options  = array_merge(
			$settings->get_defaults(),
			array(
				'enable_adaptive_images' => 1,
				'convert_to_webp'        => 1,
				'convert_to_avif'        => 1,
				'webp_quality'           => 82,
				'avif_quality'           => 78,
				'jpeg_quality'           => 85,
			)
		);

		update_option( 'trust_optimize_options', $options );
	}

	/**
	 * Remove leftovers from an interrupted previous smoke run.
	 */
	private function cleanup_existing_fixtures() {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$attachment_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts}
				WHERE post_type = %s
				AND post_title LIKE %s",
				'attachment',
				$wpdb->esc_like( 'trust-optimize-smoke-' ) . '%'
			)
		);
		// phpcs:enable

		$model = new ImageModel();

		foreach ( $attachment_ids as $attachment_id ) {
			$model->delete( (int) $attachment_id );
			wp_delete_attachment( (int) $attachment_id, true );
		}

		$upload_dir = wp_upload_dir();
		if ( empty( $upload_dir['basedir'] ) || ! is_dir( $upload_dir['basedir'] ) ) {
			return;
		}

		$base = wp_normalize_path( $upload_dir['basedir'] );
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $base, FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file ) {
			$path = wp_normalize_path( $file->getPathname() );

			if ( 0 === strpos( basename( $path ), 'trust-optimize-smoke-' ) && 0 === strpos( $path, $base ) ) {
				wp_delete_file( $path );
			}
		}
	}

	/**
	 * Check single attachment sync path.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	private function check_single_sync( $attachment_id ) {
		$service = new ImageOptimizationService();
		$result  = $service->optimize_attachment( $attachment_id );

		if ( $result->is_failed() ) {
			throw new Exception( 'Single attachment sync failed: ' . wp_json_encode( $result->to_array() ) );
		}

		$this->pass( 'Single attachment sync path returns a terminal non-failed result.' );
	}

	/**
	 * Check cleanup deletes only manifest-owned files and keeps original media.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	private function check_cleanup_preserves_original( $attachment_id ) {
		$original = get_attached_file( $attachment_id );
		$variant  = $this->create_manifest_variant( $attachment_id, 'webp' );

		$cleanup = new ImageCleanupService();
		$result  = $cleanup->cleanup_attachment( $attachment_id, array( 'keep_record' => true ) );

		if ( $result->is_failed() ) {
			throw new Exception( 'Cleanup failed: ' . wp_json_encode( $result->to_array() ) );
		}

		if ( ! file_exists( $original ) ) {
			throw new Exception( 'Cleanup removed original attachment file.' );
		}

		if ( file_exists( $variant ) ) {
			throw new Exception( 'Cleanup did not remove manifest-owned generated variant.' );
		}

		$this->pass( 'Cleanup removes manifest-owned variant and preserves original file.' );
	}

	/**
	 * Check single remove path.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	private function check_single_remove( $attachment_id ) {
		$this->create_manifest_variant( $attachment_id, 'webp' );

		$cleanup = new ImageCleanupService();
		$result  = $cleanup->cleanup_attachment( $attachment_id );

		if ( $result->is_failed() ) {
			throw new Exception( 'Single attachment remove failed: ' . wp_json_encode( $result->to_array() ) );
		}

		$this->pass( 'Single attachment remove path completed.' );
	}

	/**
	 * Check missing source handling.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	private function check_missing_source_file( $attachment_id ) {
		$result = ( new ImageOptimizationService() )->optimize_attachment( $attachment_id );

		if ( ! $result->is_failed() || 'missing_file' !== $result->get_message() ) {
			throw new Exception( 'Missing source file was not reported as failed missing_file: ' . wp_json_encode( $result->to_array() ) );
		}

		$this->pass( 'Missing source file is reported explicitly.' );
	}

	/**
	 * Check unsupported MIME handling.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	private function check_unsupported_mime( $attachment_id ) {
		$result = ( new ImageOptimizationService() )->optimize_attachment( $attachment_id );

		if ( ! $result->is_skipped() || 'unsupported_mime' !== $result->get_message() ) {
			throw new Exception( 'Unsupported MIME was not skipped as unsupported_mime: ' . wp_json_encode( $result->to_array() ) );
		}

		$this->pass( 'Unsupported MIME is skipped explicitly.' );
	}

	/**
	 * Check unsupported output format reporting.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	private function check_output_format_capabilities( $attachment_id ) {
		$metadata = wp_get_attachment_metadata( $attachment_id );
		$profile  = ( new ImageProfileFactory( new Settings() ) )->from_wp_metadata( is_array( $metadata ) ? $metadata : array() );
		$data     = $profile->to_array();
		$formats  = isset( $data['formats'] ) && is_array( $data['formats'] ) ? $data['formats'] : array();

		if ( empty( $data['options']['unsupported_output_formats'] ) ) {
			$this->assert_supported_formats_do_not_fail_conversion( $attachment_id, $formats );
			$this->pass( 'Supported WebP/AVIF output formats convert without failed tasks.' );
			return;
		}

		$result = ( new ImageOptimizationService() )->optimize_attachment( $attachment_id );
		$result_data = $result->get_data();

		if ( empty( $result_data['unsupported_output_formats'] ) ) {
			throw new Exception( 'Unsupported output formats were not exposed in sync result.' );
		}

		$this->assert_supported_formats_do_not_fail_conversion( $attachment_id, $formats );
		$this->pass( 'Unsupported WebP/AVIF output formats are reported.' );
	}

	/**
	 * Assert formats reported as supported do not fail during conversion.
	 *
	 * @param int   $attachment_id Attachment ID.
	 * @param array $formats       Supported output formats from the profile.
	 */
	private function assert_supported_formats_do_not_fail_conversion( $attachment_id, array $formats ) {
		if ( empty( $formats ) ) {
			return;
		}

		$image_data = ( new ImageModel() )->get_by_attachment_id( $attachment_id );
		$metadata   = $image_data && isset( $image_data['metadata'] ) && is_array( $image_data['metadata'] ) ? $image_data['metadata'] : array();
		$failed     = isset( $metadata['failed_tasks'] ) && is_array( $metadata['failed_tasks'] ) ? $metadata['failed_tasks'] : array();

		foreach ( $failed as $task ) {
			if ( ! is_array( $task ) || empty( $task['format'] ) ) {
				continue;
			}

			if ( in_array( $task['format'], $formats, true ) ) {
				throw new Exception(
					sprintf(
						'Format "%s" was reported supported but conversion failed: %s',
						$task['format'],
						wp_json_encode( $task )
					)
				);
			}
		}
	}

	/**
	 * Check unsupported output format settings do not affect effective hash.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	private function check_unsupported_format_setting_does_not_change_hash( $attachment_id ) {
		$metadata = wp_get_attachment_metadata( $attachment_id );
		$settings = new Settings();
		$options  = get_option( 'trust_optimize_options', array() );

		$options['convert_to_avif'] = 1;
		update_option( 'trust_optimize_options', $options );

		$enabled_profile = ( new ImageProfileFactory( $settings ) )->from_wp_metadata( is_array( $metadata ) ? $metadata : array() );
		$enabled_data    = $enabled_profile->to_array();

		if ( empty( $enabled_data['options']['unsupported_output_formats'] ) || ! in_array( 'avif', $enabled_data['options']['unsupported_output_formats'], true ) ) {
			$this->skip( 'Unsupported AVIF hash stability check skipped because AVIF is supported in this environment.' );
			return;
		}

		$options['convert_to_avif'] = 0;
		update_option( 'trust_optimize_options', $options );

		$disabled_profile = ( new ImageProfileFactory( $settings ) )->from_wp_metadata( is_array( $metadata ) ? $metadata : array() );

		if ( $enabled_profile->get_hash() !== $disabled_profile->get_hash() ) {
			throw new Exception( 'Disabling unsupported AVIF changed effective profile hash.' );
		}

		$options['convert_to_avif'] = 1;
		update_option( 'trust_optimize_options', $options );

		$this->pass( 'Unsupported AVIF setting does not change effective profile hash.' );
	}

	/**
	 * Check destructive remove command flags are declared in WP-CLI synopsis.
	 */
	private function check_wp_cli_remove_synopsis() {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			$this->skip( 'WP-CLI remove synopsis check skipped outside WP-CLI.' );
			return;
		}

		$output = \WP_CLI::runcommand(
			'help trust-optimize remove',
			array(
				'return'     => true,
				'exit_error' => false,
			)
		);

		if ( false === strpos( $output, 'wp trust-optimize remove [--all] [--yes] [--batch-size=<number>]' ) ) {
			throw new Exception( 'WP-CLI remove synopsis does not declare --all/--yes flags correctly.' );
		}

		$this->pass( 'WP-CLI remove synopsis accepts --all and --yes flags.' );
	}

	/**
	 * Check REST endpoints through WordPress REST dispatch.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	private function check_rest_endpoints( $attachment_id ) {
		$controller = new RestController();
		$controller->register_routes();

		$this->assert_rest_ok( 'GET', '/trust-optimize/v1/status' );
		$this->assert_rest_ok( 'POST', '/trust-optimize/v1/image/' . $attachment_id . '/sync' );
		$this->assert_rest_ok( 'POST', '/trust-optimize/v1/image/' . $attachment_id . '/remove', array( 'confirm' => true ) );

		$repository = new BulkJobRepository();
		$active     = $repository->get_active_job();

		if ( $active ) {
			$this->skip( 'REST pause/resume/cancel checks skipped because a pre-existing active bulk job exists: #' . $active->get_id() . ' ' . $active->get_status() );
			return;
		}

		$this->check_rest_start_runs_initial_tick();

		$scope = $this->get_smoke_attachment_scope();
		$job   = $repository->create(
			BulkJob::TYPE_SYNC,
			array(
				'smoke'          => true,
				'attachment_ids' => array_slice( $scope, 0, 1 ),
			),
			'',
			min( 1, count( $scope ) )
		);

		if ( ! $job ) {
			throw new Exception( 'Unable to create REST control smoke job; active job may already exist.' );
		}

		$runner = new BulkJobRunner( $repository );
		$runner->start( $job->get_id() );
		$this->assert_rest_ok( 'POST', '/trust-optimize/v1/bulk/pause' );
		$this->assert_rest_ok( 'POST', '/trust-optimize/v1/bulk/resume' );
		$this->assert_rest_ok( 'POST', '/trust-optimize/v1/bulk/cancel', array( 'confirm' => true ) );

		$this->pass( 'REST status, single sync/remove, pause/resume/cancel endpoints respond.' );
	}

	/**
	 * Check REST-started jobs make immediate bounded progress.
	 */
	private function check_rest_start_runs_initial_tick() {
		$response = $this->dispatch_rest_request( 'POST', '/trust-optimize/v1/bulk/inventory' );
		$data     = $response->get_data();
		$job      = isset( $data['job'] ) && is_array( $data['job'] ) ? $data['job'] : array();

		if ( empty( $job ) ) {
			throw new Exception( 'REST inventory start did not return a job.' );
		}

		$processed = isset( $job['processed'] ) ? (int) $job['processed'] : 0;
		$status    = isset( $job['status'] ) ? $job['status'] : '';

		if ( BulkJob::STATUS_RUNNING === $status && 0 === $processed ) {
			throw new Exception( 'REST-started inventory job remained running with processed=0.' );
		}

		if ( BulkJob::STATUS_RUNNING === $status ) {
			$status_response = $this->dispatch_rest_request( 'GET', '/trust-optimize/v1/bulk/status' );
			$status_data     = $status_response->get_data();
			$status_job      = isset( $status_data['job'] ) && is_array( $status_data['job'] ) ? $status_data['job'] : array();
			$status_processed = isset( $status_job['processed'] ) ? (int) $status_job['processed'] : 0;
			$status_status    = isset( $status_job['status'] ) ? $status_job['status'] : '';

			if ( BulkJob::STATUS_RUNNING === $status_status && $status_processed <= $processed ) {
				throw new Exception( 'REST status polling did not advance a running inventory job.' );
			}

			$job    = $status_job;
			$status = $status_status;
		}

		if ( BulkJob::STATUS_RUNNING === $status ) {
			$repository = new BulkJobRepository();
			$runner     = new BulkJobRunner( $repository );
			$runner->cancel( (int) $job['id'] );
		}

		$this->pass( 'REST-started inventory job makes immediate bounded progress and status polling advances it.' );
	}

	/**
	 * Check bulk inventory and a bounded sync tick.
	 */
	private function check_bulk_inventory_and_sync() {
		$repository = new BulkJobRepository();
		$runner     = new BulkJobRunner( $repository );
		$active     = $repository->get_active_job();

		if ( $active ) {
			$this->skip( 'Bulk inventory/sync checks skipped because a pre-existing active bulk job exists: #' . $active->get_id() . ' ' . $active->get_status() );
			return;
		}

		$scope = $this->get_smoke_attachment_scope();

		$inventory = $repository->create(
			BulkJob::TYPE_INVENTORY,
			array(
				'smoke'          => true,
				'attachment_ids' => $scope,
			),
			'',
			count( $scope )
		);
		if ( ! $inventory ) {
			throw new Exception( 'Unable to create inventory smoke job; active job may already exist.' );
		}

		add_filter( 'trust_optimize_bulk_batch_size', array( $this, 'smoke_batch_size' ) );
		$runner->start( $inventory->get_id() );
		$runner->tick( $inventory->get_id() );
		$inventory = $repository->get( $inventory->get_id() );
		$this->assert_job_progressed( $inventory, 'inventory' );
		$this->assert_job_counters_within_total( $inventory );
		$this->assert_inventory_counts_within_total( $inventory );

		if ( BulkJob::STATUS_RUNNING === $inventory->get_status() ) {
			$runner->cancel( $inventory->get_id() );
		}

		$sync_scope = array_slice( $scope, 0, 2 );
		$sync       = $repository->create(
			BulkJob::TYPE_SYNC,
			array(
				'smoke'          => true,
				'attachment_ids' => $sync_scope,
			),
			'',
			count( $sync_scope )
		);
		if ( ! $sync ) {
			throw new Exception( 'Unable to create sync smoke job; active job may already exist.' );
		}

		$runner->start( $sync->get_id() );
		$runner->tick( $sync->get_id() );
		$sync = $repository->get( $sync->get_id() );
		$this->assert_job_progressed( $sync, 'sync' );
		$this->assert_job_counters_within_total( $sync );

		if ( BulkJob::STATUS_RUNNING === $sync->get_status() ) {
			$runner->cancel( $sync->get_id() );
		}

		remove_filter( 'trust_optimize_bulk_batch_size', array( $this, 'smoke_batch_size' ) );

		$this->pass( 'Bulk inventory and bounded bulk sync tick progressed.' );
	}

	/**
	 * Check reprocess after profile hash changes.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	private function check_reprocess_after_profile_change( $attachment_id ) {
		$model  = new ImageModel();
		$before = $model->get_profile_hash( $attachment_id );

		$options = get_option( 'trust_optimize_options', array() );
		$options['webp_quality'] = isset( $options['webp_quality'] ) ? (int) $options['webp_quality'] - 1 : 81;
		update_option( 'trust_optimize_options', $options );

		$result = ( new ImageOptimizationService() )->optimize_attachment( $attachment_id );
		if ( $result->is_failed() ) {
			throw new Exception( 'Reprocess after profile change failed: ' . wp_json_encode( $result->to_array() ) );
		}

		$after = $model->get_profile_hash( $attachment_id );
		if ( '' !== $before && $before === $after ) {
			throw new Exception( 'Profile hash did not change after quality setting update.' );
		}

		$this->pass( 'Repeated sync observes changed profile hash.' );
	}

	/**
	 * Return small smoke batch size.
	 *
	 * @return int
	 */
	public function smoke_batch_size() {
		return 2;
	}

	/**
	 * Assert REST request is successful.
	 *
	 * @param string $method REST method.
	 * @param string $route  REST route.
	 * @param array  $params Request params.
	 */
	private function assert_rest_ok( $method, $route, array $params = array() ) {
		$response = $this->dispatch_rest_request( $method, $route, $params );

		if ( $response->is_error() || $response->get_status() >= 400 ) {
			throw new Exception( sprintf( 'REST %s %s failed: %s', $method, $route, wp_json_encode( $response->as_error() ? $response->as_error()->get_error_messages() : $response->get_data() ) ) );
		}
	}

	/**
	 * Dispatch REST request.
	 *
	 * @param string $method REST method.
	 * @param string $route  REST route.
	 * @param array  $params Request params.
	 * @return WP_REST_Response REST response.
	 */
	private function dispatch_rest_request( $method, $route, array $params = array() ) {
		$request = new WP_REST_Request( $method, $route );

		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		return rest_do_request( $request );
	}

	/**
	 * Assert job processed at least one candidate.
	 *
	 * @param BulkJob $job  Job.
	 * @param string  $type Expected type.
	 */
	private function assert_job_progressed( BulkJob $job, $type ) {
		$data = $job->to_array();

		if ( $type !== $job->get_type() ) {
			throw new Exception( sprintf( 'Expected %s job, got %s.', $type, $job->get_type() ) );
		}

		if ( (int) $data['processed'] < 1 && BulkJob::STATUS_COMPLETED !== $job->get_status() ) {
			throw new Exception( sprintf( 'Bulk %s job did not progress: %s', $type, wp_json_encode( $data ) ) );
		}
	}

	/**
	 * Assert job counters do not exceed total.
	 *
	 * @param BulkJob $job Job.
	 */
	private function assert_job_counters_within_total( BulkJob $job ) {
		$data      = $job->to_array();
		$total     = isset( $data['total'] ) ? (int) $data['total'] : 0;
		$processed = isset( $data['processed'] ) ? (int) $data['processed'] : 0;

		if ( $processed > $total ) {
			throw new Exception( 'Bulk job processed more attachments than total: ' . wp_json_encode( $data ) );
		}
	}

	/**
	 * Assert scoped inventory counters do not exceed total.
	 *
	 * @param BulkJob $job Inventory job.
	 */
	private function assert_inventory_counts_within_total( BulkJob $job ) {
		$data      = $job->to_array();
		$total     = isset( $data['total'] ) ? (int) $data['total'] : 0;
		$snapshot  = isset( $data['settings_snapshot'] ) && is_array( $data['settings_snapshot'] ) ? $data['settings_snapshot'] : array();
		$inventory = isset( $snapshot['inventory'] ) && is_array( $snapshot['inventory'] ) ? $snapshot['inventory'] : array();

		foreach ( array( 'total_image_attachments', 'eligible_attachments', 'missing_source_files' ) as $key ) {
			if ( isset( $inventory[ $key ] ) && (int) $inventory[ $key ] > $total ) {
				throw new Exception( sprintf( 'Inventory counter %s exceeds job total: %s', $key, wp_json_encode( $inventory ) ) );
			}
		}
	}

	/**
	 * Get current smoke image attachment scope.
	 *
	 * @return array Attachment IDs.
	 */
	private function get_smoke_attachment_scope() {
		$scope = array();

		foreach ( $this->attachments as $attachment_id ) {
			if ( ! get_post( $attachment_id ) || 'attachment' !== get_post_type( $attachment_id ) ) {
				continue;
			}

			if ( 0 !== strpos( (string) get_post_mime_type( $attachment_id ), 'image/' ) ) {
				continue;
			}

			$scope[] = (int) $attachment_id;
		}

		sort( $scope );

		return $scope;
	}

	/**
	 * Create image attachment fixture.
	 *
	 * @param string $extension File extension.
	 * @param string $mime_type MIME type.
	 * @return int Attachment ID.
	 */
	private function create_image_attachment( $extension, $mime_type ) {
		$path = $this->write_fixture_file( $extension, $this->get_fixture_image_bytes( $extension ) );

		$attachment_id = wp_insert_attachment(
			array(
				'post_title'     => 'trust-optimize-smoke-' . wp_generate_uuid4(),
				'post_status'    => 'inherit',
				'post_mime_type' => $mime_type,
				'post_type'      => 'attachment',
			),
			$path
		);

		if ( is_wp_error( $attachment_id ) || ! $attachment_id ) {
			throw new Exception( 'Unable to create image attachment fixture.' );
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';
		$metadata = wp_generate_attachment_metadata( $attachment_id, $path );
		wp_update_attachment_metadata( $attachment_id, is_array( $metadata ) ? $metadata : array( 'file' => _wp_relative_upload_path( $path ) ) );

		$this->attachments[] = (int) $attachment_id;

		return (int) $attachment_id;
	}

	/**
	 * Create image attachment whose source file is missing.
	 *
	 * @return int Attachment ID.
	 */
	private function create_missing_source_attachment() {
		$attachment_id = $this->create_image_attachment( 'jpg', 'image/jpeg' );
		$file          = get_attached_file( $attachment_id );

		if ( $file && file_exists( $file ) ) {
			wp_delete_file( $file );
		}

		return $attachment_id;
	}

	/**
	 * Create unsupported image MIME attachment fixture.
	 *
	 * @return int Attachment ID.
	 */
	private function create_unsupported_mime_attachment() {
		$path = $this->write_fixture_file( 'svg', '<svg xmlns="http://www.w3.org/2000/svg" width="1" height="1"></svg>' );

		$attachment_id = wp_insert_attachment(
			array(
				'post_title'     => 'trust-optimize-smoke-svg-' . wp_generate_uuid4(),
				'post_status'    => 'inherit',
				'post_mime_type' => 'image/svg+xml',
				'post_type'      => 'attachment',
			),
			$path
		);

		if ( is_wp_error( $attachment_id ) || ! $attachment_id ) {
			throw new Exception( 'Unable to create unsupported MIME attachment fixture.' );
		}

		update_post_meta( $attachment_id, '_wp_attached_file', _wp_relative_upload_path( $path ) );
		wp_update_attachment_metadata( $attachment_id, array( 'file' => _wp_relative_upload_path( $path ) ) );
		$this->attachments[] = (int) $attachment_id;

		return (int) $attachment_id;
	}

	/**
	 * Create a fake generated variant recorded in the plugin manifest.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $format        Format.
	 * @return string Variant absolute path.
	 */
	private function create_manifest_variant( $attachment_id, $format ) {
		$original = get_attached_file( $attachment_id );
		$variant  = trailingslashit( dirname( $original ) ) . pathinfo( $original, PATHINFO_FILENAME ) . '-trust-optimize-smoke.' . $format;

		file_put_contents( $variant, 'trust-optimize-smoke-generated-variant' );
		$this->files[] = $variant;

		$model    = new ImageModel();
		$metadata = wp_get_attachment_metadata( $attachment_id );
		$stored   = $model->create_base_metadata( is_array( $metadata ) ? $metadata : array() );
		$stored['generated_variants'] = array(
			'original:' . $format => array(
				'attachment_id' => (int) $attachment_id,
				'size_name'     => 'original',
				'format'        => $format,
				'mime_type'     => 'image/' . $format,
				'file'          => basename( $variant ),
				'relative_dir'  => trim( dirname( _wp_relative_upload_path( $variant ) ), '.' ),
				'file_size'     => filesize( $variant ),
				'profile_hash'  => 'trust-optimize-smoke',
			),
		);

		$model->save( $attachment_id, $stored );
		$model->update_status( $attachment_id, 'completed', 1 );
		$model->increment_completed_tasks( $attachment_id );

		return $variant;
	}

	/**
	 * Write a fixture file into uploads.
	 *
	 * @param string $extension File extension.
	 * @param string $bytes     File content.
	 * @return string Absolute path.
	 */
	private function write_fixture_file( $extension, $bytes ) {
		$upload_dir = wp_upload_dir();

		if ( empty( $upload_dir['path'] ) || ! wp_mkdir_p( $upload_dir['path'] ) ) {
			throw new Exception( 'Uploads directory is not writable.' );
		}

		$path = trailingslashit( $upload_dir['path'] ) . 'trust-optimize-smoke-' . wp_generate_uuid4() . '.' . $extension;
		file_put_contents( $path, $bytes );
		$this->files[] = $path;

		return $path;
	}

	/**
	 * Get small image fixture bytes.
	 *
	 * @param string $extension File extension.
	 * @return string Image bytes.
	 */
	private function get_fixture_image_bytes( $extension ) {
		if ( 'png' === $extension ) {
			return base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAFgwJ/lGq6HgAAAABJRU5ErkJggg==' );
		}

		return base64_decode( '/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////2wBDAf//////////////////////////////////////////////////////////////////////////////////////wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAX/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIQAxAAAAH/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEBAAEFAqf/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAEDAQE/ASP/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAECAQE/ASP/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEBAAY/Aqf/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEBAAE/ISP/2gAMAwEAAgADAAAAEP/EFBQRAQAAAAAAAAAAAAAAAAAAABD/2gAIAQMBAT8QH//EFBQRAQAAAAAAAAAAAAAAAAAAABD/2gAIAQIBAT8QH//EFBABAQAAAAAAAAAAAAAAAAAAABD/2gAIAQEAAT8QH//Z' );
	}

	/**
	 * Remove created records/files and restore options.
	 */
	private function cleanup() {
		if ( null === $this->original_options ) {
			delete_option( 'trust_optimize_options' );
		} else {
			update_option( 'trust_optimize_options', $this->original_options );
		}

		$model = new ImageModel();

		foreach ( array_reverse( $this->attachments ) as $attachment_id ) {
			$model->delete( $attachment_id );
			wp_delete_attachment( $attachment_id, true );
		}

		foreach ( array_unique( $this->files ) as $file ) {
			if ( is_string( $file ) && file_exists( $file ) && 0 === strpos( wp_normalize_path( $file ), wp_normalize_path( wp_upload_dir()['basedir'] ) ) ) {
				wp_delete_file( $file );
			}
		}

		if ( $this->created_user_id ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
			wp_delete_user( $this->created_user_id );
		}
	}

	/**
	 * Print passed check.
	 *
	 * @param string $message Message.
	 */
	private function pass( $message ) {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::log( 'PASS: ' . $message );
			return;
		}

		echo 'PASS: ' . $message . PHP_EOL;
	}

	/**
	 * Print skipped check.
	 *
	 * @param string $message Message.
	 */
	private function skip( $message ) {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::warning( 'SKIP: ' . $message );
			return;
		}

		echo 'SKIP: ' . $message . PHP_EOL;
	}

	/**
	 * Print failure and exit.
	 *
	 * @param string $message Message.
	 */
	private function fail( $message ) {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::error( $message );
		}

		fwrite( STDERR, 'FAIL: ' . $message . PHP_EOL );
		exit( 1 );
	}
};

$trust_optimize_smoke->run();
