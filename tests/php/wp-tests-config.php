<?php
/**
 * WordPress test suite configuration.
 *
 * Settings come from environment variables. To set them for one machine, create
 * tests/php/wp-tests-config.local.php (not committed) and call putenv() there.
 *
 * The test suite empties this database on every run. Never point it at a real site.
 *
 * @package CustomerLens
 */

if ( file_exists( __DIR__ . '/wp-tests-config.local.php' ) ) {
	require __DIR__ . '/wp-tests-config.local.php';
}

define( 'ABSPATH', getenv( 'WP_TESTS_ABSPATH' ) ? getenv( 'WP_TESTS_ABSPATH' ) : dirname( __DIR__, 2 ) . '/vendor/roots/wordpress-no-content/' );

define( 'DB_NAME', getenv( 'WP_TESTS_DB_NAME' ) ? getenv( 'WP_TESTS_DB_NAME' ) : 'mwtcl_tests' );
define( 'DB_USER', getenv( 'WP_TESTS_DB_USER' ) ? getenv( 'WP_TESTS_DB_USER' ) : 'root' );
define( 'DB_PASSWORD', getenv( 'WP_TESTS_DB_PASSWORD' ) ? getenv( 'WP_TESTS_DB_PASSWORD' ) : '' );
define( 'DB_HOST', getenv( 'WP_TESTS_DB_HOST' ) ? getenv( 'WP_TESTS_DB_HOST' ) : '127.0.0.1' );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );

$table_prefix = 'wptests_';

define( 'WP_DEBUG', true );
define( 'WP_DEFAULT_THEME', 'default' );
define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'Test Site' );
define( 'WP_PHP_BINARY', 'php' );
define( 'WPLANG', '' );
