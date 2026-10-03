<?php
/**
 * Status constants vs. column length tests.
 *
 * @package TrustOptimize\Tests\Unit
 */

namespace TrustOptimize\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TrustOptimize\Domain\AttachmentState;
use TrustOptimize\Domain\JobStatus;
use TrustOptimize\Domain\VariantStatus;

/**
 * Class StatusLengthTest
 *
 * Guards against H-2: a status longer than its VARCHAR column is rejected by wpdb.
 */
class StatusLengthTest extends TestCase {

	public function test_variant_statuses_fit_the_column() {
		foreach ( VariantStatus::all() as $status ) {
			$this->assertLessThanOrEqual( VariantStatus::COLUMN_LENGTH, strlen( $status ), $status );
		}
	}

	public function test_attachment_states_fit_the_column() {
		foreach ( AttachmentState::all() as $state ) {
			$this->assertLessThanOrEqual( AttachmentState::COLUMN_LENGTH, strlen( $state ), $state );
		}
	}

	public function test_job_statuses_fit_the_column() {
		foreach ( JobStatus::all() as $status ) {
			$this->assertLessThanOrEqual( JobStatus::COLUMN_LENGTH, strlen( $status ), $status );
		}
	}

	public function test_columns_are_at_least_32_characters() {
		$this->assertSame( 32, VariantStatus::COLUMN_LENGTH );
		$this->assertSame( 32, AttachmentState::COLUMN_LENGTH );
		$this->assertSame( 32, JobStatus::COLUMN_LENGTH );
	}
}
