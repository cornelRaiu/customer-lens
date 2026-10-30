<?php

namespace CustomerLens;

use CustomerLens\Events\Events;
use CustomerLens\Identity\Customers;
use CustomerLens\Identity\Merger;
use CustomerLens\Orders\OrderLinks;
use CustomerLens\Orders\OrderSync;
use CustomerLens\Signals\Signals;
use WC_Order;

defined( 'ABSPATH' ) || exit;

/**
 * What staff can do to a customer, for the REST routes and WP-CLI. Every action is recorded as an event
 * with the user who took it.
 */
final class Actions {

	/** Expiry choices of the flag dialog, in days. 0 never expires. */
	public const FLAG_EXPIRY_DAYS = [ 0, 30, 90, 365 ];

	public const NOTE_MAX_LENGTH = 1000;

	/** The order's customer, linking the order first when that has not happened yet. 0 if it cannot be linked. */
	public static function customer_of_order( WC_Order $order ): int {
		$customer_id = OrderLinks::customer_of( $order->get_id() );

		return $customer_id ? $customer_id : OrderSync::sync( $order );
	}

	public static function mark_refused( WC_Order $order, int $actor ): bool {
		$customer_id = self::customer_of_order( $order );
		if ( ! $customer_id ) {
			return false;
		}

		Events::record(
			Events::REFUSED_DELIVERY,
			$customer_id,
			[
				'order_id'      => $order->get_id(),
				'source'        => Events::SOURCE_MANUAL,
				'dedupe_key'    => 'refused:' . $order->get_id(),
				'actor_user_id' => $actor,
			]
		);
		Signals::rebuild( $customer_id );

		return true;
	}

	public static function unmark_refused( WC_Order $order ): bool {
		$event = Events::find( 'refused:' . $order->get_id() );
		if ( ! $event ) {
			return false;
		}

		Events::delete( (int) $event->id );
		Signals::rebuild( (int) $event->customer_id );

		return true;
	}

	public static function is_refused( int $order_id ): bool {
		return null !== Events::find( 'refused:' . $order_id );
	}

	public static function flag( int $customer_id, int $status, string $reason, int $expires_in_days, int $actor ): bool {
		if ( ! Customers::get( $customer_id ) || ! in_array( $status, [ Customers::FLAG_WATCH, Customers::FLAG_TRUSTED ], true ) ) {
			return false;
		}

		$reason  = mb_substr( trim( $reason ), 0, 190 );
		$expires = $expires_in_days > 0 ? gmdate( 'Y-m-d H:i:s', time() + $expires_in_days * DAY_IN_SECONDS ) : null;

		Customers::set_flag( $customer_id, $status, $reason, $actor, $expires );
		Events::record(
			Events::FLAG,
			$customer_id,
			[
				'source'        => Events::SOURCE_MANUAL,
				'payload'       => [
					'status'     => $status,
					'reason'     => $reason,
					'expires_at' => $expires,
				],
				'actor_user_id' => $actor,
			]
		);

		return true;
	}

	public static function unflag( int $customer_id, int $actor, string $source = Events::SOURCE_MANUAL ): bool {
		$customer = Customers::get( $customer_id );
		if ( ! $customer || Customers::FLAG_NONE === (int) $customer->flag_status ) {
			return false;
		}

		Customers::clear_flag( $customer_id );
		Events::record(
			Events::UNFLAG,
			$customer_id,
			[
				'source'        => $source,
				'payload'       => [ 'status' => (int) $customer->flag_status ],
				'actor_user_id' => $actor,
			]
		);

		return true;
	}

	/** Returns the note's event ID, or 0 for an empty note or an unknown customer. */
	public static function add_note( int $customer_id, string $text, int $actor, int $order_id = 0 ): int {
		$text = mb_substr( trim( $text ), 0, self::NOTE_MAX_LENGTH );
		if ( '' === $text || ! Customers::get( $customer_id ) ) {
			return 0;
		}

		return Events::record(
			Events::NOTE,
			$customer_id,
			[
				'order_id'      => $order_id,
				'source'        => Events::SOURCE_MANUAL,
				'payload'       => [ 'text' => $text ],
				'actor_user_id' => $actor,
			]
		);
	}

	/** Its author or an administrator can delete a note. */
	public static function delete_note( int $event_id, int $actor ): bool {
		$event = Events::get( $event_id );
		if ( ! $event || Events::NOTE !== $event->type ) {
			return false;
		}
		if ( (int) $event->actor_user_id !== $actor && ! user_can( $actor, 'manage_options' ) ) {
			return false;
		}

		Events::delete( $event_id );

		return true;
	}

	public static function merge( int $into, int $from, int $actor ): int {
		return Merger::merge( $into, $from, $actor );
	}

	public static function unmerge( int $event_id, int $actor ): bool {
		return Merger::unmerge( $event_id, $actor );
	}

	public static function dismiss_proposal( int $customer_id, int $other ): void {
		Merger::close_proposal( $customer_id, $other );
	}
}
