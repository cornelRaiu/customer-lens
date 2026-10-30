<?php

namespace CustomerLens\Orders;

use CustomerLens\Events\Events;
use CustomerLens\Identity\OrderIdentifiers;
use CustomerLens\Identity\Resolver;
use CustomerLens\Signals\Signals;
use WC_Order;
use WC_Order_Refund;

defined( 'ABSPATH' ) || exit;

/**
 * Follows WooCommerce: links new orders to customers, re-links them when their billing details change,
 * and records refunds, failed payments and cancellations. Reads orders only; never writes to them.
 */
final class OrderSync {

	/** @var array<int, bool> Orders being saved that need syncing, by object ID: true to re-resolve the customer. */
	private static array $pending = [];

	public static function add_hooks(): void {
		add_action( 'woocommerce_before_order_object_save', [ self::class, 'before_save' ] );
		add_action( 'woocommerce_after_order_object_save', [ self::class, 'after_save' ] );
		add_action( 'woocommerce_order_status_changed', [ self::class, 'status_changed' ], 10, 4 );
		add_action( 'woocommerce_order_refunded', [ self::class, 'refunded' ], 10, 2 );
		add_action( 'woocommerce_refund_deleted', [ self::class, 'refund_deleted' ] );
		add_action( 'woocommerce_trash_order', [ self::class, 'resync' ] );
		add_action( 'woocommerce_untrash_order', [ self::class, 'resync' ] );
		add_action( 'woocommerce_delete_order', [ self::class, 'deleted' ] );
		// Trashing from the legacy order list goes through the post functions.
		add_action( 'trashed_post', [ self::class, 'post_trashed' ] );
		add_action( 'untrashed_post', [ self::class, 'post_trashed' ] );
	}

	public static function before_save( $order ): void {
		if ( ! $order instanceof WC_Order || 'shop_order' !== $order->get_type() ) {
			return;
		}

		$changes = $order->get_changes();
		$resolve = ! $order->get_id()
			|| array_intersect_key( $changes, array_flip( [ 'billing', 'shipping', 'customer_id' ] ) )
			|| ( isset( $changes['status'] ) && in_array( $order->get_data()['status'] ?? '', OrderLinks::IGNORED, true ) );

		if ( $resolve || array_intersect_key( $changes, array_flip( [ 'status', 'total', 'currency', 'date_created' ] ) ) ) {
			self::$pending[ spl_object_id( $order ) ] = (bool) $resolve;
		}
	}

	public static function after_save( $order ): void {
		$key = spl_object_id( $order );
		if ( ! isset( self::$pending[ $key ] ) ) {
			return;
		}

		$resolve = self::$pending[ $key ];
		unset( self::$pending[ $key ] );

		self::sync( $order, $resolve );
	}

	/** Links the order, or refreshes its snapshot. Returns the order's customer, or 0. */
	public static function sync( WC_Order $order, bool $resolve = true ): int {
		if ( in_array( $order->get_status(), OrderLinks::IGNORED, true ) ) {
			return 0;
		}

		if ( ! $resolve ) {
			$customer_id = OrderLinks::sync( $order );
			if ( $customer_id ) {
				Signals::rebuild( $customer_id );
				return $customer_id;
			}
		}

		$touched = self::link( $order );
		Signals::rebuild_many( $touched );

		return $touched[0] ?? 0;
	}

	/**
	 * Links the order to its customer without rebuilding signals. Returns the customers it touched (its
	 * customer first, then the one it had before), or [] when the order cannot be linked.
	 *
	 * @return int[]
	 */
	public static function link( WC_Order $order ): array {
		if ( in_array( $order->get_status(), OrderLinks::IGNORED, true ) ) {
			return [];
		}

		// Until it has an email, phone, address or user, an order (built in code, say) cannot be linked.
		$ids = OrderIdentifiers::from_order( $order );
		if ( ! $ids->user_id && ! $ids->identities() ) {
			return [];
		}

		$result = Resolver::resolve( $order, $ids );

		return array_values( array_unique( array_filter( [ $result['customer'], $result['previous'] ] ) ) );
	}

	public static function status_changed( $order_id, $from, $to, $order ): void {
		$customer_id = OrderLinks::customer_of( (int) $order_id );
		if ( ! $customer_id || ! $order instanceof WC_Order ) {
			return;
		}

		self::record_status( $order, $customer_id, (string) $to );
		Signals::rebuild( $customer_id );
	}

	public static function refunded( $order_id, $refund_id ): void {
		$customer_id = OrderLinks::customer_of( (int) $order_id );
		$refund      = wc_get_order( $refund_id );
		if ( ! $customer_id || ! $refund instanceof WC_Order_Refund ) {
			return;
		}

		self::record_refund( $refund, (int) $order_id, $customer_id );
		Signals::rebuild( $customer_id );
	}

	public static function refund_deleted( $refund_id ): void {
		$event = Events::find( 'refund:' . (int) $refund_id );
		if ( $event ) {
			Events::delete( (int) $event->id );
			Signals::rebuild( (int) $event->customer_id );
		}
	}

	public static function resync( $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( $order instanceof WC_Order ) {
			$customer_id = OrderLinks::sync( $order );
			if ( $customer_id ) {
				Signals::rebuild( $customer_id );
			}
		}
	}

	public static function post_trashed( $post_id ): void {
		if ( 'shop_order' === get_post_type( $post_id ) ) {
			self::resync( $post_id );
		}
	}

	public static function deleted( $order_id ): void {
		$customer_id = OrderLinks::delete( (int) $order_id );
		if ( $customer_id ) {
			Events::delete_order( (int) $order_id );
			Signals::rebuild( $customer_id );
		}
	}

	/**
	 * Records what an existing order already went through, for orders placed before the plugin: its
	 * refunds, and a failed payment or cancellation when that is its status now.
	 */
	public static function backfill( WC_Order $order, int $customer_id ): void {
		foreach ( $order->get_refunds() as $refund ) {
			self::record_refund( $refund, $order->get_id(), $customer_id );
		}

		self::record_status( $order, $customer_id, (string) $order->get_status() );
	}

	private static function record_status( WC_Order $order, int $customer_id, string $status ): void {
		$order_id = $order->get_id();

		if ( 'failed' === $status ) {
			Events::record(
				Events::FAILED_PAYMENT,
				$customer_id,
				[
					'order_id'   => $order_id,
					'dedupe_key' => 'failed:' . $order_id,
				]
			);
		} elseif ( 'cancelled' === $status ) {
			Events::record(
				Events::CANCEL,
				$customer_id,
				[
					'order_id'   => $order_id,
					'dedupe_key' => 'cancel:' . $order_id,
				]
			);
		} elseif ( 'refunded' === $status && ! self::has_refunds( $order_id ) ) {
			// Set to Refunded by hand, without a refund: count the order total once.
			Events::record(
				Events::REFUND,
				$customer_id,
				[
					'order_id'   => $order_id,
					'amount'     => (float) $order->get_total(),
					'currency'   => $order->get_currency(),
					'dedupe_key' => 'refund:order:' . $order_id,
				]
			);
		}
	}

	private static function record_refund( WC_Order_Refund $refund, int $order_id, int $customer_id ): void {
		$created = $refund->get_date_created();

		Events::record(
			Events::REFUND,
			$customer_id,
			[
				'order_id'   => $order_id,
				'amount'     => (float) $refund->get_amount(),
				'currency'   => $refund->get_currency(),
				'dedupe_key' => 'refund:' . $refund->get_id(),
				'created_at' => $created ? gmdate( 'Y-m-d H:i:s', $created->getTimestamp() ) : gmdate( 'Y-m-d H:i:s' ),
			]
		);

		// A refund object makes a hand-set "refunded" event for the same order redundant.
		$manual = Events::find( 'refund:order:' . $order_id );
		if ( $manual ) {
			Events::delete( (int) $manual->id );
		}
	}

	private static function has_refunds( int $order_id ): bool {
		return (bool) wc_get_orders(
			[
				'type'   => 'shop_order_refund',
				'parent' => $order_id,
				'limit'  => 1,
				'return' => 'ids',
			]
		);
	}
}
