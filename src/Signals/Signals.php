<?php

namespace CustomerLens\Signals;

use CustomerLens\Db;
use CustomerLens\Events\Events;
use CustomerLens\Install\Schema;
use CustomerLens\Orders\OrderLinks;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The plugin's own tables, named by Schema::table(). Reads are cached in CACHE_GROUP.

/**
 * The counts staff see, one row per customer, rebuilt from orders and events whenever they change.
 */
final class Signals {

	public const CACHE_GROUP = 'mwtcl_signals_cache';

	public const COUNTERS = [
		'orders_count',
		'refunds_count',
		'cancels_count',
		'failed_payments_count',
		'refused_count',
		'chargebacks_count',
	];

	public static function rebuild( int $customer_id ): void {
		global $wpdb;

		$orders   = Schema::table( Schema::ORDER_CUSTOMERS );
		$events   = Schema::table( Schema::EVENTS );
		$skipped  = Db::strings( OrderLinks::NOT_COUNTED );
		$currency = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '';

		$totals = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					SUM(status NOT IN ({$skipped})) AS orders_count,
					SUM(status = 'cancelled') AS cancels_count,
					SUM(IF(status NOT IN ({$skipped}) AND currency = %s, total, 0)) AS gross,
					MIN(IF(status NOT IN ({$skipped}), created_at, NULL)) AS first_order_at,
					MAX(IF(status NOT IN ({$skipped}), created_at, NULL)) AS last_order_at
				FROM {$orders} WHERE customer_id = %d",
				$currency,
				$customer_id
			)
		);

		$counts = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT e.type, COUNT(*) AS n, SUM(COALESCE(e.amount, 0)) AS amount,
					SUM(IF(o.status IS NOT NULL AND o.status NOT IN ({$skipped}), COALESCE(e.amount, 0), 0)) AS counted_amount
				FROM {$events} e LEFT JOIN {$orders} o ON o.order_id = e.order_id
				WHERE e.customer_id = %d AND e.type IN (%s, %s, %s, %s)
				GROUP BY e.type",
				$customer_id,
				Events::REFUND,
				Events::FAILED_PAYMENT,
				Events::REFUSED_DELIVERY,
				Events::CHARGEBACK
			),
			OBJECT_K
		);

		$refunds = $counts[ Events::REFUND ] ?? null;

		$wpdb->replace(
			Schema::table( Schema::SIGNALS ),
			[
				'customer_id'           => $customer_id,
				'orders_count'          => (int) ( $totals->orders_count ?? 0 ),
				'refunds_count'         => (int) ( $refunds->n ?? 0 ),
				'refunds_total'         => round( (float) ( $refunds->amount ?? 0 ), 2 ),
				'cancels_count'         => (int) ( $totals->cancels_count ?? 0 ),
				'failed_payments_count' => (int) ( $counts[ Events::FAILED_PAYMENT ]->n ?? 0 ),
				'refused_count'         => (int) ( $counts[ Events::REFUSED_DELIVERY ]->n ?? 0 ),
				'chargebacks_count'     => (int) ( $counts[ Events::CHARGEBACK ]->n ?? 0 ),
				'net_total'             => round( (float) ( $totals->gross ?? 0 ) - (float) ( $refunds->counted_amount ?? 0 ), 2 ),
				'currency'              => $currency,
				'first_order_at'        => $totals->first_order_at ?? null,
				'last_order_at'         => $totals->last_order_at ?? null,
				'computed_at'           => Db::now(),
			],
			[ '%d', '%d', '%d', '%f', '%d', '%d', '%d', '%d', '%f', '%s', '%s', '%s', '%s' ]
		);

		wp_cache_delete( $customer_id, self::CACHE_GROUP );
	}

	/** @param int[] $customer_ids */
	public static function rebuild_many( array $customer_ids ): void {
		foreach ( array_unique( array_filter( array_map( 'intval', $customer_ids ) ) ) as $customer_id ) {
			self::rebuild( $customer_id );
		}
	}

	/** @return array<string, mixed>|null */
	public static function get( int $customer_id ): ?array {
		$cached = wp_cache_get( $customer_id, self::CACHE_GROUP );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$rows = self::get_many( [ $customer_id ] );

		return $rows[ $customer_id ] ?? null;
	}

	/**
	 * @param int[] $customer_ids
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_many( array $customer_ids ): array {
		global $wpdb;

		$table = Schema::table( Schema::SIGNALS );
		$ids   = Db::ids( $customer_ids );
		$rows  = [];

		foreach ( $wpdb->get_results( "SELECT * FROM {$table} WHERE customer_id IN ({$ids})", ARRAY_A ) as $row ) {
			foreach ( self::COUNTERS as $counter ) {
				$row[ $counter ] = (int) $row[ $counter ];
			}
			$row['refunds_total'] = (float) $row['refunds_total'];
			$row['net_total']     = (float) $row['net_total'];

			$rows[ (int) $row['customer_id'] ] = $row;
			wp_cache_set( (int) $row['customer_id'], $row, self::CACHE_GROUP );
		}

		return $rows;
	}

	public static function delete( int $customer_id ): void {
		global $wpdb;

		$wpdb->delete( Schema::table( Schema::SIGNALS ), [ 'customer_id' => $customer_id ], [ '%d' ] );
		wp_cache_delete( $customer_id, self::CACHE_GROUP );
	}

	/** @return int[] Every customer, for a full rebuild. */
	public static function all_customer_ids( int $after, int $limit ): array {
		global $wpdb;

		$table = Schema::table( Schema::CUSTOMERS );

		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE id > %d ORDER BY id LIMIT %d", $after, $limit ) ) );
	}
}
