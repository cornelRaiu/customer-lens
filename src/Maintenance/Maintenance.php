<?php

namespace CustomerLens\Maintenance;

use CustomerLens\Actions;
use CustomerLens\Db;
use CustomerLens\Events\Events;
use CustomerLens\Identity\Customers;
use CustomerLens\Import\Importer;
use CustomerLens\Install\Schema;
use CustomerLens\Orders\OrderLinks;
use CustomerLens\Orders\OrderSync;
use CustomerLens\Settings;
use CustomerLens\Signals\Signals;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The plugin's own tables, named by Schema::table().

/**
 * The nightly job: expires flags, deletes events past the retention period, and checks the orders that
 * changed in the last day, in case a hook was missed.
 */
final class Maintenance {

	public const DAILY_HOOK = 'mwtcl_daily';

	public static function add_hooks(): void {
		add_action( self::DAILY_HOOK, [ self::class, 'run' ] );
		add_action( 'admin_init', [ self::class, 'schedule' ] );
	}

	public static function schedule(): void {
		if ( ! as_has_scheduled_action( self::DAILY_HOOK, [], Importer::GROUP ) ) {
			as_schedule_recurring_action( (int) strtotime( 'tomorrow 03:00' ), DAY_IN_SECONDS, self::DAILY_HOOK, [], Importer::GROUP );
		}
	}

	public static function run(): void {
		self::expire_flags();
		self::purge();
		self::check_recent_orders( time() - DAY_IN_SECONDS );
		self::remove_orphans();
	}

	public static function expire_flags(): void {
		foreach ( Customers::expired_flags() as $customer_id ) {
			Actions::unflag( $customer_id, 0, Events::SOURCE_SYSTEM );
		}
	}

	/** Deletes events older than the retention setting. Notes stay until the customer is erased. */
	public static function purge(): void {
		$months = (int) Settings::get( 'retention_months' );

		Signals::rebuild_many( Events::purge( gmdate( 'Y-m-d H:i:s', (int) strtotime( "-{$months} months" ) ) ) );
	}

	/** Brings the orders changed since $since up to date, and every customer with new events. */
	public static function check_recent_orders( int $since ): void {
		$touched = Events::customers_since( gmdate( 'Y-m-d H:i:s', $since ) );
		$page    = 1;

		do {
			$orders = wc_get_orders(
				[
					'type'          => 'shop_order',
					'status'        => array_merge( Importer::statuses(), [ 'trash' ] ),
					'date_modified' => '>' . $since,
					'limit'         => Importer::BATCH_SIZE,
					'paged'         => $page,
					'orderby'       => 'ID',
					'order'         => 'ASC',
				]
			);

			foreach ( $orders as $order ) {
				$customer_id = OrderLinks::sync( $order );
				if ( $customer_id ) {
					$touched[] = $customer_id;
					continue;
				}
				array_push( $touched, ...OrderSync::link( $order ) );
			}

			++$page;
			$full = count( $orders ) === Importer::BATCH_SIZE;
		} while ( $full );

		Signals::rebuild_many( $touched );
	}

	/**
	 * Deletes customers left with no orders and no history, such as the one an order with a mistyped email
	 * created before the email was corrected.
	 */
	public static function remove_orphans(): void {
		global $wpdb;

		$customers = Schema::table( Schema::CUSTOMERS );
		$orders    = Schema::table( Schema::ORDER_CUSTOMERS );
		$events    = Schema::table( Schema::EVENTS );

		$orphans = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT c.id FROM {$customers} c
				WHERE c.created_at < %s
				AND NOT EXISTS (SELECT 1 FROM {$orders} o WHERE o.customer_id = c.id)
				AND NOT EXISTS (SELECT 1 FROM {$events} e WHERE e.customer_id = c.id AND e.type <> %s)
				LIMIT 1000",
				gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ),
				Events::ORDER_PLACED
			)
		);
		if ( ! $orphans ) {
			return;
		}

		$ids = Db::ids( $orphans );
		$wpdb->query( 'DELETE FROM ' . Schema::table( Schema::CUSTOMER_IDENTITIES ) . " WHERE customer_id IN ({$ids})" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- IDs from the query above, cast to integers.
		$wpdb->query( "DELETE FROM {$events} WHERE customer_id IN ({$ids})" );
		$wpdb->query( 'DELETE FROM ' . Schema::table( Schema::SIGNALS ) . " WHERE customer_id IN ({$ids})" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- IDs from the query above, cast to integers.
		$wpdb->query( "DELETE FROM {$customers} WHERE id IN ({$ids})" );
	}
}
