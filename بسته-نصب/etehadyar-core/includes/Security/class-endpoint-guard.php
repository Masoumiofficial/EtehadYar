<?php
/**
 * Rate limits the legacy plugin's public REST endpoints.
 *
 * @package Etehadyar\Security
 */

namespace Etehadyar\Security;

use Etehadyar\Core\Capabilities;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * The legacy plugin registers five `eaiw/v1` routes with
 * `permission_callback => __return_true`. One of them, `/support/audio`,
 * accepts an 8 MB upload from an anonymous caller and then sends it to
 * OpenAI Whisper for transcription — every request spends real money, and
 * nothing throttles it. A trivial loop against that URL is an unbounded bill.
 *
 * `/chat` already implements its own IP limiter inside the callback, so it is
 * left alone rather than double-counted. The rest get a limit here.
 *
 * This hooks `rest_pre_dispatch`, which runs after the route is matched but
 * before the callback executes, so a rejected request never reaches the
 * expensive code path.
 */
class Endpoint_Guard {

	/**
	 * Default limits: route pattern => [ bucket, limit, window seconds ].
	 *
	 * @return array
	 */
	public static function limits() {
		/**
		 * Rate limits applied to legacy public endpoints.
		 *
		 * @param array $limits Route => [ bucket, limit, window ].
		 */
		return apply_filters(
			'etehadyar_public_endpoint_limits',
			array(
				// Costs money on every call: the tightest limit of the set.
				'#^/eaiw/v1/support/audio#' => array( 'support_audio', 3, HOUR_IN_SECONDS ),
				// Writes a support row and sends a notification email.
				'#^/eaiw/v1/support$#'      => array( 'support_create', 10, HOUR_IN_SECONDS ),
				// Cheap, but trivially floodable into a table full of junk.
				'#^/eaiw/v1/feedback$#'     => array( 'feedback', 30, HOUR_IN_SECONDS ),
				// Ticket lookup: throttled to blunt access-token guessing.
				'#^/eaiw/v1/support/[a-f0-9-]{36}#' => array( 'support_read', 60, HOUR_IN_SECONDS ),
			)
		);
	}

	/**
	 * Attach hooks.
	 */
	public static function boot() {
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'guard' ), 10, 3 );
	}

	/**
	 * Reject over-limit requests before the callback runs.
	 *
	 * @param mixed            $result  Short-circuit value.
	 * @param \WP_REST_Server  $server  Server instance.
	 * @param \WP_REST_Request $request Request.
	 * @return mixed
	 */
	public static function guard( $result, $server, $request ) {
		unset( $server );

		// Something earlier already decided; do not override it.
		if ( null !== $result ) {
			return $result;
		}

		$route = is_object( $request ) && method_exists( $request, 'get_route' )
			? (string) $request->get_route()
			: '';

		if ( '' === $route ) {
			return $result;
		}

		// Staff are exempt: an administrator testing support tooling should
		// not lock themselves out.
		if ( is_user_logged_in() && current_user_can( Capabilities::MANAGE_PLATFORM ) ) {
			return $result;
		}

		foreach ( self::limits() as $pattern => $config ) {
			if ( ! preg_match( $pattern, $route ) ) {
				continue;
			}

			list( $bucket, $limit, $window ) = $config;

			// Signed-in callers are limited per account, anonymous ones per
			// IP. Keying a logged-in user by IP would punish everyone behind
			// one office NAT for a single abuser.
			$key = is_user_logged_in()
				? 'u:' . get_current_user_id()
				: 'ip:' . Rate_Limiter::client_ip();

			$check = Rate_Limiter::consume( $bucket, $key, $limit, $window );

			if ( is_wp_error( $check ) ) {
				return $check;
			}

			break;
		}

		return $result;
	}
}
