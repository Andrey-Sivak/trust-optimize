<?php
/**
 * Status of many attachments in one request, and the preloaded media column.
 *
 * @package TrustOptimize\Tests
 */

use TrustOptimize\Core\Plugin;
use TrustOptimize\Database\DatabaseManager;
use TrustOptimize\Domain\AttachmentState;
use TrustOptimize\Storage\AttachmentRepository;
use TrustOptimize\Storage\VariantRepository;

/**
 * @covers \TrustOptimize\API\RestController::get_images_status
 * @covers \TrustOptimize\Admin\Admin::prime_media_states
 * @covers \TrustOptimize\Storage\AttachmentRepository::get_states
 */
class MediaStatusRestTest extends WP_UnitTestCase {

	/**
	 * Attachments.
	 *
	 * @var AttachmentRepository
	 */
	private $attachments;

	public function set_up() {
		parent::set_up();
		$database          = new DatabaseManager();
		$this->attachments = new AttachmentRepository( $database, new VariantRepository( $database ) );
	}

	private function image() {
		return self::factory()->attachment->create( array( 'post_mime_type' => 'image/jpeg' ) );
	}

	private function request( $ids ) {
		$request = new WP_REST_Request( 'GET', '/trust-optimize/v1/images/status' );
		$request->set_param( 'ids', $ids );

		return rest_do_request( $request );
	}

	public function test_returns_the_state_of_every_requested_attachment() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );
		$queued = $this->image();
		$done   = $this->image();
		$none   = $this->image();
		$this->attachments->set_state( $queued, AttachmentState::QUEUED );
		$this->attachments->set_state( $done, AttachmentState::OPTIMIZED );

		$response = $this->request( "$queued,$done,$none" );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			array(
				$queued => AttachmentState::QUEUED,
				$done   => AttachmentState::OPTIMIZED,
				$none   => AttachmentState::NONE,
			),
			$response->get_data()['states']
		);
	}

	public function test_needs_upload_files_not_manage_options() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertSame( 403, $this->request( (string) $this->image() )->get_status() );

		wp_set_current_user( 0 );
		$this->assertSame( 401, $this->request( (string) $this->image() )->get_status() );
	}

	public function test_refuses_more_ids_than_the_limit() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$response = $this->request( implode( ',', range( 1, 101 ) ) );

		$this->assertSame( 400, $response->get_status() );
	}

	public function test_the_old_per_image_route_is_gone() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->assertSame( 404, rest_do_request( new WP_REST_Request( 'GET', '/trust-optimize/v1/image/1/status' ) )->get_status() );
	}

	public function test_the_media_page_reads_all_states_with_one_query() {
		global $wpdb;

		$ids = array();
		foreach ( array( 'canola.jpg', 'test-image.jpg', '2004-07-22-DSC_0007.jpg' ) as $file ) {
			$ids[] = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/' . $file );
		}
		$this->attachments->set_state( $ids[0], AttachmentState::QUEUED );
		$this->attachments->set_state( $ids[1], AttachmentState::OPTIMIZED );
		$this->attachments->set_state( $ids[2], AttachmentState::FAILED );

		$GLOBALS['pagenow'] = 'upload.php'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		set_current_screen( 'upload' );
		$query = new WP_Query();
		$query->query( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'post__in' => $ids ) );
		$GLOBALS['wp_the_query'] = $query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$admin  = Plugin::get_instance()->admin;
		$before = $wpdb->num_queries;

		$admin->prime_media_states( $query->posts, $query );

		$this->assertSame( 1, $wpdb->num_queries - $before, 'One IN query for the whole page.' );

		$before = $wpdb->num_queries;
		$html   = array();
		foreach ( $ids as $id ) {
			ob_start();
			$admin->render_media_column( 'trust_optimize_status', $id );
			$html[] = ob_get_clean();
		}

		$this->assertSame( $before, $wpdb->num_queries, 'Rendering the rows asks the database nothing.' );
		$this->assertStringContainsString( 'trust-optimize-polling', $html[0] );
		$this->assertStringContainsString( 'data-status="queued"', $html[0] );
		$this->assertStringNotContainsString( 'trust-optimize-polling', $html[1] . $html[2] );
		$this->assertStringContainsString( 'data-status="optimized"', $html[1] );
		$this->assertStringContainsString( 'data-status="failed"', $html[2] );
	}
}
