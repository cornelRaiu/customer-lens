<?php

namespace CustomerLens\Tests\Integration;

use CustomerLens\Import\Importer;
use CustomerLens\Install\Schema;
use CustomerLens\Orders\OrderSync;
use WC_Order;

final class ImporterTest extends TestCase {

	public function test_orders_placed_before_the_plugin_are_linked_in_batches_newest_first(): void {
		$orders = $this->without_plugin(
			function () {
				$orders = [];
				foreach ( range( 5, 1 ) as $days_ago ) {
					$orders[] = $this->order( [ 'email' => 'ioana@example.com' ], [ 'days_ago' => $days_ago ] );
				}
				$orders[] = $this->order( [ 'email' => 'radu@example.com', 'first_name' => 'Radu' ], [ 'days_ago' => 3 ] );
				return $orders;
			}
		);

		foreach ( $orders as $order ) {
			$this->assertSame( 0, $this->customer_of( $order ) );
		}

		$first = Importer::import_batch( 0, 2 );
		$this->assertSame( 2, $first['count'] );
		$this->assertFalse( $first['finished'] );
		$this->assertNotSame( 0, $this->customer_of( $orders[4] ), 'The newest order comes first.' );
		$this->assertSame( 0, $this->customer_of( $orders[0] ) );

		$cursor = $first['cursor'];
		do {
			$batch  = Importer::import_batch( $cursor, 2 );
			$cursor = $batch['cursor'];
		} while ( ! $batch['finished'] );

		foreach ( $orders as $order ) {
			$this->assertNotSame( 0, $this->customer_of( $order ) );
		}
		$this->assertSame( 5, $this->signals_of( $orders[0] )['orders_count'] );
		$this->assertNotSame( $this->customer_of( $orders[0] ), $this->customer_of( $orders[5] ) );
	}

	public function test_refunds_and_statuses_from_before_the_plugin_are_counted(): void {
		$order = $this->without_plugin(
			function () {
				$order = $this->order( [ 'email' => 'ioana@example.com' ], [ 'total' => 90 ] );
				wc_create_refund(
					[
						'order_id' => $order->get_id(),
						'amount'   => 30,
					]
				);
				$this->order( [ 'email' => 'ioana@example.com' ], [ 'status' => 'failed' ] );
				$this->order( [ 'email' => 'ioana@example.com' ], [ 'status' => 'cancelled' ] );
				return $order;
			}
		);

		Importer::import_batch( 0 );

		$signals = $this->signals_of( $order );
		$this->assertSame( 1, $signals['orders_count'] );
		$this->assertSame( 1, $signals['refunds_count'] );
		$this->assertSame( 30.0, $signals['refunds_total'] );
		$this->assertSame( 1, $signals['failed_payments_count'] );
		$this->assertSame( 1, $signals['cancels_count'] );
	}

	public function test_importing_again_changes_nothing(): void {
		$this->without_plugin(
			function () {
				$order = $this->order( [ 'email' => 'ioana@example.com', 'phone' => '0740000123' ] );
				wc_create_refund(
					[
						'order_id' => $order->get_id(),
						'amount'   => 10,
					]
				);
				$this->order( [ 'email' => 'ioana@example.com' ], [ 'status' => 'failed' ] );
			}
		);

		Importer::import_batch( 0 );
		$before = $this->row_counts();
		Importer::import_batch( 0 );

		$this->assertSame( $before, $this->row_counts() );
	}

	public function test_progress_is_saved_and_the_run_finishes(): void {
		update_option(
			Importer::STATE_OPTION,
			[
				'status' => Importer::RUNNING,
				'total'  => 10,
				'done'   => 0,
			]
		);

		$state = Importer::advance(
			Importer::state(),
			[
				'count'    => 4,
				'cursor'   => 123,
				'finished' => false,
			]
		);
		$this->assertSame( 4, $state['done'] );
		$this->assertTrue( Importer::is_running() );

		$state = Importer::advance(
			$state,
			[
				'count'    => 3,
				'cursor'   => 99,
				'finished' => true,
			]
		);
		$this->assertSame( Importer::DONE, $state['status'] );
		$this->assertSame( 10, $state['done'] );
		$this->assertFalse( Importer::is_running() );
	}

	/**
	 * Runs $callback with the plugin's order hooks off, as if the orders were placed before it.
	 *
	 * @return mixed What $callback returns.
	 */
	private function without_plugin( callable $callback ) {
		$hooks = [
			'woocommerce_before_order_object_save' => 'before_save',
			'woocommerce_after_order_object_save'  => 'after_save',
			'woocommerce_order_status_changed'     => 'status_changed',
			'woocommerce_order_refunded'           => 'refunded',
		];
		foreach ( $hooks as $hook => $method ) {
			remove_action( $hook, [ OrderSync::class, $method ] );
		}

		try {
			return $callback();
		} finally {
			OrderSync::add_hooks();
		}
	}

	/** @return array<string, int> */
	private function row_counts(): array {
		global $wpdb;

		$counts = [];
		foreach ( Schema::tables() as $name ) {
			$table           = Schema::table( $name );
			$counts[ $name ] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		}
		unset( $counts[ Schema::SIGNALS ] );

		return $counts;
	}

	public function test_an_order_built_without_any_identifier_is_skipped(): void {
		$order = $this->without_plugin(
			static function () {
				$order = new WC_Order();
				$order->set_status( 'completed' );
				$order->save();
				return $order;
			}
		);

		$batch = Importer::import_batch( 0 );

		$this->assertSame( 1, $batch['count'] );
		$this->assertSame( 0, $this->customer_of( $order ) );
	}
}
