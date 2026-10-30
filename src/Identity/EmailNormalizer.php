<?php

namespace CustomerLens\Identity;

defined( 'ABSPATH' ) || exit;

final class EmailNormalizer {

	private const GMAIL_DOMAINS = [ 'gmail.com', 'googlemail.com' ];

	/**
	 * The match key of an email address: lowercased and trimmed, and for Gmail without the dots and the
	 * +tag of the local part, which Gmail ignores. Empty when the value is not an email address.
	 */
	public static function normalize( string $email ): string {
		$email = strtolower( trim( $email ) );
		$at    = strrpos( $email, '@' );

		if ( false === $at || 0 === $at || strlen( $email ) - 1 === $at || preg_match( '/\s/', $email ) ) {
			return '';
		}

		$local  = substr( $email, 0, $at );
		$domain = substr( $email, $at + 1 );

		if ( in_array( $domain, self::GMAIL_DOMAINS, true ) ) {
			$plus   = strpos( $local, '+' );
			$local  = str_replace( '.', '', false === $plus ? $local : substr( $local, 0, $plus ) );
			$domain = 'gmail.com';
		}

		return '' === $local ? '' : $local . '@' . $domain;
	}
}
