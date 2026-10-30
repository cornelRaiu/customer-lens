<?php

namespace CustomerLens\Identity;

use CustomerLens\Db;
use CustomerLens\Events\Events;
use CustomerLens\Install\Schema;
use CustomerLens\Orders\OrderLinks;
use CustomerLens\Signals\Signals;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The plugin's own tables, named by Schema::table().

/**
 * Merges two customers that staff confirmed are one person, and undoes it within UNDO_DAYS.
 */
final class Merger {

	public const UNDO_DAYS = 30;

	/** Moves everything of customer $from to customer $into. Returns the merge event's ID, or 0. */
	public static function merge( int $into, int $from, int $actor ): int {
		global $wpdb;

		$target = Customers::get( $into );
		$source = Customers::get( $from );
		if ( ! $target || ! $source || $into === $from ) {
			return 0;
		}

		$links  = Schema::table( Schema::CUSTOMER_IDENTITIES );
		$events = Schema::table( Schema::EVENTS );

		// Enough to put everything back.
		$snapshot = [
			'from'            => $from,
			'customer'        => (array) $source,
			'into_customer'   => (array) $target,
			'identities'      => $wpdb->get_results( $wpdb->prepare( "SELECT identity_id, confidence, linked_at, linked_by FROM {$links} WHERE customer_id = %d", $from ), ARRAY_A ),
			'into_identities' => array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT identity_id FROM {$links} WHERE customer_id = %d", $into ) ) ),
			'orders'          => OrderLinks::order_ids_of( $from ),
			'events'          => array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$events} WHERE customer_id = %d", $from ) ) ),
			'undone'          => false,
		];

		$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$links} (customer_id, identity_id, confidence, linked_at, linked_by) SELECT %d, identity_id, confidence, linked_at, linked_by FROM {$links} WHERE customer_id = %d", $into, $from ) );
		$wpdb->delete( $links, [ 'customer_id' => $from ], [ '%d' ] );
		$wpdb->update( Schema::table( Schema::ORDER_CUSTOMERS ), [ 'customer_id' => $into ], [ 'customer_id' => $from ], [ '%d' ], [ '%d' ] );
		$wpdb->update( $events, [ 'customer_id' => $into ], [ 'customer_id' => $from ], [ '%d' ], [ '%d' ] );

		$fields = [];
		if ( ! $target->wp_user_id && $source->wp_user_id ) {
			$fields['wp_user_id'] = $source->wp_user_id;
		}
		if ( Customers::FLAG_NONE === (int) $target->flag_status && Customers::FLAG_NONE !== (int) $source->flag_status ) {
			foreach ( [ 'flag_status', 'flag_reason', 'flagged_by', 'flagged_at', 'flag_expires_at' ] as $column ) {
				$fields[ $column ] = $source->$column;
			}
		}
		if ( $fields ) {
			Customers::update( $into, $fields );
		}

		Customers::delete( $from );
		Signals::delete( $from );
		self::close_proposal( $into, $from );

		$event_id = Events::record(
			Events::MERGE,
			$into,
			[
				'source'        => Events::SOURCE_MANUAL,
				'payload'       => $snapshot,
				'actor_user_id' => $actor,
			]
		);

		Signals::rebuild( $into );

		return $event_id;
	}

	/** Splits a merge back into the two customers. Orders placed since then stay where they are. */
	public static function unmerge( int $event_id, int $actor ): bool {
		global $wpdb;

		$event = Events::get( $event_id );
		if ( ! self::can_undo( $event ) ) {
			return false;
		}

		$snapshot = $event->payload;
		$into     = (int) $event->customer_id;
		$from     = (int) $snapshot['from'];
		$links    = Schema::table( Schema::CUSTOMER_IDENTITIES );

		Customers::restore( (object) $snapshot['customer'] );

		foreach ( $snapshot['identities'] as $link ) {
			$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$links} (customer_id, identity_id, confidence, linked_at, linked_by) VALUES (%d, %d, %d, %s, %s)", $from, $link['identity_id'], $link['confidence'], $link['linked_at'], $link['linked_by'] ) );
		}
		$moved = array_diff( array_map( 'intval', wp_list_pluck( $snapshot['identities'], 'identity_id' ) ), $snapshot['into_identities'] );
		if ( $moved ) {
			$ids = Db::ids( $moved );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$links} WHERE customer_id = %d AND identity_id IN ({$ids})", $into ) );
		}

		$order_table = Schema::table( Schema::ORDER_CUSTOMERS );
		$event_table = Schema::table( Schema::EVENTS );
		$orders      = Db::ids( $snapshot['orders'] );
		$events      = Db::ids( $snapshot['events'] );
		$wpdb->query( $wpdb->prepare( "UPDATE {$order_table} SET customer_id = %d WHERE order_id IN ({$orders})", $from ) );
		$wpdb->query( $wpdb->prepare( "UPDATE {$event_table} SET customer_id = %d WHERE id IN ({$events})", $from ) );

		$flag = array_intersect_key( $snapshot['into_customer'], array_flip( [ 'flag_status', 'flag_reason', 'flagged_by', 'flagged_at', 'flag_expires_at', 'wp_user_id' ] ) );
		Customers::update( $into, $flag );

		$snapshot['undone'] = true;
		Events::update_payload( $event_id, $snapshot );
		Events::record(
			Events::UNMERGE,
			$into,
			[
				'source'        => Events::SOURCE_MANUAL,
				'payload'       => [ 'from' => $from ],
				'actor_user_id' => $actor,
			]
		);

		Signals::rebuild( $into );
		Signals::rebuild( $from );

		return true;
	}

	public static function can_undo( ?object $event ): bool {
		return $event
			&& Events::MERGE === $event->type
			&& empty( $event->payload['undone'] )
			&& strtotime( $event->created_at . ' UTC' ) >= time() - self::UNDO_DAYS * DAY_IN_SECONDS;
	}

	/** Open proposals that the customer may be the same person as another one. */
	public static function proposals( int $customer_id ): array {
		$open = [];
		foreach ( Events::of_customer( $customer_id, [ Events::MERGE_PROPOSAL ], 20 ) as $event ) {
			$other = (int) ( $event->payload['other'] ?? 0 );
			if ( empty( $event->payload['closed'] ) && $other && Customers::get( $other ) ) {
				$open[] = $event;
			}
		}

		return $open;
	}

	/** Closes the proposal between two customers, merged or dismissed. */
	public static function close_proposal( int $a, int $b ): void {
		$event = Events::find( 'merge:' . min( $a, $b ) . ':' . max( $a, $b ) );
		if ( $event ) {
			Events::update_payload( (int) $event->id, array_merge( $event->payload, [ 'closed' => true ] ) );
		}
	}
}
