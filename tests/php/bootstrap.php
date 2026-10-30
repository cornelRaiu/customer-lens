<?php

use Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController;
use Automattic\WooCommerce\Internal\DataStores\Orders\DataSynchronizer;

$mwtcl_root = dirname( __DIR__, 2 );

require_once $mwtcl_root . '/vendor/autoload.php';

// Hosted setups such as wp-env provide their own test library. Otherwise use the wp-phpunit package.
$mwtcl_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $mwtcl_tests_dir ) {
	$mwtcl_tests_dir = getenv( 'WP_PHPUNIT__DIR' );
	putenv( 'WP_PHPUNIT__TESTS_CONFIG=' . __DIR__ . '/wp-tests-config.php' );
}

// bin/install-woocommerce.sh puts WooCommerce here. WC_DIR points somewhere else, such as a local site.
$mwtcl_wc_dir = getenv( 'WC_DIR' ) ? getenv( 'WC_DIR' ) : __DIR__ . '/plugins/woocommerce';
if ( ! is_readable( $mwtcl_wc_dir . '/woocommerce.php' ) ) {
	fwrite( STDERR, "WooCommerce not found in {$mwtcl_wc_dir}. Run bin/install-woocommerce.sh first." . PHP_EOL );
	exit( 1 );
}

define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $mwtcl_root . '/vendor/yoast/phpunit-polyfills' );

require_once $mwtcl_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () use ( $mwtcl_root, $mwtcl_wc_dir ) {
		require $mwtcl_wc_dir . '/woocommerce.php';
		require $mwtcl_root . '/customer-lens.php';
	}
);

// MWTCL_TESTS_HPOS=0 runs the suite on legacy post storage; anything else on HPOS without sync.
// Installed once Action Scheduler and the order post types are ready, since the installer uses both.
tests_add_filter(
	'woocommerce_after_register_post_type',
	static function () {
		WC_Install::install();

		$hpos = '0' !== getenv( 'MWTCL_TESTS_HPOS' );
		wc_get_container()->get( DataSynchronizer::class )->create_database_tables();
		update_option( CustomOrdersTableController::CUSTOM_ORDERS_TABLE_USAGE_ENABLED_OPTION, wc_bool_to_string( $hpos ) );
		update_option( DataSynchronizer::ORDERS_DATA_SYNC_ENABLED_OPTION, 'no' );

		// Roles gained WooCommerce capabilities during install; reload them.
		$GLOBALS['wp_roles'] = null;
		wp_roles();
	}
);

require $mwtcl_tests_dir . '/includes/bootstrap.php';
