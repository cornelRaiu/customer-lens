<?php

namespace CustomerLens\Install;

use CustomerLens\Settings;

defined( 'ABSPATH' ) || exit;

final class Uninstaller {

	/** Each site of a network has its own tables and settings, so each one decides for itself. */
	public static function uninstall(): void {
		if ( ! is_multisite() ) {
			self::run();
			return;
		}

		foreach ( get_sites(
			[
				'fields' => 'ids',
				'number' => 0,
			]
		) as $site_id ) {
			switch_to_blog( (int) $site_id );
			self::run();
			restore_current_blog();
		}
	}

	public static function run(): void {
		global $wpdb;

		// Block and refusal history is hard to rebuild, so it is only removed when the site chose that.
		if ( ! Settings::get( 'delete_data' ) ) {
			return;
		}

		foreach ( Schema::tables() as $name ) {
			$wpdb->query( 'DROP TABLE IF EXISTS ' . esc_sql( Schema::table( $name ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- Table names cannot be placeholders.
		}

		delete_option( Schema::VERSION_OPTION );
		delete_option( Settings::OPTION );
	}
}
