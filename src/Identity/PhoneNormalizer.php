<?php

namespace CustomerLens\Identity;

use CustomerLens\Vendor\libphonenumber\NumberParseException;
use CustomerLens\Vendor\libphonenumber\PhoneNumberFormat;
use CustomerLens\Vendor\libphonenumber\PhoneNumberUtil;

defined( 'ABSPATH' ) || exit;

final class PhoneNormalizer {

	/**
	 * The number in E.164 (+40722123456), reading national numbers as numbers of $region, a two-letter
	 * country code. Empty when the value cannot be a phone number.
	 */
	public static function normalize( string $phone, string $region ): string {
		$phone = trim( $phone );
		if ( '' === $phone ) {
			return '';
		}

		$util   = PhoneNumberUtil::getInstance();
		$region = strtoupper( $region );

		try {
			$number = $util->parse( $phone, '' === $region ? 'ZZ' : $region );
		} catch ( NumberParseException $e ) {
			return '';
		}

		return $util->isPossibleNumber( $number ) ? $util->format( $number, PhoneNumberFormat::E164 ) : '';
	}

	/** An E.164 number written the international way, such as +40 722 123 456. */
	public static function display( string $e164 ): string {
		$util = PhoneNumberUtil::getInstance();

		try {
			return $util->format( $util->parse( $e164, 'ZZ' ), PhoneNumberFormat::INTERNATIONAL );
		} catch ( NumberParseException $e ) {
			return $e164;
		}
	}
}
