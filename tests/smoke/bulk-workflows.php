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
use TrustOptimize\Database\DatabaseManager;
use TrustOptimize\Capabilities\CapabilityService;
use TrustOptimize\Core\Plugin;
use TrustOptimize\Domain\JobStatus;
use TrustOptimize\Domain\VariantStatus;
use TrustOptimize\Storage\VariantRepository;

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
			$this->check_unsupported_format_is_not_planned( $second_id );
			$this->check_wp_cli_remove_synopsis();
			$this->check_rest_endpoints( $second_id );
			$this->check_bulk_sync_through_the_queue();
			$this->check_reprocess_after_quality_change( $second_id );

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
		if ( ! defined( 'TRUST_OPTIMIZE_VERSION' ) || ! class_exists( Plugin::class ) ) {
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

		// The 1.x registry ("images") is dropped by the migration, so it is not required.
		foreach ( array_diff_key( $manager->get_plugin_table_names(), array( 'images' => true ) ) as $key => $table ) {
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

		foreach ( $attachment_ids as $attachment_id ) {
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
		$result = Plugin::get_instance()->processor->sync( $attachment_id );

		if ( $result->is_failed() ) {
			throw new Exception( 'Single attachment sync failed: ' . wp_json_encode( $result->to_array() ) );
		}

		$this->pass( 'Single attachment sync path returns a terminal non-failed result.' );
	}

	/**
	 * Check cleanup deletes only files owned by a variant row and keeps original media.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	private function check_cleanup_preserves_original( $attachment_id ) {
		$original = get_attached_file( $attachment_id );
		$variant  = $this->create_manifest_variant( $attachment_id, 'webp' );

		$result = Plugin::get_instance()->cleanup->cleanup_attachment( $attachment_id );

		if ( $result->is_failed() ) {
			throw new Exception( 'Cleanup failed: ' . wp_json_encode( $result->to_array() ) );
		}

		if ( ! file_exists( $original ) ) {
			throw new Exception( 'Cleanup removed original attachment file.' );
		}

		if ( file_exists( $variant ) ) {
			throw new Exception( 'Cleanup did not remove the generated variant owned by a row.' );
		}

		$this->pass( 'Cleanup removes row-owned variant and preserves original file.' );
	}

	/**
	 * Check single remove path.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	private function check_single_remove( $attachment_id ) {
		$this->create_manifest_variant( $attachment_id, 'webp' );

		$result = Plugin::get_instance()->cleanup->cleanup_attachment( $attachment_id );

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
		$result = Plugin::get_instance()->processor->sync( $attachment_id );

		if ( ! $result->is_skipped() || 'missing_file' !== $result->get_message() || array() !== $this->variants()->get_for_attachment( $attachment_id ) ) {
			throw new Exception( 'Missing source file was not skipped as missing_file: ' . wp_json_encode( $result->to_array() ) );
		}

		$this->pass( 'Missing source file is reported explicitly.' );
	}

	/**
	 * Check unsupported MIME handling.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	private function check_unsupported_mime( $attachment_id ) {
		$result = Plugin::get_instance()->processor->sync( $attachment_id );

		if ( ! $result->is_skipped() || 'unsupported_mime' !== $result->get_message() ) {
			throw new Exception( 'Unsupported MIME was not skipped as unsupported_mime: ' . wp_json_encode( $result->to_array() ) );
		}

		$this->pass( 'Unsupported MIME is skipped explicitly.' );
	}

	/**
	 * Check that formats reported as supported do not fail during conversion.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	private function check_output_format_capabilities( $attachment_id ) {
		$result = Plugin::get_instance()->processor->sync( $attachment_id );

		if ( $result->is_failed() ) {
			throw new Exception( 'Sync of a supported image failed: ' . wp_json_encode( $result->to_array() ) );
		}

		foreach ( $this->variants()->get_for_attachment( $attachment_id ) as $row ) {
			if ( VariantStatus::FAILED === $row['status'] ) {
				throw new Exception( sprintf( 'Format "%s" was reported supported but conversion failed: %s', $row['format'], wp_json_encode( $row ) ) );
			}
		}

		$this->pass( 'Supported WebP/AVIF output formats convert without failed tasks.' );
	}

	/**
	 * Check that an enabled but unsupported format is reported and generates nothing.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	private function check_unsupported_format_is_not_planned( $attachment_id ) {
		$stored  = get_option( CapabilityService::OPTION );
		$options = get_option( 'trust_optimize_options', array() );

		$options['convert_to_avif'] = 1;
		update_option( 'trust_optimize_options', $options );
		update_option(
			CapabilityService::OPTION,
			array(
				'webp' => true,
				'avif' => false,
			)
		);

		try {
			$inventory = Plugin::get_instance()->inventory->summary();
			$plan      = Plugin::get_instance()->planner->plan( $attachment_id );
		} finally {
			false === $stored ? delete_option( CapabilityService::OPTION ) : update_option( CapabilityService::OPTION, $stored );
		}

		if ( array( 'avif' ) !== $inventory['unsupported_output_formats'] ) {
			throw new Exception( 'Unsupported AVIF was not reported by the inventory: ' . wp_json_encode( $inventory ) );
		}

		foreach ( $plan->pending() as $row ) {
			if ( 'avif' === $row['format'] ) {
				throw new Exception( 'An unsupported format was planned for conversion.' );
			}
		}

		$this->pass( 'Unsupported AVIF is reported and not planned.' );
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

		foreach ( array( 'wp trust-optimize remove', '[--all]', '[--yes]' ) as $expected ) {
			if ( false === strpos( $output, $expected ) ) {
				throw new Exception( 'WP-CLI remove synopsis does not declare --all/--yes flags correctly.' );
			}
		}

		if ( false === strpos( $output, 'Confirm destructive cleanup' ) ) {
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
		$this->assert_rest_ok( 'GET', '/trust-optimize/v1/status' );
		$this->assert_rest_ok( 'POST', '/trust-optimize/v1/image/' . $attachment_id . '/sync' );
		$this->assert_rest_ok( 'POST', '/trust-optimize/v1/image/' . $attachment_id . '/remove', array( 'confirm' => true ) );

		$active = ( new BulkJobRepository( new DatabaseManager() ) )->get_active_job();

		if ( $active ) {
			$this->skip( 'REST pause/resume/cancel checks skipped because a pre-existing active bulk job exists: #' . $active->get_id() . ' ' . $active->get_status() );
			return;
		}

		$response = $this->dispatch_rest_request( 'POST', '/trust-optimize/v1/bulk/inventory' );
		$job      = $response->get_data()['job'] ?? null;

		if ( empty( $job['id'] ) ) {
			throw new Exception( 'REST inventory start did not return a job.' );
		}

		$this->assert_rest_ok( 'POST', '/trust-optimize/v1/bulk/pause' );
		$this->assert_rest_ok( 'POST', '/trust-optimize/v1/bulk/resume' );
		$this->assert_rest_ok( 'POST', '/trust-optimize/v1/bulk/cancel', array( 'confirm' => true ) );

		$this->pass( 'REST status, single sync/remove, inventory start and pause/resume/cancel endpoints respond.' );
	}

	/**
	 * Check that a bulk sync job over the smoke attachments completes through the queue.
	 */
	private function check_bulk_sync_through_the_queue() {
		$producer = Plugin::get_instance()->bulk_producer;
		$jobs     = new BulkJobRepository( new DatabaseManager() );
		$active   = $jobs->get_active_job();

		if ( $active ) {
			$this->skip( 'Bulk sync check skipped because a pre-existing active bulk job exists: #' . $active->get_id() . ' ' . $active->get_status() );
			return;
		}

		$scope = $this->get_smoke_attachment_scope();
		$job   = $producer->launch( BulkJob::TYPE_SYNC );

		if ( ! $job || ! $scope ) {
			throw new Exception( 'Unable to launch the bulk sync smoke job.' );
		}

		// Start right before the smoke attachments, so the stand library is left alone.
		$jobs->set_cursor( $job->get_id(), min( $scope ) - 1 );

		$status = '';
		for ( $round = 0; $round < 40; $round++ ) {
			ActionScheduler_QueueRunner::instance()->run();
			$status = $jobs->get( $job->get_id() )->get_status();

			if ( ! in_array( $status, array( JobStatus::PENDING, JobStatus::RUNNING ), true ) ) {
				break;
			}

			$producer->produce( $job->get_id() );
		}

		if ( ! in_array( $status, array( JobStatus::COMPLETED, JobStatus::COMPLETED_WITH_ERRORS ), true ) ) {
			$producer->cancel( $job->get_id() );
			throw new Exception( 'The bulk sync job did not finish: ' . $status );
		}

		$response = $this->dispatch_rest_request( 'GET', '/trust-optimize/v1/bulk/status' );
		$data     = $response->get_data()['job'] ?? array();

		if ( (int) ( $data['processed'] ?? 0 ) < 1 ) {
			throw new Exception( 'Bulk status reports no processed attachments: ' . wp_json_encode( $data ) );
		}

		$this->pass( 'Bulk sync job ran through the queue and finished as ' . $status . '.' );
	}

	/**
	 * Check that a changed quality setting is applied by the next sync.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	private function check_reprocess_after_quality_change( $attachment_id ) {
		$options                 = get_option( 'trust_optimize_options', array() );
		$options['webp_quality'] = isset( $options['webp_quality'] ) ? (int) $options['webp_quality'] - 1 : 81;
		update_option( 'trust_optimize_options', $options );

		$result = Plugin::get_instance()->processor->sync( $attachment_id );
		if ( $result->is_failed() ) {
			throw new Exception( 'Reprocess after a quality change failed: ' . wp_json_encode( $result->to_array() ) );
		}

		foreach ( $this->variants()->get_servable_for_attachment( $attachment_id ) as $row ) {
			if ( 'webp' === $row['format'] && (int) $options['webp_quality'] !== $row['quality'] ) {
				throw new Exception( 'A webp variant kept the old quality after the setting changed.' );
			}
		}

		$this->pass( 'Repeated sync applies a changed quality setting.' );
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
	 * Create a fake generated variant file owned by a variant row.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $format        Format.
	 * @return string Variant absolute path.
	 */
	private function create_manifest_variant( $attachment_id, $format ) {
		$original = get_attached_file( $attachment_id );
		$variant  = $original . '.' . $format;

		file_put_contents( $variant, 'trust-optimize-smoke-generated-variant' );
		$this->files[] = $variant;

		$this->variants()->upsert(
			array(
				'attachment_id'        => (int) $attachment_id,
				'size_name'            => 'smoke',
				'format'               => $format,
				'status'               => VariantStatus::DONE,
				'source_relative_path' => _wp_relative_upload_path( $original ),
				'relative_path'        => _wp_relative_upload_path( $variant ),
			)
		);

		return $variant;
	}

	/**
	 * Variant repository of the running plugin.
	 *
	 * @return VariantRepository
	 */
	private function variants() {
		return new VariantRepository( new DatabaseManager() );
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

		if ( function_exists( 'imagecreatetruecolor' ) && function_exists( 'imagejpeg' ) ) {
			$image = imagecreatetruecolor( 16, 16 );

			if ( false !== $image ) {
				$color = imagecolorallocate( $image, 120, 30, 200 );
				imagefilledrectangle( $image, 0, 0, 15, 15, $color );

				ob_start();
				imagejpeg( $image, null, 90 );
				$bytes = ob_get_clean();
				imagedestroy( $image );

				if ( is_string( $bytes ) && '' !== $bytes ) {
					return $bytes;
				}
			}
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

		foreach ( array_reverse( $this->attachments ) as $attachment_id ) {
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

		$this->cleanup_existing_fixtures();
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
