<?php
/**
 * Integer-minor-unit money formatting and parsing.
 *
 * @package   SugarCommerce\Currency
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Currency;

/**
 * Class Money
 *
 * Formats and parses amounts held as integers in the smallest currency
 * unit — the representation Stripe, PayPal, Adyen and every sane
 * accounting system use.
 *
 * **Money is never a float.** `0.1 + 0.2` is not `0.3` in binary
 * floating point, and a rounding error in a total is a discrepancy
 * somebody eventually has to reconcile by hand. Amounts stay integer
 * from the database to the payment processor; this class exists only to
 * render them for humans and read them back.
 *
 * **Dividing by 100 is wrong for twenty currencies.** The exponent is 0
 * for fifteen (JPY, KRW, VND, the CFA francs) and 3 for five (BHD, JOD,
 * KWD, OMR, TND). A ¥1,000 order divided by 100 displays as ¥10.00, and
 * BHD 1.000 displays as BD 1000 — the first undercharges by two orders
 * of magnitude and the second overcharges by three. Everything here
 * consults {@see Currencies::decimals()} instead.
 *
 * Typical use:
 *
 *   Money::format( 4999, 'USD' );        // '$49.99'
 *   Money::format( 1000, 'JPY' );        // '¥1,000'
 *   Money::parse( '49.99', 'USD' );      // 4999
 *
 * @since 1.0.0
 */
final readonly class Money {

	/**
	 * Format an amount with its symbol.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $amount Amount in the smallest currency unit.
	 * @param string $code   ISO-4217 code.
	 *
	 * @return string e.g. `$49.99`, `¥1,000`, `BD 1.500`.
	 */
	public static function format( int $amount, string $code ): string {
		$symbol   = Currencies::symbol( $code );
		$rendered = self::decimal( $amount, $code );
		$negative = $amount < 0;

		if ( $negative ) {
			$rendered = ltrim( $rendered, '-' );
		}

		// A multi-letter symbol is a code, not a glyph, so it needs a
		// space: "BD 1.500", but "$49.99". An empty symbol means the code
		// was unrecognisable and got dropped — no leading space for it.
		$joined = match ( true ) {
			'' === $symbol            => $rendered,
			1 === mb_strlen( $symbol ) => $symbol . $rendered,
			default                    => $symbol . ' ' . $rendered,
		};

		return $negative ? '-' . $joined : $joined;
	}

	/**
	 * Format an amount, dropping minor units when they are zero.
	 *
	 * `$49` rather than `$49.00`, but `$49.99` untouched. For the places a
	 * price is being advertised rather than accounted for: a shop listing, a
	 * button, a pricing table. Every one of those reads better without two
	 * zeros nobody is going to pay.
	 *
	 * Never for a receipt, an invoice or a total. There the trailing zeros
	 * are the point — they say the number is exact, and a receipt that reads
	 * `$49` invites the question of what happened to the pennies.
	 *
	 * Currencies with no minor unit are unaffected, because there is nothing
	 * to drop: ¥1,000 is already ¥1,000.
	 *
	 * @since 1.1.0
	 *
	 * @param int    $amount Amount in the smallest currency unit.
	 * @param string $code   ISO-4217 code.
	 *
	 * @return string e.g. `$49`, `$49.99`, `¥1,000`.
	 */
	public static function format_compact( int $amount, string $code ): string {
		$formatted = self::format( $amount, $code );
		$digits     = Currencies::decimals( $code );

		if ( 0 === $digits || 0 !== $amount % ( 10 ** $digits ) ) {
			return $formatted;
		}

		// The fractional part is always at the end — every format this class
		// produces puts the symbol, the code and the minus sign in front —
		// so the separator and the zeros after it are the last $digits + 1
		// characters, whatever the separator happens to be.
		return substr( $formatted, 0, -( $digits + 1 ) );
	}

	/**
	 * Format an amount with its code rather than its symbol.
	 *
	 * Unambiguous, which matters wherever several currencies appear
	 * together — a report listing `$49.99` twice is not telling you
	 * whether those are the same currency.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $amount Amount in the smallest currency unit.
	 * @param string $code   ISO-4217 code.
	 *
	 * @return string e.g. `49.99 USD`.
	 */
	public static function format_with_code( int $amount, string $code ): string {
		$suffix = Currencies::sanitize_code( $code );

		return '' === $suffix
			? self::decimal( $amount, $code )
			: self::decimal( $amount, $code ) . ' ' . $suffix;
	}

	/**
	 * Format using the platform's locale rules.
	 *
	 * Places the symbol and separators the way the target locale expects
	 * — `1 234,56 €` in French, `€1,234.56` in English. Requires ext-intl
	 * and falls back to {@see self::format()} without it, for a code that
	 * is not three letters, or for a locale ICU refuses.
	 *
	 * The locale may come straight from an `Accept-Language` header: one
	 * ICU cannot use falls back rather than throwing. The code is checked
	 * before ICU sees it, because ICU prints an unknown code as the
	 * symbol and would otherwise echo `"><` into the page.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $amount Amount in the smallest currency unit.
	 * @param string $code   ISO-4217 code.
	 * @param string $locale Locale, or '' for the currency's default.
	 *
	 * @return string
	 */
	public static function format_localized( int $amount, string $code, string $locale = '' ): string {
		$safe = Currencies::sanitize_code( $code );

		if ( '' === $safe || ! class_exists( \NumberFormatter::class ) ) {
			return self::format( $amount, $code );
		}

		$locale = '' !== $locale ? $locale : Currencies::locale( $code );

		try {
			$formatter = new \NumberFormatter( $locale, \NumberFormatter::CURRENCY );
		} catch ( \Throwable ) {
			return self::format( $amount, $code );
		}

		$result = $formatter->formatCurrency( self::to_float( $amount, $code ), $safe );

		return false === $result ? self::format( $amount, $code ) : $result;
	}

	/**
	 * Format for a price being advertised, the way a locale writes one.
	 *
	 * format_compact() for another language: "29 €" to a German reader
	 * rather than "29,00 €", and "29,50 €" when there are cents to show.
	 * It cannot be had by trimming format_localized()'s output, because
	 * in many locales the symbol, not the fraction, is at the end.
	 *
	 * Requires ext-intl, and falls back to {@see self::format_compact()}
	 * without it, for a code that is not three letters, or when ICU
	 * cannot format the amount.
	 *
	 * @since 1.2.0
	 *
	 * @param int    $amount Amount in the smallest currency unit.
	 * @param string $code   ISO-4217 code.
	 * @param string $locale Locale tag, `pt-BR` or `pt_BR`.
	 *
	 * @return string e.g. `29 €`, `29,50 €`, `R$ 29`.
	 */
	public static function format_localized_compact( int $amount, string $code, string $locale ): string {
		$code = Currencies::sanitize_code( $code );

		// ICU prints an unknown code as the symbol, so only a well-formed
		// one is handed over; see format_localized().
		if ( '' === $code || ! class_exists( \NumberFormatter::class ) ) {
			return self::format_compact( $amount, $code );
		}

		try {
			$formatter = new \NumberFormatter( str_replace( '-', '_', trim( $locale ) ), \NumberFormatter::CURRENCY );
		} catch ( \Throwable ) {
			return self::format_compact( $amount, $code );
		}

		$digits = Currencies::decimals( $code );

		// Whole amounts drop the fraction; anything else keeps every digit
		// the currency has, so 29.50 never prints as 29.5.
		if ( 0 === $amount % ( 10 ** $digits ) ) {
			$formatter->setAttribute( \NumberFormatter::MIN_FRACTION_DIGITS, 0 );
			$formatter->setAttribute( \NumberFormatter::MAX_FRACTION_DIGITS, 0 );
		}

		$result = $formatter->formatCurrency( self::to_float( $amount, $code ), $code );

		return false === $result ? self::format_compact( $amount, $code ) : $result;
	}

	/**
	 * The decimal string for an amount, without any symbol.
	 *
	 * Thousands-separated, with exactly as many decimal places as the
	 * currency has.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $amount Amount in the smallest currency unit.
	 * @param string $code   ISO-4217 code.
	 *
	 * @return string e.g. `49.99`, `1,000`, `1.500`.
	 */
	public static function decimal( int $amount, string $code ): string {
		$decimals = Currencies::decimals( $code );

		if ( 0 === $decimals ) {
			return number_format( $amount );
		}

		$negative = $amount < 0;

		// Split the digits as a string rather than dividing. Converting to
		// float is what reintroduces the rounding error this class avoids,
		// and abs( PHP_INT_MIN ) is itself a float, so even integer
		// division cannot start from the absolute value.
		$digits = str_pad( ltrim( (string) $amount, '-' ), $decimals + 1, '0', STR_PAD_LEFT );
		$major  = (int) substr( $digits, 0, -$decimals );
		$minor  = substr( $digits, -$decimals );

		$rendered = number_format( $major ) . '.' . $minor;

		return $negative ? '-' . $rendered : $rendered;
	}

	/**
	 * The bare decimal for a form field.
	 *
	 * No thousands separators, since they do not survive a round trip
	 * through a number input.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $amount Amount in the smallest currency unit.
	 * @param string $code   ISO-4217 code.
	 *
	 * @return string e.g. `1234.56`.
	 */
	public static function input_value( int $amount, string $code ): string {
		return str_replace( ',', '', self::decimal( $amount, $code ) );
	}

	/**
	 * Parse human input back to the smallest currency unit.
	 *
	 * Accepts what people actually type: symbols, spaces, thousands
	 * separators, and either decimal convention — `€1.234,56` and
	 * `$1,234.56` both mean the same amount.
	 *
	 * @since 1.0.0
	 *
	 * @param string $input Raw input.
	 * @param string $code  ISO-4217 code.
	 *
	 * @return int Amount in the smallest currency unit. 0 when nothing
	 *             numeric was present.
	 *
	 * @throws \InvalidArgumentException When the amount is too large to
	 *                                   hold in an integer. This method
	 *                                   reads form input, so callers
	 *                                   should expect it — silently
	 *                                   clamping a money value would be
	 *                                   worse than refusing it.
	 */
	public static function parse( string $input, string $code ): int {
		$decimals = Currencies::decimals( $code );

		// Strip everything but digits, separators and a leading sign.
		$clean    = (string) preg_replace( '/[^\d.,\-]/u', '', trim( $input ) );
		$negative = str_starts_with( $clean, '-' );
		$clean    = str_replace( '-', '', $clean );

		if ( '' === $clean ) {
			return 0;
		}

		$clean = self::normalize_separators( $clean, $decimals );
		$parts = explode( '.', $clean, 2 );

		// Digits only on both sides. The major part is never routed
		// through a float: above 2^53 a float silently drops the last
		// digits, so a large figure would parse to a different amount
		// rather than failing.
		$major = (string) preg_replace( '/\D/', '', $parts[0] );
		$tail  = (string) preg_replace( '/\D/', '', $parts[1] ?? '' );

		// Pad or truncate the fraction to the currency's exponent rather
		// than trusting the input to have the right number of digits.
		$fraction = substr( str_pad( $tail, $decimals, '0' ), 0, $decimals );
		$amount   = self::digits_to_int( $major . $fraction );

		// A currency with no minor unit rounds half up on the first
		// dropped digit, as it always has.
		if ( 0 === $decimals && '' !== $tail && (int) $tail[0] >= 5 ) {
			$amount = self::to_int( $amount + 1 );
		}

		return $negative ? -$amount : $amount;
	}

	/**
	 * Read a run of decimal digits as an integer, exactly.
	 *
	 * @since 1.3.0
	 *
	 * @param string $digits Decimal digits only; '' reads as zero.
	 *
	 * @return int Non-negative.
	 *
	 * @throws \InvalidArgumentException When the value reaches PHP_INT_MAX.
	 */
	private static function digits_to_int( string $digits ): int {
		$digits = ltrim( $digits, '0' );

		if ( '' === $digits ) {
			return 0;
		}

		$max = (string) PHP_INT_MAX;

		// At equal length a lexical comparison is a numeric one.
		if ( strlen( $digits ) > strlen( $max ) || ( strlen( $digits ) === strlen( $max ) && strcmp( $digits, $max ) >= 0 ) ) {
			throw new \InvalidArgumentException( 'That amount is larger than this system can represent.' );
		}

		return (int) $digits;
	}

	/**
	 * Narrow to an integer, refusing anything that would not survive it.
	 *
	 * A twenty-digit figure typed into a price field overflows to a
	 * float, and PHP raises a `TypeError` at the return boundary rather
	 * than let it through — which reaches the user as a fatal error
	 * instead of a validation message. Rejecting it here makes the
	 * failure catchable and specific.
	 *
	 * @since 1.0.0
	 *
	 * @param int|float $value Candidate amount.
	 *
	 * @return int
	 *
	 * @throws \InvalidArgumentException When outside integer range.
	 */
	private static function to_int( int|float $value ): int {
		if ( is_int( $value ) ) {
			return $value;
		}

		if ( ! is_finite( $value ) || $value >= (float) PHP_INT_MAX || $value <= (float) PHP_INT_MIN ) {
			throw new \InvalidArgumentException( 'That amount is larger than this system can represent.' );
		}

		return (int) $value;
	}

	/**
	 * Convert to a float, for a formatter that demands one.
	 *
	 * Deliberately not public as a general conversion — use it to hand a
	 * value to something like `NumberFormatter`, never to store, compare,
	 * or do arithmetic with.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $amount Amount in the smallest currency unit.
	 * @param string $code   ISO-4217 code.
	 *
	 * @return float
	 */
	public static function to_float( int $amount, string $code ): float {
		return $amount / ( 10 ** Currencies::decimals( $code ) );
	}

	/**
	 * Currencies that must be sent as whole major units.
	 *
	 * ISK and UGX both moved to zero decimals, but Stripe still requires
	 * them expressed as two-decimal values with `00` in the minor
	 * position — 5 ISK is sent as 500, and fractions are rejected.
	 *
	 * HUF and TWD accept two-decimal charges but are treated as
	 * zero-decimal for payouts, where the amount must divide by 100.
	 *
	 * Either way the safe rule for an amount you will settle is the same:
	 * make it a multiple of 100.
	 */
	private const array HUNDRED_MULTIPLE = array( 'isk', 'ugx', 'huf', 'twd' );

	/**
	 * Whether an amount is one the processor will accept.
	 *
	 * Two rules, both of which produce an API rejection rather than a
	 * rounding difference, and neither of which is implied by the decimal
	 * exponent:
	 *
	 * **Three-decimal currencies settle in hundredths.** BHD, JOD, KWD,
	 * OMR and TND have an exponent of 3, but Stripe settles them to two
	 * places, so the amount must be a multiple of ten. BHD 1.234 is
	 * refused.
	 *
	 * **Four currencies must be whole major units.** ISK and UGX moved to
	 * zero decimals but are still expressed with `00` in the minor
	 * position; HUF and TWD are zero-decimal for payout purposes. All
	 * four need a multiple of 100.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $amount Amount in the smallest currency unit.
	 * @param string $code   ISO-4217 code.
	 *
	 * @return bool
	 */
	public static function is_valid_amount( int $amount, string $code ): bool {
		return 0 === $amount % self::increment( $code );
	}

	/**
	 * Whether an amount can actually be charged.
	 *
	 * Combines the three ways an amount is rejected at the API rather
	 * than by your own validation: below the minimum, above the maximum,
	 * or not a permitted increment.
	 *
	 * The minimum is the one that bites in practice — a 0.30 USD charge
	 * looks perfectly reasonable and is refused, because Stripe will not
	 * let its own fee exceed the payment.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $amount Amount in the smallest currency unit.
	 * @param string $code   ISO-4217 code.
	 *
	 * @return string|null Null when chargeable, else why not.
	 */
	public static function why_not_chargeable( int $amount, string $code ): ?string {
		$upper = strtoupper( trim( $code ) );

		// The code is usually caller-supplied and the message is meant to
		// be shown, so an unrecognised one is echoed only when it is three
		// plain letters.
		if ( ! Currencies::supports( $code ) ) {
			$safe = Currencies::sanitize_code( $code );

			return '' === $safe ? 'That is not a supported currency.' : $safe . ' is not a supported currency.';
		}

		if ( $amount < 0 ) {
			return 'Amount cannot be negative.';
		}

		$minimum = Currencies::minimum_charge( $code );

		// Zero is allowed: subscriptions use it for trials and full
		// coupons, and Stripe accepts it.
		if ( 0 !== $amount && $minimum > 0 && $amount < $minimum ) {
			return 'Minimum charge for ' . $upper . ' is ' . self::format( $minimum, $code ) . '.';
		}

		if ( $amount > Currencies::maximum_charge( $code ) ) {
			return 'Maximum charge for ' . $upper . ' is ' . self::format( Currencies::maximum_charge( $code ), $code ) . '.';
		}

		if ( ! self::is_valid_amount( $amount, $code ) ) {
			return $upper . ' amounts must be a multiple of ' . self::increment( $code ) . '.';
		}

		return null;
	}

	/**
	 * Whether an amount can be charged.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $amount Amount in the smallest currency unit.
	 * @param string $code   ISO-4217 code.
	 *
	 * @return bool
	 */
	public static function is_chargeable( int $amount, string $code ): bool {
		return null === self::why_not_chargeable( $amount, $code );
	}

	/**
	 * Round an amount to something the processor will accept.
	 *
	 * Rounds half away from zero, in integer arithmetic so an amount
	 * beyond 2^53 is not nudged by float rounding.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $amount Amount in the smallest currency unit.
	 * @param string $code   ISO-4217 code.
	 *
	 * @return int
	 *
	 * @throws \InvalidArgumentException When rounding up would pass the
	 *                                   integer range.
	 */
	public static function round_to_valid( int $amount, string $code ): int {
		$increment = self::increment( $code );
		$remainder = $amount % $increment;

		if ( 0 === $remainder ) {
			return $amount;
		}

		$down = $amount - $remainder;

		if ( 2 * abs( $remainder ) < $increment ) {
			return $down;
		}

		return self::to_int( $down + ( $amount < 0 ? -$increment : $increment ) );
	}

	/**
	 * The smallest amount step a currency permits.
	 *
	 * @since 1.0.0
	 *
	 * @param string $code ISO-4217 code.
	 *
	 * @return int 1, 10, or 100.
	 */
	public static function increment( string $code ): int {
		$lower = strtolower( trim( $code ) );

		if ( in_array( $lower, self::HUNDRED_MULTIPLE, true ) ) {
			return 100;
		}

		return Currencies::is_three_decimal( $code ) ? 10 : 1;
	}

	/**
	 * Apply a percentage, rounding half up.
	 *
	 * For discounts and tax. Kept here so the rounding rule is applied
	 * once rather than reinvented at each call site — inconsistent
	 * rounding is how an order total stops matching the sum of its lines.
	 *
	 * @since 1.0.0
	 *
	 * The arithmetic is integer. The percentage is read to six decimal
	 * places and the product is rounded exactly, because a float product
	 * misses ties: 17.5% of 180 is 31.5, which float arithmetic computes
	 * as 31.499… and rounds to 31.
	 *
	 * @param int   $amount  Amount in the smallest currency unit.
	 * @param float $percent Percentage, e.g. 20.0 for 20%. Read to six
	 *                       decimal places.
	 *
	 * @return int Rounded half away from zero.
	 *
	 * @throws \InvalidArgumentException When the percentage is not finite or
	 *                                   the result is outside integer range.
	 */
	public static function percentage( int $amount, float $percent ): int {
		if ( ! is_finite( $percent ) ) {
			throw new \InvalidArgumentException( 'A percentage must be a finite number.' );
		}

		$scaled = round( $percent * self::PERCENT_SCALE );

		// Beyond 10,000% the remainder × rate product below could pass
		// PHP_INT_MAX. Rates that size are not discounts or tax, so they
		// take the float path, which is still range-checked.
		if ( abs( $scaled ) >= self::PERCENT_SCALE * 10_000 ) {
			return self::to_int( round( $amount * $percent / 100 ) );
		}

		$rate        = (int) $scaled;
		$denominator = 100 * self::PERCENT_SCALE;

		// amount × rate ÷ denominator without overflowing the product:
		// the whole multiples of the denominator are multiplied directly,
		// and only the remainder (below 10^8) meets the rate.
		$whole     = intdiv( $amount, $denominator );
		$remainder = $amount % $denominator;
		$product   = $remainder * $rate;
		$result    = self::to_int( $whole * $rate ) + intdiv( $product, $denominator );
		$dropped   = $product % $denominator;

		if ( 2 * abs( $dropped ) >= $denominator ) {
			$result += $product < 0 ? -1 : 1;
		}

		return self::to_int( $result );
	}

	/**
	 * Decimal places of a percentage that {@see self::percentage()}
	 * honours, as a power of ten.
	 */
	private const int PERCENT_SCALE = 1_000_000;

	/**
	 * Split an amount into shares that still sum to the original.
	 *
	 * Dividing 100 by 3 and rounding each share gives 33+33+33 = 99, and
	 * the missing penny turns up in a reconciliation months later. The
	 * remainder is distributed one unit at a time across the first
	 * shares, so the total always holds.
	 *
	 * @since 1.0.0
	 *
	 * @param int $amount Amount in the smallest currency unit.
	 * @param int $parts  How many shares.
	 *
	 * @return list<int> Shares summing exactly to `$amount`.
	 *
	 * @throws \InvalidArgumentException When asked for fewer than one share.
	 */
	public static function allocate( int $amount, int $parts ): array {
		if ( $parts < 1 ) {
			throw new \InvalidArgumentException( 'Cannot split an amount into fewer than one share.' );
		}

		$base      = intdiv( $amount, $parts );
		$remainder = $amount - ( $base * $parts );
		$extra     = abs( $remainder );
		$step      = $remainder > 0 ? 1 : -1;
		$shares    = array();

		for ( $i = 0; $i < $parts; $i++ ) {
			$shares[] = $i < $extra ? $base + $step : $base;
		}

		return $shares;
	}

	/**
	 * Resolve mixed decimal conventions to a single dot.
	 *
	 * `1.234,56` and `1,234.56` are the same amount written for different
	 * audiences, and both arrive from real forms. The rightmost separator
	 * is the decimal one when what follows it is shorter than the
	 * currency's exponent or the two separators differ.
	 *
	 * @since 1.0.0
	 *
	 * @param string $value    Digits and separators only.
	 * @param int    $decimals Currency exponent.
	 *
	 * @return string Digits with at most one dot.
	 */
	private static function normalize_separators( string $value, int $decimals ): string {
		$last_dot   = strrpos( $value, '.' );
		$last_comma = strrpos( $value, ',' );

		// Both present: the rightmost is the decimal separator.
		if ( false !== $last_dot && false !== $last_comma ) {
			return $last_comma > $last_dot
				? str_replace( ',', '.', str_replace( '.', '', $value ) )
				: str_replace( ',', '', $value );
		}

		// Only commas. Grouped thousands ("1,234,567") have three digits
		// after each; a single comma with a short tail is a decimal mark.
		if ( false !== $last_comma ) {
			$tail = strlen( $value ) - $last_comma - 1;

			return ( 3 === $tail && substr_count( $value, ',' ) >= 1 && $decimals !== 3 )
				? str_replace( ',', '', $value )
				: str_replace( ',', '.', $value );
		}

		// Only dots. Several means thousands separators.
		if ( substr_count( $value, '.' ) > 1 ) {
			return str_replace( '.', '', $value );
		}

		return $value;
	}
}
