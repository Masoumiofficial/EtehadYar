<?php
/**
 * REST endpoints for mobile sign-in.
 *
 * These are the only public (nopriv) routes in the plugin, so they carry the
 * heaviest protection: strict argument validation, an origin check, a honeypot
 * field, and the layered rate limiting in OTP_Service.
 *
 * @package Etehadyar\Rest
 */

namespace Etehadyar\Rest;

use Etehadyar\Auth\Mobile_Auth;
use Etehadyar\Auth\OTP_Service;
use Etehadyar\Auth\Phone;

defined( 'ABSPATH' ) || exit;

/**
 * Authentication controller.
 */
class Auth_Controller {

	const NAMESPACE_V1 = 'etehadyar/v1';

	/**
	 * Register routes.
	 */
	public function register_routes() {
		register_rest_route(
			self::NAMESPACE_V1,
			'/auth/request-code',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'request_code' ),
				'permission_callback' => array( $this, 'public_permission' ),
				'args'                => array(
					'phone'   => array(
						'required'          => true,
						'type'              => 'string',
						'validate_callback' => array( $this, 'validate_phone' ),
					),
					'website' => array(
						'required' => false,
						'type'     => 'string',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/auth/verify',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'verify' ),
				'permission_callback' => array( $this, 'public_permission' ),
				'args'                => array(
					'phone'    => array(
						'required'          => true,
						'type'              => 'string',
						'validate_callback' => array( $this, 'validate_phone' ),
					),
					'code'     => array(
						'required' => true,
						'type'     => 'string',
					),
					'remember' => array(
						'required' => false,
						'type'     => 'boolean',
						'default'  => true,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/auth/logout',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'logout' ),
				'permission_callback' => static function () {
					return is_user_logged_in();
				},
			)
		);
	}

	/**
	 * Gate for the public routes.
	 *
	 * Already-authenticated visitors are turned away rather than allowed to
	 * churn through OTP sends while logged in.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return true|\WP_Error
	 */
	public function public_permission( $request ) {
		if ( is_user_logged_in() ) {
			return new \WP_Error(
				'etehadyar_already_logged_in',
				__( 'شما در حال حاضر وارد شده‌اید.', 'etehadyar-core' ),
				array( 'status' => 400 )
			);
		}

		// Honeypot: a real browser leaves this hidden field empty.
		if ( '' !== trim( (string) $request->get_param( 'website' ) ) ) {
			return new \WP_Error(
				'etehadyar_spam',
				__( 'درخواست نامعتبر است.', 'etehadyar-core' ),
				array( 'status' => 400 )
			);
		}

		if ( ! $this->origin_is_allowed( $request ) ) {
			return new \WP_Error(
				'etehadyar_bad_origin',
				__( 'درخواست از مبدأ نامعتبر ارسال شده است.', 'etehadyar-core' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Require the request to come from this site.
	 *
	 * Blocks the simplest cross-origin scripted abuse. Requests with no Origin
	 * or Referer at all (curl, server-side tools) are allowed only when the
	 * site explicitly opts in, since legitimate browsers always send one.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool
	 */
	protected function origin_is_allowed( $request ) {
		$origin = $request->get_header( 'origin' );

		if ( ! $origin ) {
			$referer = $request->get_header( 'referer' );
			$origin  = $referer ? $this->origin_of( $referer ) : '';
		} else {
			$origin = $this->origin_of( $origin );
		}

		if ( ! $origin ) {
			/**
			 * Filter whether headerless requests may hit the auth routes.
			 *
			 * @param bool $allow Default false.
			 */
			return (bool) apply_filters( 'etehadyar_allow_headerless_auth', false );
		}

		$allowed = array( $this->origin_of( home_url() ), $this->origin_of( site_url() ) );

		/**
		 * Filter the origins allowed to call the auth routes.
		 *
		 * @param string[] $allowed Allowed origins.
		 */
		$allowed = apply_filters( 'etehadyar_allowed_auth_origins', array_filter( array_unique( $allowed ) ) );

		return in_array( $origin, $allowed, true );
	}

	/**
	 * Reduce a URL to scheme://host[:port].
	 *
	 * @param string $url URL.
	 * @return string
	 */
	protected function origin_of( $url ) {
		$parts = wp_parse_url( $url );

		if ( empty( $parts['host'] ) ) {
			return '';
		}

		$origin = ( $parts['scheme'] ?? 'https' ) . '://' . $parts['host'];

		if ( ! empty( $parts['port'] ) ) {
			$origin .= ':' . $parts['port'];
		}

		return $origin;
	}

	/**
	 * Validate a phone argument.
	 *
	 * @param mixed $value Raw value.
	 * @return bool
	 */
	public function validate_phone( $value ) {
		return Phone::is_valid( (string) $value );
	}

	/**
	 * POST /auth/request-code
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function request_code( $request ) {
		$result = OTP_Service::request( (string) $request->get_param( 'phone' ), 'login' );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response(
			array(
				'sent'       => true,
				'masked'     => $result['masked'],
				'expires_in' => $result['expires_in'],
				'resend_in'  => $result['resend_in'],
				'message'    => __( 'کد ورود پیامک شد.', 'etehadyar-core' ),
			)
		);
	}

	/**
	 * POST /auth/verify
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function verify( $request ) {
		$result = Mobile_Auth::login(
			(string) $request->get_param( 'phone' ),
			(string) $request->get_param( 'code' ),
			(bool) $request->get_param( 'remember' )
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response(
			array(
				'success'  => true,
				'created'  => $result['created'],
				'redirect' => $result['redirect'],
				// A fresh nonce, because logging in changes the session and
				// invalidates the nonce the page was rendered with.
				'nonce'    => wp_create_nonce( 'wp_rest' ),
			)
		);
	}

	/**
	 * POST /auth/logout
	 *
	 * @return \WP_REST_Response
	 */
	public function logout() {
		wp_logout();

		return rest_ensure_response(
			array(
				'success'  => true,
				'redirect' => home_url( '/' ),
			)
		);
	}
}
