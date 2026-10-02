<?php
/**
 * AttachmentRepository tests.
 *
 * @package TrustOptimize\Tests
 */

use TrustOptimize\Database\DatabaseManager;
use TrustOptimize\Domain\AttachmentState;
use TrustOptimize\Domain\VariantStatus;
use TrustOptimize\Storage\AttachmentRepository;
use TrustOptimize\Storage\VariantRepository;

/**
 * @covers \TrustOptimize\Storage\AttachmentRepository
 */
class AttachmentRepositoryTest extends WP_UnitTestCase {

	/**
	 * Variants.
	 *
	 * @var VariantRepository
	 */
	private $variants;

	/**
	 * Attachments.
	 *
	 * @var AttachmentRepository
	 */
	private $attachments;

	public function set_up() {
		parent::set_up();
		$database          = new DatabaseManager();
		$this->variants    = new VariantRepository( $database );
		$this->attachments = new AttachmentRepository( $database, $this->variants );
	}

	private function add_variant( $attachment_id, $format, $status ) {
		$this->variants->upsert(
			array(
				'attachment_id' => $attachment_id,
				'size_name'     => 'original',
				'format'        => $format,
				'status'        => $status,
			)
		);
	}

	public function test_state_is_none_without_row() {
		$this->assertSame( AttachmentState::NONE, $this->attachments->get_state( 701 ) );
		$this->assertNull( $this->attachments->get( 701 ) );
	}

	public function test_claim_succeeds_exactly_once() {
		$this->assertTrue( $this->attachments->claim( 702 ) );
		$this->assertFalse( $this->attachments->claim( 702 ) );
		$this->assertSame( AttachmentState::PROCESSING, $this->attachments->get_state( 702 ) );
	}

	public function test_mark_queued_prevents_duplicates() {
		$this->assertTrue( $this->attachments->mark_queued( 703 ) );
		$this->assertFalse( $this->attachments->mark_queued( 703 ) );

		$this->attachments->recompute( 703 );
		$this->attachments->set_state( 703, AttachmentState::OPTIMIZED );
		$this->assertTrue( $this->attachments->mark_queued( 703 ), 'A finished attachment can be queued again.' );
	}

	/**
	 * Regression H-2: "done + failed" must be stored as "partial" (a 21-character literal was rejected by the old column).
	 */
	public function test_recompute_stores_partial_for_done_and_failed() {
		$this->add_variant( 704, 'webp', VariantStatus::DONE );
		$this->add_variant( 704, 'avif', VariantStatus::FAILED );

		$this->assertSame( AttachmentState::PARTIAL, $this->attachments->recompute( 704 ) );
		$this->assertSame( AttachmentState::PARTIAL, $this->attachments->get_state( 704 ) );
	}

	public function test_recompute_covers_each_aggregate() {
		$this->add_variant( 705, 'webp', VariantStatus::DONE );
		$this->add_variant( 705, 'avif', VariantStatus::SKIPPED );
		$this->assertSame( AttachmentState::OPTIMIZED, $this->attachments->recompute( 705 ) );

		$this->add_variant( 706, 'webp', VariantStatus::FAILED );
		$this->assertSame( AttachmentState::FAILED, $this->attachments->recompute( 706 ) );

		$this->add_variant( 707, 'webp', VariantStatus::PENDING );
		$this->assertSame( AttachmentState::QUEUED, $this->attachments->recompute( 707 ) );

		$this->add_variant( 708, 'webp', VariantStatus::PROCESSING );
		$this->assertSame( AttachmentState::PROCESSING, $this->attachments->recompute( 708 ) );
	}

	public function test_recompute_keeps_explicit_skipped_without_variants() {
		$this->attachments->set_state( 709, AttachmentState::SKIPPED, 'unsupported_mime' );

		$this->assertSame( AttachmentState::SKIPPED, $this->attachments->recompute( 709 ) );
		$this->assertSame( 'unsupported_mime', $this->attachments->get( 709 )['reason'] );
	}

	public function test_last_error_is_truncated_to_255_characters() {
		$this->attachments->set_state( 710, AttachmentState::FAILED, 'convert_failed', str_repeat( 'é', 400 ) );

		$this->assertSame( 255, mb_strlen( $this->attachments->get( 710 )['last_error'], 'UTF-8' ) );
	}
}
