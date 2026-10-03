<?php
/**
 * Variant data of all images of a page is loaded in one query.
 *
 * @package TrustOptimize\Tests
 */

use TrustOptimize\Capabilities\CapabilityService;
use TrustOptimize\Database\DatabaseManager;
use TrustOptimize\Queue\ConversionQueue;

/**
 * @covers \TrustOptimize\Frontend\ContentPrimer
 * @covers \TrustOptimize\Storage\VariantRepository::prime
 */
class ContentPrimerTest extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		update_option( CapabilityService::OPTION, array( 'webp' => true, 'avif' => true ) );
		update_option( 'trust_optimize_options', array( 'convert_to_webp' => 1, 'convert_to_avif' => 0, 'enable_adaptive_images' => 1 ) );
		as_unschedule_all_actions( ConversionQueue::HOOK_PROCESS );
		set_current_screen( 'front' );
	}

	public function test_a_page_with_twenty_images_reads_the_variants_table_at_most_twice() {
		$tables = ( new DatabaseManager() )->get_plugin_table_names();
		$html   = '';
		for ( $i = 0; $i < 20; $i++ ) {
			$id    = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
			$html .= '<img src="' . wp_get_attachment_url( $id ) . '" class="wp-image-' . $id . '" alt="">';
		}
		ActionScheduler_QueueRunner::instance()->run();
		wp_cache_flush();

		$queries = 0;
		add_filter(
			'query',
			function ( $sql ) use ( &$queries, $tables ) {
				if ( false !== strpos( $sql, $tables['variants'] ) ) {
					++$queries;
				}
				return $sql;
			}
		);

		$out = apply_filters( 'the_content', $html );

		$this->assertSame( 20, substr_count( $out, '<picture' ) );
		$this->assertLessThanOrEqual( 2, $queries );
	}
}
