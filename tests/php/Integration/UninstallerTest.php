<?php

namespace CustomerLens\Tests\Integration;

use CustomerLens\Install\Schema;
use CustomerLens\Install\Uninstaller;
use CustomerLens\Settings;
use WP_UnitTestCase;

final class UninstallerTest extends WP_UnitTestCase {

	public function test_data_is_kept_by_default(): void {
		update_option( Settings::OPTION, [ 'refunds_warning' => 2 ] );

		$this->assertSame( [], $this->dropped_tables() );
		$this->assertSame( Schema::VERSION, (int) get_option( Schema::VERSION_OPTION ) );
		$this->assertIsArray( get_option( Settings::OPTION ) );
	}

	public function test_tables_and_options_are_deleted_when_the_site_chose_that(): void {
		update_option( Settings::OPTION, [ 'delete_data' => true ] );

		$expected = array_map( [ Schema::class, 'table' ], Schema::tables() );

		$this->assertSame( $expected, $this->dropped_tables() );
		$this->assertFalse( get_option( Schema::VERSION_OPTION ) );
		$this->assertFalse( get_option( Settings::OPTION ) );
	}

	public function test_each_site_of_a_network_decides_for_itself(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Runs with WP_MULTISITE=1.' );
		}

		self::factory()->blog->create();
		$deletes = self::factory()->blog->create();

		switch_to_blog( $deletes );
		update_option( Settings::OPTION, [ 'delete_data' => true ] );
		$expected = array_map( [ Schema::class, 'table' ], Schema::tables() );
		restore_current_blog();

		$this->assertSame( $expected, $this->dropped_tables() );
	}

	/**
	 * Runs the uninstaller and returns the tables it dropped. The test case turns DROP TABLE into
	 * DROP TEMPORARY TABLE, so the real tables survive for the next tests.
	 *
	 * @return string[]
	 */
	private function dropped_tables(): array {
		$tables  = [];
		$capture = static function ( $query ) use ( &$tables ) {
			if ( preg_match( '/^DROP TABLE IF EXISTS (\S+)$/', $query, $match ) ) {
				$tables[] = $match[1];
			}
			return $query;
		};

		add_filter( 'query', $capture, 9 );
		Uninstaller::uninstall();
		remove_filter( 'query', $capture, 9 );

		return $tables;
	}
}
