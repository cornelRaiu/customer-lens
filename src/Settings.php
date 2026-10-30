<?php

namespace CustomerLens;

defined( 'ABSPATH' ) || exit;

final class Settings {

	public const OPTION = 'mwtcl_settings';

	public const RETENTION_MIN = 12;
	public const RETENTION_MAX = 60;

	/**
	 * Badge thresholds: a counter at or above its threshold shows a warning (or critical) badge.
	 * Zero turns that badge off.
	 */
	public const THRESHOLDS = [
		'refunds_warning',
		'cancels_warning',
		'failed_warning',
		'refused_warning',
		'refused_critical',
		'chargebacks_critical',
	];

	public static function defaults(): array {
		return [
			'refunds_warning'      => 1,
			'cancels_warning'      => 2,
			'failed_warning'       => 2,
			'refused_warning'      => 1,
			'refused_critical'     => 3,
			'chargebacks_critical' => 1,
			'first_order_warning'  => false,
			'retention_months'     => 36,
			'delete_data'          => false,
		];
	}

	/** @return array{refunds_warning: int, cancels_warning: int, failed_warning: int, refused_warning: int, refused_critical: int, chargebacks_critical: int, first_order_warning: bool, retention_months: int, delete_data: bool} */
	public static function all(): array {
		$stored = get_option( self::OPTION, [] );

		return self::sanitize( is_array( $stored ) ? $stored : [] );
	}

	public static function get( string $key ) {
		$settings = self::all();

		return $settings[ $key ] ?? null;
	}

	/** Saves one settings tab: the fields it sends, over the stored values of the other tabs. */
	public static function save( $input ): array {
		return self::sanitize( array_merge( self::all(), is_array( $input ) ? $input : [] ) );
	}

	public static function sanitize( $input ): array {
		$defaults = self::defaults();
		$input    = is_array( $input ) ? $input : [];
		$output   = [];

		foreach ( self::THRESHOLDS as $key ) {
			$output[ $key ] = self::number( $input, $key, 0, 999, $defaults[ $key ] );
		}

		$output['first_order_warning'] = self::flag( $input, 'first_order_warning', $defaults['first_order_warning'] );
		$output['retention_months']    = self::number( $input, 'retention_months', self::RETENTION_MIN, self::RETENTION_MAX, $defaults['retention_months'] );
		$output['delete_data']         = self::flag( $input, 'delete_data', $defaults['delete_data'] );

		return $output;
	}

	private static function number( array $input, string $key, int $min, int $max, int $fallback ): int {
		if ( ! isset( $input[ $key ] ) || ! is_numeric( $input[ $key ] ) ) {
			return $fallback;
		}

		return max( $min, min( $max, (int) $input[ $key ] ) );
	}

	private static function flag( array $input, string $key, bool $fallback ): bool {
		// The settings page sends a hidden "0" before each checkbox, so a missing key is not "off".
		if ( ! array_key_exists( $key, $input ) ) {
			return $fallback;
		}

		return (bool) filter_var( $input[ $key ], FILTER_VALIDATE_BOOLEAN );
	}
}
