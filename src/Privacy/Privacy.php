<?php

namespace CustomerLens\Privacy;

use CustomerLens\Db;
use CustomerLens\Events\Events;
use CustomerLens\Identity\Customers;
use CustomerLens\Identity\EmailNormalizer;
use CustomerLens\Identity\Identities;
use CustomerLens\Identity\PhoneNormalizer;
use CustomerLens\Install\Schema;
use CustomerLens\Settings;
use CustomerLens\Signals\Signals;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The plugin's own tables, named by Schema::table().

/**
 * The personal data exporter and eraser (Tools > Export and Erase Personal Data), and the privacy
 * policy text the store can paste.
 */
final class Privacy {

	public const KEY = 'customer-lens';

	public static function add_hooks(): void {
		add_filter( 'wp_privacy_personal_data_exporters', [ self::class, 'register_exporter' ] );
		add_filter( 'wp_privacy_personal_data_erasers', [ self::class, 'register_eraser' ] );
		add_action( 'admin_init', [ self::class, 'suggest_policy_text' ] );
	}

	public static function register_exporter( $exporters ): array {
		$exporters              = (array) $exporters;
		$exporters[ self::KEY ] = [
			'exporter_friendly_name' => __( 'Customer Lens', 'customer-lens' ),
			'callback'               => [ self::class, 'export' ],
		];

		return $exporters;
	}

	public static function register_eraser( $erasers ): array {
		$erasers              = (array) $erasers;
		$erasers[ self::KEY ] = [
			'eraser_friendly_name' => __( 'Customer Lens', 'customer-lens' ),
			'callback'             => [ self::class, 'erase' ],
		];

		return $erasers;
	}

	/**
	 * The customers an email address or phone number belongs to: those it is linked to, and those whose
	 * orders used it (a shared phone, say, links to nobody).
	 *
	 * @return int[]
	 */
	public static function customers_of( string $identifier ): array {
		global $wpdb;

		$identity_ids = self::identities_of( $identifier );
		if ( ! $identity_ids ) {
			return [];
		}

		$ids            = Db::ids( $identity_ids );
		$customer_links = Schema::table( Schema::CUSTOMER_IDENTITIES );
		$order_links    = Schema::table( Schema::ORDER_IDENTITIES );
		$orders         = Schema::table( Schema::ORDER_CUSTOMERS );

		$customers = $wpdb->get_col(
			"SELECT customer_id FROM {$customer_links} WHERE identity_id IN ({$ids})
			UNION SELECT o.customer_id FROM {$order_links} l INNER JOIN {$orders} o ON o.order_id = l.order_id WHERE l.identity_id IN ({$ids})"
		);

		$customers = array_unique( array_map( 'intval', $customers ) );
		sort( $customers );

		return $customers;
	}

	/** @return array{data: array<int, array<string, mixed>>, done: bool} */
	public static function export( $email ): array {
		$data = [];

		foreach ( self::customers_of( (string) $email ) as $customer_id ) {
			$customer = Customers::get( $customer_id );
			if ( ! $customer ) {
				continue;
			}

			$fields = [
				[
					'name'  => __( 'Customer number', 'customer-lens' ),
					'value' => $customer_id,
				],
			];
			foreach ( Identities::of_customer( $customer_id ) as $identity ) {
				$fields[] = [
					'name'  => self::kind_label( $identity->kind ),
					'value' => Identities::display( $identity->kind, $identity->value_norm ),
				];
			}
			if ( Customers::FLAG_NONE !== (int) $customer->flag_status ) {
				$fields[] = [
					'name'  => __( 'Flag', 'customer-lens' ),
					'value' => trim( ( Customers::FLAG_WATCH === (int) $customer->flag_status ? __( 'Watch', 'customer-lens' ) : __( 'Trusted', 'customer-lens' ) ) . ' ' . (string) $customer->flag_reason ),
				];
			}

			$data[] = [
				'group_id'    => self::KEY,
				'group_label' => __( 'Customer Lens', 'customer-lens' ),
				'item_id'     => 'customer-' . $customer_id,
				'data'        => $fields,
			];

			foreach ( Events::of_customer( $customer_id, self::exported_events(), 500 ) as $event ) {
				$data[] = [
					'group_id'    => self::KEY . '-events',
					'group_label' => __( 'Customer Lens history', 'customer-lens' ),
					'item_id'     => 'customer-lens-event-' . $event->id,
					'data'        => self::event_fields( $event ),
				];
			}
		}

		return [
			'data' => $data,
			'done' => true,
		];
	}

	/** @return array{items_removed: bool, items_retained: bool, messages: string[], done: bool} */
	public static function erase( $email ): array {
		$customers = self::customers_of( (string) $email );
		foreach ( $customers as $customer_id ) {
			self::erase_customer( $customer_id );
		}

		return [
			'items_removed'  => (bool) $customers,
			'items_retained' => false,
			'messages'       => [],
			'done'           => true,
		];
	}

	/**
	 * Deletes the customer's identities (and every link to them), events and notes, and blanks the
	 * customer row. The orders stay linked, without any personal data, so the store's counts still add up.
	 */
	public static function erase_customer( int $customer_id ): void {
		global $wpdb;

		$identities     = Schema::table( Schema::IDENTITIES );
		$customer_links = Schema::table( Schema::CUSTOMER_IDENTITIES );
		$order_links    = Schema::table( Schema::ORDER_IDENTITIES );
		$orders         = Schema::table( Schema::ORDER_CUSTOMERS );

		$identity_ids = array_merge(
			$wpdb->get_col( $wpdb->prepare( "SELECT identity_id FROM {$customer_links} WHERE customer_id = %d", $customer_id ) ),
			$wpdb->get_col( $wpdb->prepare( "SELECT l.identity_id FROM {$order_links} l INNER JOIN {$orders} o ON o.order_id = l.order_id WHERE o.customer_id = %d", $customer_id ) )
		);
		$ids          = Db::ids( $identity_ids );

		$wpdb->query( "DELETE FROM {$customer_links} WHERE identity_id IN ({$ids})" );
		$wpdb->query( "DELETE FROM {$order_links} WHERE identity_id IN ({$ids})" );
		$wpdb->query( "DELETE FROM {$identities} WHERE id IN ({$ids})" );
		$wpdb->delete( Schema::table( Schema::EVENTS ), [ 'customer_id' => $customer_id ], [ '%d' ] );

		Customers::update(
			$customer_id,
			[
				'wp_user_id'       => null,
				'flag_status'      => Customers::FLAG_NONE,
				'flag_reason'      => null,
				'flagged_by'       => null,
				'flagged_at'       => null,
				'flag_expires_at'  => null,
				'block_status'     => 0,
				'block_reason'     => null,
				'blocked_by'       => null,
				'blocked_at'       => null,
				'block_expires_at' => null,
			]
		);

		Signals::rebuild( $customer_id );
	}

	/** A paragraph for the store's privacy policy, in the current language. */
	public static function policy_text(): string {
		return sprintf(
			/* translators: %s: number of months. */
			__( 'When you place an order, we keep a record of your earlier orders with us, matched by your email address, phone number and delivery address, together with their refunds, cancellations, failed payments and refused deliveries. Our staff use this record, only within our own systems, to prepare and confirm orders and to prevent fraud and repeatedly refused cash-on-delivery parcels. This is our legitimate interest under article 6(1)(f) of the GDPR. We keep these records for %s months and do not share them with anyone. You can ask us for a copy of this data, or for its erasure, at any time.', 'customer-lens' ),
			number_format_i18n( (int) Settings::get( 'retention_months' ) )
		);
	}

	public static function suggest_policy_text(): void {
		if ( function_exists( 'wp_add_privacy_policy_content' ) ) {
			wp_add_privacy_policy_content( __( 'Customer Lens', 'customer-lens' ), wpautop( esc_html( self::policy_text() ) ) );
		}
	}

	/** @return int[] */
	private static function identities_of( string $identifier ): array {
		$email = EmailNormalizer::normalize( $identifier );
		if ( '' !== $email ) {
			$id = Identities::find( Identities::EMAIL, $email );
			return $id ? [ $id ] : [];
		}

		$phone = PhoneNormalizer::normalize( $identifier, (string) wc_get_base_location()['country'] );
		$id    = '' === $phone ? 0 : Identities::find( Identities::PHONE, $phone );

		return $id ? [ $id ] : [];
	}

	/** @return array<int, array{name: string, value: mixed}> */
	private static function event_fields( object $event ): array {
		$fields = [
			[
				'name'  => __( 'Event', 'customer-lens' ),
				'value' => $event->type,
			],
			[
				'name'  => __( 'Date', 'customer-lens' ),
				'value' => get_date_from_gmt( $event->created_at ),
			],
		];

		if ( $event->order_id ) {
			$fields[] = [
				'name'  => __( 'Order', 'customer-lens' ),
				'value' => (int) $event->order_id,
			];
		}
		if ( null !== $event->amount ) {
			$fields[] = [
				'name'  => __( 'Amount', 'customer-lens' ),
				'value' => $event->amount . ' ' . $event->currency,
			];
		}
		if ( isset( $event->payload['text'] ) ) {
			$fields[] = [
				'name'  => __( 'Note', 'customer-lens' ),
				'value' => $event->payload['text'],
			];
		}

		return $fields;
	}

	/** @return string[] */
	private static function exported_events(): array {
		return [ Events::REFUND, Events::CANCEL, Events::FAILED_PAYMENT, Events::REFUSED_DELIVERY, Events::CHARGEBACK, Events::FLAG, Events::UNFLAG, Events::NOTE ];
	}

	private static function kind_label( string $kind ): string {
		if ( Identities::EMAIL === $kind ) {
			return __( 'Email', 'customer-lens' );
		}

		return Identities::PHONE === $kind ? __( 'Phone', 'customer-lens' ) : __( 'Address', 'customer-lens' );
	}
}
