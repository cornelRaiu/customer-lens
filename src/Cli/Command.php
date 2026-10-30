<?php

namespace CustomerLens\Cli;

use CustomerLens\Import\Importer;
use CustomerLens\Install\Schema;
use CustomerLens\Privacy\Privacy;
use CustomerLens\Signals\Signals;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * Indexes orders, rebuilds signals and erases a customer's data.
 */
final class Command {

	/**
	 * Links existing orders to customers, newest first, in the foreground.
	 *
	 * Continues an indexing run that is under way. Any batches waiting in Action Scheduler are cancelled,
	 * so the two do not run side by side.
	 *
	 * ## OPTIONS
	 *
	 * [--restart]
	 * : Start again from the newest order, even when indexing finished.
	 *
	 * [--batch=<size>]
	 * : Orders per batch.
	 * ---
	 * default: 200
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp customer-lens import
	 *     wp customer-lens import --restart
	 *
	 * @param string[]              $args
	 * @param array<string, string> $assoc_args
	 */
	public function import( array $args, array $assoc_args ): void {
		$state = Importer::state();
		if ( isset( $assoc_args['restart'] ) || Importer::RUNNING !== $state['status'] ) {
			if ( Importer::DONE === $state['status'] && ! isset( $assoc_args['restart'] ) ) {
				WP_CLI::success( 'Every order is indexed already. Use --restart to index them again.' );
				return;
			}
			Importer::start( false );
			$state = Importer::state();
		} else {
			as_unschedule_all_actions( Importer::BATCH_HOOK, [], Importer::GROUP );
		}

		$size     = max( 1, (int) ( $assoc_args['batch'] ?? Importer::BATCH_SIZE ) );
		$progress = \WP_CLI\Utils\make_progress_bar( 'Indexing orders', (int) $state['total'] );
		$started  = microtime( true );

		while ( Importer::RUNNING === $state['status'] ) {
			$batch = Importer::import_batch( (int) $state['cursor'], $size );
			$state = Importer::advance( $state, $batch );
			$progress->tick( $batch['count'] );

			// Each batch loads hundreds of orders; let their caches go.
			wp_cache_flush_runtime();
		}

		$progress->finish();
		WP_CLI::success( sprintf( 'Indexed %d orders in %.1f seconds, peak memory %d MB.', $state['total'], microtime( true ) - $started, memory_get_peak_usage( true ) / MB_IN_BYTES ) );
	}

	/**
	 * Shows indexing progress and how much the plugin stores.
	 */
	public function status(): void {
		global $wpdb;

		$state = Importer::state();
		$rows  = [
			[
				'item'  => 'indexing',
				'value' => $state['status'] ? $state['status'] : 'not started',
			],
			[
				'item'  => 'orders indexed',
				'value' => $state['done'] . ' of ' . $state['total'],
			],
		];

		foreach ( Schema::tables() as $name ) {
			$table  = Schema::table( $name );
			$rows[] = [
				'item'  => $name,
				'value' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The plugin's own tables.
			];
		}

		\WP_CLI\Utils\format_items( 'table', $rows, [ 'item', 'value' ] );
	}

	/**
	 * Recomputes the signals of one customer, or of all of them.
	 *
	 * ## OPTIONS
	 *
	 * [--customer=<id>]
	 * : Only this customer.
	 *
	 * ## EXAMPLES
	 *
	 *     wp customer-lens rebuild
	 *     wp customer-lens rebuild --customer=1204
	 *
	 * @param string[]              $args
	 * @param array<string, string> $assoc_args
	 */
	public function rebuild( array $args, array $assoc_args ): void {
		if ( isset( $assoc_args['customer'] ) ) {
			Signals::rebuild( (int) $assoc_args['customer'] );
			WP_CLI::success( 'Rebuilt the signals of customer #' . (int) $assoc_args['customer'] . '.' );
			return;
		}

		$after = 0;
		$count = 0;
		while ( $ids = Signals::all_customer_ids( $after, 500 ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
			Signals::rebuild_many( $ids );
			$after  = (int) end( $ids );
			$count += count( $ids );
			wp_cache_flush_runtime();
		}

		WP_CLI::success( sprintf( 'Rebuilt the signals of %d customers.', $count ) );
	}

	/**
	 * Erases everything stored about the customers of an email address or phone number: their identities,
	 * events and notes. The orders themselves stay; WooCommerce manages them.
	 *
	 * ## OPTIONS
	 *
	 * <identifier>
	 * : An email address or a phone number.
	 *
	 * [--yes]
	 * : Do not ask for confirmation.
	 *
	 * ## EXAMPLES
	 *
	 *     wp customer-lens erase ioana.pop@example.com
	 *     wp customer-lens erase "+40 740 000 123" --yes
	 *
	 * @param string[]              $args
	 * @param array<string, string> $assoc_args
	 */
	public function erase( array $args, array $assoc_args ): void {
		$customers = Privacy::customers_of( $args[0] );
		if ( ! $customers ) {
			WP_CLI::error( 'No customer has that email address or phone number.' );
		}

		WP_CLI::confirm( sprintf( 'Erase customer #%s?', implode( ', #', $customers ) ), $assoc_args );

		foreach ( $customers as $customer_id ) {
			Privacy::erase_customer( $customer_id );
		}

		WP_CLI::success( sprintf( 'Erased %d customer(s).', count( $customers ) ) );
	}
}
