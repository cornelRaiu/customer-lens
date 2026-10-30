<?php

namespace CustomerLens\Signals;

use CustomerLens\Identity\Customers;

defined( 'ABSPATH' ) || exit;

/**
 * Turns counts into the badges staff read. Counts, not verdicts: every badge is a number, a date or a
 * plain fact, and its level only says whether the store's threshold for it is reached.
 */
final class Badges {

	public const NEUTRAL  = 'neutral';
	public const WARNING  = 'warning';
	public const CRITICAL = 'critical';
	public const GOOD     = 'good';

	private const RANK = [
		self::CRITICAL => 0,
		self::WARNING  => 1,
		self::GOOD     => 2,
		self::NEUTRAL  => 3,
	];

	/**
	 * The badges for one order: where it falls among the customer's orders, then each non-zero counter.
	 *
	 * @param array<string, mixed>|null $signals
	 * @param array<string, mixed>      $settings
	 * @param string|null               $previous_order_at When the customer's previous order was placed (UTC).
	 * @return array<int, array{key: string, label: string, level: string}>
	 */
	public static function for_order( ?array $signals, ?object $customer, int $ordinal, array $settings, ?string $previous_order_at = null, bool $with_totals = true ): array {
		$badges = [];

		if ( $ordinal > 0 ) {
			$badges[] = self::badge( 'ordinal', self::ordinal_label( $ordinal ), 1 === $ordinal && $settings['first_order_warning'] ? self::WARNING : self::NEUTRAL );
		}

		if ( $customer && Customers::FLAG_WATCH === (int) $customer->flag_status ) {
			$badges[] = self::badge( 'flag', __( 'Flagged: watch', 'customer-lens' ), self::WARNING );
		} elseif ( $customer && Customers::FLAG_TRUSTED === (int) $customer->flag_status ) {
			$badges[] = self::badge( 'flag', __( 'Trusted', 'customer-lens' ), self::GOOD );
		}

		$signals = $signals ?? [];

		$refunds = (int) ( $signals['refunds_count'] ?? 0 );
		if ( $refunds ) {
			$label = $with_totals && ! empty( $signals['refunds_total'] )
				/* translators: 1: number of refunds, 2: refunded amount, such as €38. */
				? sprintf( _n( '%1$s refund (%2$s)', '%1$s refunds (%2$s)', $refunds, 'customer-lens' ), number_format_i18n( $refunds ), self::money( (float) $signals['refunds_total'], (string) $signals['currency'] ) )
				/* translators: %s: number of refunds. */
				: sprintf( _n( '%s refund', '%s refunds', $refunds, 'customer-lens' ), number_format_i18n( $refunds ) );
			$badges[] = self::badge( 'refunds', $label, self::level( $refunds, (int) $settings['refunds_warning'] ) );
		}

		$refused = (int) ( $signals['refused_count'] ?? 0 );
		if ( $refused ) {
			/* translators: %s: number of refused cash-on-delivery parcels. */
			$label    = sprintf( _n( '%s refused COD', '%s refused COD', $refused, 'customer-lens' ), number_format_i18n( $refused ) );
			$badges[] = self::badge( 'refused', $label, self::level( $refused, (int) $settings['refused_warning'], (int) $settings['refused_critical'] ) );
		}

		$cancels = (int) ( $signals['cancels_count'] ?? 0 );
		if ( $cancels ) {
			/* translators: %s: number of cancelled orders. */
			$label    = sprintf( _n( '%s cancelled', '%s cancelled', $cancels, 'customer-lens' ), number_format_i18n( $cancels ) );
			$badges[] = self::badge( 'cancels', $label, self::level( $cancels, (int) $settings['cancels_warning'] ) );
		}

		$failed = (int) ( $signals['failed_payments_count'] ?? 0 );
		if ( $failed ) {
			/* translators: %s: number of failed payments. */
			$label    = sprintf( _n( '%s failed payment', '%s failed payments', $failed, 'customer-lens' ), number_format_i18n( $failed ) );
			$badges[] = self::badge( 'failed', $label, self::level( $failed, (int) $settings['failed_warning'] ) );
		}

		$chargebacks = (int) ( $signals['chargebacks_count'] ?? 0 );
		if ( $chargebacks ) {
			/* translators: %s: number of chargebacks. */
			$label    = sprintf( _n( '%s chargeback', '%s chargebacks', $chargebacks, 'customer-lens' ), number_format_i18n( $chargebacks ) );
			$badges[] = self::badge( 'chargebacks', $label, self::level( $chargebacks, 0, (int) $settings['chargebacks_critical'] ) );
		}

		if ( $with_totals && $previous_order_at ) {
			$days = max( 0, (int) floor( ( time() - strtotime( $previous_order_at . ' UTC' ) ) / DAY_IN_SECONDS ) );
			/* translators: %s: number of days. */
			$label    = 0 === $days ? __( 'Last order today', 'customer-lens' ) : sprintf( _n( 'Last order %s day ago', 'Last order %s days ago', $days, 'customer-lens' ), number_format_i18n( $days ) );
			$badges[] = self::badge( 'last_order', $label, self::NEUTRAL );
		}

		if ( $with_totals && ( $signals['orders_count'] ?? 0 ) > 1 ) {
			/* translators: %s: amount, such as €174. */
			$badges[] = self::badge( 'net', sprintf( __( '%s net lifetime', 'customer-lens' ), self::money( (float) $signals['net_total'], (string) $signals['currency'] ) ), self::NEUTRAL );
		}

		return $badges;
	}

	/**
	 * The ordinal badge and up to $max badges that need attention, most serious first. For the orders list.
	 *
	 * @param array<int, array{key: string, label: string, level: string}> $badges
	 * @return array<int, array{key: string, label: string, level: string}>
	 */
	public static function summary( array $badges, int $max = 2 ): array {
		$ordinal   = array_values( array_filter( $badges, static fn( $badge ) => 'ordinal' === $badge['key'] ) );
		$attention = array_values( array_filter( $badges, static fn( $badge ) => 'ordinal' !== $badge['key'] && self::NEUTRAL !== $badge['level'] ) );

		usort( $attention, static fn( $a, $b ) => self::RANK[ $a['level'] ] <=> self::RANK[ $b['level'] ] );

		return array_merge( $ordinal, array_slice( $attention, 0, $max ) );
	}

	/** "First order", "2nd order", "11th order". Each English suffix is its own string for translators. */
	public static function ordinal_label( int $n ): string {
		if ( 1 === $n ) {
			return __( 'First order', 'customer-lens' );
		}

		$number = number_format_i18n( $n );
		$teen   = in_array( $n % 100, [ 11, 12, 13 ], true );

		switch ( $teen ? 0 : $n % 10 ) {
			case 1:
				/* translators: %s: ordinal number ending in 1, such as 21. Used as "21st order". */
				return sprintf( __( '%sst order', 'customer-lens' ), $number );
			case 2:
				/* translators: %s: ordinal number ending in 2, such as 2. Used as "2nd order". */
				return sprintf( __( '%snd order', 'customer-lens' ), $number );
			case 3:
				/* translators: %s: ordinal number ending in 3, such as 3. Used as "3rd order". */
				return sprintf( __( '%srd order', 'customer-lens' ), $number );
			default:
				/* translators: %s: ordinal number, such as 4. Used as "4th order". */
				return sprintf( __( '%sth order', 'customer-lens' ), $number );
		}
	}

	/** Warning from $warning on, critical from $critical on. A threshold of 0 never applies. */
	public static function level( int $count, int $warning, int $critical = 0 ): string {
		if ( $critical > 0 && $count >= $critical ) {
			return self::CRITICAL;
		}

		return $warning > 0 && $count >= $warning ? self::WARNING : self::NEUTRAL;
	}

	public static function money( float $amount, string $currency ): string {
		$symbol = function_exists( 'get_woocommerce_currency_symbol' ) ? html_entity_decode( get_woocommerce_currency_symbol( $currency ) ) : $currency;
		$amount = round( $amount );

		return 'left' === get_option( 'woocommerce_currency_pos', 'left' ) || 'left_space' === get_option( 'woocommerce_currency_pos' )
			? $symbol . number_format_i18n( $amount )
			: number_format_i18n( $amount ) . ' ' . $symbol;
	}

	/** @return array{key: string, label: string, level: string} */
	private static function badge( string $key, string $label, string $level ): array {
		return [
			'key'   => $key,
			'label' => $label,
			'level' => $level,
		];
	}
}
