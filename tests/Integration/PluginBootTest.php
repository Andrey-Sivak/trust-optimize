<?php
/**
 * Smoke test: the plugin boots inside WordPress.
 *
 * @package TrustOptimize\Tests
 */

use TrustOptimize\Database\DatabaseManager;
use TrustOptimize\Queue\ConversionQueue;

/**
 * @covers \TrustOptimize\Core\Plugin
 */
class PluginBootTest extends WP_UnitTestCase {

	public function test_plugin_classes_are_loaded() {
		$this->assertTrue( class_exists( 'TrustOptimize\\Core\\Plugin' ) );
		$this->assertTrue( function_exists( 'as_enqueue_async_action' ) );
	}

	public function test_database_tables_exist() {
		$database = new DatabaseManager();

		foreach ( $database->get_plugin_table_names() as $table ) {
			$this->assertTrue( $database->table_exists( $table ), "Table {$table} is missing." );
		}
	}

	public function test_conversion_hook_is_registered() {
		$this->assertNotFalse( has_action( ConversionQueue::HOOK_PROCESS ) );
		$this->assertNotFalse( has_action( ConversionQueue::HOOK_CONVERT ), 'The 1.x per-variant hook stays as a shim until 03.6.' );
	}
}
