<?php

namespace CustomerLens\Admin;

use CustomerLens\Actions;
use CustomerLens\Events\Events;
use CustomerLens\Identity\Customers;
use CustomerLens\Identity\Identities;
use CustomerLens\Identity\Merger;
use CustomerLens\Import\Importer;
use CustomerLens\Orders\OrderLinks;
use CustomerLens\Settings;
use CustomerLens\Signals\Badges;
use CustomerLens\Signals\Signals;
use WC_Order;

defined( 'ABSPATH' ) || exit;

/**
 * The customer panel on the order screen: the five-second read before shipping.
 */
final class Panel {

	public const PER_PAGE = 10;

	/** Events shown in the notes and activity column. */
	public const TIMELINE = [ Events::NOTE, Events::FLAG, Events::UNFLAG, Events::REFUSED_DELIVERY, Events::MERGE, Events::UNMERGE ];

	public static function html( WC_Order $order, string $status = '', int $page = 1 ): string {
		$view = self::view( $order, $status, max( 1, $page ) );

		ob_start();
		include dirname( __DIR__, 2 ) . '/templates/panel.php';

		return (string) ob_get_clean();
	}

	/** @return array<string, mixed> Everything the template shows. */
	public static function view( WC_Order $order, string $status, int $page ): array {
		$order_id    = $order->get_id();
		$customer_id = Actions::customer_of_order( $order );

		$view = [
			'order'       => $order,
			'customer_id' => $customer_id,
			'importing'   => Importer::is_running() ? Importer::state() : null,
			'retention'   => (int) Settings::get( 'retention_months' ),
		];
		if ( ! $customer_id ) {
			return $view;
		}

		$signals = Signals::get( $customer_id );
		if ( ! $signals ) {
			Signals::rebuild( $customer_id );
			$signals = Signals::get( $customer_id );
		}

		$customer  = Customers::get( $customer_id );
		$snapshot  = OrderLinks::snapshot( $order );
		$position  = OrderLinks::positions( [ $order_id ] )[ $order_id ]['ordinal'] ?? 1;
		$counts    = OrderLinks::status_counts( $customer_id, $order_id );
		$total     = array_sum( $counts );
		$status    = isset( $counts[ $status ] ) ? $status : '';
		$filtered  = '' === $status ? $total : $counts[ $status ];
		$pages     = max( 1, (int) ceil( $filtered / self::PER_PAGE ) );
		$page      = min( $page, $pages );
		$rows      = OrderLinks::of_customer( $customer_id, $order_id, $status, $page, self::PER_PAGE );
		$row_ids   = array_map( static fn( $row ) => (int) $row->order_id, $rows );
		$timeline  = Events::of_customer( $customer_id, self::TIMELINE, 10 );
		$shared    = array_filter( array_map( [ Identities::class, 'get' ], Identities::of_order( $order_id ) ), static fn( $identity ) => $identity && '1' === (string) $identity->shared );
		$merges    = array_filter( Events::of_customer( $customer_id, [ Events::MERGE ], 5 ), [ Merger::class, 'can_undo' ] );
		$proposals = Merger::proposals( $customer_id );

		return array_merge(
			$view,
			[
				'customer'    => $customer,
				'badges'      => Badges::for_order( $signals, $customer, $position, Settings::all(), OrderLinks::previous_order_date( $customer_id, $order_id, $snapshot['created_at'] ) ),
				'counts'      => $counts,
				'total'       => $total,
				'status'      => $status,
				'page'        => $page,
				'pages'       => $pages,
				'filtered'    => $filtered,
				'rows'        => $rows,
				'orders'      => self::load_orders( $row_ids ),
				'kinds'       => Identities::matched_kinds( $customer_id, $row_ids ),
				'row_events'  => Events::types_of_orders( $row_ids ),
				'identities'  => Identities::of_customer( $customer_id ),
				'shared'      => array_values( $shared ),
				'timeline'    => $timeline,
				'users'       => self::user_names( array_merge( wp_list_pluck( $timeline, 'actor_user_id' ), [ $customer ? $customer->flagged_by : 0 ] ) ),
				'proposals'   => $proposals,
				'merges'      => array_values( $merges ),
				'refused'     => Actions::is_refused( $order_id ),
				'expiry_days' => Actions::FLAG_EXPIRY_DAYS,
			]
		);
	}

	/**
	 * @param int[] $ids
	 * @return array<int, WC_Order>
	 */
	private static function load_orders( array $ids ): array {
		if ( ! $ids ) {
			return [];
		}

		$orders = [];
		foreach ( wc_get_orders(
			[
				'post__in' => $ids,
				'type'     => 'shop_order',
				'status'   => array_keys( wc_get_order_statuses() ),
				'limit'    => count( $ids ),
			]
		) as $order ) {
			$orders[ $order->get_id() ] = $order;
		}

		return $orders;
	}

	/**
	 * @param array<int, mixed> $ids
	 * @return array<int, string> User ID => display name.
	 */
	private static function user_names( array $ids ): array {
		$names = [];
		foreach ( array_unique( array_filter( array_map( 'intval', $ids ) ) ) as $id ) {
			$user         = get_userdata( $id );
			$names[ $id ] = $user ? $user->display_name : __( 'A former user', 'customer-lens' );
		}

		return $names;
	}

	/** A date stored in UTC, short, in the site's time zone. */
	public static function date( ?string $utc ): string {
		if ( ! $utc ) {
			return '';
		}

		/* translators: Short date format, see https://www.php.net/manual/datetime.format.php */
		return (string) wp_date( __( 'M j, Y', 'customer-lens' ), (int) strtotime( $utc . ' UTC' ) );
	}

	/** What the timeline says about an event. */
	public static function describe( object $event ): string {
		$payload = $event->payload;

		switch ( $event->type ) {
			case Events::NOTE:
				return (string) ( $payload['text'] ?? '' );
			case Events::FLAG:
				$what = Customers::FLAG_TRUSTED === (int) ( $payload['status'] ?? 0 ) ? __( 'Marked as trusted.', 'customer-lens' ) : __( 'Flagged: watch.', 'customer-lens' );
				return trim( $what . ' ' . ( $payload['reason'] ?? '' ) );
			case Events::UNFLAG:
				return Events::SOURCE_SYSTEM === $event->source ? __( 'The flag expired.', 'customer-lens' ) : __( 'Flag removed.', 'customer-lens' );
			case Events::REFUSED_DELIVERY:
				/* translators: %s: order number. */
				return sprintf( __( 'Refused delivery of order #%s.', 'customer-lens' ), (string) $event->order_id );
			case Events::MERGE:
				/* translators: %s: customer number. */
				return sprintf( __( 'Merged customer #%s into this one.', 'customer-lens' ), (string) ( $payload['from'] ?? '' ) );
			case Events::UNMERGE:
				/* translators: %s: customer number. */
				return sprintf( __( 'Split customer #%s back out.', 'customer-lens' ), (string) ( $payload['from'] ?? '' ) );
		}

		return (string) $event->type;
	}

	/** "same phone", "same email and phone". */
	public static function via( array $kinds ): string {
		$labels = [
			Identities::EMAIL   => __( 'email', 'customer-lens' ),
			Identities::PHONE   => __( 'phone', 'customer-lens' ),
			Identities::ADDRESS => __( 'address', 'customer-lens' ),
			'user'              => __( 'account', 'customer-lens' ),
		];
		$names  = array_values( array_intersect_key( $labels, array_flip( $kinds ) ) );

		/* translators: %s: what two customers have in common, such as "phone" or "email, phone". */
		return sprintf( __( 'same %s', 'customer-lens' ), wp_sprintf_l( '%l', $names ) );
	}

	public static function kind_label( string $kind ): string {
		$labels = [
			Identities::EMAIL   => __( 'email', 'customer-lens' ),
			Identities::PHONE   => __( 'phone', 'customer-lens' ),
			Identities::ADDRESS => __( 'address', 'customer-lens' ),
		];

		return $labels[ $kind ] ?? $kind;
	}
}
