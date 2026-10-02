<?php
/**
 * Aggregate state derivation tests.
 *
 * @package TrustOptimize\Tests\Unit
 */

namespace TrustOptimize\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TrustOptimize\Domain\AttachmentState;
use TrustOptimize\Domain\VariantStatus;
use TrustOptimize\Storage\AttachmentRepository;

/**
 * Class AttachmentStateDerivationTest
 */
class AttachmentStateDerivationTest extends TestCase {

	public function provider() {
		return array(
			'no variants'           => array( array(), AttachmentState::NONE ),
			'pending'               => array( array( VariantStatus::PENDING => 2 ), AttachmentState::QUEUED ),
			'processing wins'       => array( array( VariantStatus::PROCESSING => 1, VariantStatus::PENDING => 1 ), AttachmentState::PROCESSING ),
			'all done'              => array( array( VariantStatus::DONE => 3 ), AttachmentState::OPTIMIZED ),
			'done and skipped'      => array( array( VariantStatus::DONE => 1, VariantStatus::SKIPPED => 2 ), AttachmentState::OPTIMIZED ),
			'only skipped'          => array( array( VariantStatus::SKIPPED => 2 ), AttachmentState::OPTIMIZED ),
			'done and failed'       => array( array( VariantStatus::DONE => 1, VariantStatus::FAILED => 1 ), AttachmentState::PARTIAL ),
			'failed only'           => array( array( VariantStatus::FAILED => 2 ), AttachmentState::FAILED ),
			'failed and skipped'    => array( array( VariantStatus::FAILED => 1, VariantStatus::SKIPPED => 1 ), AttachmentState::FAILED ),
			'pending beats failed'  => array( array( VariantStatus::PENDING => 1, VariantStatus::FAILED => 1 ), AttachmentState::QUEUED ),
		);
	}

	/**
	 * @dataProvider provider
	 *
	 * @param array  $counts   Counts.
	 * @param string $expected Expected state.
	 */
	public function test_derive_state( array $counts, $expected ) {
		$this->assertSame( $expected, AttachmentRepository::derive_state( $counts ) );
	}
}
