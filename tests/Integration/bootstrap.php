<?php
/**
 * PHPUnit bootstrap for WordPress integration tests.
 *
 * @package TrustOptimize\Tests
 */

$trust_optimize_root = dirname( __DIR__, 2 );

require_once $trust_optimize_root . '/vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php';

define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $trust_optimize_root . '/vendor/yoast/phpunit-polyfills' );
define( 'WP_TESTS_CONFIG_FILE_PATH', __DIR__ . '/wp-tests-config.php' );

// WP_TESTS_LIB_DIR lets tools/bin/test pin a wp-phpunit release matching the core under test.
$trust_optimize_tests_dir = getenv( 'WP_TESTS_LIB_DIR' ) ? getenv( 'WP_TESTS_LIB_DIR' ) : $trust_optimize_root . '/vendor/wp-phpunit/wp-phpunit';

require_once $trust_optimize_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () use ( $trust_optimize_root ) {
		require_once $trust_optimize_root . '/vendor/woocommerce/action-scheduler/action-scheduler.php';
		require_once $trust_optimize_root . '/trust-optimize.php';
	}
);

require_once $trust_optimize_tests_dir . '/includes/bootstrap.php';
