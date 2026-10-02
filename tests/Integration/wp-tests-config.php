<?php
/**
 * WordPress test suite configuration, read from the environment.
 *
 * @package TrustOptimize\Tests
 */

$trust_optimize_core_dir = rtrim( (string) getenv( 'WP_CORE_DIR' ), '/' );

if ( '' === $trust_optimize_core_dir ) {
	echo 'WP_CORE_DIR is not set. Run the suite via tools/bin/test.' . PHP_EOL;
	exit( 1 );
}

define( 'ABSPATH', $trust_optimize_core_dir . '/' );
define( 'WP_DEFAULT_THEME', 'default' );
define( 'WP_DEBUG', true );

define( 'DB_NAME', getenv( 'WP_TESTS_DB_NAME' ) ? getenv( 'WP_TESTS_DB_NAME' ) : 'wordpress_test' );
define( 'DB_USER', getenv( 'WP_TESTS_DB_USER' ) ? getenv( 'WP_TESTS_DB_USER' ) : 'root' );
define( 'DB_PASSWORD', getenv( 'WP_TESTS_DB_PASSWORD' ) ? getenv( 'WP_TESTS_DB_PASSWORD' ) : '' );
define( 'DB_HOST', getenv( 'WP_TESTS_DB_HOST' ) ? getenv( 'WP_TESTS_DB_HOST' ) : 'db' );
define( 'DB_CHARSET', 'utf8' );
define( 'DB_COLLATE', '' );

define( 'AUTH_KEY', 'trust-optimize-tests' );
define( 'SECURE_AUTH_KEY', 'trust-optimize-tests' );
define( 'LOGGED_IN_KEY', 'trust-optimize-tests' );
define( 'NONCE_KEY', 'trust-optimize-tests' );
define( 'AUTH_SALT', 'trust-optimize-tests' );
define( 'SECURE_AUTH_SALT', 'trust-optimize-tests' );
define( 'LOGGED_IN_SALT', 'trust-optimize-tests' );
define( 'NONCE_SALT', 'trust-optimize-tests' );

$table_prefix = 'wptests_';

define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'TrustOptimize Tests' );
define( 'WP_PHP_BINARY', 'php' );
define( 'WPLANG', '' );
