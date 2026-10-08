<?php
/**
 * Iranian mobile number normalisation and validation.
 *
 * Users paste numbers in a dozen shapes — +98, 0098, 98, 09, with spaces,
 * dashes, zero-width characters, and very often in Persian or Arabic-Indic
 * digits. Everything is reduced to one canonical form (9XXXXXXXXX) before it
 * is stored or compared, so the same person can never end up with two
 * accounts just because they typed their number differently.
 *
 * @package Etehadyar\Auth
 */

namespace Etehadyar\Auth;

defined( 'ABSPATH' ) || exit;

/**
 * Phone number helper.
 */
class Phone {

	/**
	 * Persian digits (U+06F0–U+06F9).
	 */
	const PERSIAN_DIGITS = array( '۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹' );

	/**
	 * Arabic-Indic digits (U+0660–U+0669).
	 */
	const ARABIC_DIGITS = array( '٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩' );

	/**
	 * Known Iranian mobile operator prefixes, after the leading 9.
	 *
	 * Used only for a soft sanity check; the list is deliberately broad
	 * because operators add ranges regularly and rejecting a valid new range
	 * would lock real customers out.
	 *
	 * @var string[]
	 */
	const OPERATOR_PREFIXES = array( '0', '1', '2', '3', '5', '9' );

	/**
	 * Convert Persian/Arabic digits to ASCII.
	 *
	 * @param string $value Raw input.
	 * @return string
	 */
	public static function to_ascii_digits( $value ) {
		$value = (string) $value;
		$value = str_replace( self::PERSIAN_DIGITS, range( 0, 9 ), $value );
		$value = str_replace( self::ARABIC_DIGITS, range( 0, 9 ), $value );

		return $value;
	}

	/**
	 * Reduce any accepted input to the canonical 10-digit form.
	 *
	 * Canonical form is `9XXXXXXXXX` — no country code, no leading zero.
	 *
	 * @param string $value Raw input.
	 * @return string Empty string when the input is not a valid Iranian mobile.
	 */
	public static function normalise( $value ) {
		$value = self::to_ascii_digits( $value );

		// Strip everything that is not a digit: spaces, dashes, parentheses,
		// zero-width joiners and RTL marks pasted from Persian keyboards.
		$digits = preg_replace( '/\D+/', '', $value );

		if ( '' === $digits ) {
			return '';
		}

		// 0098XXXXXXXXXX
		if ( 0 === strpos( $digits, '0098' ) ) {
			$digits = substr( $digits, 4 );
		} elseif ( 0 === strpos( $digits, '98' ) && 12 === strlen( $digits ) ) {
			// 98XXXXXXXXXX — only when the length matches, so a local number
			// legitimately starting with 98 is not mangled.
			$digits = substr( $digits, 2 );
		} elseif ( 0 === strpos( $digits, '0' ) && 11 === strlen( $digits ) ) {
			// 09XXXXXXXXX
			$digits = substr( $digits, 1 );
		}

		if ( 10 !== strlen( $digits ) || '9' !== $digits[0] ) {
			return '';
		}

		if ( ! in_array( $digits[1], self::OPERATOR_PREFIXES, true ) ) {
			return '';
		}

		return $digits;
	}

	/**
	 * Whether the input is a valid Iranian mobile number.
	 *
	 * @param string $value Raw input.
	 * @return bool
	 */
	public static function is_valid( $value ) {
		return '' !== self::normalise( $value );
	}

	/**
	 * Local dialling format: 09XXXXXXXXX.
	 *
	 * This is what the SMS provider expects.
	 *
	 * @param string $value Raw or canonical input.
	 * @return string
	 */
	public static function to_local( $value ) {
		$canonical = self::normalise( $value );

		return $canonical ? '0' . $canonical : '';
	}

	/**
	 * E.164 format: +989XXXXXXXXX.
	 *
	 * @param string $value Raw or canonical input.
	 * @return string
	 */
	public static function to_e164( $value ) {
		$canonical = self::normalise( $value );

		return $canonical ? '+98' . $canonical : '';
	}

	/**
	 * Partially masked number for display and logs.
	 *
	 * Shows enough for a user to recognise their own number without printing
	 * a full identifier into a support ticket or audit row.
	 *
	 * @param string $value Raw or canonical input.
	 * @return string
	 */
	public static function mask( $value ) {
		$canonical = self::normalise( $value );

		if ( ! $canonical ) {
			return '';
		}

		// 9123456789 -> 0912***6789
		return '0' . substr( $canonical, 0, 3 ) . '***' . substr( $canonical, -4 );
	}

	/**
	 * Deterministic WordPress username derived from the number.
	 *
	 * @param string $value Raw or canonical input.
	 * @return string
	 */
	public static function to_username( $value ) {
		$canonical = self::normalise( $value );

		return $canonical ? '09' . substr( $canonical, 1 ) : '';
	}
}
