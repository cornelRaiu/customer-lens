<?php

namespace CustomerLens\Tests\Unit;

use CustomerLens\Identity\Customers;
use CustomerLens\Settings;
use CustomerLens\Signals\Badges;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

final class BadgesTest extends TestCase {

	public function test_ordinals_read_like_english(): void {
		$labels = array_map( [ Badges::class, 'ordinal_label' ], [ 1, 2, 3, 4, 11, 12, 13, 21, 22, 23, 101, 111 ] );

		$this->assertSame( [ 'First order', '2nd order', '3rd order', '4th order', '11th order', '12th order', '13th order', '21st order', '22nd order', '23rd order', '101st order', '111th order' ], $labels );
	}

	public function test_a_counter_warns_from_its_threshold_and_turns_critical_from_the_critical_one(): void {
		$this->assertSame( Badges::NEUTRAL, Badges::level( 0, 1, 3 ) );
		$this->assertSame( Badges::WARNING, Badges::level( 1, 1, 3 ) );
		$this->assertSame( Badges::CRITICAL, Badges::level( 3, 1, 3 ) );
		$this->assertSame( Badges::NEUTRAL, Badges::level( 5, 0 ), 'A threshold of 0 never applies.' );
	}

	public function test_only_counters_above_zero_get_a_badge(): void {
		$badges = Badges::for_order( $this->signals( [ 'refused_count' => 2 ] ), null, 4, Settings::defaults(), null, false );

		$this->assertSame( [ 'ordinal', 'refused' ], array_column( $badges, 'key' ) );
		$this->assertSame( [ '4th order', '2 refused COD' ], array_column( $badges, 'label' ) );
		$this->assertSame( Badges::WARNING, $badges[1]['level'] );
	}

	public function test_three_refused_deliveries_are_critical_by_default(): void {
		$badges = Badges::for_order( $this->signals( [ 'refused_count' => 3 ] ), null, 2, Settings::defaults(), null, false );

		$this->assertSame( Badges::CRITICAL, $badges[1]['level'] );
	}

	public function test_a_first_order_warns_only_when_the_store_asks_for_it(): void {
		$settings = Settings::defaults();
		$this->assertSame( Badges::NEUTRAL, Badges::for_order( null, null, 1, $settings )[0]['level'] );

		$settings['first_order_warning'] = true;
		$this->assertSame( Badges::WARNING, Badges::for_order( null, null, 1, $settings )[0]['level'] );
	}

	public function test_flags_show_as_badges(): void {
		$watch   = (object) [ 'flag_status' => Customers::FLAG_WATCH ];
		$trusted = (object) [ 'flag_status' => Customers::FLAG_TRUSTED ];

		$this->assertSame( Badges::WARNING, Badges::for_order( null, $watch, 2, Settings::defaults() )[1]['level'] );
		$this->assertSame( Badges::GOOD, Badges::for_order( null, $trusted, 2, Settings::defaults() )[1]['level'] );
	}

	public function test_the_summary_keeps_the_ordinal_and_the_most_serious_badges(): void {
		$badges = Badges::for_order( $this->signals( [ 'refused_count' => 3, 'refunds_count' => 1, 'cancels_count' => 1 ] ), null, 5, Settings::defaults(), null, false );

		$this->assertSame( [ 'ordinal', 'refused', 'refunds' ], array_column( Badges::summary( $badges ), 'key' ) );
	}

	private function signals( array $counts ): array {
		return array_merge(
			[
				'orders_count'          => 1,
				'refunds_count'         => 0,
				'refunds_total'         => 0.0,
				'cancels_count'         => 0,
				'failed_payments_count' => 0,
				'refused_count'         => 0,
				'chargebacks_count'     => 0,
				'net_total'             => 0.0,
				'currency'              => 'EUR',
			],
			$counts
		);
	}
}
