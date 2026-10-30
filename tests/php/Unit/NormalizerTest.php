<?php

namespace CustomerLens\Tests\Unit;

use CustomerLens\Identity\AddressNormalizer;
use CustomerLens\Identity\EmailNormalizer;
use CustomerLens\Identity\NameKey;
use CustomerLens\Identity\PhoneNormalizer;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

final class NormalizerTest extends TestCase {

	/** @dataProvider romanian_numbers */
	public function test_romanian_numbers_in_every_common_shape_give_one_e164( string $written ): void {
		$this->assertSame( '+40722123456', PhoneNormalizer::normalize( $written, 'RO' ) );
	}

	public static function romanian_numbers(): array {
		return [
			'national'          => [ '0722123456' ],
			'national, spaced'  => [ '0722 123 456' ],
			'national, dashed'  => [ '0722-123-456' ],
			'international'     => [ '+40722123456' ],
			'international, 00' => [ '0040 722 123 456' ],
			'brackets'          => [ '+40 (722) 123-456' ],
			'no leading zero'   => [ '722123456' ],
			'dots'              => [ '0722.123.456' ],
		];
	}

	public function test_a_foreign_number_keeps_its_country_and_a_national_one_uses_the_order_country(): void {
		$this->assertSame( '+36301234567', PhoneNormalizer::normalize( '+36 30 123 4567', 'RO' ) );
		$this->assertSame( '+36301234567', PhoneNormalizer::normalize( '06 30 123 4567', 'HU' ) );
	}

	public function test_values_that_cannot_be_phone_numbers_give_nothing(): void {
		$this->assertSame( '', PhoneNormalizer::normalize( '', 'RO' ) );
		$this->assertSame( '', PhoneNormalizer::normalize( '123', 'RO' ) );
		$this->assertSame( '', PhoneNormalizer::normalize( 'n/a', 'RO' ) );
		$this->assertSame( '', PhoneNormalizer::normalize( '0722123456', '' ) );
	}

	public function test_numbers_are_displayed_the_international_way(): void {
		$this->assertSame( '+40 722 123 456', PhoneNormalizer::display( '+40722123456' ) );
	}

	public function test_emails_are_lowercased_and_trimmed(): void {
		$this->assertSame( 'ioana.pop@example.com', EmailNormalizer::normalize( '  Ioana.Pop@Example.COM ' ) );
	}

	public function test_gmail_dots_and_tags_do_not_make_a_new_address(): void {
		$this->assertSame( 'ioanapop@gmail.com', EmailNormalizer::normalize( 'Ioana.Pop+shop@gmail.com' ) );
		$this->assertSame( 'ioanapop@gmail.com', EmailNormalizer::normalize( 'ioanapop@googlemail.com' ) );
		// Elsewhere dots and tags can be different mailboxes.
		$this->assertSame( 'ioana.pop+shop@example.com', EmailNormalizer::normalize( 'ioana.pop+shop@example.com' ) );
	}

	public function test_values_that_are_not_emails_give_nothing(): void {
		foreach ( [ '', 'ioana', '@example.com', 'ioana@', 'ioana pop@example.com', '+@gmail.com' ] as $value ) {
			$this->assertSame( '', EmailNormalizer::normalize( $value ), $value );
		}
	}

	public function test_the_same_address_written_differently_gives_one_key(): void {
		$key = AddressNormalizer::normalize( 'Str. Memorandumului nr. 12', 'Cluj-Napoca', '400 114' );

		$this->assertSame( 'memorandumului|12|cluj napoca|400114', $key );
		$this->assertSame( $key, AddressNormalizer::normalize( 'Strada Memorandumului 12', 'cluj napoca', '400114' ) );
		$this->assertSame( $key, AddressNormalizer::normalize( 'MEMORANDUMULUI, NR.12', 'Cluj Napoca', '400114' ) );
	}

	public function test_diacritics_are_folded_in_addresses(): void {
		$this->assertSame(
			AddressNormalizer::normalize( 'Șoseaua Ștefan cel Mare 3', 'București', '020121' ),
			AddressNormalizer::normalize( 'Soseaua Stefan cel Mare 3', 'Bucuresti', '020121' )
		);
	}

	public function test_an_address_needs_a_street_and_a_city(): void {
		$this->assertSame( '', AddressNormalizer::normalize( '12', 'Cluj-Napoca', '400114' ) );
		$this->assertSame( '', AddressNormalizer::normalize( 'Str. Memorandumului 12', '', '400114' ) );
	}

	public function test_an_address_key_is_shown_as_an_address_line(): void {
		$this->assertSame( 'memorandumului 12, cluj napoca 400114', AddressNormalizer::display( 'memorandumului|12|cluj napoca|400114' ) );
	}

	public function test_a_name_key_ignores_word_order_case_and_diacritics(): void {
		$key = NameKey::from( 'Ioana', 'Pop' );

		$this->assertSame( 8, strlen( $key ) );
		$this->assertSame( $key, NameKey::from( 'POP', 'ioana' ) );
		$this->assertSame( NameKey::from( 'Ștefan', 'Țurcanu' ), NameKey::from( 'stefan', 'turcanu' ) );
		$this->assertNotSame( $key, NameKey::from( 'Ioana', 'Popescu' ) );
		$this->assertSame( '', NameKey::from( '', ' ' ) );
	}
}
