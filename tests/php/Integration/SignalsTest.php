<?php

namespace CustomerLens\Tests\Integration;

use CustomerLens\Actions;
use CustomerLens\Orders\OrderLinks;
use CustomerLens\Signals\Signals;

final class SignalsTest extends TestCase {

	public function test_cancelled_and_failed_orders_do_not_count_as_orders(): void {
		$this->order( [ 'email' => 'ioana@example.com' ], [ 'status' => 'completed', 'days_ago' => 30 ] );
		$this->order( [ 'email' => 'ioana@example.com' ], [ 'status' => 'cancelled', 'days_ago' => 20 ] );
		$order = $this->order( [ 'email' => 'ioana@example.com' ], [ 'status' => 'failed', 'days_ago' => 10 ] );

		$signals = $this->signals_of( $order );
		$this->assertSame( 1, $signals['orders_count'] );
		$this->assertSame( 1, $signals['cancels_count'] );
		$this->assertSame( 1, $signals['failed_payments_count'] );
	}

	public function test_an_order_restored_from_cancelled_is_no_longer_counted_as_cancelled(): void {
		$order = $this->order( [ 'email' => 'ioana@example.com' ] );
		$order->update_status( 'cancelled' );
		$this->assertSame( 1, $this->signals_of( $order )['cancels_count'] );

		$order->update_status( 'processing' );
		$this->assertSame( 0, $this->signals_of( $order )['cancels_count'] );
		$this->assertSame( 1, $this->signals_of( $order )['orders_count'] );
	}

	public function test_partial_refunds_are_counted_one_by_one_with_their_amounts(): void {
		$order = $this->order( [ 'email' => 'ioana@example.com' ], [ 'total' => 100 ] );
		wc_create_refund( [ 'order_id' => $order->get_id(), 'amount' => 10 ] );
		wc_create_refund( [ 'order_id' => $order->get_id(), 'amount' => 28 ] );

		$signals = $this->signals_of( $order );
		$this->assertSame( 2, $signals['refunds_count'] );
		$this->assertSame( 38.0, $signals['refunds_total'] );
		$this->assertSame( 62.0, $signals['net_total'] );
	}

	public function test_a_full_refund_counts_once(): void {
		$order = $this->order( [ 'email' => 'ioana@example.com' ], [ 'total' => 54 ] );
		wc_create_refund( [ 'order_id' => $order->get_id(), 'amount' => 54 ] );

		$this->assertSame( 'refunded', wc_get_order( $order->get_id() )->get_status() );
		$this->assertSame( 1, $this->signals_of( $order )['refunds_count'] );
		$this->assertSame( 54.0, $this->signals_of( $order )['refunds_total'] );
	}

	public function test_an_order_set_to_refunded_by_hand_counts_its_total_once(): void {
		$order = $this->order( [ 'email' => 'ioana@example.com' ], [ 'total' => 40 ] );
		$order->update_status( 'refunded' );
		$order->update_status( 'processing' );
		$order->update_status( 'refunded' );

		$this->assertSame( 1, $this->signals_of( $order )['refunds_count'] );
		$this->assertSame( 40.0, $this->signals_of( $order )['refunds_total'] );
	}

	public function test_deleting_a_refund_takes_it_off_the_count(): void {
		$order  = $this->order( [ 'email' => 'ioana@example.com' ] );
		$refund = wc_create_refund( [ 'order_id' => $order->get_id(), 'amount' => 10 ] );

		// As the order screen deletes a refund. delete() resets the object's ID, so keep it first.
		$refund_id = $refund->get_id();
		$refund->delete( true );
		do_action( 'woocommerce_refund_deleted', $refund_id, $order->get_id() );

		$this->assertSame( 0, $this->signals_of( $order )['refunds_count'] );
	}

	public function test_a_refused_delivery_marked_by_hand_counts_until_it_is_undone(): void {
		$order = $this->order( [ 'email' => 'ioana@example.com' ] );

		$this->assertTrue( Actions::mark_refused( $order, 1 ) );
		Actions::mark_refused( $order, 1 );
		$this->assertSame( 1, $this->signals_of( $order )['refused_count'] );

		$this->assertTrue( Actions::unmark_refused( $order ) );
		$this->assertSame( 0, $this->signals_of( $order )['refused_count'] );
	}

	public function test_trashed_orders_do_not_count(): void {
		$kept     = $this->order( [ 'email' => 'ioana@example.com' ] );
		$order    = $this->order( [ 'email' => 'ioana@example.com' ] );
		$order_id = $order->get_id();
		$order->delete();

		$this->assertSame( 'trash', OrderLinks::get( $order_id )->status );
		$this->assertSame( 1, $this->signals_of( $kept )['orders_count'] );
	}

	public function test_deleted_orders_are_forgotten(): void {
		$kept  = $this->order( [ 'email' => 'ioana@example.com' ] );
		$order    = $this->order( [ 'email' => 'ioana@example.com' ] );
		$order_id = $order->get_id();
		$order->delete( true );

		$this->assertSame( 0, OrderLinks::customer_of( $order_id ) );
		$this->assertSame( 1, $this->signals_of( $kept )['orders_count'] );
	}

	public function test_first_and_last_order_dates_come_from_counted_orders(): void {
		$first = $this->order( [ 'email' => 'ioana@example.com' ], [ 'days_ago' => 60 ] );
		$this->order( [ 'email' => 'ioana@example.com' ], [ 'days_ago' => 30 ] );
		$this->order( [ 'email' => 'ioana@example.com' ], [ 'status' => 'cancelled', 'days_ago' => 5 ] );

		$signals = $this->signals_of( $first );
		$this->assertSame( gmdate( 'Y-m-d', time() - 60 * DAY_IN_SECONDS ), substr( $signals['first_order_at'], 0, 10 ) );
		$this->assertSame( gmdate( 'Y-m-d', time() - 30 * DAY_IN_SECONDS ), substr( $signals['last_order_at'], 0, 10 ) );
	}

	public function test_positions_place_each_order_among_the_customers_orders(): void {
		$first  = $this->order( [ 'email' => 'ioana@example.com' ], [ 'days_ago' => 30 ] );
		$this->order( [ 'email' => 'ioana@example.com' ], [ 'status' => 'cancelled', 'days_ago' => 20 ] );
		$third  = $this->order( [ 'email' => 'ioana@example.com' ], [ 'days_ago' => 10 ] );

		$positions = OrderLinks::positions( [ $first->get_id(), $third->get_id() ] );
		$this->assertSame( 1, $positions[ $first->get_id() ]['ordinal'] );
		$this->assertSame( 2, $positions[ $third->get_id() ]['ordinal'], 'The cancelled order does not count.' );
	}

	public function test_a_rebuild_from_scratch_gives_the_same_signals(): void {
		$order = $this->order( [ 'email' => 'ioana@example.com' ], [ 'total' => 80 ] );
		$this->order( [ 'email' => 'ioana@example.com' ], [ 'status' => 'cancelled' ] );
		wc_create_refund( [ 'order_id' => $order->get_id(), 'amount' => 20 ] );
		Actions::mark_refused( $order, 1 );

		$live = $this->signals_of( $order );
		Signals::rebuild( $this->customer_of( $order ) );
		$rebuilt = $this->signals_of( $order );

		unset( $live['computed_at'], $rebuilt['computed_at'] );
		$this->assertSame( $live, $rebuilt );
	}
}
