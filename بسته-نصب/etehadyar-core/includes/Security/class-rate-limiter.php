<?php
/**
 * Generic fixed-window rate limiter.
 *
 * @package Etehadyar\Security
 */

namespace Etehadyar\Security;

use Etehadyar\Core\Audit;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * A small, dependency-free limiter built on transients.
 *
 * Deliberately a fixed window rather than a sliding log: a sliding window
 * needs per-hit storage, and on a shared-hosting WordPress the object cache
 * is often just the database. A fixed window costs one row per key per
 * window, which is what a plugin can honestly afford.
 *
 * The trade-off is the usual one — a caller can send `limit` requests at the
 * end of one window and `limit` again at the start of the next. For abuse
 * protection on a public endpoint that is acceptable; it turns an unbounded
 * flood into a bounded one, which is the property that matters when each
 * request spends money upstream.
 */
class Rate_Limiter {

	/**
	 * Transient prefix.
	 */
	const PREFIX = 'etehadyar_rl_';

	/**
	 * Consume one unit against a bucket.
	 *
	 * @param string $bucket  Logical name, e.g. 'support_audio'.
	 * @param string $key     Caller identity (IP, user id, phone…).
	 * @param int    $limit   Allowed hits per window.
	 * @param int    $window  Window length in seconds.
	 * @return true|WP_Error
	 */
	public static function consume( $bucket, $key, $limit, $window ) {
		$limit  = max( 1, (int) $limit );
		$window = max( 1, (int) $window );

		$name  = self::PREFIX . md5( $bucket . '|' . $key );
		$hits  = get_transient( $name );
		$count = is_array( $hits ) ? (int) ( $hits['count'] ?? 0 ) : 0;

		if ( $count >= $limit ) {
			Audit::log(
				'security.rate_limited',
				array(
					'severity' => 'notice',
					'context'  => array(
						'bucket' => $bucket,
						'limit'  => $limit,
						'window' => $window,
					),
				)
			);

			return new WP_Error(
				'etehadyar_rate_limited',
				__( 'تعداد درخواست‌ها بیش از حد مجاز است. کمی بعد دوباره تلاش کنید.', 'etehadyar-core' ),
				array(
					'status'      => 429,
					'retry_after' => $window,
				)
			);
		}

		// The window must be anchored to its first hit. Calling set_transient()
		// with the full window on every hit would push the expiry forward
		// continuously, so a steady stream of requests would never let the
		// counter reset — the limit would become permanent instead of
		// per-window.
		//
		// The expiry is stored inside the payload rather than read back from
		// `_transient_timeout_*`: that option only exists when transients live
		// in the database, and a site using Redis or Memcached would silently
		// lose the anchor and fall back to a sliding window.
		if ( ! is_array( $hits ) ) {
			$hits = null;
		}

		$now = time();

		if ( null === $hits || empty( $hits['expires'] ) || $hits['expires'] <= $now ) {
			set_transient(
				$name,
				array( 'count' => 1, 'expires' => $now + $window ),
				$window
			);

			return true;
		}

		$remaining = max( 1, (int) $hits['expires'] - $now );

		set_transient(
			$name,
			array( 'count' => (int) $hits['count'] + 1, 'expires' => (int) $hits['expires'] ),
			$remaining
		);

		return true;
	}

	/**
	 * Best-effort client IP.
	 *
	 * Proxy headers are forgeable, so they are ignored unless the site
	 * explicitly opts in — the same rule the OTP service already follows.
	 * Trusting them by default would let an attacker bypass every limit by
	 * rotating a header value.
	 *
	 * @return string
	 */
	public static function client_ip() {
		/** This filter is documented in includes/Auth/class-otp-service.php */
		$trust = (bool) apply_filters( 'etehadyar_trust_proxy_headers', false );

		if ( $trust ) {
			foreach ( array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR' ) as $header ) {
				if ( empty( $_SERVER[ $header ] ) ) {
					continue;
				}

				$raw = sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) );
				$ip  = trim( explode( ',', $raw )[0] );

				if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
					return $ip;
				}
			}
		}

		$remote = isset( $_SERVER['REMOTE_ADDR'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
			: '';

		return filter_var( $remote, FILTER_VALIDATE_IP ) ? $remote : 'unknown';
	}

	/**
	 * Reset a bucket. Intended for tests and support tooling.
	 *
	 * @param string $bucket Bucket name.
	 * @param string $key    Caller identity.
	 */
	public static function reset( $bucket, $key ) {
		delete_transient( self::PREFIX . md5( $bucket . '|' . $key ) );
	}
}
