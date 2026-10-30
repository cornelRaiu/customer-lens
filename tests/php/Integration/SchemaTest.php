<?php

namespace CustomerLens\Tests\Integration;

use CustomerLens\Install\Schema;
use CustomerLens\Plugin;
use WP_UnitTestCase;

final class SchemaTest extends WP_UnitTestCase {

	public function test_the_plugin_runs_with_woocommerce_and_created_every_table(): void {
		global $wpdb;

		$this->assertTrue( Plugin::woocommerce_ready() );
		$this->assertSame( Schema::VERSION, (int) get_option( Schema::VERSION_OPTION ) );

		foreach ( Schema::tables() as $name ) {
			$table = Schema::table( $name );
			$this->assertSame( $table, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ), "{$table} is missing." );
		}
	}

	public function test_an_up_to_date_site_runs_no_schema_queries(): void {
		$queries = $this->capture_queries( [ Schema::class, 'maybe_upgrade' ] );
		$schema  = preg_grep( '/^\s*(CREATE|ALTER|DESCRIBE|SHOW)\s/i', $queries );

		$this->assertSame( [], array_values( $schema ) );
	}

	public function test_reinstalling_over_the_same_version_changes_no_table(): void {
		update_option( Schema::VERSION_OPTION, 0 );

		$queries = $this->capture_queries( [ Schema::class, 'maybe_upgrade' ] );
		$changes = preg_grep( '/^\s*(CREATE|ALTER)\s/i', $queries );

		$this->assertSame( [], array_values( $changes ), 'dbDelta found differences between the definitions and the tables they created.' );
		$this->assertSame( Schema::VERSION, (int) get_option( Schema::VERSION_OPTION ) );
	}

	public function test_an_event_can_only_be_recorded_once_per_dedupe_key(): void {
		global $wpdb;

		$event = [
			'customer_id' => 1,
			'order_id'    => 10,
			'type'        => 'refund',
			'source'      => 'woo',
			'dedupe_key'  => 'refund:11',
			'created_at'  => current_time( 'mysql', true ),
		];

		$this->assertSame( 1, $wpdb->insert( Schema::table( Schema::EVENTS ), $event ) );

		$suppressed = $wpdb->suppress_errors( true );
		$this->assertFalse( $wpdb->insert( Schema::table( Schema::EVENTS ), $event ) );
		$wpdb->suppress_errors( $suppressed );

		// Events without a key (notes, flags) are never deduplicated.
		$event['dedupe_key'] = null;
		$this->assertSame( 1, $wpdb->insert( Schema::table( Schema::EVENTS ), $event ) );
		$this->assertSame( 1, $wpdb->insert( Schema::table( Schema::EVENTS ), $event ) );
	}

	public function test_an_identity_is_stored_once_per_kind_and_hash(): void {
		global $wpdb;

		$identity = [
			'kind'       => 'phone',
			'value_norm' => '+40722123456',
			'hash'       => hash( 'sha256', 'phone:+40722123456' ),
			'first_seen' => current_time( 'mysql', true ),
			'last_seen'  => current_time( 'mysql', true ),
		];

		$this->assertSame( 1, $wpdb->insert( Schema::table( Schema::IDENTITIES ), $identity ) );

		$suppressed = $wpdb->suppress_errors( true );
		$this->assertFalse( $wpdb->insert( Schema::table( Schema::IDENTITIES ), $identity ) );
		$wpdb->suppress_errors( $suppressed );
	}

	/** @return string[] */
	private function capture_queries( callable $callback ): array {
		$queries = [];
		$capture = static function ( $query ) use ( &$queries ) {
			$queries[] = $query;
			return $query;
		};

		// Before the test case turns CREATE and DROP into their TEMPORARY forms.
		add_filter( 'query', $capture, 9 );
		$callback();
		remove_filter( 'query', $capture, 9 );

		return $queries;
	}
}
