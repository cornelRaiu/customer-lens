<?php

namespace CustomerLens\Admin;

use CustomerLens\Plugin;
use WC_Order;

defined( 'ABSPATH' ) || exit;

/**
 * Puts the panel on the order edit screen, for legacy and HPOS order storage alike.
 */
final class OrderPanel {

	public const BOX_ID = 'customer-lens-panel';
	public const HANDLE = 'customer-lens-admin';

	public static function add_hooks(): void {
		// After WooCommerce adds its own boxes, at priority 30.
		add_action( 'add_meta_boxes', [ self::class, 'add_box' ], 40 );
		add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue' ] );
	}

	public static function screen_id(): string {
		return wc_get_page_screen_id( 'shop-order' );
	}

	public static function add_box(): void {
		if ( current_user_can( Plugin::capability() ) ) {
			add_meta_box( self::BOX_ID, __( 'Customer Lens', 'customer-lens' ), [ self::class, 'render' ], self::screen_id(), 'normal', 'high' );
		}
	}

	public static function render( $post_or_order ): void {
		$order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order( $post_or_order->ID ?? 0 );
		if ( ! $order instanceof WC_Order || 'shop_order' !== $order->get_type() ) {
			return;
		}

		printf(
			'<div class="mwtcl" data-mwtcl-order="%d">%s</div>',
			(int) $order->get_id(),
			Panel::html( $order ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The template escapes everything it prints.
		);
	}

	public static function enqueue(): void {
		$screen = get_current_screen();
		if ( ! $screen || ! current_user_can( Plugin::capability() ) || ! in_array( $screen->id, [ self::screen_id(), 'edit-shop_order' ], true ) ) {
			return;
		}

		wp_enqueue_style( self::HANDLE, Plugin::url( 'assets/css/admin.css' ), [], Plugin::VERSION );

		// With HPOS the list and the edit screen share one screen ID.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only reads which screen this is.
		$editing = 'shop_order' === $screen->id || ( isset( $_GET['action'] ) && 'edit' === sanitize_key( wp_unslash( $_GET['action'] ) ) );
		if ( ! $editing ) {
			return;
		}

		wp_enqueue_script( self::HANDLE, Plugin::url( 'assets/js/panel.js' ), [ 'wp-api-fetch' ], Plugin::VERSION, true );
		wp_add_inline_script(
			self::HANDLE,
			'window.mwtclPanel = ' . wp_json_encode(
				[
					// Right below the order details, unless the user arranged the boxes themselves.
					'moveUp' => ! get_user_option( 'meta-box-order_' . $screen->id ),
					'i18n'   => [
						'refused'    => __( 'Count a refused delivery for this order?', 'customer-lens' ),
						'unrefused'  => __( 'Take back the refused delivery of this order?', 'customer-lens' ),
						'merge'      => __( 'Merge the other customer into this one? Their orders, identities and notes move here. You can undo it for 30 days.', 'customer-lens' ),
						'unmerge'    => __( 'Split the merged customer back out?', 'customer-lens' ),
						'deleteNote' => __( 'Delete this note?', 'customer-lens' ),
						'unflag'     => __( 'Remove the flag?', 'customer-lens' ),
						'error'      => __( 'Customer Lens could not do that. Reload the page and try again.', 'customer-lens' ),
					],
				]
			) . ';',
			'before'
		);
	}
}
