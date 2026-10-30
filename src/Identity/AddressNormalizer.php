<?php

namespace CustomerLens\Identity;

defined( 'ABSPATH' ) || exit;

final class AddressNormalizer {

	/**
	 * Words left out of the street, so that "Str. Memorandumului nr. 12" and "Strada Memorandumului 12"
	 * give the same key. Romanian and English street types, and the words for "number".
	 */
	private const SKIP_WORDS = [
		'str',
		'strada',
		'street',
		'st',
		'bd',
		'bdul',
		'blvd',
		'bulevardul',
		'boulevard',
		'calea',
		'cal',
		'sos',
		'soseaua',
		'aleea',
		'al',
		'piata',
		'pta',
		'splaiul',
		'intrarea',
		'road',
		'rd',
		'avenue',
		'ave',
		'lane',
		'nr',
		'no',
		'numar',
		'numarul',
	];

	/**
	 * The match key of an address: "street|number|city|postcode", lowercased, without diacritics or
	 * punctuation. Empty without a street and a city. Building, staircase and flat are not part of it.
	 */
	public static function normalize( string $address_1, string $city, string $postcode ): string {
		$line = self::fold( $address_1 );
		$city = self::fold( $city );

		$number = '';
		if ( preg_match( '/\b(?:nr|no|numar|numarul) (\d+[a-z]?)\b/', $line, $match ) || preg_match( '/\b(\d+[a-z]?)\b/', $line, $match ) ) {
			$number = $match[1];
		}

		$street = [];
		foreach ( explode( ' ', $line ) as $word ) {
			if ( '' !== $word && ! in_array( $word, self::SKIP_WORDS, true ) && ! preg_match( '/^\d+[a-z]?$/', $word ) ) {
				$street[] = $word;
			}
		}

		if ( ! $street || '' === $city ) {
			return '';
		}

		return implode( '|', [ implode( ' ', $street ), $number, $city, str_replace( ' ', '', self::fold( $postcode ) ) ] );
	}

	/** The key written as an address line, such as "memorandumului 12, cluj napoca 400114". */
	public static function display( string $key ): string {
		$parts = explode( '|', $key ) + [ '', '', '', '' ];

		return trim( trim( $parts[0] . ' ' . $parts[1] ) . ', ' . trim( $parts[2] . ' ' . $parts[3] ), ', ' );
	}

	private static function fold( string $text ): string {
		$text = strtolower( remove_accents( $text ) );
		$text = preg_replace( '/[^a-z0-9]+/', ' ', $text );

		return trim( (string) $text );
	}
}
