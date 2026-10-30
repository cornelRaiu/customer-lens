<?php

namespace CustomerLens;

use Automattic\WooCommerce\Utilities\FeaturesUtil;
use CustomerLens\Install\Schema;
use CustomerLens\Orders\OrderSync;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	public const VERSION = '0.1.0';
	public const MIN_WC  = '8.2';

	private static string $file = '';

	public static function boot( string $file ): void {
		self::$file = $file;

		add_action( 'before_woocommerce_init', [ self::class, 'declare_compatibility' ] );
		// WooCommerce loads after this plugin (alphabetical order), so everything else waits for it.
		add_action( 'plugins_loaded', [ self::class, 'init' ] );
	}

	public static function init(): void {
		if ( ! self::woocommerce_ready() ) {
			add_action( 'admin_notices', [ self::class, 'woocommerce_notice' ] );
			return;
		}

		// Also creates the tables on each site of a network activation, on that site's first request.
		Schema::maybe_upgrade();

		OrderSync::add_hooks();
	}

	public static function declare_compatibility(): void {
		if ( ! class_exists( FeaturesUtil::class ) ) {
			return;
		}

		FeaturesUtil::declare_compatibility( 'custom_order_tables', self::$file, true );
		FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', self::$file, true );
	}

	public static function woocommerce_ready(): bool {
		return defined( 'WC_VERSION' ) && version_compare( WC_VERSION, self::MIN_WC, '>=' );
	}

	public static function woocommerce_notice(): void {
		global $pagenow;

		if ( 'plugins.php' !== $pagenow || ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: %s: minimum WooCommerce version, such as 8.2. */
					__( 'Customer Lens needs WooCommerce %s or later, active on this site, so it is not running.', 'customer-lens' ),
					self::MIN_WC
				)
			)
		);
	}

	public static function file(): string {
		return self::$file;
	}

	public static function url( string $relative ): string {
		return plugins_url( $relative, self::$file );
	}
}
