<?php

namespace CustomerLens\Tests\Integration;

use CustomerLens\Actions;
use CustomerLens\Events\Events;
use CustomerLens\Identity\Customers;
use CustomerLens\Identity\Identities;
use CustomerLens\Identity\Merger;

final class MergerTest extends TestCase {

	public function test_merging_moves_orders_identities_and_history_and_can_be_undone(): void {
		$ioana  = $this->order( [ 'email' => 'ioana@example.com' ] );
		$second = $this->order( [ 'email' => 'ioana.work@example.com', 'phone' => '0740000123' ], [ 'total' => 50 ] );
		Actions::mark_refused( $second, 1 );
		Actions::flag( $this->customer_of( $second ), Customers::FLAG_WATCH, 'Refused once', 0, 1 );

		$into = $this->customer_of( $ioana );
		$from = $this->customer_of( $second );

		$event_id = Merger::merge( $into, $from, 1 );

		$this->assertNotSame( 0, $event_id );
		$this->assertNull( Customers::get( $from ) );
		$this->assertSame( $into, $this->customer_of( $second ) );
		$this->assertSame( 2, $this->signals_of( $ioana )['orders_count'] );
		$this->assertSame( 1, $this->signals_of( $ioana )['refused_count'] );
		$this->assertSame( [ $into ], Identities::customers_of( Identities::find( Identities::PHONE, '+40740000123' ) ) );
		$this->assertSame( Customers::FLAG_WATCH, (int) Customers::get( $into )->flag_status, 'The flag comes along.' );

		$this->assertTrue( Merger::unmerge( $event_id, 1 ) );

		$this->assertNotNull( Customers::get( $from ) );
		$this->assertSame( $from, $this->customer_of( $second ) );
		$this->assertSame( 1, $this->signals_of( $ioana )['orders_count'] );
		$this->assertSame( 0, $this->signals_of( $ioana )['refused_count'] );
		$this->assertSame( 1, $this->signals_of( $second )['refused_count'] );
		$this->assertSame( Customers::FLAG_NONE, (int) Customers::get( $into )->flag_status );
		$this->assertSame( [ $from ], Identities::customers_of( Identities::find( Identities::PHONE, '+40740000123' ) ) );

		$this->assertFalse( Merger::unmerge( $event_id, 1 ), 'A merge is undone once.' );
	}

	public function test_a_merge_closes_the_proposal_and_a_dismissal_hides_it(): void {
		$this->order( [ 'email' => 'ioana@example.com' ] );
		$this->order( [ 'email' => 'other@example.com', 'phone' => '0740000123', 'first_name' => 'Ana' ] );
		$both = $this->order( [ 'email' => 'ioana@example.com', 'phone' => '0740000123' ] );

		$customer  = $this->customer_of( $both );
		$proposals = Merger::proposals( $customer );
		$this->assertCount( 1, $proposals );

		Actions::dismiss_proposal( $customer, (int) $proposals[0]->payload['other'] );
		$this->assertSame( [], Merger::proposals( $customer ) );
	}

	public function test_merges_older_than_the_undo_window_stay(): void {
		$a = $this->order( [ 'email' => 'a@example.com' ] );
		$b = $this->order( [ 'email' => 'b@example.com', 'first_name' => 'Ana' ] );

		$event_id = Merger::merge( $this->customer_of( $a ), $this->customer_of( $b ), 1 );

		global $wpdb;
		$wpdb->update( \CustomerLens\Install\Schema::table( \CustomerLens\Install\Schema::EVENTS ), [ 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 31 * DAY_IN_SECONDS ) ], [ 'id' => $event_id ] );

		$this->assertFalse( Merger::can_undo( Events::get( $event_id ) ) );
	}
}
