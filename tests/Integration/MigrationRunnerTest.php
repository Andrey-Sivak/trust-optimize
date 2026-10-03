<?php
/**
 * Resumable migration runner (03.1).
 *
 * @package TrustOptimize\Tests
 */

require_once __DIR__ . '/legacy-schema-fixture.php';

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
 * Step that always asks to be retried in an hour.
 */
class Waiting_Migration_Step implements MigrationStep {

	public function name() {
		return 'waiting';
	}

	public function run_batch( $cursor, $limit ) {
		return BatchResult::retry( $cursor, 3600 );
	}
}

/**
 * @covers \TrustOptimize\Migration\MigrationRunner
 * @covers \TrustOptimize\Migration\BatchResult
 */
class MigrationRunnerTest extends WP_UnitTestCase {

	use Legacy_Schema_Fixture;

	public function tear_down() {
		$this->remove_legacy_schema();
		parent::tear_down();
	}

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

	/**
	 * Regression: the schema check starts the migration on plugins_loaded, before Action Scheduler is ready.
	 */
	public function test_start_before_action_scheduler_is_ready_waits_for_it_and_then_queues_the_batch() {
		$flag = new ReflectionProperty( ActionScheduler::class, 'data_store_initialized' );
		$flag->setAccessible( true );
		$runner = $this->runner( array( new Recording_Migration_Step( 'one', 1 ) ) );

		$flag->setValue( null, false );
		try {
			$runner->start( '1.3.0', '2.0.0' );
		} finally {
			$flag->setValue( null, true );
		}

		$this->assertSame( array(), $this->pending(), 'Nothing can be queued yet, and no _doing_it_wrong().' );
		$this->assertNotFalse( has_action( 'action_scheduler_init', array( $runner, 'schedule' ) ) );
		$this->assertTrue( $runner->is_running() );

		$runner->schedule();

		$this->assertCount( 1, $this->pending() );
		remove_action( 'action_scheduler_init', array( $runner, 'schedule' ) );
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

	public function test_the_queue_runner_chains_the_batches_until_the_migration_has_finished() {
		$runner = $this->runner( array( new Recording_Migration_Step( 'a', 120 ), new Recording_Migration_Step( 'b', 10 ) ) );

		// The runner of the plugin listens to the same action and shares the state: leave only this one.
		remove_all_actions( MigrationRunner::HOOK_MIGRATE );
		add_action( MigrationRunner::HOOK_MIGRATE, array( $runner, 'run_scheduled' ) );
		$runner->start( '1.3.0', '2.0.0' );

		for ( $i = 0; $i < 10 && $runner->is_running(); $i++ ) {
			ActionScheduler_QueueRunner::instance()->run();
		}

		$this->assertFalse( $runner->is_running(), 'Each batch queues the next one while its own action is still running.' );
		$this->assertSame( array( 'a:0', 'a:50', 'a:100', 'b:0' ), Recording_Migration_Step::$calls );
	}

	public function test_a_step_can_ask_to_be_retried_later() {
		$runner = $this->runner( array( new Waiting_Migration_Step() ) );
		$runner->start( '1.3.0', '2.0.0' );
		as_unschedule_all_actions( MigrationRunner::HOOK_MIGRATE );

		$runner->run_scheduled();

		$this->assertTrue( $runner->is_running() );
		$this->assertSame( 3600, $runner->get_delay() );
		$next = as_next_scheduled_action( MigrationRunner::HOOK_MIGRATE );
		$this->assertGreaterThan( time() + 3000, $next, 'The next batch is queued for later, not at once.' );
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
		$runner = $this->runner( array() );

		$runner->maybe_start( '1.3.0', '2.0.0' );
		$this->assertNull( $runner->get_state(), 'Without the 1.x registry table there is nothing to migrate.' );

		$this->install_legacy_table();
		$runner->maybe_start( '1.3.0', '2.0.0' );

		$this->assertTrue( $runner->is_running() );
		$this->assertSame( '1.3.0', $runner->get_state()['from'] );
	}
}
