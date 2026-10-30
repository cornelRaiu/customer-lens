<?php
/**
 * Plugin Name:          Customer Lens – Customer History & Risk Signals for WooCommerce
 * Plugin URI:           https://customerlens.app/
 * Description:          Shows each customer's previous orders and risk signals on the WooCommerce order screen: refunds, cancellations, failed payments and refused deliveries, matched by email, phone and address.
 * Version:              1.0.0
 * Requires at least:    6.4
 * Requires PHP:         8.1
 * Requires Plugins:     woocommerce
 * Author:               Cornel Raiu
 * Author URI:           https://www.cornelraiu.com/
 * License:              GPLv3 or later
 * License URI:          https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:          customer-lens
 * Domain Path:          /languages
 * WC requires at least: 8.2
 * WC tested up to:      11.2
 *
 * @package CustomerLens
 */

namespace CustomerLens;

defined( 'ABSPATH' ) || exit;

// Keep this file free of newer PHP syntax so the notices below can still show on old PHP versions.

/**
 * Explains on the Plugins screen, to the people who can fix it, why the plugin is not running.
 *
 * @param string $problem Either "requirements" or "vendor".
 */
function show_problem( $problem ) {
	add_action(
		'admin_notices',
		static function () use ( $problem ) {
			global $pagenow;

			if ( 'plugins.php' !== $pagenow || ! current_user_can( 'activate_plugins' ) ) {
				return;
			}

			$message = 'requirements' === $problem
				? __( 'Customer Lens needs PHP 8.1 or later and WordPress 6.4 or later, so it is not running. Update PHP or WordPress to use it.', 'customer-lens' )
				: __( 'Customer Lens is missing its vendor folders, so it is not running. Run composer install, or reinstall the plugin from WordPress.org.', 'customer-lens' );

			printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( $message ) );
		}
	);
}

if ( version_compare( PHP_VERSION, '8.1', '<' ) || version_compare( get_bloginfo( 'version' ), '6.4', '<' ) ) {
	show_problem( 'requirements' );
	return;
}

if ( ! is_readable( __DIR__ . '/vendor/autoload.php' ) || ! is_readable( __DIR__ . '/vendor-prefixed/autoload.php' ) ) {
	show_problem( 'vendor' );
	return;
}

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/vendor-prefixed/autoload.php';

register_activation_hook( __FILE__, [ Install\Schema::class, 'install' ] );

Plugin::boot( __FILE__ );
