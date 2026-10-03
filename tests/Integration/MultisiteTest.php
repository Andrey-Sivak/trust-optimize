<?php
/**
 * Activation, conversion and delivery on the sites of a network (07.0).
 *
 * @package TrustOptimize\Tests
 */

require_once __DIR__ . '/uninstall-fixture.php';

use TrustOptimize\Admin\Settings;
use TrustOptimize\Capabilities\CapabilityService;
use TrustOptimize\Database\DatabaseManager;
use TrustOptimize\Domain\AttachmentState;
use TrustOptimize\Queue\ConversionQueue;
use TrustOptimize\Storage\AttachmentRepository;
use TrustOptimize\Storage\VariantRepository;

/**
 * @group ms-required
 * @covers \TrustOptimize\Database\DatabaseManager
 * @covers \TrustOptimize\Queue\ConversionQueue
 * @covers \TrustOptimize\Frontend\ImageDelivery
 */
class MultisiteTest extends WP_UnitTestCase {

	use Uninstall_Fixture;

	/**
	 * Sites created by the test (the main site is not among them).
	 *
	 * @var int[]
	 */
	private $blog_ids = array();

	public function set_up() {
		parent::set_up();

		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Needs WP_MULTISITE=1.' );
		}

		// The sites come first: their core tables stay temporary, only the plugin tables are real.
		$this->blog_ids = array( self::factory()->blog->create(), self::factory()->blog->create() );
		$this->use_real_tables();
		array_map( array( $this, 'reset_site' ), $this->blog_ids );
		set_current_screen( 'front' );
	}

	/**
	 * Forget what an earlier run left for a site ID.
	 *
	 * Site IDs restart after the test database is reinstalled, but the reinstall only drops the tables
	 * of the main site, so tables and options of the plugin from an earlier run can still be there.
	 *
	 * @param int $blog_id Site ID.
	 */
	private function reset_site( $blog_id ) {
		$this->drop_site_tables( $blog_id );

		switch_to_blog( $blog_id );
		foreach ( array( 'trust_optimize_db_version', CapabilityService::OPTION, 'trust_optimize_options' ) as $option ) {
			delete_option( $option );
		}
		restore_current_blog();
	}

	/**
	 * Drop the plugin tables of a site.
	 *

	 * @param int $blog_id Site ID.
	 */
	private function drop_site_tables( $blog_id ) {
		global $wpdb;

		switch_to_blog( $blog_id );
		$database = new DatabaseManager();
		foreach ( array( 'attachments', 'variants', 'jobs' ) as $key ) {
			$wpdb->query( 'DROP TABLE IF EXISTS ' . $database->get_plugin_table_names()[ $key ] ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		restore_current_blog();
	}

	public function tear_down() {
		global $wpdb;

		$wpdb->query( 'ROLLBACK' );
		while ( ms_is_switched() ) {
			restore_current_blog();
		}
		array_map( array( $this, 'drop_site_tables' ), $this->blog_ids );
		$this->restore_plugin_tables();
		delete_option( 'trust_optimize_db_version' );
		parent::tear_down();
	}

	/**
	 * What a first request of a site does: the schema check of the plugin and the Action Scheduler tables.
	 *
	 * Call it inside switch_to_blog().
	 */
	private function first_request() {
		( new DatabaseManager() )->check_version();
		( new ActionScheduler_StoreSchema() )->register_tables( true );
		( new ActionScheduler_LoggerSchema() )->register_tables( true );
	}

	/**
	 * Make a site ready to convert: first request, formats and WebP only.
	 *
	 * @param int $blog_id Site ID.
	 */
	private function prepare_site( $blog_id ) {
		switch_to_blog( $blog_id );
		$this->first_request();
		update_option( CapabilityService::OPTION, array( 'webp' => true, 'avif' => false ) );
		update_option( 'trust_optimize_options', array( 'convert_to_webp' => 1, 'convert_to_avif' => 0, 'enable_adaptive_images' => 1 ) );
		restore_current_blog();
	}

	private function upload() {
		return self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
	}

	private function attachments() {
		$database = new DatabaseManager();

		return new AttachmentRepository( $database, new VariantRepository( $database ) );
	}

	private function variants() {
		return new VariantRepository( new DatabaseManager() );
	}

	private function run_queue() {
		ActionScheduler_QueueRunner::instance()->run();
	}

	private function row_count( $key ) {
		global $wpdb;

		$table = ( new DatabaseManager() )->get_plugin_table_names()[ $key ];

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public function test_network_activation_creates_main_tables_and_subsites_get_their_own_on_first_request() {
		global $wpdb;

		list( $site_two ) = $this->blog_ids;
		$main_tables      = ( new DatabaseManager() )->get_plugin_table_names();
		foreach ( array( 'attachments', 'variants', 'jobs' ) as $key ) {
			$wpdb->query( 'DROP TABLE ' . $main_tables[ $key ] ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		delete_option( 'trust_optimize_db_version' );
		delete_option( CapabilityService::OPTION );

		do_action( 'activate_' . TRUST_OPTIMIZE_PLUGIN_BASENAME, true );

		foreach ( array( 'attachments', 'variants', 'jobs' ) as $key ) {
			$this->assertTrue( $this->table_exists( $key ), "The main site has its {$key} table right after activation." );
		}
		$this->assertIsArray( get_option( CapabilityService::OPTION ) );

		switch_to_blog( $site_two );
		$this->assertSame( "wptests_{$site_two}_", $wpdb->prefix );
		foreach ( array( 'attachments', 'variants', 'jobs' ) as $key ) {
			$this->assertFalse( $this->table_exists( $key ), "A subsite has no {$key} table before its first request." );
		}
		$this->assertFalse( get_option( CapabilityService::OPTION ) );

		$this->first_request();

		foreach ( array( 'attachments', 'variants', 'jobs' ) as $key ) {
			$this->assertTrue( $this->table_exists( $key ), "A subsite gets its {$key} table on the first request." );
		}
		$this->assertTrue( ( new DatabaseManager() )->table_exists( "wptests_{$site_two}_trust_optimize_variants" ) );
		$this->assertSame( DatabaseManager::DB_VERSION, get_option( 'trust_optimize_db_version' ) );
		( new CapabilityService() )->supports( 'webp' );
		$this->assertIsArray( get_option( CapabilityService::OPTION ), 'The subsite keeps its own capabilities.' );
		restore_current_blog();
	}

	public function test_settings_are_per_site() {
		list( $site_two ) = $this->blog_ids;
		update_option( 'trust_optimize_options', array( 'webp_quality' => 50 ) );

		switch_to_blog( $site_two );
		$settings = new Settings();
		$this->assertSame( 85, $settings->get( 'webp_quality' ), 'A new site starts from the defaults, not from the main site.' );
		$this->assertSame( 1, $settings->get( 'convert_to_webp' ) );
		restore_current_blog();

		$this->assertSame( 50, ( new Settings() )->get( 'webp_quality' ) );
	}

	public function test_a_site_created_after_network_activation_optimizes_uploads() {
		$new_site = self::factory()->blog->create();
		$this->blog_ids[] = $new_site;
		$this->use_real_tables();
		$this->reset_site( $new_site );

		switch_to_blog( $new_site );
		$this->first_request();
		update_option( CapabilityService::OPTION, array( 'webp' => true, 'avif' => false ) );
		$this->assertTrue( $this->table_exists( 'variants' ) );

		$id = $this->upload();

		$this->assertSame( AttachmentState::QUEUED, $this->attachments()->get_state( $id ), 'The upload on the new site is queued with the default settings.' );
		restore_current_blog();
	}

	public function test_conversion_on_a_subsite_writes_into_its_uploads_and_its_own_tables() {
		list( $site_two ) = $this->blog_ids;
		$this->prepare_site( $site_two );
		$main_rows = $this->row_count( 'variants' );

		switch_to_blog( $site_two );
		$id = $this->upload();
		$this->run_queue();

		$this->assertSame( AttachmentState::OPTIMIZED, $this->attachments()->get_state( $id ) );
		$basedir = wp_upload_dir()['basedir'];
		$this->assertStringContainsString( "/sites/{$site_two}", $basedir );
		$rows = $this->variants()->get_servable_for_attachment( $id );
		$this->assertNotEmpty( $rows );
		foreach ( $rows as $row ) {
			$this->assertMatchesRegularExpression( '#/canola[\w-]*\.jpg\.webp$#', $row['relative_path'] );
			$this->assertFileExists( $basedir . '/' . $row['relative_path'] );
		}
		$this->assertGreaterThan( 0, $this->row_count( 'variants' ) );
		restore_current_blog();

		$this->assertSame( $main_rows, $this->row_count( 'variants' ), 'The main site has no rows of the subsite.' );
	}

	public function test_delivery_on_a_subsite_uses_the_urls_of_that_site() {
		list( $site_two ) = $this->blog_ids;
		$this->prepare_site( $site_two );

		switch_to_blog( $site_two );
		$id = $this->upload();
		$this->run_queue();
		$baseurl = wp_upload_dir()['baseurl'];

		$html = apply_filters( 'the_content', '<img src="' . wp_get_attachment_url( $id ) . '" class="wp-image-' . $id . '" alt="x">' );

		$this->assertStringContainsString( '<picture>', $html );
		$this->assertStringContainsString( "/uploads/sites/{$site_two}", $baseurl );
		$this->assertSame( 1, preg_match( '#<source[^>]*srcset="([^"]+)"#', $html, $match ) );
		foreach ( explode( ',', $match[1] ) as $candidate ) {
			$url = preg_split( '/\s+/', trim( $candidate ) )[0];
			$this->assertStringStartsWith( $baseurl . '/', $url );
			$this->assertStringEndsWith( '.jpg.webp', $url );
		}
		restore_current_blog();
	}

	public function test_deleting_an_attachment_of_one_site_leaves_the_other_alone() {
		list( $site_two, $site_three ) = $this->blog_ids;
		$this->prepare_site( $site_two );
		$this->prepare_site( $site_three );

		switch_to_blog( $site_three );
		$other = $this->upload();
		$this->run_queue();
		$other_rows  = $this->variants()->get_servable_for_attachment( $other );
		$other_base  = wp_upload_dir()['basedir'];
		$other_count = $this->row_count( 'variants' );
		restore_current_blog();

		switch_to_blog( $site_two );
		$id = $this->upload();
		$this->run_queue();
		$own_rows = $this->variants()->get_servable_for_attachment( $id );
		$own_base = wp_upload_dir()['basedir'];
		wp_delete_attachment( $id, true );
		$this->assertSame( 0, $this->row_count( 'variants' ) );
		foreach ( $own_rows as $row ) {
			$this->assertFileDoesNotExist( $own_base . '/' . $row['relative_path'] );
		}
		restore_current_blog();

		switch_to_blog( $site_three );
		$this->assertSame( $other_count, $this->row_count( 'variants' ) );
		foreach ( $other_rows as $row ) {
			$this->assertFileExists( $other_base . '/' . $row['relative_path'] );
		}
		restore_current_blog();
	}

	public function test_the_queue_of_a_site_is_only_run_in_the_context_of_that_site() {
		list( $site_two, $site_three ) = $this->blog_ids;
		$this->prepare_site( $site_two );
		$this->prepare_site( $site_three );

		$ids = array();
		foreach ( $this->blog_ids as $blog_id ) {
			switch_to_blog( $blog_id );
			$ids[ $blog_id ] = $this->upload();
			$this->assertCount( 1, as_get_scheduled_actions( array( 'hook' => ConversionQueue::HOOK_PROCESS, 'status' => ActionScheduler_Store::STATUS_PENDING ), 'ids' ), "The action of site {$blog_id} is in the tables of that site." );
			restore_current_blog();
		}
		$this->assertSame( array(), as_get_scheduled_actions( array( 'hook' => ConversionQueue::HOOK_PROCESS, 'args' => array( 'attachment_id' => $ids[ $site_two ] ), 'status' => ActionScheduler_Store::STATUS_PENDING ), 'ids' ), 'The main site does not see the actions of subsites.' );

		$this->run_queue();

		foreach ( $this->blog_ids as $blog_id ) {
			switch_to_blog( $blog_id );
			$this->assertSame( AttachmentState::QUEUED, $this->attachments()->get_state( $ids[ $blog_id ] ), 'A run in the main site context does not process other sites.' );
			restore_current_blog();
		}

		switch_to_blog( $site_two );
		$this->run_queue();
		$this->assertSame( AttachmentState::OPTIMIZED, $this->attachments()->get_state( $ids[ $site_two ] ) );
		restore_current_blog();

		switch_to_blog( $site_three );
		$this->assertSame( AttachmentState::QUEUED, $this->attachments()->get_state( $ids[ $site_three ] ), 'Running site 2 leaves site 3 alone.' );
		restore_current_blog();
	}
}
