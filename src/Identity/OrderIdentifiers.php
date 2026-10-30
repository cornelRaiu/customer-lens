<?php

namespace CustomerLens\Identity;

use WC_Order;

defined( 'ABSPATH' ) || exit;

/**
 * The normalised identifiers of one order.
 */
final class OrderIdentifiers {

	public int $user_id     = 0;
	public string $email    = '';
	public string $phone    = '';
	public string $address  = '';
	public string $name_key = '';
	public string $seen_at  = '';

	public static function from_order( WC_Order $order ): self {
		$ids = new self();

		$ids->user_id = (int) $order->get_customer_id();
		$ids->email   = EmailNormalizer::normalize( (string) $order->get_billing_email() );

		// National numbers are read as numbers of the billing country, then the store's country.
		$region = $order->get_billing_country();
		if ( ! $region ) {
			$region = $order->get_shipping_country();
		}
		if ( ! $region ) {
			$region = wc_get_base_location()['country'];
		}

		$phone = (string) $order->get_billing_phone();
		if ( '' === trim( $phone ) ) {
			$phone = (string) $order->get_shipping_phone();
		}
		$ids->phone = PhoneNormalizer::normalize( $phone, (string) $region );

		// A cash-on-delivery parcel goes to the shipping address, so that one wins when the order has it.
		$ids->address = $order->has_shipping_address()
			? AddressNormalizer::normalize( (string) $order->get_shipping_address_1(), (string) $order->get_shipping_city(), (string) $order->get_shipping_postcode() )
			: AddressNormalizer::normalize( (string) $order->get_billing_address_1(), (string) $order->get_billing_city(), (string) $order->get_billing_postcode() );

		$ids->name_key = NameKey::from( (string) $order->get_billing_first_name(), (string) $order->get_billing_last_name() );

		$created      = $order->get_date_created();
		$ids->seen_at = $created ? gmdate( 'Y-m-d H:i:s', $created->getTimestamp() ) : gmdate( 'Y-m-d H:i:s' );

		return $ids;
	}

	/** @return array<string, string> Kind => value, for the identifiers the order has. */
	public function identities(): array {
		return array_filter(
			[
				Identities::EMAIL   => $this->email,
				Identities::PHONE   => $this->phone,
				Identities::ADDRESS => $this->address,
			]
		);
	}
}
