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

		foreach ( array( 'attachments', 'variants', 'jobs' ) as $key ) {
			$table = $database->get_plugin_table_names()[ $key ];
			$this->assertTrue( $database->table_exists( $table ), "Table {$table} is missing." );
		}
	}

	public function test_conversion_hook_is_registered() {
		$this->assertNotFalse( has_action( ConversionQueue::HOOK_PROCESS ) );
	}

	/**
	 * Whether an object of a class is hooked to a hook.
	 *
	 * @param string $hook  Hook name.
	 * @param string $class Class name.
	 * @return bool
	 */
	private function is_hooked( $hook, $class ) {
		global $wp_filter;

		foreach ( $wp_filter[ $hook ]->callbacks ?? array() as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				if ( is_array( $callback['function'] ) && $callback['function'][0] instanceof $class ) {
					return true;
				}
			}
		}

		return false;
	}

	public function test_every_component_registers_its_own_hooks() {
		$expected = array(
			'the_content'                     => TrustOptimize\Frontend\ContentPrimer::class,
			'wp_content_img_tag'              => TrustOptimize\Frontend\ImageDelivery::class,
			'wp_get_attachment_image'         => TrustOptimize\Frontend\ImageDelivery::class,
			'delete_attachment'               => TrustOptimize\Service\ImageCleanupService::class,
			'rest_api_init'                   => TrustOptimize\API\RestController::class,
			'admin_menu'                      => TrustOptimize\Admin\Admin::class,
			'admin_init'                      => TrustOptimize\Capabilities\CapabilityService::class,
			'wp_generate_attachment_metadata' => ConversionQueue::class,
			'plugins_loaded'                  => DatabaseManager::class,
		);

		foreach ( $expected as $hook => $class ) {
			$this->assertTrue( $this->is_hooked( $hook, $class ), "{$class} is not hooked to {$hook}." );
		}
	}
}
