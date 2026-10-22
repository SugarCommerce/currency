<?php
/**
 * Untrusted input reaching the money helpers.
 *
 * @package   SugarCommerce\Currency
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Currency\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use SugarCommerce\Currency\Currencies;
use SugarCommerce\Currency\Money;

/**
 * A currency code routinely arrives from a query string — `?currency=`
 * on a headless checkout — and an amount arrives from a form field.
 * Neither may reach the rendered page or a fatal error.
 */
final class MoneySecurityTest extends TestCase {

	/* ─── Codes ─────────────────────────────────────────────────────── */

	#[DataProvider( 'hostile_codes' )]
	public function test_an_unrecognised_code_is_never_echoed( string $code ): void {
		$this->assertSame( '', Currencies::sanitize_code( $code ) );
		$this->assertSame( '', Currencies::symbol( $code ) );
		$this->assertSame( '', Currencies::name( $code ) );

		foreach ( array( Money::format( 100, $code ), Money::format_with_code( 100, $code ) ) as $rendered ) {
			$this->assertStringNotContainsString( '<', $rendered );
			$this->assertStringNotContainsString( '"', $rendered );
			$this->assertSame( '1.00', trim( $rendered ) );
		}
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function hostile_codes(): array {
		return array(
			'script tag'   => array( '<script>alert(1)</script>' ),
			'attribute'    => array( '" onload="alert(1)' ),
			'too short'    => array( 'US' ),
			'too long'     => array( 'USDD' ),
			'symbol'       => array( '€' ),
			'digits'       => array( '123' ),
			'empty'        => array( '' ),
			'null byte'    => array( "US\x00D" ),
			'entity'       => array( '&lt;b&gt;' ),
		);
	}

	/**
	 * An unknown but well-formed code is a data problem, not an attack,
	 * so it is shown rather than swallowed.
	 */
	public function test_a_plausible_unknown_code_is_kept(): void {
		$this->assertSame( 'XYZ', Currencies::sanitize_code( 'xyz' ) );
		$this->assertSame( 'XYZ', Currencies::symbol( 'XYZ' ) );
		$this->assertSame( '1.00 XYZ', Money::format_with_code( 100, 'xyz' ) );
	}

	public function test_known_codes_are_unaffected(): void {
		$this->assertSame( '$1.00', Money::format( 100, 'usd' ) );
		$this->assertSame( '1.00 USD', Money::format_with_code( 100, 'USD' ) );
		$this->assertSame( '¥1,000', Money::format( 1000, 'JPY' ) );
	}

	/* ─── Amounts ───────────────────────────────────────────────────── */

	/**
	 * Twenty digits in a price field overflow to a float, and PHP raises
	 * a `TypeError` at the return boundary — which reaches the user as a
	 * fatal rather than a validation message.
	 */
	#[DataProvider( 'oversized_amounts' )]
	public function test_an_unrepresentable_amount_is_refused( string $input ): void {
		$this->expectException( InvalidArgumentException::class );

		Money::parse( $input, 'USD' );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function oversized_amounts(): array {
		return array(
			'21 digits'  => array( '999999999999999999999' ),
			'int max'    => array( '9223372036854775807' ),
			'negative'   => array( '-999999999999999999999' ),
			'separators' => array( '999,999,999,999,999,999,999.99' ),
		);
	}

	#[DataProvider( 'ordinary_amounts' )]
	public function test_ordinary_amounts_still_parse( string $input, int $expected ): void {
		$this->assertSame( $expected, Money::parse( $input, 'USD' ) );
	}

	/**
	 * @return array<string, array{0: string, 1: int}>
	 */
	public static function ordinary_amounts(): array {
		return array(
			'plain'        => array( '49.99', 4999 ),
			'separators'   => array( '$1,234.56', 123456 ),
			'european'     => array( '€1.234,56', 123456 ),
			'negative'     => array( '-5.00', -500 ),
			'no fraction'  => array( '10', 1000 ),
			'nothing'      => array( 'abc', 0 ),
			'empty'        => array( '', 0 ),
			'large valid'  => array( '90000000000000', 9000000000000000 ),
		);
	}

	public function test_parsing_never_emits_a_type_error(): void {
		foreach ( array( '1e400', str_repeat( '9', 400 ), '.'. str_repeat( '9', 400 ) ) as $input ) {
			try {
				$this->assertIsInt( Money::parse( $input, 'USD' ) );
			} catch ( InvalidArgumentException ) {
				$this->addToAssertionCount( 1 );
			}
		}
	}

	/* ─── Hardening, 1.3.0 ──────────────────────────────────────────── */

	/**
	 * ICU prints an unknown currency code as the symbol, so a hostile code
	 * reached the page through the localised formatters.
	 */
	#[RequiresPhpExtension( 'intl' )]
	#[DataProvider( 'hostile_codes' )]
	public function test_localised_formats_never_echo_a_hostile_code( string $code ): void {
		foreach ( array( Money::format_localized( 100, $code, 'en_US' ), Money::format_localized_compact( 100, $code, 'en_US' ) ) as $rendered ) {
			$this->assertStringNotContainsString( '<', $rendered );
			$this->assertStringNotContainsString( '"', $rendered );
			$this->assertStringNotContainsString( '>', $rendered );
		}
	}

	/**
	 * The reason is written to be shown to the shopper, and the code is
	 * usually theirs.
	 */
	public function test_the_unsupported_currency_message_never_echoes_markup(): void {
		$reason = (string) Money::why_not_chargeable( 100, '<script>alert(1)</script>' );

		$this->assertStringNotContainsString( '<', $reason );
		$this->assertStringContainsString( 'not a supported currency', $reason );
		$this->assertSame( 'XYZ is not a supported currency.', Money::why_not_chargeable( 100, 'xyz' ) );
	}

	/**
	 * A locale from an Accept-Language header that ICU refuses fell
	 * through as an uncaught IntlException.
	 */
	#[RequiresPhpExtension( 'intl' )]
	public function test_an_unusable_locale_falls_back_rather_than_throwing(): void {
		$this->assertSame( '$1.00', Money::format_localized( 100, 'USD', str_repeat( 'x', 200 ) ) );
	}

	/**
	 * Above 2^53 the major part was read through a float and came back as
	 * a different, plausible amount instead of failing.
	 */
	public function test_large_amounts_parse_exactly(): void {
		$this->assertSame( 9007199254740993123, Money::parse( '90071992547409931.23', 'USD' ) );
		$this->assertSame( PHP_INT_MAX - 1, Money::parse( (string) ( PHP_INT_MAX - 1 ), 'JPY' ) );
		$this->assertSame( 1234, Money::parse( '1,234.00', 'JPY' ) );
	}

	/**
	 * A fraction finer than the currency's minor unit was truncated —
	 * `1.234` USD became 123 — and a zero-decimal currency rounded half
	 * up. Either way the stored price was not the one typed.
	 */
	#[DataProvider( 'excess_decimals' )]
	public function test_an_amount_finer_than_the_minor_unit_is_refused( string $input, string $code ): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'more decimal places than the currency allows' );

		Money::parse( $input, $code );
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function excess_decimals(): array {
		return array(
			'two decimal, three given'   => array( '1.234', 'USD' ),
			'two decimal, rounds up'     => array( '49.999', 'USD' ),
			'two decimal, negative'      => array( '-1.234', 'USD' ),
			'two decimal, european'      => array( '1.234,567', 'EUR' ),
			'two decimal, nonzero after' => array( '1.2301', 'USD' ),
			'zero decimal, half'         => array( '100.5', 'JPY' ),
			'zero decimal, under half'   => array( '1234.49', 'JPY' ),
			'zero decimal, grouped'      => array( '1,234.5', 'JPY' ),
			'zero decimal, trailing'     => array( '100.01', 'JPY' ),
			'three decimal, four given'  => array( '1.5001', 'BHD' ),
		);
	}

	/**
	 * Zeros past the exponent do not change the amount, so they are not
	 * a reason to refuse it.
	 */
	public function test_trailing_zeros_past_the_minor_unit_are_accepted(): void {
		$this->assertSame( 123, Money::parse( '1.230', 'USD' ) );
		$this->assertSame( 123, Money::parse( '1.23000', 'USD' ) );
		$this->assertSame( 100, Money::parse( '100.00', 'JPY' ) );
		$this->assertSame( 100, Money::parse( '100.', 'JPY' ) );
		$this->assertSame( 1500, Money::parse( '1.5000', 'BHD' ) );
		$this->assertSame( 4999, Money::parse( '49.99', 'USD' ) );
		$this->assertSame( 4990, Money::parse( '49.9', 'USD' ) );
	}

	public function test_the_smallest_integer_formats_rather_than_throwing(): void {
		$this->assertSame( '-$92,233,720,368,547,758.08', Money::format( PHP_INT_MIN, 'USD' ) );
		$this->assertSame( '-BD 0.005', Money::format( -5, 'BHD' ) );
	}

	/**
	 * 17.5% of 180 is exactly 31.5. Float arithmetic made it 31.499… and
	 * rounded it down, so "half up" was not.
	 */
	public function test_percentages_round_exact_ties_up(): void {
		$this->assertSame( 32, Money::percentage( 180, 17.5 ) );
		$this->assertSame( -32, Money::percentage( -180, 17.5 ) );
		$this->assertSame( 89, Money::percentage( 1000, 8.875 ) );
		$this->assertSame( 92233720368547758, Money::percentage( PHP_INT_MAX, 1.0 ) );
	}

	/**
	 * An overflowing or non-finite percentage used to warn and return 0 —
	 * a free order rather than an error.
	 */
	#[DataProvider( 'unrepresentable_percentages' )]
	public function test_an_unrepresentable_percentage_is_refused( int $amount, float $percent ): void {
		$this->expectException( InvalidArgumentException::class );

		Money::percentage( $amount, $percent );
	}

	/**
	 * @return array<string, array{0: int, 1: float}>
	 */
	public static function unrepresentable_percentages(): array {
		return array(
			'overflow'      => array( PHP_INT_MAX, 200.0 ),
			'huge rate'     => array( PHP_INT_MAX, 1e9 ),
			'not a number'  => array( 100, NAN ),
			'infinite'      => array( 100, INF ),
		);
	}

	public function test_rounding_to_a_valid_increment_is_exact_for_large_amounts(): void {
		$this->assertSame( 9007199254741000, Money::round_to_valid( 9007199254740993, 'ISK' ) );
		$this->assertSame( -600, Money::round_to_valid( -550, 'ISK' ) );
		$this->assertSame( -500, Money::round_to_valid( -549, 'ISK' ) );
	}
}
