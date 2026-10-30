<?php

namespace CustomerLens\Install;

defined( 'ABSPATH' ) || exit;

/**
 * The plugin's tables. Bump VERSION whenever a definition changes; dbDelta then brings every site up to date.
 */
final class Schema {

	public const VERSION        = 1;
	public const VERSION_OPTION = 'mwtcl_schema_version';

	public const CUSTOMERS           = 'mwtcl_customers';
	public const IDENTITIES          = 'mwtcl_identities';
	public const CUSTOMER_IDENTITIES = 'mwtcl_customer_identities';
	public const ORDER_CUSTOMERS     = 'mwtcl_order_customers';
	public const ORDER_IDENTITIES    = 'mwtcl_order_identities';
	public const EVENTS              = 'mwtcl_events';
	public const SIGNALS             = 'mwtcl_signals';

	/** @return string[] Unprefixed table names. */
	public static function tables(): array {
		return [
			self::CUSTOMERS,
			self::IDENTITIES,
			self::CUSTOMER_IDENTITIES,
			self::ORDER_CUSTOMERS,
			self::ORDER_IDENTITIES,
			self::EVENTS,
			self::SIGNALS,
		];
	}

	public static function table( string $name ): string {
		global $wpdb;

		return $wpdb->prefix . $name;
	}

	public static function maybe_upgrade(): void {
		if ( self::VERSION === (int) get_option( self::VERSION_OPTION ) ) {
			return;
		}

		self::install();
	}

	public static function install(): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta( self::definitions() );
		update_option( self::VERSION_OPTION, self::VERSION, true );
	}

	/**
	 * CREATE TABLE statements in the format dbDelta parses: one column per line, two spaces after
	 * PRIMARY KEY, every index named. Enum-like columns are varchar(32) backed by class constants.
	 *
	 * @return string[]
	 */
	public static function definitions(): array {
		global $wpdb;

		$collate = $wpdb->get_charset_collate();
		$t       = [];
		foreach ( self::tables() as $name ) {
			$t[ $name ] = self::table( $name );
		}

		return [
			"CREATE TABLE {$t[ self::CUSTOMERS ]} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  wp_user_id bigint(20) unsigned DEFAULT NULL,
  flag_status tinyint(3) unsigned NOT NULL DEFAULT 0,
  flag_reason varchar(190) DEFAULT NULL,
  flagged_by bigint(20) unsigned DEFAULT NULL,
  flagged_at datetime DEFAULT NULL,
  flag_expires_at datetime DEFAULT NULL,
  block_status tinyint(3) unsigned NOT NULL DEFAULT 0,
  block_reason varchar(190) DEFAULT NULL,
  blocked_by bigint(20) unsigned DEFAULT NULL,
  blocked_at datetime DEFAULT NULL,
  block_expires_at datetime DEFAULT NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY wp_user_id (wp_user_id),
  KEY flag_status (flag_status),
  KEY block_status (block_status)
) {$collate};",
			"CREATE TABLE {$t[ self::IDENTITIES ]} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  kind varchar(32) NOT NULL,
  value_norm varchar(190) NOT NULL,
  hash char(64) NOT NULL,
  shared tinyint(1) unsigned NOT NULL DEFAULT 0,
  first_seen datetime NOT NULL,
  last_seen datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY kind_hash (kind,hash)
) {$collate};",
			"CREATE TABLE {$t[ self::CUSTOMER_IDENTITIES ]} (
  customer_id bigint(20) unsigned NOT NULL,
  identity_id bigint(20) unsigned NOT NULL,
  confidence tinyint(3) unsigned NOT NULL DEFAULT 0,
  linked_at datetime NOT NULL,
  linked_by varchar(32) NOT NULL,
  PRIMARY KEY  (customer_id,identity_id),
  KEY identity_id (identity_id)
) {$collate};",
			"CREATE TABLE {$t[ self::ORDER_CUSTOMERS ]} (
  order_id bigint(20) unsigned NOT NULL,
  customer_id bigint(20) unsigned NOT NULL,
  match_method varchar(32) NOT NULL,
  confidence tinyint(3) unsigned NOT NULL DEFAULT 0,
  matched_at datetime NOT NULL,
  PRIMARY KEY  (order_id),
  KEY customer_id (customer_id)
) {$collate};",
			"CREATE TABLE {$t[ self::ORDER_IDENTITIES ]} (
  order_id bigint(20) unsigned NOT NULL,
  identity_id bigint(20) unsigned NOT NULL,
  name_key char(8) NOT NULL DEFAULT '',
  seen_at datetime NOT NULL,
  PRIMARY KEY  (order_id,identity_id),
  KEY identity_seen (identity_id,seen_at)
) {$collate};",
			"CREATE TABLE {$t[ self::EVENTS ]} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  customer_id bigint(20) unsigned NOT NULL,
  order_id bigint(20) unsigned DEFAULT NULL,
  type varchar(32) NOT NULL,
  source varchar(32) NOT NULL,
  amount decimal(12,2) DEFAULT NULL,
  currency char(3) DEFAULT NULL,
  payload longtext NULL,
  dedupe_key varchar(64) DEFAULT NULL,
  actor_user_id bigint(20) unsigned DEFAULT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY dedupe_key (dedupe_key),
  KEY customer_type (customer_id,type),
  KEY order_id (order_id),
  KEY created_at (created_at)
) {$collate};",
			"CREATE TABLE {$t[ self::SIGNALS ]} (
  customer_id bigint(20) unsigned NOT NULL,
  orders_count int(10) unsigned NOT NULL DEFAULT 0,
  refunds_count int(10) unsigned NOT NULL DEFAULT 0,
  refunds_total decimal(14,2) NOT NULL DEFAULT 0,
  cancels_count int(10) unsigned NOT NULL DEFAULT 0,
  failed_payments_count int(10) unsigned NOT NULL DEFAULT 0,
  refused_count int(10) unsigned NOT NULL DEFAULT 0,
  chargebacks_count int(10) unsigned NOT NULL DEFAULT 0,
  net_total decimal(14,2) NOT NULL DEFAULT 0,
  currency char(3) NOT NULL DEFAULT '',
  first_order_at datetime DEFAULT NULL,
  last_order_at datetime DEFAULT NULL,
  computed_at datetime NOT NULL,
  PRIMARY KEY  (customer_id)
) {$collate};",
		];
	}
}
