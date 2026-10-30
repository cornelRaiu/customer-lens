<?php

namespace CustomerLens\Tests\Integration;

use CustomerLens\Events\Events;
use CustomerLens\Identity\Customers;
use CustomerLens\Identity\Identities;
use CustomerLens\Identity\Merger;
use CustomerLens\Identity\Resolver;
use CustomerLens\Orders\OrderLinks;
use WC_Order;

final class ResolverTest extends TestCase {

	public function test_guest_orders_with_the_same_email_are_one_customer(): void {
		$first  = $this->order( [ 'email' => 'ioana.pop@example.com' ] );
		$second = $this->order( [ 'email' => 'Ioana.Pop@Example.COM' ] );

		$this->assertNotSame( 0, $this->customer_of( $first ) );
		$this->assertSame( $this->customer_of( $first ), $this->customer_of( $second ) );
		$this->assertSame( Resolver::METHOD_EMAIL, OrderLinks::get( $second->get_id() )->match_method );
	}

	public function test_a_new_email_with_a_known_phone_is_the_same_customer(): void {
		$first  = $this->order( [ 'email' => 'ioana@example.com', 'phone' => '0740 000 123' ] );
		$second = $this->order( [ 'email' => 'ioana.new@example.com', 'phone' => '+40740000123' ] );

		$this->assertSame( $this->customer_of( $first ), $this->customer_of( $second ) );
		$this->assertSame( Resolver::METHOD_PHONE, OrderLinks::get( $second->get_id() )->match_method );
	}

	public function test_identifiers_of_two_customers_attach_to_the_email_match_and_propose_a_merge(): void {
		$by_email = $this->order( [ 'email' => 'ioana@example.com' ] );
		$by_phone = $this->order( [ 'email' => 'other@example.com', 'phone' => '0740000123', 'first_name' => 'Ana' ] );
		$both     = $this->order( [ 'email' => 'ioana@example.com', 'phone' => '0740000123' ] );

		$email_customer = $this->customer_of( $by_email );
		$phone_customer = $this->customer_of( $by_phone );

		$this->assertNotSame( $email_customer, $phone_customer );
		$this->assertSame( $email_customer, $this->customer_of( $both ) );

		$proposals = Merger::proposals( $email_customer );
		$this->assertCount( 1, $proposals );
		$this->assertSame( $phone_customer, $proposals[0]->payload['other'] );
		$this->assertSame( [ Identities::PHONE ], $proposals[0]->payload['via'] );

		// The phone stays with the customer it belongs to until staff merge them.
		$this->assertSame( [ $phone_customer ], Identities::customers_of( Identities::find( Identities::PHONE, '+40740000123' ) ) );
	}

	public function test_a_guest_who_creates_an_account_keeps_their_history(): void {
		$user  = self::factory()->user->create();
		$guest = $this->order( [ 'email' => 'ioana@example.com' ] );
		$later = $this->order( [ 'email' => 'ioana@example.com' ], [ 'user' => $user ] );

		$this->assertSame( $this->customer_of( $guest ), $this->customer_of( $later ) );
		$this->assertSame( $user, (int) Customers::get( $this->customer_of( $later ) )->wp_user_id );

		// From now on the account alone is enough.
		$no_email = $this->order( [ 'email' => 'different@example.com' ], [ 'user' => $user ] );
		$this->assertSame( $this->customer_of( $guest ), $this->customer_of( $no_email ) );
	}

	public function test_a_phone_used_by_three_people_stops_matching(): void {
		$ana    = $this->order( [ 'email' => 'ana@example.com', 'phone' => '0212000000', 'first_name' => 'Ana', 'last_name' => 'Ionescu' ] );
		$bogdan = $this->order( [ 'email' => 'bogdan@example.com', 'phone' => '0212000000', 'first_name' => 'Bogdan', 'last_name' => 'Rusu' ] );
		$cristi = $this->order( [ 'email' => 'cristi@example.com', 'phone' => '0212000000', 'first_name' => 'Cristi', 'last_name' => 'Marin' ] );

		$this->assertSame( $this->customer_of( $ana ), $this->customer_of( $bogdan ), 'Two names can still be one household.' );
		$this->assertNotSame( $this->customer_of( $ana ), $this->customer_of( $cristi ) );
		$this->assertSame( '1', Identities::get( Identities::find( Identities::PHONE, '+40212000000' ) )->shared );
	}

	public function test_an_address_alone_never_links_two_orders(): void {
		$address = [
			'address_1' => 'Str. Memorandumului 12',
			'city'      => 'Cluj-Napoca',
			'postcode'  => '400114',
		];
		$first   = $this->order( array_merge( $address, [ 'email' => 'a@example.com' ] ) );
		$second  = $this->order( array_merge( $address, [ 'email' => 'b@example.com', 'first_name' => 'Ana' ] ) );

		$this->assertNotSame( $this->customer_of( $first ), $this->customer_of( $second ) );

		$address_id = Identities::find( Identities::ADDRESS, 'memorandumului|12|cluj napoca|400114' );
		$this->assertSame( [ $this->customer_of( $first ), $this->customer_of( $second ) ], Identities::customers_of( $address_id ) );
	}

	public function test_the_shipping_address_is_used_when_the_order_has_one(): void {
		$order = $this->order(
			[
				'email'     => 'a@example.com',
				'address_1' => 'Str. Memorandumului 12',
				'city'      => 'Cluj-Napoca',
			],
			[
				'shipping' => [
					'address_1' => 'Bd. Eroilor 5',
					'city'      => 'Brasov',
				],
			]
		);

		$kinds = Identities::matched_kinds( $this->customer_of( $order ), [ $order->get_id() ] );
		$this->assertSame( [ Identities::EMAIL, Identities::ADDRESS ], $kinds[ $order->get_id() ] );
		$this->assertNotSame( 0, Identities::find( Identities::ADDRESS, 'eroilor|5|brasov|' ) );
	}

	public function test_changing_the_billing_email_moves_the_order_and_its_events(): void {
		$other = $this->order( [ 'email' => 'radu@example.com', 'first_name' => 'Radu' ] );
		$order = $this->order( [ 'email' => 'typo@example.com' ] );
		$wrong = $this->customer_of( $order );
		wc_create_refund(
			[
				'order_id' => $order->get_id(),
				'amount'   => 10,
			]
		);

		$order = wc_get_order( $order->get_id() );
		$order->set_billing_email( 'radu@example.com' );
		$order->save();

		$this->assertSame( $this->customer_of( $other ), $this->customer_of( $order ) );
		$this->assertSame( 0, \CustomerLens\Signals\Signals::get( $wrong )['refunds_count'] );
		$this->assertSame( 1, $this->signals_of( $order )['refunds_count'] );
	}

	public function test_orders_without_any_identifier_or_still_in_draft_are_not_linked(): void {
		$empty = new WC_Order();
		$empty->set_status( 'processing' );
		$empty->save();

		$draft = $this->order( [ 'email' => 'draft@example.com' ], [ 'status' => 'checkout-draft' ] );

		$this->assertSame( 0, $this->customer_of( $empty ) );
		$this->assertSame( 0, $this->customer_of( $draft ) );

		// Submitting the draft links it.
		$draft->set_status( 'processing' );
		$draft->save();
		$this->assertNotSame( 0, $this->customer_of( $draft ) );
	}

	public function test_each_order_is_recorded_once_as_placed(): void {
		$order = $this->order( [ 'email' => 'ioana@example.com' ] );
		$order->set_billing_phone( '0740000123' );
		$order->save();

		$this->assertCount( 1, Events::of_customer( $this->customer_of( $order ), [ Events::ORDER_PLACED ] ) );
	}
}
