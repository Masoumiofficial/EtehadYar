<?php
/**
 * One-time password issue and verification.
 *
 * Design notes that matter for security:
 *
 * - Codes are stored as a salted SHA-256 hash, never in plaintext.
 * - Verification uses `hash_equals` to avoid timing leaks.
 * - Enumeration is blocked: whether a number already has an account is never
 *   revealed before a code is verified, and rate-limit responses look the same
 *   for known and unknown numbers.
 * - Three independent limits apply: per number (cooldown + hourly), per IP
 *   (hourly), and per challenge (max verification attempts). Any one of them
 *   alone is easy to work around; together they make bulk abuse expensive.
 * - Every issued challenge invalidates the previous one for that number, so a
 *   user who requests three codes cannot be confused into replaying an old one.
 *
 * @package Etehadyar\Auth
 */

namespace Etehadyar\Auth;

use Etehadyar\Core\Audit;
use Etehadyar\Core\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * OTP lifecycle manager.
 */
class OTP_Service {

	/**
	 * Seconds a code stays valid.
	 */
	const TTL = 120;

	/**
	 * Seconds a number must wait before requesting another code.
	 */
	const RESEND_COOLDOWN = 60;

	/**
	 * Maximum codes per number per hour.
	 */
	const MAX_PER_PHONE_HOUR = 5;

	/**
	 * Maximum codes per IP address per hour.
	 */
	const MAX_PER_IP_HOUR = 10;

	/**
	 * Maximum wrong guesses before a challenge is burned.
	 */
	const MAX_ATTEMPTS = 5;

	/**
	 * Digits in a code.
	 */
	const CODE_LENGTH = 5;

	/**
	 * Issue a code and dispatch it by SMS.
	 *
	 * @param string $phone   Raw phone input.
	 * @param string $purpose Challenge purpose.
	 * @return array|\WP_Error { expires_in:int, resend_in:int, masked:string }
	 */
	public static function request( $phone, $purpose = 'login' ) {
		global $wpdb;

		$canonical = Phone::normalise( $phone );

		if ( ! $canonical ) {
			return new \WP_Error(
				'etehadyar_otp_bad_phone',
				__( 'شماره موبایل واردشده معتبر نیست.', 'etehadyar-core' ),
				array( 'status' => 400 )
			);
		}

		$purpose = sanitize_key( $purpose ) ?: 'login';
		$table   = Schema::table( 'otp' );

		if ( ! Schema::table_exists( $table ) ) {
			return new \WP_Error(
				'etehadyar_otp_unavailable',
				__( 'سرویس ورود در دسترس نیست. با مدیر سایت تماس بگیرید.', 'etehadyar-core' ),
				array( 'status' => 503 )
			);
		}

		$limited = self::check_rate_limits( $canonical, $purpose );

		if ( is_wp_error( $limited ) ) {
			return $limited;
		}

		$gateway = Gateway_Registry::active();

		if ( ! $gateway || ! $gateway->is_configured() ) {
			return new \WP_Error(
				'etehadyar_otp_gateway',
				__( 'سرویس پیامک پیکربندی نشده است. با مدیر سایت تماس بگیرید.', 'etehadyar-core' ),
				array( 'status' => 503 )
			);
		}

		$code = self::generate_code();

		// Retire any outstanding challenge for this number and purpose so only
		// the newest code can ever be used.
		// phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE `{$table}` SET consumed_at = %s WHERE phone = %s AND purpose = %s AND consumed_at IS NULL",
				current_time( 'mysql', true ),
				$canonical,
				$purpose
			)
		);

		$now = time();

		$inserted = $wpdb->insert(
			$table,
			array(
				'phone'      => $canonical,
				'code_hash'  => self::hash( $code, $canonical ),
				'purpose'    => $purpose,
				'attempts'   => 0,
				'ip'         => self::client_ip(),
				'user_agent' => substr( (string) ( $_SERVER['HTTP_USER_AGENT'] ?? '' ), 0, 255 ),
				'expires_at' => gmdate( 'Y-m-d H:i:s', $now + self::TTL ),
				'created_at' => gmdate( 'Y-m-d H:i:s', $now ),
			),
			array( '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s' )
		);

		if ( ! $inserted ) {
			return new \WP_Error(
				'etehadyar_otp_store_failed',
				__( 'ثبت درخواست ورود انجام نشد. دوباره تلاش کنید.', 'etehadyar-core' ),
				array( 'status' => 500 )
			);
		}

		$sent = $gateway->send_otp( $canonical, $code );

		if ( is_wp_error( $sent ) ) {
			// Burn the challenge: a code the user never received must not stay
			// live, and leaving it would also consume their resend budget.
			$wpdb->update(
				$table,
				array( 'consumed_at' => gmdate( 'Y-m-d H:i:s', $now ) ),
				array( 'id' => (int) $wpdb->insert_id ),
				array( '%s' ),
				array( '%d' )
			);

			Audit::log(
				'auth.otp_send_failed',
				array(
					'severity' => 'error',
					'context'  => array(
						'phone'  => Phone::mask( $canonical ),
						'reason' => $sent->get_error_code(),
					),
				)
			);

			return new \WP_Error(
				'etehadyar_otp_send_failed',
				self::public_send_error( $sent ),
				array( 'status' => 502 )
			);
		}

		self::touch_rate_counters( $canonical );

		Audit::log(
			'auth.otp_requested',
			array(
				'severity' => 'info',
				'context'  => array( 'phone' => Phone::mask( $canonical ) ),
			)
		);

		return array(
			'expires_in' => self::TTL,
			'resend_in'  => self::RESEND_COOLDOWN,
			'masked'     => Phone::mask( $canonical ),
		);
	}

	/**
	 * Verify a submitted code.
	 *
	 * @param string $phone   Raw phone input.
	 * @param string $code    Submitted code.
	 * @param string $purpose Challenge purpose.
	 * @return string|\WP_Error Canonical phone number on success.
	 */
	public static function verify( $phone, $code, $purpose = 'login' ) {
		global $wpdb;

		$canonical = Phone::normalise( $phone );
		$code      = preg_replace( '/\D+/', '', Phone::to_ascii_digits( $code ) );
		$purpose   = sanitize_key( $purpose ) ?: 'login';

		if ( ! $canonical || '' === $code ) {
			return new \WP_Error(
				'etehadyar_otp_invalid',
				__( 'کد واردشده نادرست است.', 'etehadyar-core' ),
				array( 'status' => 400 )
			);
		}

		$table = Schema::table( 'otp' );

		if ( ! Schema::table_exists( $table ) ) {
			return new \WP_Error(
				'etehadyar_otp_unavailable',
				__( 'سرویس ورود در دسترس نیست.', 'etehadyar-core' ),
				array( 'status' => 503 )
			);
		}

		// phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM `{$table}`
				 WHERE phone = %s AND purpose = %s AND consumed_at IS NULL
				 ORDER BY id DESC LIMIT 1",
				$canonical,
				$purpose
			),
			ARRAY_A
		);

		if ( ! $row ) {
			return new \WP_Error(
				'etehadyar_otp_missing',
				__( 'کد فعالی برای این شماره وجود ندارد. دوباره درخواست دهید.', 'etehadyar-core' ),
				array( 'status' => 400 )
			);
		}

		if ( strtotime( $row['expires_at'] . ' UTC' ) < time() ) {
			return new \WP_Error(
				'etehadyar_otp_expired',
				__( 'مهلت کد به پایان رسیده است. کد جدید بگیرید.', 'etehadyar-core' ),
				array( 'status' => 400 )
			);
		}

		if ( (int) $row['attempts'] >= self::MAX_ATTEMPTS ) {
			$wpdb->update(
				$table,
				array( 'consumed_at' => gmdate( 'Y-m-d H:i:s' ) ),
				array( 'id' => (int) $row['id'] ),
				array( '%s' ),
				array( '%d' )
			);

			Audit::log(
				'auth.otp_locked',
				array(
					'severity' => 'warning',
					'context'  => array( 'phone' => Phone::mask( $canonical ) ),
				)
			);

			return new \WP_Error(
				'etehadyar_otp_locked',
				__( 'تعداد تلاش‌های ناموفق زیاد بود. لطفاً کد جدیدی درخواست کنید.', 'etehadyar-core' ),
				array( 'status' => 429 )
			);
		}

		if ( ! hash_equals( (string) $row['code_hash'], self::hash( $code, $canonical ) ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery
			$wpdb->query(
				$wpdb->prepare( "UPDATE `{$table}` SET attempts = attempts + 1 WHERE id = %d", (int) $row['id'] )
			);

			$remaining = max( 0, self::MAX_ATTEMPTS - ( (int) $row['attempts'] + 1 ) );

			return new \WP_Error(
				'etehadyar_otp_invalid',
				sprintf(
					/* translators: %d: remaining attempts. */
					_n(
						'کد واردشده نادرست است. %d تلاش باقی مانده است.',
						'کد واردشده نادرست است. %d تلاش باقی مانده است.',
						$remaining,
						'etehadyar-core'
					),
					$remaining
				),
				array(
					'status'    => 400,
					'remaining' => $remaining,
				)
			);
		}

		// Single-use: mark consumed immediately so a replayed request fails.
		$wpdb->update(
			$table,
			array( 'consumed_at' => gmdate( 'Y-m-d H:i:s' ) ),
			array( 'id' => (int) $row['id'] ),
			array( '%s' ),
			array( '%d' )
		);

		delete_transient( self::phone_key( $canonical ) );

		return $canonical;
	}

	/**
	 * Enforce cooldown and hourly quotas.
	 *
	 * @param string $canonical Canonical number.
	 * @param string $purpose   Challenge purpose.
	 * @return true|\WP_Error
	 */
	protected static function check_rate_limits( $canonical, $purpose ) {
		global $wpdb;

		$cooldown = get_transient( self::phone_key( $canonical ) );

		if ( $cooldown ) {
			$wait = max( 1, (int) $cooldown - time() );

			return new \WP_Error(
				'etehadyar_otp_cooldown',
				sprintf(
					/* translators: %d: seconds to wait. */
					__( 'برای درخواست کد جدید %d ثانیه صبر کنید.', 'etehadyar-core' ),
					$wait
				),
				array(
					'status'    => 429,
					'retry_after' => $wait,
				)
			);
		}

		$table = Schema::table( 'otp' );
		$since = gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS );

		// phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery
		$per_phone = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM `{$table}` WHERE phone = %s AND purpose = %s AND created_at > %s",
				$canonical,
				$purpose,
				$since
			)
		);

		if ( $per_phone >= self::MAX_PER_PHONE_HOUR ) {
			Audit::log(
				'auth.otp_rate_limited',
				array(
					'severity' => 'warning',
					'context'  => array(
						'phone' => Phone::mask( $canonical ),
						'scope' => 'phone',
					),
				)
			);

			return new \WP_Error(
				'etehadyar_otp_too_many',
				__( 'تعداد درخواست‌های کد برای این شماره زیاد بوده است. یک ساعت دیگر تلاش کنید.', 'etehadyar-core' ),
				array( 'status' => 429 )
			);
		}

		$ip = self::client_ip();

		if ( $ip ) {
			// phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery
			$per_ip = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM `{$table}` WHERE ip = %s AND created_at > %s",
					$ip,
					$since
				)
			);

			if ( $per_ip >= self::MAX_PER_IP_HOUR ) {
				Audit::log(
					'auth.otp_rate_limited',
					array(
						'severity' => 'warning',
						'context'  => array( 'scope' => 'ip' ),
					)
				);

				return new \WP_Error(
					'etehadyar_otp_too_many',
					__( 'تعداد درخواست‌ها از این دستگاه زیاد بوده است. کمی بعد تلاش کنید.', 'etehadyar-core' ),
					array( 'status' => 429 )
				);
			}
		}

		return true;
	}

	/**
	 * Start the resend cooldown for a number.
	 *
	 * @param string $canonical Canonical number.
	 */
	protected static function touch_rate_counters( $canonical ) {
		set_transient(
			self::phone_key( $canonical ),
			time() + self::RESEND_COOLDOWN,
			self::RESEND_COOLDOWN
		);
	}

	/**
	 * Transient key for a number's cooldown.
	 *
	 * The number itself is hashed so a transient dump does not become a list
	 * of everyone's phone numbers.
	 *
	 * @param string $canonical Canonical number.
	 * @return string
	 */
	protected static function phone_key( $canonical ) {
		return 'etehadyar_otp_cd_' . substr( hash( 'sha256', $canonical ), 0, 24 );
	}

	/**
	 * Hash a code for storage or comparison.
	 *
	 * @param string $code      Plain code.
	 * @param string $canonical Canonical number, used as a per-user salt.
	 * @return string
	 */
	protected static function hash( $code, $canonical ) {
		$salt = defined( 'AUTH_SALT' ) ? AUTH_SALT : 'etehadyar';

		return hash( 'sha256', $salt . '|' . $canonical . '|' . $code );
	}

	/**
	 * Generate a cryptographically strong numeric code.
	 *
	 * `random_int` is used rather than `rand`/`mt_rand`: predictable codes
	 * would let an attacker guess the OTP without ever receiving the SMS.
	 *
	 * @return string
	 */
	protected static function generate_code() {
		$min = (int) str_pad( '1', self::CODE_LENGTH, '0' );
		$max = (int) str_repeat( '9', self::CODE_LENGTH );

		try {
			$code = random_int( $min, $max );
		} catch ( \Exception $e ) {
			$code = $min + ( (int) hexdec( bin2hex( wp_generate_password( 4, false ) ) ) % ( $max - $min ) );
		}

		return (string) $code;
	}

	/**
	 * Best-effort client IP.
	 *
	 * Proxy headers are only trusted when the site explicitly opts in, because
	 * on a site without a trusted proxy anyone can forge X-Forwarded-For and
	 * sidestep the per-IP quota entirely.
	 *
	 * @return string
	 */
	public static function client_ip() {
		$ip = $_SERVER['REMOTE_ADDR'] ?? '';

		/**
		 * Filter whether forwarded headers may be trusted.
		 *
		 * Enable only behind a proxy or CDN that overwrites the header.
		 *
		 * @param bool $trust Default false.
		 */
		if ( apply_filters( 'etehadyar_trust_proxy_headers', false ) ) {
			$forwarded = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';

			if ( $forwarded ) {
				$parts = explode( ',', $forwarded );
				$ip    = trim( $parts[0] );
			}
		}

		$ip = filter_var( $ip, FILTER_VALIDATE_IP );

		return $ip ? substr( $ip, 0, 45 ) : '';
	}

	/**
	 * Convert a gateway error into something safe to show a visitor.
	 *
	 * Provider wording can leak account state ("credit exhausted", "user
	 * inactive"); visitors get a neutral message while administrators get the
	 * detail through the audit log.
	 *
	 * @param \WP_Error $error Gateway error.
	 * @return string
	 */
	protected static function public_send_error( $error ) {
		$code = $error->get_error_code();

		// Blacklist and invalid-number cases are genuinely actionable by the
		// user, so those are passed through.
		$passthrough = array( 'etehadyar_sms_35', 'etehadyar_sms_18', 'etehadyar_sms_16', 'etehadyar_sms_bad_number' );

		if ( in_array( $code, $passthrough, true ) ) {
			return $error->get_error_message();
		}

		return __( 'ارسال پیامک ممکن نشد. لطفاً کمی بعد دوباره تلاش کنید.', 'etehadyar-core' );
	}

	/**
	 * Delete expired challenges.
	 *
	 * @param int $days Retain consumed rows for this many days.
	 * @return int Rows removed.
	 */
	public static function purge( $days = 2 ) {
		global $wpdb;

		$table = Schema::table( 'otp' );

		if ( ! Schema::table_exists( $table ) ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM `{$table}` WHERE created_at < %s",
				gmdate( 'Y-m-d H:i:s', time() - ( max( 1, (int) $days ) * DAY_IN_SECONDS ) )
			)
		);
	}
}
