<?php

namespace CustomerLens\Tests\Integration;

use CustomerLens\Actions;
use CustomerLens\Admin\OrdersList;
use CustomerLens\Admin\Panel;
use CustomerLens\Identity\Customers;
use CustomerLens\Rest\Routes;
use WC_Order;
use WP_REST_Request;

final class PanelTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
	}

	public function test_the_panel_shows_the_signals_previous_orders_and_identities(): void {
		$old = $this->order( [ 'email' => 'ioana@example.com', 'phone' => '0740000123' ], [ 'status' => 'completed', 'days_ago' => 42 ] );
		Actions::mark_refused( $old, get_current_user_id() );
		$this->order( [ 'email' => 'ioana@example.com' ], [ 'status' => 'cancelled', 'days_ago' => 60 ] );
		$current = $this->order( [ 'email' => 'ioana@example.com' ] );

		$html = Panel::html( $current );

		$this->assertStringContainsString( '2nd order', $html );
		$this->assertStringContainsString( '1 refused COD', $html );
		$this->assertStringContainsString( '1 cancelled', $html );
		$this->assertStringContainsString( 'Last order 42 days ago', $html );
		$this->assertStringContainsString( 'Previous orders (2)', $html );
		$this->assertStringContainsString( '#' . $old->get_id(), $html );
		$this->assertStringContainsString( '+40 740 000 123', $html );
		$this->assertStringContainsString( 'Refused delivery of order #' . $old->get_id(), $html );
	}

	public function test_previous_orders_can_be_filtered_by_status_and_paged(): void {
		foreach ( range( 1, 12 ) as $days_ago ) {
			$this->order( [ 'email' => 'ioana@example.com' ], [ 'status' => 1 === $days_ago ? 'cancelled' : 'completed', 'days_ago' => $days_ago ] );
		}
		$current = $this->order( [ 'email' => 'ioana@example.com' ] );

		$view = Panel::view( $current, 'cancelled', 1 );
		$this->assertCount( 1, $view['rows'] );

		$view = Panel::view( $current, '', 2 );
		$this->assertSame( 2, $view['pages'] );
		$this->assertCount( 2, $view['rows'] );

		$view = Panel::view( $current, 'no-such-status', 9 );
		$this->assertSame( '', $view['status'] );
		$this->assertSame( 2, $view['page'], 'A page past the end shows the last page.' );
	}

	public function test_a_first_order_says_so(): void {
		$html = Panel::html( $this->order( [ 'email' => 'new@example.com' ] ) );

		$this->assertStringContainsString( 'First order', $html );
		$this->assertStringContainsString( 'first order with you', $html );
	}

	public function test_an_order_without_identifiers_explains_why_it_is_empty(): void {
		$order = new WC_Order();
		$order->set_status( 'processing' );
		$order->save();

		$this->assertStringContainsString( 'appears once the order has an email address', Panel::html( $order ) );
	}

	public function test_the_routes_act_and_return_the_new_panel(): void {
		$order    = $this->order( [ 'email' => 'ioana@example.com' ] );
		$customer = $this->customer_of( $order );

		$response = $this->call( 'POST', '/order/' . $order->get_id() . '/refused' );
		$this->assertSame( 200, $response->get_status() );
		$this->assertStringContainsString( '1 refused COD', $response->get_data()['html'] );

		$response = $this->call( 'POST', '/customer/' . $customer . '/flag', [ 'order' => $order->get_id(), 'status' => Customers::FLAG_WATCH, 'reason' => 'Calls first', 'expires' => 30 ] );
		$this->assertStringContainsString( 'Flagged (watch)', $response->get_data()['html'] );
		$this->assertNotNull( Customers::get( $customer )->flag_expires_at );

		$response = $this->call( 'POST', '/customer/' . $customer . '/notes', [ 'order' => $order->get_id(), 'text' => 'Confirmed the locker.' ] );
		$this->assertStringContainsString( 'Confirmed the locker.', $response->get_data()['html'] );

		$response = $this->call( 'DELETE', '/customer/' . $customer . '/flag', [ 'order' => $order->get_id() ] );
		$this->assertSame( Customers::FLAG_NONE, (int) Customers::get( $customer )->flag_status );

		$response = $this->call( 'GET', '/order/' . $order->get_id() . '/panel', [ 'status' => 'processing' ] );
		$this->assertSame( 200, $response->get_status() );
	}

	public function test_the_routes_refuse_bad_input(): void {
		$order = $this->order( [ 'email' => 'ioana@example.com' ] );

		$this->assertSame( 404, $this->call( 'GET', '/order/999999/panel' )->get_status() );
		$this->assertSame( 400, $this->call( 'POST', '/customer/' . $this->customer_of( $order ) . '/flag', [ 'order' => $order->get_id(), 'status' => 7 ] )->get_status() );
		$this->assertSame( 400, $this->call( 'POST', '/customer/' . $this->customer_of( $order ) . '/notes', [ 'order' => $order->get_id(), 'text' => '   ' ] )->get_status() );
	}

	public function test_only_store_managers_can_use_the_routes(): void {
		$order = $this->order( [ 'email' => 'ioana@example.com' ] );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'customer' ] ) );
		$this->assertSame( 403, $this->call( 'POST', '/order/' . $order->get_id() . '/refused' )->get_status() );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'shop_manager' ] ) );
		$this->assertSame( 200, $this->call( 'POST', '/order/' . $order->get_id() . '/refused' )->get_status() );
	}

	public function test_only_the_author_or_an_administrator_deletes_a_note(): void {
		$order    = $this->order( [ 'email' => 'ioana@example.com' ] );
		$manager  = self::factory()->user->create( [ 'role' => 'shop_manager' ] );
		$note     = Actions::add_note( $this->customer_of( $order ), 'Mine', $manager );
		$other    = self::factory()->user->create( [ 'role' => 'shop_manager' ] );

		wp_set_current_user( $other );
		$this->assertSame( 403, $this->call( 'DELETE', '/notes/' . $note, [ 'order' => $order->get_id() ] )->get_status() );

		wp_set_current_user( $manager );
		$this->assertSame( 200, $this->call( 'DELETE', '/notes/' . $note, [ 'order' => $order->get_id() ] )->get_status() );
	}

	public function test_the_list_column_shows_the_ordinal_and_what_needs_attention(): void {
		$this->order( [ 'email' => 'ioana@example.com' ], [ 'days_ago' => 10 ] );
		$order = $this->order( [ 'email' => 'ioana@example.com' ] );
		Actions::mark_refused( $order, 1 );

		ob_start();
		OrdersList::cell( OrdersList::COLUMN, $order );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( '2nd order', $html );
		$this->assertStringContainsString( 'mwtcl-badge--warning">1 refused COD', $html );
	}

	public function test_the_list_filter_keeps_the_matching_orders(): void {
		$refused = $this->order( [ 'email' => 'ioana@example.com' ] );
		Actions::mark_refused( $refused, 1 );
		$other = $this->order( [ 'email' => 'radu@example.com', 'first_name' => 'Radu' ] );

		$clauses = OrdersList::hpos_clauses( [ 'join' => '', 'where' => '1=1' ], new class() {
			public function get_table_name( string $table ): string {
				return 'orders';
			}
		}, [ OrdersList::FILTER_VAR => OrdersList::REFUSED ] );

		$this->assertStringContainsString( 'cl_o.order_id = orders.id', $clauses['join'] );
		$this->assertStringContainsString( 'cl_s.refused_count > 0', $clauses['where'] );

		if ( \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) {
			// The plugin adds the list hooks in wp-admin only.
			OrdersList::add_hooks();
			$ids = wc_get_orders( [ OrdersList::FILTER_VAR => OrdersList::REFUSED, 'return' => 'ids', 'limit' => -1 ] );
			$this->assertContains( $refused->get_id(), $ids );
			$this->assertNotContains( $other->get_id(), $ids );
		}
	}

	/** @param array<string, mixed> $params */
	private function call( string $method, string $path, array $params = [] ) {
		$request = new WP_REST_Request( $method, '/' . Routes::NAMESPACE . $path );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		return rest_do_request( $request );
	}
}
