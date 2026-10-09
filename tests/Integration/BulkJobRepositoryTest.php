<?php
/**
 * The compare-and-set update of bulk jobs only touches known columns.
 *
 * @package TrustOptimize\Tests
 */

use TrustOptimize\Bulk\BulkJobRepository;
use TrustOptimize\Database\DatabaseManager;
use TrustOptimize\Domain\JobStatus;

/**
 * @covers \TrustOptimize\Bulk\BulkJobRepository::transition
 */
class BulkJobRepositoryTest extends WP_UnitTestCase {

	/**
	 * Jobs.
	 *
	 * @var BulkJobRepository
	 */
	private $jobs;

	public function set_up() {
		parent::set_up();
		$this->jobs = new BulkJobRepository( new DatabaseManager() );
	}

	private function row( $job_id ) {
		global $wpdb;

		return $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $wpdb->prefix . 'trust_optimize_jobs', $job_id ),
			ARRAY_A
		);
	}

	private function transition( $job_id, array $from, array $data ) {
		$method = new ReflectionMethod( BulkJobRepository::class, 'transition' );
		$method->setAccessible( true );

		return $method->invoke( $this->jobs, $job_id, $from, $data );
	}

	public function test_a_column_that_is_not_updatable_is_ignored() {
		$job = $this->jobs->create( 'library' );

		$this->assertTrue(
			$this->transition(
				$job->get_id(),
				array( JobStatus::PENDING ),
				array(
					'status'                         => JobStatus::PAUSED,
					'type'                           => 'hacked',
					'id = 0, status'                 => 'x',
					'last_error` = (SELECT 1), `type' => 'x',
				)
			)
		);

		$row = $this->row( $job->get_id() );
		$this->assertSame( JobStatus::PAUSED, $row['status'] );
		$this->assertSame( 'library', $row['type'] );
	}

	public function test_null_is_stored_as_null() {
		$job = $this->jobs->create( 'library' );
		$id  = $job->get_id();

		$this->transition( $id, array( JobStatus::PENDING ), array( 'last_error' => 'boom', 'finished_at' => '2026-05-01 10:00:00' ) );
		$row = $this->row( $id );
		$this->assertSame( 'boom', $row['last_error'] );
		$this->assertSame( '2026-05-01 10:00:00', $row['finished_at'] );

		$this->assertTrue( $this->transition( $id, array( JobStatus::PENDING ), array( 'last_error' => null, 'finished_at' => null ) ) );
		$row = $this->row( $id );
		$this->assertNull( $row['last_error'] );
		$this->assertNull( $row['finished_at'] );
	}

	public function test_a_job_in_another_status_is_left_alone() {
		$job = $this->jobs->create( 'library' );
		$id  = $job->get_id();

		$this->assertFalse( $this->transition( $id, array( JobStatus::RUNNING ), array( 'status' => JobStatus::PAUSED ) ) );
		$this->assertSame( JobStatus::PENDING, $this->row( $id )['status'] );
	}
}
