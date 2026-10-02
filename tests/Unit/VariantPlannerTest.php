<?php
/**
 * VariantPlanner pure logic tests.
 *
 * @package TrustOptimize\Tests\Unit
 */

namespace TrustOptimize\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TrustOptimize\Planning\VariantPlanner;
use TrustOptimize\Settings\OptimizationSettings;

/**
 * Class VariantPlannerTest
 */
class VariantPlannerTest extends TestCase {

	private function metadata() {
		return array(
			'file'   => '2026/05/photo.jpg',
			'width'  => 2000,
			'height' => 1000,
			'sizes'  => array(
				'thumbnail' => array(
					'file'      => 'photo-150x150.jpg',
					'width'     => 150,
					'height'    => 150,
					'mime-type' => 'image/jpeg',
				),
				'medium'    => array(
					'file'      => 'photo-300x150.jpg',
					'width'     => 300,
					'height'    => 150,
					'mime-type' => 'image/jpeg',
				),
			),
		);
	}

	private function keys( array $variants ) {
		return array_map(
			static function ( $v ) {
				return $v['size_name'] . '|' . $v['format'] . '|' . $v['source_relative_path'];
			},
			$variants
		);
	}

	public function test_plans_original_and_every_size_for_each_format() {
		$desired = VariantPlanner::desired_variants( '2026/05/photo.jpg', $this->metadata(), array( 'webp', 'avif' ) );

		$this->assertSame(
			array(
				'original|webp|2026/05/photo.jpg',
				'original|avif|2026/05/photo.jpg',
				'thumbnail|webp|2026/05/photo-150x150.jpg',
				'thumbnail|avif|2026/05/photo-150x150.jpg',
				'medium|webp|2026/05/photo-300x150.jpg',
				'medium|avif|2026/05/photo-300x150.jpg',
			),
			$this->keys( $desired )
		);
		$this->assertSame( 2000, $desired[0]['width'] );
	}

	public function test_scaled_original_is_the_source_of_the_original_variant() {
		$metadata         = $this->metadata();
		$metadata['file'] = '2026/05/photo-scaled.jpg';

		$desired = VariantPlanner::desired_variants( '2026/05/photo-scaled.jpg', $metadata, array( 'webp' ) );

		$this->assertSame( 'original|webp|2026/05/photo-scaled.jpg', $this->keys( $desired )[0] );
	}

	public function test_a_file_shared_by_two_sizes_is_planned_once() {
		$metadata                    = $this->metadata();
		$metadata['sizes']['large']  = $metadata['sizes']['medium'];
		$metadata['sizes']['custom'] = array(
			'file'      => 'photo.jpg',
			'width'     => 2000,
			'height'    => 1000,
			'mime-type' => 'image/jpeg',
		);

		$desired = VariantPlanner::desired_variants( '2026/05/photo.jpg', $metadata, array( 'webp' ) );

		$this->assertCount( 3, $desired );
		$this->assertSame( 'original', $desired[0]['size_name'] );
		$this->assertSame( 'medium', $desired[2]['size_name'] );
	}

	public function test_webp_and_avif_sizes_are_not_planned() {
		$metadata                              = $this->metadata();
		$metadata['sizes']['medium']['mime-type'] = 'image/webp';

		$desired = VariantPlanner::desired_variants( '2026/05/photo.jpg', $metadata, array( 'webp' ) );

		$this->assertSame( array( 'original', 'thumbnail' ), array_column( $desired, 'size_name' ) );
	}

	public function test_no_formats_means_no_variants() {
		$this->assertSame( array(), VariantPlanner::desired_variants( '2026/05/photo.jpg', $this->metadata(), array() ) );
	}

	private function row( array $overrides = array() ) {
		return array_merge(
			array(
				'size_name'            => 'original',
				'format'               => 'webp',
				'status'               => 'done',
				'quality'              => 80,
				'source_relative_path' => '2026/05/photo.jpg',
			),
			$overrides
		);
	}

	private function want( array $overrides = array() ) {
		return array_merge(
			array(
				'size_name'            => 'original',
				'format'               => 'webp',
				'source_relative_path' => '2026/05/photo.jpg',
				'width'                => 1,
				'height'               => 1,
			),
			$overrides
		);
	}

	private function settings() {
		return new OptimizationSettings( array( 'webp' ), array( 'webp' ), array( 'webp' => 80 ) );
	}

	public function test_reconcile_inserts_missing_variants() {
		$result = VariantPlanner::reconcile( array(), array( $this->want() ), $this->settings() );

		$this->assertCount( 1, $result['insert'] );
		$this->assertSame( array(), $result['reset'] );
		$this->assertSame( array(), $result['delete'] );
	}

	public function test_reconcile_keeps_current_done_rows() {
		$result = VariantPlanner::reconcile( array( $this->row() ), array( $this->want() ), $this->settings() );

		$this->assertSame( array( 'insert' => array(), 'reset' => array(), 'delete' => array(), 'replaced' => array() ), $result );
	}

	public function test_reconcile_resets_stale_and_failed_rows() {
		$stale  = VariantPlanner::reconcile( array( $this->row( array( 'quality' => 60 ) ) ), array( $this->want() ), $this->settings() );
		$failed = VariantPlanner::reconcile( array( $this->row( array( 'status' => 'failed' ) ) ), array( $this->want() ), $this->settings() );
		$busy   = VariantPlanner::reconcile( array( $this->row( array( 'status' => 'processing', 'quality' => 60 ) ) ), array( $this->want() ), $this->settings() );

		$this->assertCount( 1, $stale['reset'] );
		$this->assertCount( 1, $failed['reset'] );
		$this->assertSame( array(), $busy['reset'], 'A row being processed is left alone.' );
	}

	public function test_reconcile_deletes_rows_of_disabled_formats_and_vanished_sizes() {
		$existing = array(
			$this->row(),
			$this->row( array( 'format' => 'avif' ) ),
			$this->row( array( 'size_name' => 'gone' ) ),
		);

		$result = VariantPlanner::reconcile( $existing, array( $this->want() ), $this->settings() );

		$this->assertSame( array( 'avif', 'webp' ), array( $result['delete'][0]['format'], $result['delete'][1]['format'] ) );
		$this->assertSame( 'gone', $result['delete'][1]['size_name'] );
	}

	public function test_reconcile_replaces_a_row_whose_source_file_changed() {
		$result = VariantPlanner::reconcile(
			array( $this->row() ),
			array( $this->want( array( 'source_relative_path' => '2026/05/photo-scaled.jpg' ) ) ),
			$this->settings()
		);

		$this->assertCount( 1, $result['replaced'] );
		$this->assertSame( '2026/05/photo.jpg', $result['replaced'][0]['source_relative_path'] );
		$this->assertSame( '2026/05/photo-scaled.jpg', $result['reset'][0]['source_relative_path'] );
		$this->assertSame( array(), $result['delete'] );
	}

	public function test_reconcile_leaves_rows_of_an_enabled_but_unsupported_format_alone() {
		$settings = new OptimizationSettings( array( 'webp', 'avif' ), array( 'webp' ), array( 'webp' => 80, 'avif' => 60 ) );
		$existing = array(
			$this->row( array( 'format' => 'avif', 'quality' => 10 ) ),
			$this->row( array( 'format' => 'avif', 'size_name' => 'thumb', 'status' => 'failed' ) ),
		);
		$desired  = array(
			$this->want( array( 'format' => 'avif' ) ),
			$this->want( array( 'format' => 'avif', 'size_name' => 'thumb' ) ),
			$this->want( array( 'format' => 'avif', 'size_name' => 'new' ) ),
		);

		$result = VariantPlanner::reconcile( $existing, $desired, $settings );

		$this->assertSame( array( 'insert' => array(), 'reset' => array(), 'delete' => array(), 'replaced' => array() ), $result );
	}

	public function test_reconcile_still_deletes_vanished_sizes_of_an_unsupported_format() {
		$settings = new OptimizationSettings( array( 'webp', 'avif' ), array( 'webp' ), array() );
		$existing = array( $this->row( array( 'format' => 'avif', 'size_name' => 'gone' ) ) );

		$result = VariantPlanner::reconcile( $existing, array( $this->want() ), $settings );

		$this->assertCount( 1, $result['delete'] );
		$this->assertSame( 'gone', $result['delete'][0]['size_name'] );
	}
}
