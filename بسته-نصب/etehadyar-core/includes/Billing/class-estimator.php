<?php
/**
 * Usage estimation for billable operations.
 *
 * Also fixes a real defect inherited from the legacy plugin: it used
 * `str_word_count()`, which is byte-oriented and returns 0 for Persian text.
 * With a prepaid wallet that is no longer a cosmetic reporting bug — it means
 * every Persian content job is billed as zero words and the platform gives the
 * work away for free. The counter here is UTF-8 aware and handles Persian,
 * Arabic, and Latin scripts.
 *
 * @package Etehadyar\Billing
 */

namespace Etehadyar\Billing;

defined( 'ABSPATH' ) || exit;

/**
 * Quantity estimators.
 */
class Estimator {

	/**
	 * Characters of speech per minute, used to turn text into TTS minutes.
	 *
	 * Persian narration runs slower than English. This is deliberately
	 * conservative: over-estimating is refunded at reconciliation, while
	 * under-estimating means the platform eats the difference.
	 */
	const CHARS_PER_SPOKEN_MINUTE = 750;

	/**
	 * Count words in a way that works for Persian.
	 *
	 * `str_word_count()` only recognises ASCII letters, so a 2,000 word Persian
	 * article counts as zero. This splits on Unicode whitespace and counts
	 * tokens containing at least one letter or digit in any script.
	 *
	 * @param string $text Input text.
	 * @return int
	 */
	public static function word_count( $text ) {
		$text = (string) $text;

		if ( '' === trim( $text ) ) {
			return 0;
		}

		// Normalise zero-width joiners used inside Persian compound words so
		// "می‌رود" counts as one word rather than two.
		$text = str_replace( array( "\xE2\x80\x8C", "\xE2\x80\x8D" ), '', $text );

		// Split on any Unicode whitespace.
		$parts = preg_split( '/[\s\x{00A0}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY );

		if ( ! is_array( $parts ) ) {
			// preg_split can fail on invalid UTF-8; fall back to a byte split
			// rather than returning zero and billing nothing.
			$parts = preg_split( '/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY ) ?: array();
		}

		$count = 0;

		foreach ( $parts as $part ) {
			// A token counts only if it contains a letter or a number, so
			// standalone punctuation such as «» or — is not billed as a word.
			if ( preg_match( '/[\p{L}\p{N}]/u', $part ) ) {
				$count++;
			}
		}

		return $count;
	}

	/**
	 * Count characters, UTF-8 aware.
	 *
	 * @param string $text Input text.
	 * @return int
	 */
	public static function char_count( $text ) {
		$text = (string) $text;

		return function_exists( 'mb_strlen' ) ? mb_strlen( $text, 'UTF-8' ) : strlen( $text );
	}

	/**
	 * Billing units for a content generation request.
	 *
	 * Priced per 100 words. The requested length is used up front; the real
	 * word count reconciles afterwards.
	 *
	 * @param int $words Word count.
	 * @return float Units of `content_word`.
	 */
	public static function content_units( $words ) {
		return max( 0, (int) $words ) / 100;
	}

	/**
	 * Billing units for text-to-speech.
	 *
	 * @param string $text Text to speak.
	 * @return float Units of `voice_minute`.
	 */
	public static function tts_units( $text ) {
		$chars = self::char_count( $text );

		if ( $chars <= 0 ) {
			return 0;
		}

		return $chars / self::CHARS_PER_SPOKEN_MINUTE;
	}

	/**
	 * Billing units for transcription.
	 *
	 * @param int $seconds Audio duration in seconds.
	 * @return float Units of `transcribe_min`.
	 */
	public static function transcribe_units( $seconds ) {
		return max( 0, (int) $seconds ) / 60;
	}

	/**
	 * Billing units for a video build.
	 *
	 * @param int $seconds Video duration in seconds.
	 * @return float Units of `video_minute`.
	 */
	public static function video_units( $seconds ) {
		$seconds = max( 0, (int) $seconds );

		// A video job with no declared duration still costs the platform
		// something; bill a minimum of one minute rather than nothing.
		return $seconds > 0 ? $seconds / 60 : 1;
	}
}
