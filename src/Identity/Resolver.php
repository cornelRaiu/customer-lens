<?php

namespace CustomerLens\Identity;

use CustomerLens\Events\Events;
use CustomerLens\Orders\OrderLinks;
use WC_Order;

defined( 'ABSPATH' ) || exit;

/**
 * Attaches an order to a customer by its user, email, phone and address, in that order of trust.
 */
final class Resolver {

	public const METHOD_USER    = 'user';
	public const METHOD_EMAIL   = 'email';
	public const METHOD_PHONE   = 'phone';
	public const METHOD_ADDRESS = 'address';
	public const METHOD_MANUAL  = 'manual';
	public const METHOD_NEW     = 'new';

	/** How much each kind of match is trusted, 0 to 100. */
	public const CONFIDENCE = [
		self::METHOD_USER    => 100,
		self::METHOD_EMAIL   => 95,
		self::METHOD_PHONE   => 90,
		self::METHOD_ADDRESS => 60,
	];

	/** An address alone links an order to a customer only at this confidence or above, so by default never. */
	public const ADDRESS_THRESHOLD = 70;

	/** Filters ADDRESS_THRESHOLD. */
	public const ADDRESS_THRESHOLD_FILTER = 'mwtcl_address_threshold';

	/**
	 * Links the order to its customer, creating the customer when no identifier matches.
	 *
	 * When the identifiers point at more than one customer, the order goes to the most trusted match and
	 * a merge proposal is recorded for staff; customers are never merged automatically.
	 *
	 * @return array{customer: int, previous: int} The order's customer, and the one it had before (0 if none).
	 */
	public static function resolve( WC_Order $order ): array {
		$order_id = $order->get_id();
		$ids      = OrderIdentifiers::from_order( $order );
		$previous = OrderLinks::customer_of( $order_id );

		$identity_ids = [];
		foreach ( $ids->identities() as $kind => $value ) {
			$identity_ids[ $kind ] = Identities::upsert( $kind, $value, $ids->seen_at );
		}
		Identities::record_order( $order_id, $identity_ids, $ids->name_key, $ids->seen_at );

		// Shared phones and emails (an office line, a reseller) no longer identify one person.
		$usable = $identity_ids;
		foreach ( [ Identities::EMAIL, Identities::PHONE ] as $kind ) {
			if ( isset( $usable[ $kind ] ) && Identities::refresh_shared( $usable[ $kind ] ) ) {
				unset( $usable[ $kind ] );
			}
		}

		$candidates = self::candidates( $ids->user_id, $usable );

		if ( $candidates ) {
			$customer_id = (int) array_key_first( $candidates );
			$method      = $candidates[ $customer_id ][0];
		} else {
			$customer_id = Customers::create( $ids->user_id );
			$method      = self::METHOD_NEW;
		}

		foreach ( array_slice( $candidates, 1, null, true ) as $other => $kinds ) {
			self::propose_merge( $customer_id, (int) $other, $kinds );
		}

		self::link_identities( $customer_id, $usable, $identity_ids );

		if ( $ids->user_id ) {
			$customer = Customers::get( $customer_id );
			if ( $customer && ! $customer->wp_user_id ) {
				Customers::update( $customer_id, [ 'wp_user_id' => $ids->user_id ] );
			}
		}

		$confidence = self::CONFIDENCE[ $method ] ?? self::strongest( $ids );
		OrderLinks::save( $order, $customer_id, $method, $confidence );

		if ( $previous && $previous !== $customer_id ) {
			Events::move_order( $order_id, $customer_id );
		}

		Events::record(
			Events::ORDER_PLACED,
			$customer_id,
			[
				'order_id'   => $order_id,
				'dedupe_key' => 'placed:' . $order_id,
				'created_at' => $ids->seen_at,
			]
		);

		return [
			'customer' => $customer_id,
			'previous' => $previous,
		];
	}

	/**
	 * Customers the order's identifiers point at, most trusted first, each with the kinds that matched.
	 *
	 * @param array<string, int> $identity_ids Kind => identity ID, without shared identities.
	 * @return array<int, string[]>
	 */
	private static function candidates( int $user_id, array $identity_ids ): array {
		$candidates        = [];
		$address_threshold = (int) apply_filters( self::ADDRESS_THRESHOLD_FILTER, self::ADDRESS_THRESHOLD ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- The constant holds the prefixed hook name.

		if ( $user_id ) {
			$customer_id = Customers::of_user( $user_id );
			if ( $customer_id ) {
				$candidates[ $customer_id ][] = self::METHOD_USER;
			}
		}

		foreach ( [ Identities::EMAIL, Identities::PHONE, Identities::ADDRESS ] as $kind ) {
			if ( ! isset( $identity_ids[ $kind ] ) ) {
				continue;
			}
			if ( Identities::ADDRESS === $kind && self::CONFIDENCE[ self::METHOD_ADDRESS ] < $address_threshold ) {
				continue;
			}

			$customers = Identities::customers_of( $identity_ids[ $kind ] );
			if ( $customers ) {
				// The oldest customer, when bad data linked one identity to several.
				$candidates[ $customers[0] ][] = $kind;
			}
		}

		return $candidates;
	}

	/**
	 * Links the order's identities to its customer. An email or phone that already belongs to another
	 * customer stays there (the merge proposal covers it); addresses are only hints and link to anyone.
	 *
	 * @param array<string, int> $usable Kind => identity ID, without shared identities.
	 * @param array<string, int> $all    Kind => identity ID.
	 */
	private static function link_identities( int $customer_id, array $usable, array $all ): void {
		foreach ( [ Identities::EMAIL, Identities::PHONE ] as $kind ) {
			if ( ! isset( $usable[ $kind ] ) ) {
				continue;
			}

			$owners = Identities::customers_of( $usable[ $kind ] );
			if ( ! $owners || in_array( $customer_id, $owners, true ) ) {
				Identities::link( $customer_id, $usable[ $kind ], self::CONFIDENCE[ $kind ] );
			}
		}

		if ( isset( $all[ Identities::ADDRESS ] ) ) {
			Identities::link( $customer_id, $all[ Identities::ADDRESS ], self::CONFIDENCE[ self::METHOD_ADDRESS ] );
		}
	}

	/** @param string[] $kinds What the other customer has in common with the order. */
	private static function propose_merge( int $customer_id, int $other, array $kinds ): void {
		Events::record(
			Events::MERGE_PROPOSAL,
			$customer_id,
			[
				'source'     => Events::SOURCE_SYSTEM,
				'payload'    => [
					'other' => $other,
					'via'   => $kinds,
				],
				'dedupe_key' => 'merge:' . min( $customer_id, $other ) . ':' . max( $customer_id, $other ),
			]
		);
	}

	private static function strongest( OrderIdentifiers $ids ): int {
		foreach ( [
			self::METHOD_USER    => $ids->user_id,
			self::METHOD_EMAIL   => $ids->email,
			self::METHOD_PHONE   => $ids->phone,
			self::METHOD_ADDRESS => $ids->address,
		] as $method => $value ) {
			if ( $value ) {
				return self::CONFIDENCE[ $method ];
			}
		}

		return 0;
	}
}
