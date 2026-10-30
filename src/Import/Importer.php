<?php

namespace CustomerLens\Import;

use CustomerLens\Orders\OrderLinks;
use CustomerLens\Plugin;
use CustomerLens\Orders\OrderSync;
use CustomerLens\Signals\Signals;

defined( 'ABSPATH' ) || exit;

/**
 * Links the orders placed before the plugin to their customers, newest first, in batches run by Action
 * Scheduler. Every step is idempotent, so a batch that runs twice or stops halfway does no harm.
 */
final class Importer {

	public const STATE_OPTION = 'mwtcl_import';
	public const BATCH_HOOK   = 'mwtcl_import_batch';
	public const GROUP        = 'customer-lens';
	public const BATCH_SIZE   = 200;

	public const RUNNING = 'running';
	public const DONE    = 'done';

	public static function add_hooks(): void {
		add_action( self::BATCH_HOOK, [ self::class, 'run_batch' ] );
		// After Action Scheduler sets up its data store, on init priority 1.
		add_action( 'init', [ self::class, 'maybe_start' ], 20 );
		add_action( 'admin_notices', [ self::class, 'notice' ] );
	}

	/** Starts indexing the first time the plugin runs. */
	public static function maybe_start(): void {
		if ( ! get_option( self::STATE_OPTION ) ) {
			self::start();
		}
	}

	/** Starts again from the newest order. With $schedule false, the caller runs the batches itself. */
	public static function start( bool $schedule = true ): void {
		update_option(
			self::STATE_OPTION,
			[
				'status'   => self::RUNNING,
				'total'    => self::count_orders(),
				'done'     => 0,
				'cursor'   => 0,
				'started'  => time(),
				'finished' => 0,
			],
			true
		);

		as_unschedule_all_actions( self::BATCH_HOOK, [], self::GROUP );
		if ( $schedule ) {
			as_enqueue_async_action( self::BATCH_HOOK, [], self::GROUP );
		}
	}

	/** @return array{status: string, total: int, done: int, cursor: int, started: int, finished: int} */
	public static function state(): array {
		$state = get_option( self::STATE_OPTION, [] );

		return array_merge(
			[
				'status'   => '',
				'total'    => 0,
				'done'     => 0,
				'cursor'   => 0,
				'started'  => 0,
				'finished' => 0,
			],
			is_array( $state ) ? $state : []
		);
	}

	public static function is_running(): bool {
		return self::RUNNING === self::state()['status'];
	}

	public static function run_batch(): void {
		$state = self::state();
		if ( self::RUNNING !== $state['status'] ) {
			return;
		}

		$state = self::advance( $state, self::import_batch( $state['cursor'] ) );
		if ( self::RUNNING === $state['status'] ) {
			as_enqueue_async_action( self::BATCH_HOOK, [], self::GROUP );
		}
	}

	/**
	 * Saves the progress of one batch and returns the new state.
	 *
	 * @param array<string, mixed>                              $state
	 * @param array{count: int, cursor: int, finished: bool} $batch
	 * @return array<string, mixed>
	 */
	public static function advance( array $state, array $batch ): array {
		$state['done']   = min( (int) $state['total'], (int) $state['done'] + $batch['count'] );
		$state['cursor'] = $batch['cursor'];

		if ( $batch['finished'] ) {
			$state['status']   = self::DONE;
			$state['done']     = $state['total'];
			$state['finished'] = time();
		}

		update_option( self::STATE_OPTION, $state, true );

		return $state;
	}

	/**
	 * Imports up to $size orders created at or before $cursor (a timestamp; 0 for the newest).
	 *
	 * The cursor is inclusive so that orders sharing the last second are never skipped; they are imported
	 * again instead, which changes nothing.
	 *
	 * @return array{count: int, cursor: int, finished: bool}
	 */
	public static function import_batch( int $cursor, int $size = self::BATCH_SIZE ): array {
		$args = [
			'type'    => 'shop_order',
			'status'  => self::statuses(),
			'limit'   => $size,
			'orderby' => 'date',
			'order'   => 'DESC',
		];
		if ( $cursor ) {
			$args['date_created'] = '<=' . $cursor;
		}

		$orders  = wc_get_orders( $args );
		$touched = [];
		$oldest  = $cursor ? $cursor : PHP_INT_MAX;

		foreach ( $orders as $order ) {
			$customers = OrderSync::link( $order );
			if ( $customers ) {
				OrderSync::backfill( $order, $customers[0] );
				array_push( $touched, ...$customers );
			}

			$created = $order->get_date_created();
			if ( $created ) {
				$oldest = min( $oldest, $created->getTimestamp() );
			}
		}

		Signals::rebuild_many( $touched );

		// A full batch from a single second would come back forever; step past that second.
		$next = $oldest === $cursor ? $cursor - 1 : $oldest;

		return [
			'count'    => count( $orders ),
			'cursor'   => PHP_INT_MAX === $next ? 0 : $next,
			'finished' => count( $orders ) < $size,
		];
	}

	public static function count_orders(): int {
		$result = wc_get_orders(
			[
				'type'     => 'shop_order',
				'status'   => self::statuses(),
				'limit'    => 1,
				'paginate' => true,
				'return'   => 'ids',
			]
		);

		return (int) $result->total;
	}

	/** @return string[] Every order status except drafts. */
	public static function statuses(): array {
		$statuses = array_map( static fn( $status ) => preg_replace( '/^wc-/', '', $status ), array_keys( wc_get_order_statuses() ) );

		return array_values( array_diff( $statuses, OrderLinks::IGNORED ) );
	}

	public static function notice(): void {
		$state  = self::state();
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( self::RUNNING !== $state['status'] || ! $screen || ! current_user_can( Plugin::capability() ) ) {
			return;
		}
		if ( ! in_array( $screen->id, [ 'dashboard', 'plugins', 'woocommerce_page_wc-orders', 'edit-shop_order', 'shop_order', 'toplevel_page_customer-lens' ], true ) ) {
			return;
		}

		printf(
			'<div class="notice notice-info"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: 1: orders indexed so far, 2: all orders. */
					__( 'Customer Lens is indexing your orders: %1$s of %2$s. Until it finishes, order screens show part of each customer\'s history.', 'customer-lens' ),
					number_format_i18n( (int) $state['done'] ),
					number_format_i18n( (int) $state['total'] )
				)
			)
		);
	}
}
