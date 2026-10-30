<?php

namespace CustomerLens\Identity;

defined( 'ABSPATH' ) || exit;

/**
 * A short fingerprint of a billing name, used to count how many different people use one phone or email.
 * Word order, case and diacritics do not matter: "Pop Ioana" and "ioana pop" give the same key.
 */
final class NameKey {

	public static function from( string $first, string $last ): string {
		$words = preg_split( '/[^a-z0-9]+/', strtolower( remove_accents( $first . ' ' . $last ) ), -1, PREG_SPLIT_NO_EMPTY );
		if ( ! $words ) {
			return '';
		}

		sort( $words );

		return substr( md5( implode( ' ', $words ) ), 0, 8 );
	}
}
