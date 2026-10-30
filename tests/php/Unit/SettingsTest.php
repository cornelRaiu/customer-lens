<?php

namespace CustomerLens\Tests\Unit;

use CustomerLens\Settings;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

final class SettingsTest extends TestCase {

	public function test_missing_settings_get_their_defaults(): void {
		$this->assertSame( Settings::defaults(), Settings::sanitize( [] ) );
		$this->assertSame( Settings::defaults(), Settings::sanitize( 'not an array' ) );
	}

	public function test_thresholds_are_whole_numbers_and_zero_turns_a_badge_off(): void {
		$settings = Settings::sanitize(
			[
				'refunds_warning'  => '3',
				'refused_critical' => '0',
				'cancels_warning'  => -4,
				'failed_warning'   => 'many',
			]
		);

		$this->assertSame( 3, $settings['refunds_warning'] );
		$this->assertSame( 0, $settings['refused_critical'] );
		$this->assertSame( 0, $settings['cancels_warning'] );
		$this->assertSame( Settings::defaults()['failed_warning'], $settings['failed_warning'] );
	}

	public function test_retention_stays_within_the_allowed_months(): void {
		$this->assertSame( Settings::RETENTION_MIN, Settings::sanitize( [ 'retention_months' => 1 ] )['retention_months'] );
		$this->assertSame( Settings::RETENTION_MAX, Settings::sanitize( [ 'retention_months' => 120 ] )['retention_months'] );
		$this->assertSame( 24, Settings::sanitize( [ 'retention_months' => '24' ] )['retention_months'] );
	}

	public function test_an_unticked_checkbox_saves_off_and_a_ticked_one_on(): void {
		$this->assertFalse( Settings::sanitize( [ 'delete_data' => '0' ] )['delete_data'] );
		$this->assertTrue( Settings::sanitize( [ 'first_order_warning' => '1' ] )['first_order_warning'] );
	}

	public function test_unknown_keys_are_dropped(): void {
		$this->assertArrayNotHasKey( 'score', Settings::sanitize( [ 'score' => 99 ] ) );
	}
}
