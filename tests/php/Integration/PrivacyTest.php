<?php

namespace CustomerLens\Tests\Integration;

use CustomerLens\Actions;
use CustomerLens\Events\Events;
use CustomerLens\Identity\Customers;
use CustomerLens\Identity\Identities;
use CustomerLens\Privacy\Privacy;

final class PrivacyTest extends TestCase {

	public function test_the_export_lists_identities_flag_and_history(): void {
		$order    = $this->order( [ 'email' => 'ioana@example.com', 'phone' => '0740000123' ] );
		$customer = $this->customer_of( $order );
		Actions::flag( $customer, Customers::FLAG_WATCH, 'Calls before shipping', 0, 1 );
		Actions::add_note( $customer, 'Confirmed the locker.', 1 );

		$export = Privacy::export( 'ioana@example.com' );
		$this->assertTrue( $export['done'] );

		$values = [];
		foreach ( $export['data'] as $item ) {
			foreach ( $item['data'] as $field ) {
				$values[] = (string) $field['value'];
			}
		}

		$this->assertContains( 'ioana@example.com', $values );
		$this->assertContains( '+40 740 000 123', $values );
		$this->assertContains( 'Watch Calls before shipping', $values );
		$this->assertContains( 'Confirmed the locker.', $values );
	}

	public function test_erasure_by_email_or_phone_leaves_no_identifier_behind(): void {
		$order    = $this->order( [ 'email' => 'ioana@example.com', 'phone' => '0740000123' ] );
		$customer = $this->customer_of( $order );
		Actions::add_note( $customer, 'Refused once.', 1 );
		Actions::mark_refused( $order, 1 );

		$this->assertSame( [ $customer ], Privacy::customers_of( '+40 740 000 123' ) );

		$result = Privacy::erase( 'ioana@example.com' );

		$this->assertTrue( $result['items_removed'] );
		$this->assertSame( 0, Identities::find( Identities::EMAIL, 'ioana@example.com' ) );
		$this->assertSame( 0, Identities::find( Identities::PHONE, '+40740000123' ) );
		$this->assertSame( [], Events::of_customer( $customer, [ Events::NOTE, Events::REFUSED_DELIVERY, Events::ORDER_PLACED ] ) );
		$this->assertSame( [], Privacy::customers_of( 'ioana@example.com' ) );

		// The order itself still counts for the store, without anything that identifies the person.
		$this->assertSame( 1, $this->signals_of( $order )['orders_count'] );
		$this->assertSame( 0, $this->signals_of( $order )['refused_count'] );
	}

	public function test_an_unknown_address_erases_nothing(): void {
		$this->assertFalse( Privacy::erase( 'nobody@example.com' )['items_removed'] );
	}

	public function test_the_policy_text_names_the_retention_period(): void {
		$this->assertStringContainsString( '36 months', Privacy::policy_text() );
	}
}
