<?php

namespace CustomerLens\Events;

use CustomerLens\Db;
use CustomerLens\Install\Schema;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The plugin's own tables, named by Schema::table().

/**
 * Everything that happened to a customer, one row each. Signals are derived from these rows.
 */
final class Events {

	public const ORDER_PLACED     = 'order_placed';
	public const REFUND           = 'refund';
	public const CANCEL           = 'cancel';
	public const FAILED_PAYMENT   = 'failed_payment';
	public const REFUSED_DELIVERY = 'refused_delivery';
	public const CHARGEBACK       = 'chargeback';
	public const FLAG             = 'flag';
	public const UNFLAG           = 'unflag';
	public const NOTE             = 'note';
	public const MERGE_PROPOSAL   = 'merge_proposal';
	public const MERGE            = 'merge';
	public const UNMERGE          = 'unmerge';

	public const SOURCE_WOO    = 'woo';
	public const SOURCE_MANUAL = 'manual';
	public const SOURCE_SYSTEM = 'system';

	/** Kept until the customer is erased, whatever the retention setting. */
	public const KEPT = [ self::NOTE, self::MERGE, self::UNMERGE ];

	/**
	 * Records an event. With a dedupe_key, the same event is only ever recorded once.
	 *
	 * @param array{order_id?: int, source?: string, amount?: float, currency?: string, payload?: array, dedupe_key?: string, actor_user_id?: int, created_at?: string} $args
	 * @return int The new event's ID, or 0 when an event with the same dedupe_key exists.
	 */
	public static function record( string $type, int $customer_id, array $args = [] ): int {
		global $wpdb;

		$table = Schema::table( Schema::EVENTS );
		$wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$table} (customer_id, order_id, type, source, amount, currency, payload, dedupe_key, actor_user_id, created_at)
				VALUES (%d, NULLIF(%d, 0), %s, %s, NULLIF(%s, ''), NULLIF(%s, ''), NULLIF(%s, ''), NULLIF(%s, ''), NULLIF(%d, 0), %s)",
				$customer_id,
				$args['order_id'] ?? 0,
				$type,
				$args['source'] ?? self::SOURCE_WOO,
				isset( $args['amount'] ) ? number_format( (float) $args['amount'], 2, '.', '' ) : '',
				$args['currency'] ?? '',
				isset( $args['payload'] ) ? wp_json_encode( $args['payload'] ) : '',
				$args['dedupe_key'] ?? '',
				$args['actor_user_id'] ?? 0,
				$args['created_at'] ?? Db::now()
			)
		);

		return (int) $wpdb->rows_affected ? (int) $wpdb->insert_id : 0;
	}

	public static function get( int $id ): ?object {
		global $wpdb;

		$table = Schema::table( Schema::EVENTS );

		return self::decode( $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) ) );
	}

	public static function find( string $dedupe_key ): ?object {
		global $wpdb;

		$table = Schema::table( Schema::EVENTS );

		return self::decode( $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE dedupe_key = %s", $dedupe_key ) ) );
	}

	public static function delete( int $id ): void {
		global $wpdb;

		$wpdb->delete( Schema::table( Schema::EVENTS ), [ 'id' => $id ], [ '%d' ] );
	}

	/** @param array<string, mixed> $payload */
	public static function update_payload( int $id, array $payload ): void {
		global $wpdb;

		$wpdb->update( Schema::table( Schema::EVENTS ), [ 'payload' => wp_json_encode( $payload ) ], [ 'id' => $id ], [ '%s' ], [ '%d' ] );
	}

	/**
	 * @param string[] $types
	 * @return object[] Newest first, with payload decoded.
	 */
	public static function of_customer( int $customer_id, array $types, int $limit = 50 ): array {
		global $wpdb;

		$table = Schema::table( Schema::EVENTS );
		$types = Db::strings( $types );

		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE customer_id = %d AND type IN ({$types}) ORDER BY created_at DESC, id DESC LIMIT %d", $customer_id, $limit ) );

		return array_map( [ self::class, 'decode' ], $rows );
	}

	/**
	 * @param int[] $order_ids
	 * @return array<int, string[]> Order ID => event types recorded for it.
	 */
	public static function types_of_orders( array $order_ids ): array {
		global $wpdb;

		$table = Schema::table( Schema::EVENTS );
		$ids   = Db::ids( $order_ids );
		$types = [];

		foreach ( $wpdb->get_results( "SELECT DISTINCT order_id, type FROM {$table} WHERE order_id IN ({$ids})" ) as $row ) {
			$types[ (int) $row->order_id ][] = $row->type;
		}

		return $types;
	}

	/** Deletes the events of a deleted order. */
	public static function delete_order( int $order_id ): void {
		global $wpdb;

		$wpdb->delete( Schema::table( Schema::EVENTS ), [ 'order_id' => $order_id ], [ '%d' ] );
	}

	/** Moves an order's events to another customer, after the order moved. */
	public static function move_order( int $order_id, int $customer_id ): void {
		global $wpdb;

		$wpdb->update( Schema::table( Schema::EVENTS ), [ 'customer_id' => $customer_id ], [ 'order_id' => $order_id ], [ '%d' ], [ '%d' ] );
	}

	/** @return int[] Customers that have events created since $since. */
	public static function customers_since( string $since ): array {
		global $wpdb;

		$table = Schema::table( Schema::EVENTS );

		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT customer_id FROM {$table} WHERE created_at >= %s", $since ) ) );
	}

	/**
	 * Deletes events older than $before, except the kinds that are kept until erasure.
	 *
	 * @return int[] Customers that lost events.
	 */
	public static function purge( string $before ): array {
		global $wpdb;

		$table     = Schema::table( Schema::EVENTS );
		$kept      = Db::strings( self::KEPT );
		$customers = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT customer_id FROM {$table} WHERE created_at < %s AND type NOT IN ({$kept})", $before ) ) );

		if ( $customers ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s AND type NOT IN ({$kept})", $before ) );
		}

		return $customers;
	}

	/** @return object|null The row with payload decoded to an array. */
	public static function decode( ?object $row ): ?object {
		if ( $row ) {
			$row->payload = $row->payload ? (array) json_decode( $row->payload, true ) : [];
		}

		return $row;
	}
}
