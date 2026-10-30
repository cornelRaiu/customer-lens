<?php

namespace CustomerLens\Tests\Integration;

use CustomerLens\Actions;
use CustomerLens\Events\Events;
use CustomerLens\Identity\Customers;
use CustomerLens\Install\Schema;
use CustomerLens\Maintenance\Maintenance;
use CustomerLens\Orders\OrderLinks;
use CustomerLens\Settings;

final class MaintenanceTest extends TestCase {

	public function test_expired_flags_are_removed_and_recorded(): void {
		$customer = $this->customer_of( $this->order( [ 'email' => 'ioana@example.com' ] ) );
		Actions::flag( $customer, Customers::FLAG_WATCH, 'Calls first', 30, 1 );
		Customers::update( $customer, [ 'flag_expires_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ] );

		Maintenance::expire_flags();

		$this->assertSame( Customers::FLAG_NONE, (int) Customers::get( $customer )->flag_status );
		$unflag = Events::of_customer( $customer, [ Events::UNFLAG ] );
		$this->assertSame( Events::SOURCE_SYSTEM, $unflag[0]->source );
	}

	public function test_events_past_retention_are_purged_but_notes_stay(): void {
		$order    = $this->order( [ 'email' => 'ioana@example.com' ] );
		$customer = $this->customer_of( $order );
		Actions::mark_refused( $order, 1 );
		Actions::add_note( $customer, 'Old note', 1 );
		$this->backdate_events( $customer, 40 );

		update_option( Settings::OPTION, [ 'retention_months' => 36 ] );
		Maintenance::purge();

		$this->assertSame( [], Events::of_customer( $customer, [ Events::REFUSED_DELIVERY ] ) );
		$this->assertCount( 1, Events::of_customer( $customer, [ Events::NOTE ] ) );
		$this->assertSame( 0, $this->signals_of( $order )['refused_count'] );
	}

	public function test_a_missed_status_change_is_caught_the_next_night(): void {
		$order = $this->order( [ 'email' => 'ioana@example.com' ] );
		OrderLinks::set_status( $order->get_id(), 'processing' );

		global $wpdb;
		$wpdb->update( Schema::table( Schema::ORDER_CUSTOMERS ), [ 'status' => 'pending' ], [ 'order_id' => $order->get_id() ] );

		Maintenance::check_recent_orders( time() - DAY_IN_SECONDS );

		$this->assertSame( 'processing', OrderLinks::get( $order->get_id() )->status );
	}

	public function test_customers_left_without_orders_are_removed(): void {
		$order = $this->order( [ 'email' => 'typo@example.com' ] );
		$typo  = $this->customer_of( $order );
		$this->order( [ 'email' => 'ioana@example.com' ] );

		$order->set_billing_email( 'ioana@example.com' );
		$order->save();
		Customers::update( $typo, [] );

		global $wpdb;
		$wpdb->update( Schema::table( Schema::CUSTOMERS ), [ 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS ) ], [ 'id' => $typo ] );

		Maintenance::remove_orphans();

		$this->assertNull( Customers::get( $typo ) );
		$this->assertNotNull( Customers::get( $this->customer_of( $order ) ) );
	}

	public function test_saving_one_settings_tab_keeps_the_other_tabs_values(): void {
		update_option( Settings::OPTION, Settings::save( [ 'delete_data' => '1' ] ) );
		update_option( Settings::OPTION, Settings::save( [ 'refunds_warning' => '3' ] ) );

		$this->assertTrue( Settings::get( 'delete_data' ) );
		$this->assertSame( 3, Settings::get( 'refunds_warning' ) );
	}

	private function backdate_events( int $customer_id, int $months ): void {
		global $wpdb;

		$wpdb->update( Schema::table( Schema::EVENTS ), [ 'created_at' => gmdate( 'Y-m-d H:i:s', (int) strtotime( "-{$months} months" ) ) ], [ 'customer_id' => $customer_id ] );
	}
}
