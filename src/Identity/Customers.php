<?php

namespace CustomerLens\Identity;

use CustomerLens\Db;
use CustomerLens\Install\Schema;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The plugin's own tables, named by Schema::table().

/**
 * A customer is a cluster of identities, not a WordPress user. Guests are customers too.
 */
final class Customers {

	public const FLAG_NONE    = 0;
	public const FLAG_WATCH   = 1;
	public const FLAG_TRUSTED = 2;

	public static function create( int $wp_user_id = 0 ): int {
		global $wpdb;

		$now = Db::now();
		$wpdb->insert(
			Schema::table( Schema::CUSTOMERS ),
			[
				'wp_user_id' => $wp_user_id ? $wp_user_id : null,
				'created_at' => $now,
				'updated_at' => $now,
			],
			[ '%d', '%s', '%s' ]
		);

		return (int) $wpdb->insert_id;
	}

	public static function get( int $id ): ?object {
		global $wpdb;

		$table = Schema::table( Schema::CUSTOMERS );

		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) );
	}

	/** @return array<int, object> */
	public static function get_many( array $ids ): array {
		global $wpdb;

		$table = Schema::table( Schema::CUSTOMERS );
		$ids   = Db::ids( $ids );
		$rows  = [];

		foreach ( $wpdb->get_results( "SELECT * FROM {$table} WHERE id IN ({$ids})" ) as $row ) {
			$rows[ (int) $row->id ] = $row;
		}

		return $rows;
	}

	public static function of_user( int $wp_user_id ): int {
		global $wpdb;

		$table = Schema::table( Schema::CUSTOMERS );

		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE wp_user_id = %d ORDER BY id LIMIT 1", $wp_user_id ) );
	}

	/** @param array<string, mixed> $fields Column => value. */
	public static function update( int $id, array $fields ): void {
		global $wpdb;

		$fields['updated_at'] = Db::now();
		$wpdb->update( Schema::table( Schema::CUSTOMERS ), $fields, [ 'id' => $id ] );
	}

	public static function set_flag( int $id, int $status, string $reason, int $by, ?string $expires_at ): void {
		self::update(
			$id,
			[
				'flag_status'     => $status,
				'flag_reason'     => '' === $reason ? null : mb_substr( $reason, 0, 190 ),
				'flagged_by'      => $by ? $by : null,
				'flagged_at'      => Db::now(),
				'flag_expires_at' => $expires_at,
			]
		);
	}

	public static function clear_flag( int $id ): void {
		self::update(
			$id,
			[
				'flag_status'     => self::FLAG_NONE,
				'flag_reason'     => null,
				'flagged_by'      => null,
				'flagged_at'      => null,
				'flag_expires_at' => null,
			]
		);
	}

	/** @return int[] Customers whose flag has expired. */
	public static function expired_flags(): array {
		global $wpdb;

		$table = Schema::table( Schema::CUSTOMERS );

		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE flag_status <> %d AND flag_expires_at IS NOT NULL AND flag_expires_at <= %s", self::FLAG_NONE, Db::now() ) ) );
	}

	public static function delete( int $id ): void {
		global $wpdb;

		$wpdb->delete( Schema::table( Schema::CUSTOMERS ), [ 'id' => $id ], [ '%d' ] );
	}

	/** Puts back a deleted customer row with its original ID. */
	public static function restore( object $row ): void {
		global $wpdb;

		$wpdb->insert( Schema::table( Schema::CUSTOMERS ), (array) $row );
	}
}
