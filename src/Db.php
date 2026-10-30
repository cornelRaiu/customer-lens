<?php

namespace CustomerLens;

defined( 'ABSPATH' ) || exit;

final class Db {

	/** IDs for an SQL IN () list. Never empty, so "IN ()" cannot happen. */
	public static function ids( array $ids ): string {
		$ids = array_unique( array_filter( array_map( 'intval', $ids ) ) );

		return $ids ? implode( ',', $ids ) : '0';
	}

	/** Strings for an SQL IN () list, escaped. */
	public static function strings( array $values ): string {
		return "'" . implode( "','", array_map( 'esc_sql', $values ) ) . "'";
	}

	/** Now in UTC, in the format of a DATETIME column. */
	public static function now(): string {
		return gmdate( 'Y-m-d H:i:s' );
	}
}
