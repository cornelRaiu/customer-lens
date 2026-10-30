<?php

namespace CustomerLens\Tests\Integration;

use CustomerLens\Orders\OrderLinks;
use CustomerLens\Signals\Signals;
use WC_Order;
use WP_UnitTestCase;

abstract class TestCase extends WP_UnitTestCase {

	/**
	 * Places an order the way checkout would: billing details first, then one save.
	 *
	 * @param array<string, string> $billing Billing fields without the billing_ prefix.
	 * @param array{status?: string, total?: float, user?: int, days_ago?: int, shipping?: array<string, string>} $args
	 */
	protected function order( array $billing, array $args = [] ): WC_Order {
		$order = new WC_Order();
		$order->set_address(
			array_merge(
				[
					'first_name' => 'Ioana',
					'last_name'  => 'Pop',
					'country'    => 'RO',
				],
				$billing
			),
			'billing'
		);
		if ( isset( $args['shipping'] ) ) {
			$order->set_address( $args['shipping'], 'shipping' );
		}
		$order->set_customer_id( $args['user'] ?? 0 );
		$order->set_total( $args['total'] ?? 100 );
		$order->set_status( $args['status'] ?? 'processing' );
		if ( isset( $args['days_ago'] ) ) {
			$order->set_date_created( time() - $args['days_ago'] * DAY_IN_SECONDS );
		}
		$order->save();

		return $order;
	}

	protected function customer_of( WC_Order $order ): int {
		return OrderLinks::customer_of( $order->get_id() );
	}

	/** @return array<string, mixed> */
	protected function signals_of( WC_Order $order ): array {
		return Signals::get( $this->customer_of( $order ) ) ?? [];
	}
}
