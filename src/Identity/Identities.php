<?php

namespace CustomerLens\Identity;

use CustomerLens\Db;
use CustomerLens\Install\Schema;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The plugin's own tables, named by Schema::table().

/**
 * Emails, phones and addresses, each stored once, and which customers and orders they belong to.
 */
final class Identities {

	public const EMAIL   = 'email';
	public const PHONE   = 'phone';
	public const ADDRESS = 'address';

	public const LINKED_AUTO   = 'auto';
	public const LINKED_MANUAL = 'manual';

	/** A phone or email used with this many different names in SHARED_MONTHS stops identifying one person. */
	public const SHARED_NAMES  = 3;
	public const SHARED_MONTHS = 12;

	public static function hash( string $kind, string $value ): string {
		return hash( 'sha256', $kind . ':' . $value );
	}

	/** Stores the identity, or updates when it was first and last seen. Returns its ID. */
	public static function upsert( string $kind, string $value, string $seen_at ): int {
		global $wpdb;

		$table = Schema::table( Schema::IDENTITIES );
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (kind, value_norm, hash, first_seen, last_seen) VALUES (%s, %s, %s, %s, %s)
				ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), first_seen = LEAST(first_seen, %s), last_seen = GREATEST(last_seen, %s)",
				$kind,
				mb_substr( $value, 0, 190 ),
				self::hash( $kind, $value ),
				$seen_at,
				$seen_at,
				$seen_at,
				$seen_at
			)
		);

		return (int) $wpdb->insert_id;
	}

	public static function find( string $kind, string $value ): int {
		global $wpdb;

		$table = Schema::table( Schema::IDENTITIES );

		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE kind = %s AND hash = %s", $kind, self::hash( $kind, $value ) ) );
	}

	/** @return object|null Row with id, kind, value_norm, shared. */
	public static function get( int $id ): ?object {
		global $wpdb;

		$table = Schema::table( Schema::IDENTITIES );

		return $wpdb->get_row( $wpdb->prepare( "SELECT id, kind, value_norm, shared FROM {$table} WHERE id = %d", $id ) );
	}

	/** Remembers which identities an order used, and under which name, replacing what was there. */
	public static function record_order( int $order_id, array $identity_ids, string $name_key, string $seen_at ): void {
		global $wpdb;

		$table = Schema::table( Schema::ORDER_IDENTITIES );
		$wpdb->delete( $table, [ 'order_id' => $order_id ], [ '%d' ] );

		foreach ( array_unique( array_filter( $identity_ids ) ) as $identity_id ) {
			$wpdb->insert(
				$table,
				[
					'order_id'    => $order_id,
					'identity_id' => $identity_id,
					'name_key'    => $name_key,
					'seen_at'     => $seen_at,
				],
				[ '%d', '%d', '%s', '%s' ]
			);
		}
	}

	/** @return int[] */
	public static function of_order( int $order_id ): array {
		global $wpdb;

		$table = Schema::table( Schema::ORDER_IDENTITIES );

		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT identity_id FROM {$table} WHERE order_id = %d", $order_id ) ) );
	}

	/**
	 * Marks the identity shared when orders in the last SHARED_MONTHS used it with SHARED_NAMES or more
	 * different names, and unmarks it when they no longer do. Returns whether it is shared.
	 */
	public static function refresh_shared( int $identity_id ): bool {
		global $wpdb;

		$links  = Schema::table( Schema::ORDER_IDENTITIES );
		$since  = gmdate( 'Y-m-d H:i:s', strtotime( '-' . self::SHARED_MONTHS . ' months' ) );
		$names  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT name_key) FROM {$links} WHERE identity_id = %d AND seen_at >= %s AND name_key <> ''", $identity_id, $since ) );
		$shared = $names >= self::SHARED_NAMES;

		$wpdb->update( Schema::table( Schema::IDENTITIES ), [ 'shared' => (int) $shared ], [ 'id' => $identity_id ], [ '%d' ], [ '%d' ] );

		return $shared;
	}

	/** @return int[] Customers the identity is linked to, oldest first. */
	public static function customers_of( int $identity_id ): array {
		global $wpdb;

		$table = Schema::table( Schema::CUSTOMER_IDENTITIES );

		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT customer_id FROM {$table} WHERE identity_id = %d ORDER BY customer_id", $identity_id ) ) );
	}

	public static function link( int $customer_id, int $identity_id, int $confidence, string $linked_by = self::LINKED_AUTO ): void {
		global $wpdb;

		$table = Schema::table( Schema::CUSTOMER_IDENTITIES );
		$wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$table} (customer_id, identity_id, confidence, linked_at, linked_by) VALUES (%d, %d, %d, %s, %s)",
				$customer_id,
				$identity_id,
				$confidence,
				Db::now(),
				$linked_by
			)
		);
	}

	/** @return object[] Rows with id, kind, value_norm, shared, confidence; strongest first. */
	public static function of_customer( int $customer_id ): array {
		global $wpdb;

		$links      = Schema::table( Schema::CUSTOMER_IDENTITIES );
		$identities = Schema::table( Schema::IDENTITIES );

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT i.id, i.kind, i.value_norm, i.shared, l.confidence FROM {$links} l
				INNER JOIN {$identities} i ON i.id = l.identity_id
				WHERE l.customer_id = %d ORDER BY l.confidence DESC, i.last_seen DESC",
				$customer_id
			)
		);
	}

	/**
	 * Which kinds of the customer's identities each order used: [order_id => ['email', 'phone']].
	 *
	 * @param int[] $order_ids
	 * @return array<int, string[]>
	 */
	public static function matched_kinds( int $customer_id, array $order_ids ): array {
		global $wpdb;

		$order_links    = Schema::table( Schema::ORDER_IDENTITIES );
		$customer_links = Schema::table( Schema::CUSTOMER_IDENTITIES );
		$identities     = Schema::table( Schema::IDENTITIES );
		$ids            = Db::ids( $order_ids );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT o.order_id, i.kind FROM {$order_links} o
				INNER JOIN {$customer_links} c ON c.identity_id = o.identity_id AND c.customer_id = %d
				INNER JOIN {$identities} i ON i.id = o.identity_id
				WHERE o.order_id IN ({$ids})",
				$customer_id
			)
		);

		$kinds = [];
		foreach ( $rows as $row ) {
			$kinds[ (int) $row->order_id ][] = $row->kind;
		}

		// Strongest first: email, phone, address.
		$order = array_flip( [ self::EMAIL, self::PHONE, self::ADDRESS ] );
		foreach ( $kinds as &$list ) {
			usort( $list, static fn( $a, $b ) => $order[ $a ] <=> $order[ $b ] );
		}

		return $kinds;
	}

	public static function display( string $kind, string $value ): string {
		if ( self::PHONE === $kind ) {
			return PhoneNormalizer::display( $value );
		}

		return self::ADDRESS === $kind ? AddressNormalizer::display( $value ) : $value;
	}
}
