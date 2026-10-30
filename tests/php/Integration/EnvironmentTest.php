<?php

namespace CustomerLens\Tests\Integration;

use Automattic\WooCommerce\Utilities\OrderUtil;
use WP_UnitTestCase;

/**
 * Guards the CI matrix: a run that asks for legacy order storage must not quietly test HPOS, or the reverse.
 */
final class EnvironmentTest extends WP_UnitTestCase {

	public function test_orders_use_the_storage_this_run_asked_for(): void {
		$this->assertSame( '0' !== getenv( 'MWTCL_TESTS_HPOS' ), OrderUtil::custom_orders_table_usage_is_enabled() );
	}

	public function test_orders_are_saved_and_read_back(): void {
		$order = wc_create_order();
		$order->set_billing_email( 'ana@example.org' );
		$order->save();

		$this->assertSame( 'ana@example.org', wc_get_order( $order->get_id() )->get_billing_email() );
	}
}
