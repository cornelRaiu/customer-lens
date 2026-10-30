<?php

namespace CustomerLens\Orders;

use CustomerLens\Db;
use CustomerLens\Install\Schema;
use WC_Order;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- The plugin's own tables, named by Schema::table().

/**
 * Which customer each order belongs to, with a snapshot of the order's status, total and date so that
 * signals, ordinals and list filters are computed without loading orders.
 */
final class OrderLinks {

	/** Statuses whose orders do not count as orders the customer placed. */
	public const NOT_COUNTED = [ 'cancelled', 'failed', 'trash', 'checkout-draft' ];

	/** Statuses that are never linked: drafts the customer may never submit. */
	public const IGNORED = [ 'checkout-draft', 'auto-draft' ];

	public static function get( int $order_id ): ?object {
		global $wpdb;

		$table = Schema::table( Schema::ORDER_CUSTOMERS );

		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE order_id = %d", $order_id ) );
	}

	public static function customer_of( int $order_id ): int {
		global $wpdb;

		$table = Schema::table( Schema::ORDER_CUSTOMERS );

		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT customer_id FROM {$table} WHERE order_id = %d", $order_id ) );
	}

	public static function save( WC_Order $order, int $customer_id, string $method, int $confidence ): void {
		global $wpdb;

		$table    = Schema::table( Schema::ORDER_CUSTOMERS );
		$snapshot = self::snapshot( $order );

		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (order_id, customer_id, match_method, confidence, matched_at, status, total, currency, created_at)
				VALUES (%d, %d, %s, %d, %s, %s, %f, %s, %s)
				ON DUPLICATE KEY UPDATE customer_id = %d, match_method = %s, confidence = %d, matched_at = %s, status = %s, total = %f, currency = %s, created_at = %s",
				$order->get_id(),
				$customer_id,
				$method,
				$confidence,
				Db::now(),
				$snapshot['status'],
				$snapshot['total'],
				$snapshot['currency'],
				$snapshot['created_at'],
				$customer_id,
				$method,
				$confidence,
				Db::now(),
				$snapshot['status'],
				$snapshot['total'],
				$snapshot['currency'],
				$snapshot['created_at']
			)
		);
	}

	/** Brings the snapshot up to date. Returns the order's customer, or 0 when the order is not linked. */
	public static function sync( WC_Order $order ): int {
		global $wpdb;

		$customer_id = self::customer_of( $order->get_id() );
		if ( $customer_id ) {
			$wpdb->update( Schema::table( Schema::ORDER_CUSTOMERS ), self::snapshot( $order ), [ 'order_id' => $order->get_id() ], [ '%s', '%f', '%s', '%s' ], [ '%d' ] );
		}

		return $customer_id;
	}

	public static function set_status( int $order_id, string $status ): int {
		global $wpdb;

		$wpdb->update( Schema::table( Schema::ORDER_CUSTOMERS ), [ 'status' => $status ], [ 'order_id' => $order_id ], [ '%s' ], [ '%d' ] );

		return self::customer_of( $order_id );
	}

	/** Forgets the order. Returns the customer it belonged to, or 0. */
	public static function delete( int $order_id ): int {
		global $wpdb;

		$customer_id = self::customer_of( $order_id );
		$wpdb->delete( Schema::table( Schema::ORDER_CUSTOMERS ), [ 'order_id' => $order_id ], [ '%d' ] );
		$wpdb->delete( Schema::table( Schema::ORDER_IDENTITIES ), [ 'order_id' => $order_id ], [ '%d' ] );

		return $customer_id;
	}

	/** @return array{status: string, total: float, currency: string, created_at: string} */
	public static function snapshot( WC_Order $order ): array {
		$created = $order->get_date_created();

		return [
			'status'     => (string) $order->get_status(),
			'total'      => (float) $order->get_total(),
			'currency'   => (string) $order->get_currency(),
			'created_at' => $created ? gmdate( 'Y-m-d H:i:s', $created->getTimestamp() ) : Db::now(),
		];
	}

	/**
	 * The customer's other orders, newest first.
	 *
	 * @return object[] Rows with order_id, status, total, currency, created_at.
	 */
	public static function of_customer( int $customer_id, int $exclude_order, string $status, int $page, int $per_page ): array {
		global $wpdb;

		$table  = Schema::table( Schema::ORDER_CUSTOMERS );
		$filter = '' === $status ? '' : $wpdb->prepare( ' AND status = %s', $status );

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT order_id, status, total, currency, created_at FROM {$table}
				WHERE customer_id = %d AND order_id <> %d{$filter}
				ORDER BY created_at DESC, order_id DESC LIMIT %d OFFSET %d",
				$customer_id,
				$exclude_order,
				$per_page,
				max( 0, ( $page - 1 ) * $per_page )
			)
		);
	}

	/** @return array<string, int> Status => number of the customer's other orders. */
	public static function status_counts( int $customer_id, int $exclude_order ): array {
		global $wpdb;

		$table  = Schema::table( Schema::ORDER_CUSTOMERS );
		$counts = [];

		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT status, COUNT(*) AS n FROM {$table} WHERE customer_id = %d AND order_id <> %d GROUP BY status ORDER BY n DESC", $customer_id, $exclude_order ) ) as $row ) {
			$counts[ $row->status ] = (int) $row->n;
		}

		return $counts;
	}

	/** When the customer last placed a counted order before this one, or null. */
	public static function previous_order_date( int $customer_id, int $order_id, string $before ): ?string {
		global $wpdb;

		$table   = Schema::table( Schema::ORDER_CUSTOMERS );
		$skipped = Db::strings( self::NOT_COUNTED );

		$date = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT MAX(created_at) FROM {$table} WHERE customer_id = %d AND order_id <> %d AND created_at <= %s AND status NOT IN ({$skipped})",
				$customer_id,
				$order_id,
				$before
			)
		);

		return $date ? (string) $date : null;
	}

	/**
	 * For each linked order: its customer and its place among that customer's orders (1 = first order),
	 * counting only the counted orders placed before it. One query for a whole list page.
	 *
	 * @param int[] $order_ids
	 * @return array<int, array{customer_id: int, ordinal: int}>
	 */
	public static function positions( array $order_ids ): array {
		global $wpdb;

		$table   = Schema::table( Schema::ORDER_CUSTOMERS );
		$ids     = Db::ids( $order_ids );
		$skipped = Db::strings( self::NOT_COUNTED );

		$rows = $wpdb->get_results(
			"SELECT o.order_id, o.customer_id, 1 + (
				SELECT COUNT(*) FROM {$table} p
				WHERE p.customer_id = o.customer_id AND p.order_id <> o.order_id AND p.status NOT IN ({$skipped})
				AND ( p.created_at < o.created_at OR ( p.created_at = o.created_at AND p.order_id < o.order_id ) )
			) AS ordinal
			FROM {$table} o WHERE o.order_id IN ({$ids})"
		);

		$positions = [];
		foreach ( $rows as $row ) {
			$positions[ (int) $row->order_id ] = [
				'customer_id' => (int) $row->customer_id,
				'ordinal'     => (int) $row->ordinal,
			];
		}

		return $positions;
	}

	/**
	 * Everything the orders list shows for a page of orders, in one query: each order's place among its
	 * customer's orders, the customer's flag and the customer's signals.
	 *
	 * @param int[] $order_ids
	 * @return array<int, array<string, mixed>> Order ID => row; orders not linked yet are missing.
	 */
	public static function summaries( array $order_ids ): array {
		global $wpdb;

		$links     = Schema::table( Schema::ORDER_CUSTOMERS );
		$customers = Schema::table( Schema::CUSTOMERS );
		$signals   = Schema::table( Schema::SIGNALS );
		$ids       = Db::ids( $order_ids );
		$skipped   = Db::strings( self::NOT_COUNTED );

		$rows = $wpdb->get_results(
			"SELECT o.order_id, o.customer_id, c.flag_status, s.*, 1 + (
				SELECT COUNT(*) FROM {$links} p
				WHERE p.customer_id = o.customer_id AND p.order_id <> o.order_id AND p.status NOT IN ({$skipped})
				AND ( p.created_at < o.created_at OR ( p.created_at = o.created_at AND p.order_id < o.order_id ) )
			) AS ordinal
			FROM {$links} o
			INNER JOIN {$customers} c ON c.id = o.customer_id
			LEFT JOIN {$signals} s ON s.customer_id = o.customer_id
			WHERE o.order_id IN ({$ids})",
			ARRAY_A
		);

		$summaries = [];
		foreach ( $rows as $row ) {
			$row['customer_id'] = (int) $row['customer_id'];

			$summaries[ (int) $row['order_id'] ] = $row;
		}

		return $summaries;
	}

	/** Moves an order to another customer. */
	public static function move( int $order_id, int $customer_id ): void {
		global $wpdb;

		$wpdb->update( Schema::table( Schema::ORDER_CUSTOMERS ), [ 'customer_id' => $customer_id ], [ 'order_id' => $order_id ], [ '%d' ], [ '%d' ] );
	}

	/** @return int[] */
	public static function order_ids_of( int $customer_id ): array {
		global $wpdb;

		$table = Schema::table( Schema::ORDER_CUSTOMERS );

		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT order_id FROM {$table} WHERE customer_id = %d", $customer_id ) ) );
	}
}
