<?php
/**
 * Fills a demo store with made-up customers and orders: for screenshots, and to try the plugin.
 *
 * Never run it on a real store. It refuses to run unless CUSTOMER_LENS_DEMO=1 is set.
 *
 * Usage: CUSTOMER_LENS_DEMO=1 wp eval-file bin/demo-data.php
 *
 * @package CustomerLens
 */

use CustomerLens\Actions;
use CustomerLens\Identity\Customers;
use CustomerLens\Orders\OrderLinks;

if ( '1' !== getenv( 'CUSTOMER_LENS_DEMO' ) ) {
	WP_CLI::error( 'This adds made-up orders. Run it on a demo store only, with CUSTOMER_LENS_DEMO=1.' );
}

mt_srand( 7 );

// Staff who write the notes and flags.
$staff = [];
foreach ( [ 'ana' => 'Ana Popescu', 'mihai' => 'Mihai Stoica' ] as $login => $name ) {
	$user = get_user_by( 'login', $login );
	$staff[ $login ] = $user ? $user->ID : wp_insert_user(
		[
			'user_login'   => $login,
			'user_email'   => $login . '@lumen-hair.example',
			'display_name' => $name,
			'first_name'   => strtok( $name, ' ' ),
			'role'         => 'shop_manager',
			'user_pass'    => wp_generate_password( 24 ),
		]
	);
}

$products = [];
foreach ( [ 'Hair Building Fibers 27.5 g' => 29, 'Spray Applicator' => 16, 'Hairline Optimizer' => 12, 'FiberHold Spray' => 19 ] as $name => $price ) {
	$existing = get_page_by_title( $name, OBJECT, 'product' ); // phpcs:ignore WordPress.WP.DeprecatedFunctions
	if ( $existing ) {
		$products[] = wc_get_product( $existing->ID );
		continue;
	}
	$product = new WC_Product_Simple();
	$product->set_name( $name );
	$product->set_regular_price( (string) $price );
	$product->set_status( 'publish' );
	$product->save();
	$products[] = $product;
}

$cities = [
	[ 'Cluj-Napoca', '400114', 'Str. Memorandumului', 'CJ' ],
	[ 'București', '010011', 'Bd. Unirii', 'B' ],
	[ 'Iași', '700259', 'Str. Lăpușneanu', 'IS' ],
	[ 'Timișoara', '300077', 'Str. Alba Iulia', 'TM' ],
	[ 'Brașov', '500030', 'Str. Republicii', 'BV' ],
	[ 'Constanța', '900178', 'Bd. Tomis', 'CT' ],
	[ 'Sibiu', '550160', 'Str. Nicolae Bălcescu', 'SB' ],
	[ 'Oradea', '410087', 'Calea Republicii', 'BH' ],
];
$couriers = [ 'Sameday', 'FAN Courier', 'GLS' ];

/** A person: billing details, built from a name, a city index and a phone. */
$person = static function ( string $first, string $last, int $city, string $phone, string $email = '' ) use ( $cities ): array {
	$place = $cities[ $city % count( $cities ) ];

	return [
		'first_name' => $first,
		'last_name'  => $last,
		'email'      => $email ? $email : strtolower( remove_accents( $first . '.' . $last ) ) . '@example.com',
		'phone'      => $phone,
		'address_1'  => $place[2] . ' ' . ( 3 + ( crc32( $first . $last ) % 90 ) ),
		'city'       => $place[0],
		'postcode'   => $place[1],
		'state'      => $place[3],
		'country'    => 'RO',
	];
};

// Order specs: person, days ago, status, total override, payment, extras.
$specs = [];

$first_names = [ 'Maria', 'Elena', 'Andrei', 'Radu', 'Cristina', 'Vlad', 'Mihaela', 'Alexandru', 'Gabriel', 'Diana', 'Bogdan', 'Raluca', 'Sorin', 'Adriana', 'Florin', 'Simona', 'Cătălin', 'Larisa', 'Dan', 'Oana' ];
$last_names  = [ 'Ionescu', 'Dumitru', 'Stan', 'Marin', 'Munteanu', 'Constantin', 'Gheorghe', 'Matei', 'Tudor', 'Lazăr', 'Neagu', 'Dobre', 'Barbu', 'Moldovan', 'Sârbu' ];

foreach ( range( 0, 35 ) as $i ) {
	$customer = $person( $first_names[ $i % 20 ], $last_names[ ( $i * 7 ) % 15 ], $i, sprintf( '07%02d %03d %03d', 20 + $i, mt_rand( 100, 999 ), mt_rand( 100, 999 ) ) );
	$count    = [ 1, 1, 1, 2, 2, 3, 3, 4, 5, 6 ][ mt_rand( 0, 9 ) ];
	foreach ( range( 1, $count ) as $n ) {
		$roll    = mt_rand( 1, 100 );
		$status  = $roll <= 82 ? 'completed' : ( $roll <= 93 ? 'cancelled' : 'refunded' );
		$specs[] = [
			'person'  => $customer,
			'days'    => mt_rand( 8, 540 ),
			'status'  => $status,
			'refused' => 'cancelled' === $status && mt_rand( 1, 100 ) <= 50,
		];
	}
}

// The customers the screenshots are about.
$ioana     = $person( 'Ioana', 'Pop', 0, '0745 111 222', 'ioana.pop@example.com' );
$ioana_old = $person( 'Ioana', 'Pop', 0, '0740 000 123', 'ioana.pop.cj@example.net' );
$vlad      = $person( 'Vlad', 'Popescu', 5, '0733 456 789' );
$maria     = $person( 'Maria', 'Dumitru', 2, '0722 345 678' );
$radu      = $person( 'Radu', 'Ionescu', 3, '0766 234 567' );
$cristina  = $person( 'Cristina', 'Stan', 4, '0755 123 456' );

$specs[] = [ 'person' => $ioana_old, 'days' => 400, 'status' => 'completed' ];
$specs[] = [ 'person' => $ioana, 'days' => 160, 'status' => 'cancelled', 'refused' => true, 'courier' => 'FAN Courier', 'total' => 54 ];
$specs[] = [ 'person' => $ioana, 'days' => 118, 'status' => 'completed', 'refund' => 38, 'refused' => true, 'total' => 76 ];
$specs[] = [ 'person' => $ioana, 'days' => 42, 'status' => 'completed', 'total' => 96 ];
foreach ( [ 300, 210, 95 ] as $days ) {
	$specs[] = [ 'person' => $vlad, 'days' => $days, 'status' => 'cancelled', 'refused' => true ];
}
$specs[] = [ 'person' => $vlad, 'days' => 20, 'status' => 'completed' ];
foreach ( range( 1, 10 ) as $n ) {
	$specs[] = [ 'person' => $maria, 'days' => $n * 45, 'status' => 'completed', 'payment' => 'card' ];
}
$specs[] = [ 'person' => $radu, 'days' => 64, 'status' => 'completed', 'refund' => 24 ];
foreach ( [ 500, 380, 260, 170, 60 ] as $days ) {
	$specs[] = [ 'person' => $cristina, 'days' => $days, 'status' => 'completed', 'payment' => 'card' ];
}

// Today's orders, newest last so they top the orders list.
$today = [
	[ 'person' => $person( 'Elena', 'Marin', 1, '0744 987 654' ), 'minutes' => 170, 'status' => 'completed', 'payment' => 'card' ],
	[ 'person' => $cristina, 'minutes' => 150, 'status' => 'completed', 'payment' => 'card' ],
	[ 'person' => $vlad, 'minutes' => 110, 'status' => 'on-hold' ],
	[ 'person' => $radu, 'minutes' => 70, 'status' => 'processing' ],
	[ 'person' => $maria, 'minutes' => 40, 'status' => 'processing', 'payment' => 'card' ],
	[ 'person' => $person( 'Andrei', 'Rusu', 1, '0721 555 010' ), 'minutes' => 22, 'status' => 'on-hold', 'total' => 212 ],
	// The current order: Ioana's email, and the phone of her old account.
	[ 'person' => array_merge( $ioana, [ 'phone' => '0740 000 123' ] ), 'minutes' => 5, 'status' => 'processing', 'total' => 74, 'hero' => true ],
];

usort( $specs, static fn( $a, $b ) => $b['days'] <=> $a['days'] );

$create = static function ( array $spec, int $timestamp ) use ( $products, $couriers ): WC_Order {
	$order = new WC_Order();
	$order->set_address( $spec['person'], 'billing' );
	$order->set_address( $spec['person'], 'shipping' );
	$order->set_created_via( 'checkout' );

	$items = array_slice( $products, 0, 1 + ( crc32( $spec['person']['email'] . $timestamp ) % 3 ) );
	foreach ( $items as $product ) {
		$order->add_product( $product, 1 );
	}

	$shipping = new WC_Order_Item_Shipping();
	$shipping->set_method_title( $spec['courier'] ?? $couriers[ crc32( $spec['person']['email'] ) % 3 ] );
	$shipping->set_method_id( 'flat_rate' );
	$shipping->set_total( '4.99' );
	$order->add_item( $shipping );

	$card = 'card' === ( $spec['payment'] ?? '' );
	$order->set_payment_method( $card ? 'stripe' : 'cod' );
	$order->set_payment_method_title( $card ? 'Card' : 'Cash on delivery' );
	$order->set_date_created( $timestamp );
	$order->calculate_totals( false );
	if ( isset( $spec['total'] ) ) {
		$order->set_total( $spec['total'] );
	}
	$order->set_status( $spec['status'] );
	if ( 'completed' === $spec['status'] ) {
		$order->set_date_completed( $timestamp + 2 * DAY_IN_SECONDS );
	}
	$order->save();

	return $order;
};

$refused = [];
$hero    = null;
foreach ( array_merge( $specs, $today ) as $spec ) {
	$timestamp = isset( $spec['minutes'] ) ? time() - $spec['minutes'] * MINUTE_IN_SECONDS : time() - $spec['days'] * DAY_IN_SECONDS - mt_rand( 0, 36000 );
	$order     = $create( $spec, $timestamp );

	if ( 'refunded' === $spec['status'] || isset( $spec['refund'] ) ) {
		wc_create_refund(
			[
				'order_id' => $order->get_id(),
				'amount'   => $spec['refund'] ?? $order->get_total(),
				'reason'   => 'Returned',
			]
		);
	}
	if ( ! empty( $spec['refused'] ) ) {
		$refused[] = $order->get_id();
	}
	if ( ! empty( $spec['hero'] ) ) {
		$hero = $order;
	}
}

foreach ( $refused as $order_id ) {
	Actions::mark_refused( wc_get_order( $order_id ), $staff['ana'] );
}

$customer_of = static fn( array $who ) => OrderLinks::customer_of( (int) wc_get_orders( [ 'billing_email' => $who['email'], 'limit' => 1, 'return' => 'ids' ] )[0] );

$ioana_id = OrderLinks::customer_of( $hero->get_id() );
Actions::add_note( $ioana_id, 'Said the first parcel came while she was abroad.', $staff['mihai'] );
Actions::flag( $ioana_id, Customers::FLAG_WATCH, 'Refused two COD parcels. Call before shipping.', 90, $staff['ana'] );
Actions::add_note( $ioana_id, 'Called her: she confirmed the locker and will pay by card at delivery.', $staff['ana'], $hero->get_id() );

Actions::flag( $customer_of( $maria ), Customers::FLAG_TRUSTED, 'Regular since 2025.', 0, $staff['mihai'] );
Actions::flag( $customer_of( $vlad ), Customers::FLAG_WATCH, 'Three refused parcels.', 0, $staff['ana'] );

// Backdate the activity, so the timeline reads like a real history: refusals a few days after each
// order, the older note at the second refusal, the flag a month ago.
global $wpdb;
$events = CustomerLens\Install\Schema::table( CustomerLens\Install\Schema::EVENTS );
foreach ( $refused as $order_id ) {
	$created = wc_get_order( $order_id )->get_date_created()->getTimestamp();
	$wpdb->update( $events, [ 'created_at' => gmdate( 'Y-m-d H:i:s', $created + 3 * DAY_IN_SECONDS ) ], [ 'dedupe_key' => 'refused:' . $order_id ] );
}
$wpdb->query( $wpdb->prepare( "UPDATE {$events} SET created_at = %s WHERE customer_id = %d AND type = 'note' AND actor_user_id = %d", gmdate( 'Y-m-d H:i:s', time() - 112 * DAY_IN_SECONDS ), $ioana_id, $staff['mihai'] ) );
$wpdb->query( $wpdb->prepare( "UPDATE {$events} SET created_at = %s WHERE customer_id = %d AND type = 'flag'", gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ), $ioana_id ) );
Customers::update( $ioana_id, [ 'flagged_at' => gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ), 'flag_expires_at' => gmdate( 'Y-m-d H:i:s', time() + 60 * DAY_IN_SECONDS ) ] );
update_option( 'woocommerce_coming_soon', 'no' );

WP_CLI::success( sprintf( 'Created %d orders. The order for the screenshots is #%d.', count( $specs ) + count( $today ), $hero->get_id() ) );
update_option( 'customer_lens_demo_order', $hero->get_id() );
