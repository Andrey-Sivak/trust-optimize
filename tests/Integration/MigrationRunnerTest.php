<?php
/**
 * Resumable migration runner (03.1).
 *
 * @package TrustOptimize\Tests
 */

use TrustOptimize\Database\DatabaseManager;
use TrustOptimize\Migration\BatchResult;
use TrustOptimize\Migration\MigrationRunner;
use TrustOptimize\Migration\MigrationStep;

/**
 * Step that processes a fixed number of items and records every call.
 */
class Recording_Migration_Step implements MigrationStep {

	/**
	 * Calls as "name:cursor".
	 *
	 * @var string[]
	 */
	public static $calls = array();

	/**
	 * Name of the step that must throw once.
	 *
	 * @var string
	 */
	public static $fail_once = '';

	private $name;
	private $items;

	public function __construct( $name, $items ) {
		$this->name  = $name;
		$this->items = $items;
	}

	public function name() {
		return $this->name;
	}

	public function run_batch( $cursor, $limit ) {
		if ( self::$fail_once === $this->name ) {
			self::$fail_once = '';
			throw new RuntimeException( 'boom' );
		}

		self::$calls[] = $this->name . ':' . $cursor;
		$next          = min( $this->items, $cursor + $limit );

		return $next >= $this->items ? BatchResult::finished( array( 'items' => $next - $cursor ) ) : BatchResult::more( $next, array( 'items' => $next - $cursor ) );
	}
}

/**
 * @covers \TrustOptimize\Migration\MigrationRunner
 * @covers \TrustOptimize\Migration\BatchResult
 */
class MigrationRunnerTest extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		delete_option( MigrationRunner::OPTION );
		as_unschedule_all_actions( MigrationRunner::HOOK_MIGRATE );
		Recording_Migration_Step::$calls     = array();
		Recording_Migration_Step::$fail_once = '';
	}

	private function runner( array $steps ) {
		return new MigrationRunner( new DatabaseManager(), $steps );
	}

	private function pending() {
		return as_get_scheduled_actions(
			array(
				'hook'   => MigrationRunner::HOOK_MIGRATE,
				'status' => ActionScheduler_Store::STATUS_PENDING,
			),
			'ids'
		);
	}

	public function test_runner_without_steps_finishes_in_one_batch() {
		$runner = $this->runner( array() );
		$runner->start( '1.3.0', '2.0.0' );

		$this->assertTrue( $runner->is_running() );
		$this->assertCount( 1, $this->pending() );

		$state = $runner->run_batch();

		$this->assertFalse( $runner->is_running() );
		$this->assertNotEmpty( $state['finished_at'] );
		$this->assertSame( '1.3.0', $state['from'] );
	}

	public function test_steps_run_in_order_and_counts_are_summed() {
		$runner = $this->runner( array( new Recording_Migration_Step( 'a', 5 ), new Recording_Migration_Step( 'b', 2 ) ) );
		$runner->start( '1.3.0', '2.0.0' );

		while ( $runner->is_running() ) {
			$state = $runner->run_batch( 2 );
		}

		$this->assertSame( array( 'a:0', 'a:2', 'a:4', 'b:0' ), Recording_Migration_Step::$calls );
		$this->assertSame( 7, $state['counts']['items'] );
		$this->assertSame( array( 2, 2 ), $runner->get_progress() );
	}

	public function test_progress_is_resumed_from_the_stored_cursor_by_a_new_runner() {
		$steps = array( new Recording_Migration_Step( 'a', 5 ), new Recording_Migration_Step( 'b', 1 ) );
		$this->runner( $steps )->start( '1.3.0', '2.0.0' );

		$first = $this->runner( $steps );
		$first->run_batch( 2 );
		$first->run_batch( 2 );

		// The process dies here; a fresh runner continues from the persisted state.
		$resumed = $this->runner( $steps );
		$this->assertSame( 4, $resumed->get_state()['cursor'] );

		while ( $resumed->is_running() ) {
			$resumed->run_batch( 2 );
		}

		$this->assertSame( array( 'a:0', 'a:2', 'a:4', 'b:0' ), Recording_Migration_Step::$calls );
	}

	public function test_failed_batch_is_recorded_and_retried_from_the_same_cursor() {
		$runner = $this->runner( array( new Recording_Migration_Step( 'a', 4 ) ) );
		$runner->start( '1.3.0', '2.0.0' );
		$runner->run_batch( 2 );

		Recording_Migration_Step::$fail_once = 'a';
		$state                               = $runner->run_batch( 2 );

		$this->assertSame( 2, $state['cursor'] );
		$this->assertSame( 'boom', $state['errors'][0]['message'] );
		$this->assertTrue( $runner->is_running() );

		$state = $runner->run_batch( 2 );

		$this->assertFalse( $runner->is_running() );
		$this->assertSame( array( 'a:0', 'a:2' ), Recording_Migration_Step::$calls );
	}

	public function test_errors_are_capped() {
		$runner = $this->runner( array( new Recording_Migration_Step( 'a', 4 ) ) );
		$runner->start( '1.3.0', '2.0.0' );

		for ( $i = 0; $i < MigrationRunner::MAX_ERRORS + 5; $i++ ) {
			Recording_Migration_Step::$fail_once = 'a';
			$state                               = $runner->run_batch();
		}

		$this->assertCount( MigrationRunner::MAX_ERRORS, $state['errors'] );
	}

	public function test_scheduled_action_chains_the_next_batch() {
		$runner = $this->runner( array( new Recording_Migration_Step( 'a', 400 ) ) );
		$runner->start( '1.3.0', '2.0.0' );
		$runner->start( '1.3.0', '2.0.0' );

		$this->assertCount( 1, $this->pending(), 'A running migration is not restarted or queued twice.' );

		as_unschedule_all_actions( MigrationRunner::HOOK_MIGRATE );
		$runner->run_scheduled();

		$this->assertCount( 1, $this->pending() );
	}

	public function test_schema_upgrade_starts_the_migration_only_when_the_legacy_table_exists() {
		global $wpdb;

		$database = new DatabaseManager();
		$legacy   = $database->get_plugin_table_names()['images'];
		$runner   = $this->runner( array() );

		$runner->maybe_start( '0.0.0', '2.0.0' );
		$this->assertNull( $runner->get_state(), 'A fresh install has nothing to migrate.' );

		// A real table is needed: table_exists() does not see the temporary tables the test case creates.
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "CREATE TABLE IF NOT EXISTS `{$legacy}` (id bigint(20) unsigned NOT NULL AUTO_INCREMENT, attachment_id bigint(20) unsigned NOT NULL, metadata longtext NOT NULL, status varchar(20) NOT NULL DEFAULT 'completed', PRIMARY KEY  (id), UNIQUE KEY attachment_id (attachment_id))" );
		$runner->maybe_start( '1.3.0', '2.0.0' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "DROP TABLE IF EXISTS `{$legacy}`" );
		add_filter( 'query', array( $this, '_create_temporary_tables' ) );
		add_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		$this->assertTrue( $runner->is_running() );
		$this->assertSame( '1.3.0', $runner->get_state()['from'] );
	}
}
