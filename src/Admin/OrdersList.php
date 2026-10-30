<?php

namespace CustomerLens\Admin;

use CustomerLens\Identity\Customers;
use CustomerLens\Install\Schema;
use CustomerLens\Orders\OrderLinks;
use CustomerLens\Plugin;
use CustomerLens\Settings;
use CustomerLens\Signals\Badges;
use CustomerLens\Signals\Signals;
use WC_Order;
use WP_Query;

defined( 'ABSPATH' ) || exit;

/**
 * The Customer Lens column and filter on the WooCommerce orders list, for HPOS and legacy storage.
 */
final class OrdersList {

	public const COLUMN     = 'customer_lens';
	public const FILTER_VAR = 'mwtcl_filter';

	public const FIRST_TIME = 'first_time';
	public const FLAGGED    = 'flagged';
	public const REFUNDS    = 'refunds';
	public const REFUSED    = 'refused';

	/** @var array<int, array<string, mixed>>|null The current list page, by order ID. */
	private static ?array $rows = null;

	/** @var int[] Orders of the current list page, from the list query. */
	private static array $page_ids = [];

	public static function add_hooks(): void {
		add_filter( 'manage_woocommerce_page_wc-orders_columns', [ self::class, 'columns' ] );
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', [ self::class, 'cell' ], 10, 2 );
		add_action( 'woocommerce_order_list_table_restrict_manage_orders', [ self::class, 'dropdown' ] );
		add_filter( 'woocommerce_order_list_table_prepare_items_query_args', [ self::class, 'query_args' ] );
		add_filter( 'woocommerce_orders_table_query_clauses', [ self::class, 'hpos_clauses' ], 10, 3 );
		add_filter( 'woocommerce_order_query', [ self::class, 'remember_page' ], 10, 2 );

		add_filter( 'manage_edit-shop_order_columns', [ self::class, 'columns' ] );
		add_action( 'manage_shop_order_posts_custom_column', [ self::class, 'cell' ], 10, 2 );
		add_action( 'restrict_manage_posts', [ self::class, 'legacy_dropdown' ] );
		add_filter( 'posts_clauses', [ self::class, 'legacy_clauses' ], 10, 2 );
	}

	/** @return array<string, string> Filter => label. */
	public static function filters(): array {
		return [
			self::REFUSED    => __( 'Has refused deliveries', 'customer-lens' ),
			self::REFUNDS    => __( 'Has refunds', 'customer-lens' ),
			self::FLAGGED    => __( 'Flagged: watch', 'customer-lens' ),
			self::FIRST_TIME => __( 'First-time customers', 'customer-lens' ),
		];
	}

	public static function columns( $columns ): array {
		$columns = (array) $columns;
		if ( ! current_user_can( Plugin::capability() ) ) {
			return $columns;
		}

		$added = [];
		foreach ( $columns as $key => $label ) {
			$added[ $key ] = $label;
			if ( 'order_status' === $key ) {
				$added[ self::COLUMN ] = __( 'Customer Lens', 'customer-lens' );
			}
		}
		if ( ! isset( $added[ self::COLUMN ] ) ) {
			$added[ self::COLUMN ] = __( 'Customer Lens', 'customer-lens' );
		}

		return $added;
	}

	public static function cell( $column, $order_or_id ): void {
		if ( self::COLUMN !== $column ) {
			return;
		}

		$order_id = $order_or_id instanceof WC_Order ? $order_or_id->get_id() : (int) $order_or_id;
		self::load( $order_id );

		$row = self::$rows[ $order_id ] ?? null;
		if ( ! $row ) {
			printf( '<span class="mwtcl-muted" title="%s">—</span>', esc_attr__( 'Not indexed yet', 'customer-lens' ) );
			return;
		}

		$signals  = null === $row['computed_at'] ? null : self::signals( $row );
		$customer = (object) [ 'flag_status' => (int) $row['flag_status'] ];
		$badges   = Badges::summary( Badges::for_order( $signals, $customer, (int) $row['ordinal'], Settings::all(), null, false ) );

		foreach ( $badges as $badge ) {
			printf( '<span class="mwtcl-badge mwtcl-badge--%s">%s</span>', esc_attr( $badge['level'] ), esc_html( $badge['label'] ) );
		}
	}

	/** Notes the orders of the list page when the HPOS list table queries them. */
	public static function remember_page( $results, $args ) {
		if ( ! self::$page_ids && ! empty( $args['paginate'] ) && is_admin() && did_action( 'load-woocommerce_page_wc-orders' ) && isset( $results->orders ) ) {
			foreach ( (array) $results->orders as $order ) {
				self::$page_ids[] = $order instanceof WC_Order ? $order->get_id() : (int) $order;
			}
		}

		return $results;
	}

	public static function dropdown( $order_type ): void {
		if ( 'shop_order' === $order_type ) {
			self::print_dropdown();
		}
	}

	public static function legacy_dropdown( $post_type ): void {
		if ( 'shop_order' === $post_type ) {
			self::print_dropdown();
		}
	}

	public static function query_args( $args ): array {
		$args   = (array) $args;
		$filter = self::current_filter();
		if ( '' !== $filter ) {
			$args[ self::FILTER_VAR ] = $filter;
		}

		return $args;
	}

	public static function hpos_clauses( $clauses, $query, $args ): array {
		$clauses = (array) $clauses;
		$filter  = is_array( $args ) ? (string) ( $args[ self::FILTER_VAR ] ?? '' ) : '';
		if ( '' === $filter || ! isset( self::filters()[ $filter ] ) || ! method_exists( $query, 'get_table_name' ) ) {
			return $clauses;
		}

		return self::add_filter_sql( $clauses, $query->get_table_name( 'orders' ) . '.id', $filter );
	}

	public static function legacy_clauses( $clauses, $query ): array {
		global $wpdb;

		$clauses = (array) $clauses;
		$filter  = self::current_filter();
		if ( '' === $filter || ! is_admin() || ! $query instanceof WP_Query || ! $query->is_main_query() || 'shop_order' !== $query->get( 'post_type' ) ) {
			return $clauses;
		}

		return self::add_filter_sql( $clauses, $wpdb->posts . '.ID', $filter );
	}

	/**
	 * @param array<string, string> $clauses
	 * @return array<string, string>
	 */
	private static function add_filter_sql( array $clauses, string $order_id_column, string $filter ): array {
		$links     = Schema::table( Schema::ORDER_CUSTOMERS );
		$customers = Schema::table( Schema::CUSTOMERS );
		$signals   = Schema::table( Schema::SIGNALS );

		$conditions = [
			self::FIRST_TIME => 'COALESCE(cl_s.orders_count, 0) <= 1',
			self::FLAGGED    => 'cl_c.flag_status = ' . (int) Customers::FLAG_WATCH,
			self::REFUNDS    => 'cl_s.refunds_count > 0',
			self::REFUSED    => 'cl_s.refused_count > 0',
		];

		$clauses['join']  = ( $clauses['join'] ?? '' ) . " INNER JOIN {$links} cl_o ON cl_o.order_id = {$order_id_column}"
			. " INNER JOIN {$customers} cl_c ON cl_c.id = cl_o.customer_id"
			. " LEFT JOIN {$signals} cl_s ON cl_s.customer_id = cl_o.customer_id";
		$clauses['where'] = ( $clauses['where'] ?? '' ) . ' AND ' . $conditions[ $filter ];

		return $clauses;
	}

	private static function print_dropdown(): void {
		if ( ! current_user_can( Plugin::capability() ) ) {
			return;
		}

		$current = self::current_filter();
		?>
		<label class="screen-reader-text" for="mwtcl-filter"><?php esc_html_e( 'Filter by Customer Lens signals', 'customer-lens' ); ?></label>
		<select name="<?php echo esc_attr( self::FILTER_VAR ); ?>" id="mwtcl-filter">
			<option value=""><?php esc_html_e( 'Customer Lens', 'customer-lens' ); ?></option>
			<?php foreach ( self::filters() as $value => $label ) : ?>
				<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $current, $value ); ?>><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	private static function current_filter(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filter.
		$filter = isset( $_GET[ self::FILTER_VAR ] ) ? sanitize_key( wp_unslash( $_GET[ self::FILTER_VAR ] ) ) : '';

		return isset( self::filters()[ $filter ] ) ? $filter : '';
	}

	/** Loads the whole list page on its first cell, so the column costs one query per page. */
	private static function load( int $order_id ): void {
		if ( null !== self::$rows && array_key_exists( $order_id, self::$rows ) ) {
			return;
		}

		$ids = self::$page_ids;
		if ( ! $ids && isset( $GLOBALS['wp_query'] ) && $GLOBALS['wp_query'] instanceof WP_Query ) {
			$ids = array_map( static fn( $post ) => (int) ( $post->ID ?? $post ), (array) $GLOBALS['wp_query']->posts );
		}
		$ids[] = $order_id;

		$rows = OrderLinks::summaries( $ids );
		foreach ( $ids as $id ) {
			$rows[ $id ] = $rows[ $id ] ?? null;
		}

		self::$rows = $rows + (array) self::$rows;
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	private static function signals( array $row ): array {
		foreach ( Signals::COUNTERS as $counter ) {
			$row[ $counter ] = (int) $row[ $counter ];
		}

		return $row;
	}
}
