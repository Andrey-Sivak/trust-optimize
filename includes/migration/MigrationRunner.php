<?php
/**
 * Resumable runner of the 1.x to 2.0 data migration.
 *
 * @package TrustOptimize\Migration
 */

namespace TrustOptimize\Migration;

use TrustOptimize\Database\DatabaseManager;

/**
 * Class MigrationRunner
 *
 * Runs the ordered steps batch by batch. The state lives in one option and is
 * written after every batch, so a crash or a timeout resumes from the last cursor.
 */
class MigrationRunner {

	/**
	 * Option that holds the migration state.
	 */
	const OPTION = 'trust_optimize_migration';

	/**
	 * Action Scheduler hook that runs one batch.
	 */
	const HOOK_MIGRATE = 'trust_optimize_migrate';

	/**
	 * Action Scheduler group name.
	 */
	const GROUP = 'trust-optimize';

	/**
	 * Fired by the database manager after the schema was upgraded: ( $from, $to ).
	 */
	const ACTION_SCHEMA_UPGRADED = 'trust_optimize_schema_upgraded';

	/**
	 * Items per batch.
	 */
	const BATCH_SIZE = 50;

	/**
	 * Maximum number of stored errors.
	 */
	const MAX_ERRORS = 20;

	/**
	 * Delay before a failed batch is retried, in seconds.
	 */
	const RETRY_DELAY = HOUR_IN_SECONDS;

	/**
	 * Ordered steps.
	 *
	 * @var MigrationStep[]
	 */
	private $steps;

	/**
	 * Database manager.
	 *
	 * @var DatabaseManager
	 */
	private $database;

	/**
	 * Constructor.
	 *
	 * @param DatabaseManager $database Database manager.
	 * @param MigrationStep[] $steps    Ordered steps.
	 */
	public function __construct( DatabaseManager $database, array $steps ) {
		$this->database = $database;
		$this->steps    = array_values( $steps );
	}

	/**
	 * Register the hooks.
	 */
	public function register() {
		add_action( self::ACTION_SCHEMA_UPGRADED, array( $this, 'maybe_start' ), 10, 2 );
		add_action( self::HOOK_MIGRATE, array( $this, 'run_scheduled' ) );
		add_action( 'admin_notices', array( $this, 'render_notice' ) );
	}

	/**
	 * Start the migration when a site with the 1.x registry table was upgraded.
	 *
	 * @param string $from Previous schema version.
	 * @param string $to   New schema version.
	 */
	public function maybe_start( $from, $to ) {
		if ( ! $this->database->table_exists( $this->database->get_plugin_table_names()['images'] ) ) {
			return;
		}

		$this->start( $from, $to );
	}

	/**
	 * Create the state and schedule the first batch. A migration in progress is left untouched.
	 *
	 * @param string $from Previous schema version.
	 * @param string $to   New schema version.
	 */
	public function start( $from, $to ) {
		$state = $this->get_state();

		if ( $state && empty( $state['finished_at'] ) ) {
			return;
		}

		$this->save_state(
			array(
				'from'        => (string) $from,
				'to'          => (string) $to,
				'step'        => $this->steps ? $this->steps[0]->name() : '',
				'cursor'      => 0,
				'counts'      => array(),
				'started_at'  => current_time( 'mysql', true ),
				'finished_at' => null,
				'errors'      => array(),
			)
		);

		$this->schedule();
	}

	/**
	 * Whether a migration was started and has not finished yet.
	 *
	 * @return bool
	 */
	public function is_running() {
		$state = $this->get_state();

		return $state && empty( $state['finished_at'] );
	}

	/**
	 * Get the stored state.
	 *
	 * @return array|null Null when no migration was ever started.
	 */
	public function get_state() {
		$state = get_option( self::OPTION );

		return is_array( $state ) ? $state : null;
	}

	/**
	 * Action Scheduler callback: run a batch and queue the next one.
	 */
	public function run_scheduled() {
		$this->run_batch();

		if ( $this->is_running() ) {
			$this->schedule();
		}
	}

	/**
	 * Run one batch of the current step and persist the new state.
	 *
	 * A failing batch is recorded and retried later from the same cursor.
	 *
	 * @param int $limit Items per batch.
	 * @return array|null The new state, or null when no migration is running.
	 */
	public function run_batch( $limit = self::BATCH_SIZE ) {
		$state = $this->get_state();

		if ( ! $state || ! empty( $state['finished_at'] ) ) {
			return null;
		}

		$index = $this->step_index( $state['step'] );

		if ( null !== $index ) {
			try {
				$result = $this->steps[ $index ]->run_batch( (int) $state['cursor'], max( 1, (int) $limit ) );
			} catch ( \Throwable $e ) {
				$state['errors'] = $this->add_error( $state['errors'], $state['step'], $e->getMessage() );
				$this->save_state( $state );
				$this->schedule( self::RETRY_DELAY );

				return $state;
			}

			foreach ( $result->get_counts() as $key => $value ) {
				$state['counts'][ $key ] = ( $state['counts'][ $key ] ?? 0 ) + (int) $value;
			}

			if ( ! $result->is_done() ) {
				$state['cursor'] = $result->get_cursor();
				$this->save_state( $state );

				return $state;
			}
		}

		$next = null === $index ? 0 : $index + 1;

		if ( isset( $this->steps[ $next ] ) ) {
			$state['step']   = $this->steps[ $next ]->name();
			$state['cursor'] = 0;
		} else {
			$state['finished_at'] = current_time( 'mysql', true );
		}

		$this->save_state( $state );

		return $state;
	}

	/**
	 * Position of the current step, 1-based, and the number of steps.
	 *
	 * @return array{0:int,1:int}
	 */
	public function get_progress() {
		$state = $this->get_state();
		$index = $state ? $this->step_index( $state['step'] ) : null;

		return array( null === $index ? 0 : $index + 1, count( $this->steps ) );
	}

	/**
	 * Cancel the scheduled batch (used when a batch is run synchronously).
	 */
	public function unschedule() {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK_MIGRATE, array(), self::GROUP );
		}
	}

	/**
	 * Show the migration progress to administrators.
	 */
	public function render_notice() {
		if ( ! current_user_can( 'manage_options' ) || ! $this->is_running() ) {
			return;
		}

		list( $position, $total ) = $this->get_progress();

		printf(
			'<div class="notice notice-info"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: 1: current migration step, 2: number of steps. */
					__( 'TrustOptimize is migrating data from the previous version in the background (step %1$d of %2$d). Optimized images keep being served meanwhile.', 'trust-optimize' ),
					$position,
					$total
				)
			)
		);
	}

	/**
	 * Index of a step by name.
	 *
	 * @param string $name Step name.
	 * @return int|null Null for an unknown name.
	 */
	private function step_index( $name ) {
		foreach ( $this->steps as $index => $step ) {
			if ( $step->name() === $name ) {
				return $index;
			}
		}

		return null;
	}

	/**
	 * Append an error, keeping the latest ones.
	 *
	 * @param array  $errors Stored errors.
	 * @param string $step   Step name.
	 * @param string $error  Error message.
	 * @return array
	 */
	private function add_error( array $errors, $step, $error ) {
		$errors[] = array(
			'step'    => $step,
			'message' => $error,
			'time'    => current_time( 'mysql', true ),
		);

		return array_slice( $errors, -self::MAX_ERRORS );
	}

	/**
	 * Persist the state.
	 *
	 * @param array $state State.
	 */
	private function save_state( array $state ) {
		update_option( self::OPTION, $state, false );
	}

	/**
	 * Queue the next batch unless one is already queued.
	 *
	 * @param int $delay Delay in seconds.
	 */
	private function schedule( $delay = 0 ) {
		if ( ! function_exists( 'as_enqueue_async_action' ) || as_has_scheduled_action( self::HOOK_MIGRATE, array(), self::GROUP ) ) {
			return;
		}

		if ( $delay > 0 ) {
			as_schedule_single_action( time() + $delay, self::HOOK_MIGRATE, array(), self::GROUP );
		} else {
			as_enqueue_async_action( self::HOOK_MIGRATE, array(), self::GROUP );
		}
	}
}
