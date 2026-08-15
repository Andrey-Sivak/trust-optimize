<?php
/**
 * Operation result tests.
 *
 * @package TrustOptimize\Tests\Unit
 */

namespace TrustOptimize\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TrustOptimize\Value\CapabilityCheck;
use TrustOptimize\Value\DeleteResult;
use TrustOptimize\Value\OptimizeResult;

/**
 * Class OperationResultTest
 */
class OperationResultTest extends TestCase {

	/**
	 * OptimizeResult should represent partial failures distinctly.
	 */
	public function test_optimize_result_partial_is_not_success() {
		$result = OptimizeResult::partial( 'completed_with_errors', array( 'failed_variant' ), array( 'completed' => 1 ) );

		$this->assertTrue( $result->is_partial() );
		$this->assertFalse( $result->is_success() );
		$this->assertSame( 'partial', $result->get_status() );
	}

	/**
	 * DeleteResult should expose skipped status.
	 */
	public function test_delete_result_skipped() {
		$result = DeleteResult::skipped( 'no_generated_variants' );

		$this->assertTrue( $result->is_skipped() );
		$this->assertSame( 'no_generated_variants', $result->get_message() );
	}

	/**
	 * CapabilityCheck should carry structured denial reasons.
	 */
	public function test_capability_check_denied_reasons() {
		$result = CapabilityCheck::denied( array( CapabilityCheck::REASON_MISSING_FILE ) );

		$this->assertFalse( $result->is_allowed() );
		$this->assertSame( array( CapabilityCheck::REASON_MISSING_FILE ), $result->get_reasons() );
	}
}
