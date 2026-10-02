<?php
/**
 * Detection of 1.x variants that collide with other attachments' files (03.3, H-1).
 *
 * @package TrustOptimize\Tests
 */

require_once __DIR__ . '/legacy-schema-fixture.php';

use TrustOptimize\Database\DatabaseManager;
use TrustOptimize\Domain\AttachmentState;
use TrustOptimize\Domain\VariantStatus;
use TrustOptimize\Migration\ConflictReport;
use TrustOptimize\Migration\DetectCollisions;
use TrustOptimize\Service\LegacyPathGuard;
use TrustOptimize\Storage\AttachmentRepository;
use TrustOptimize\Storage\VariantRepository;

/**
 * @covers \TrustOptimize\Migration\DetectCollisions
 * @covers \TrustOptimize\Migration\ConflictReport
 */
class DetectCollisionsTest extends WP_UnitTestCase {

	use Legacy_Schema_Fixture;

	/**
	 * Variants.
	 *
	 * @var VariantRepository
	 */
	private $variants;

	/**
	 * Step under test.
	 *
	 * @var DetectCollisions
	 */
	private $step;

	/**
	 * Attachments.
	 *
	 * @var AttachmentRepository
	 */
	private $attachments;

	public function set_up() {
		parent::set_up();
		$this->install_legacy_table();
		delete_option( ConflictReport::OPTION );

		$database          = new DatabaseManager();
		$this->variants    = new VariantRepository( $database );
		$this->attachments = new AttachmentRepository( $database, $this->variants );
		$this->step        = new DetectCollisions( $this->variants, $this->attachments, new LegacyPathGuard( $database, $this->variants ), new ConflictReport() );
	}

	public function tear_down() {
		$this->remove_legacy_schema();
		delete_option( ConflictReport::OPTION );
		parent::tear_down();
	}

	private function legacy_row( $attachment_id, $path, array $fields = array() ) {
		return $this->variants->upsert(
			array_merge(
				array(
					'attachment_id' => $attachment_id,
					'size_name'     => 'original',
					'format'        => 'webp',
					'status'        => VariantStatus::DONE,
					'naming'        => 'legacy',
					'relative_path' => $path,
				),
				$fields
			)
		);
	}

	private function status( $attachment_id ) {
		return $this->variants->get_for_attachment( $attachment_id )[0]['status'];
	}

	public function test_scenario_a_a_png_variant_over_the_original_of_another_attachment() {
		$a = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		$b = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/test-image.jpg' );
		$this->variants->delete_for_attachment( $a );
		$this->variants->delete_for_attachment( $b );

		$original = get_post_meta( $a, '_wp_attached_file', true );
		$this->legacy_row( $b, $original, array( 'format' => 'png' ) );

		$result = $this->step->run_batch( 0, 10 );

		$this->assertTrue( $result->is_done() );
		$this->assertSame( 1, $result->get_counts()['conflicts'] );
		$this->assertFileExists( get_attached_file( $a ) );

		$row = $this->variants->get_for_attachment( $b )[0];
		$this->assertSame( VariantStatus::FAILED, $row['status'] );
		$this->assertSame( 'legacy_conflict', $row['reason'] );
		$this->assertNull( VariantRepository::servable_path( $row ), 'A conflicting file is not served.' );

		$report = array_values( ( new ConflictReport() )->all() );
		$this->assertSame( array( $b, $original, $a, 'attachment_file' ), array( $report[0]['attachment_id'], $report[0]['path'], $report[0]['conflicts_with'], $report[0]['source'] ) );
		$this->assertSame( AttachmentState::FAILED, $this->attachments->get_state( $b ) );
	}

	public function test_a_png_variant_over_a_thumbnail_of_another_attachment() {
		$a = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		$b = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/test-image.jpg' );
		$this->variants->delete_for_attachment( $b );

		$thumbnail = dirname( get_post_meta( $a, '_wp_attached_file', true ) ) . '/' . wp_get_attachment_metadata( $a )['sizes']['thumbnail']['file'];
		$this->legacy_row( $b, $thumbnail, array( 'format' => 'png' ) );

		$this->step->run_batch( 0, 10 );

		$this->assertSame( VariantStatus::FAILED, $this->status( $b ) );
		$this->assertFileExists( wp_upload_dir()['basedir'] . '/' . $thumbnail );
	}

	public function test_scenario_b_two_attachments_claim_the_same_variant_file() {
		$path = 'legacy-test/photo.webp';
		$this->make_legacy_file( $path );
		$this->legacy_row( 901, $path );
		$this->legacy_row( 902, $path );
		$this->legacy_row( 903, 'legacy-test/other.webp' );
		$this->make_legacy_file( 'legacy-test/other.webp' );

		$result = $this->step->run_batch( 0, 10 );

		$this->assertSame( 2, $result->get_counts()['conflicts'] );
		$this->assertSame( VariantStatus::FAILED, $this->status( 901 ) );
		$this->assertSame( VariantStatus::FAILED, $this->status( 902 ) );
		$this->assertSame( VariantStatus::DONE, $this->status( 903 ), 'A file nobody else claims stays served.' );
		$this->assertFileExists( wp_upload_dir()['basedir'] . '/' . $path );
		$this->assertCount( 2, ( new ConflictReport() )->all() );
	}

	public function test_a_variant_listed_in_the_1x_registry_of_another_attachment_is_a_conflict() {
		$this->make_legacy_file( 'legacy-test/registry.webp' );
		$this->add_legacy_manifest( 905, array( array( 'size_name' => 'original', 'format' => 'webp', 'file' => 'legacy-test/registry.webp' ) ) );
		$this->legacy_row( 904, 'legacy-test/registry.webp' );

		$this->step->run_batch( 0, 10 );

		$this->assertSame( VariantStatus::FAILED, $this->status( 904 ) );
	}

	public function test_batches_continue_from_the_cursor_and_rows_already_marked_are_not_checked_again() {
		$this->legacy_row( 911, 'legacy-test/a.webp' );
		$this->legacy_row( 912, 'legacy-test/b.webp' );

		$first = $this->step->run_batch( 0, 1 );
		$this->assertFalse( $first->is_done() );
		$this->assertSame( 911, $first->get_cursor() );

		$second = $this->step->run_batch( $first->get_cursor(), 1 );
		$this->assertSame( 912, $second->get_cursor() );
		$this->assertTrue( $this->step->run_batch( $second->get_cursor(), 1 )->is_done() );
		$this->assertSame( 0, $this->step->run_batch( 0, 10 )->get_counts()['conflicts'] );
	}

	public function test_the_report_template_lists_the_conflicts() {
		( new ConflictReport() )->add( 7, '2024/05/logo.png', 5, 'attachment_file' );

		$trust_optimize_conflicts = array_values( ( new ConflictReport() )->all() );
		ob_start();
		require TRUST_OPTIMIZE_PLUGIN_DIR . 'templates/admin/migration-conflicts.php';
		$html = ob_get_clean();

		$this->assertStringContainsString( '2024/05/logo.png', $html );
		$this->assertStringContainsString( 'backup', $html );
	}
}
