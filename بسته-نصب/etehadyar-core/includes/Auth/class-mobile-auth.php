<?php
/**
 * Passwordless sign-in and registration by mobile number.
 *
 * A verified OTP is the credential. If the number already belongs to an
 * account the visitor is signed into it; otherwise an account is created,
 * enrolled as a tenant, and signed in. From the visitor's point of view there
 * is one flow, not two, which is exactly what makes it hard to use the form to
 * discover which numbers are registered.
 *
 * @package Etehadyar\Auth
 */

namespace Etehadyar\Auth;

use Etehadyar\Core\Audit;
use Etehadyar\Core\Capabilities;
use Etehadyar\Core\Tenant;

defined( 'ABSPATH' ) || exit;

/**
 * Mobile authentication service.
 */
class Mobile_Auth {

	/**
	 * User meta key holding the canonical number.
	 */
	const META_PHONE = 'etehadyar_phone';

	/**
	 * User meta key recording when the number was verified.
	 */
	const META_VERIFIED_AT = 'etehadyar_phone_verified_at';

	/**
	 * Find the account owning a number.
	 *
	 * @param string $phone Raw or canonical number.
	 * @return \WP_User|null
	 */
	public static function find_user( $phone ) {
		$canonical = Phone::normalise( $phone );

		if ( ! $canonical ) {
			return null;
		}

		$users = get_users(
			array(
				'meta_key'   => self::META_PHONE, // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value' => $canonical,       // phpcs:ignore WordPress.DB.SlowDBQuery
				'number'     => 1,
				'fields'     => 'all',
			)
		);

		if ( $users ) {
			return $users[0];
		}

		// Fall back to the deterministic username, which covers accounts made
		// before the meta key existed or imported from the legacy plugin.
		$user = get_user_by( 'login', Phone::to_username( $canonical ) );

		return $user ?: null;
	}

	/**
	 * Complete a login: verify the code, then sign in or register.
	 *
	 * @param string $phone    Raw number.
	 * @param string $code     Submitted code.
	 * @param bool   $remember Whether to set a long-lived cookie.
	 * @return array|\WP_Error { user_id:int, created:bool, redirect:string }
	 */
	public static function login( $phone, $code, $remember = true ) {
		$canonical = OTP_Service::verify( $phone, $code, 'login' );

		if ( is_wp_error( $canonical ) ) {
			return $canonical;
		}

		$user    = self::find_user( $canonical );
		$created = false;

		if ( ! $user ) {
			if ( ! self::registration_open() ) {
				return new \WP_Error(
					'etehadyar_registration_closed',
					__( 'ثبت‌نام در حال حاضر باز نیست.', 'etehadyar-core' ),
					array( 'status' => 403 )
				);
			}

			$user = self::create_user( $canonical );

			if ( is_wp_error( $user ) ) {
				return $user;
			}

			$created = true;
		}

		$tenant = Tenant::get( $user->ID );

		if ( $tenant && 'suspended' === ( $tenant['status'] ?? '' ) ) {
			Audit::log(
				'auth.login_blocked_suspended',
				array(
					'user_id'  => $user->ID,
					'severity' => 'warning',
				)
			);

			return new \WP_Error(
				'etehadyar_tenant_suspended',
				__( 'حساب شما موقتاً غیرفعال است. با پشتیبانی تماس بگیرید.', 'etehadyar-core' ),
				array( 'status' => 403 )
			);
		}

		Tenant::ensure( $user->ID );

		update_user_meta( $user->ID, self::META_VERIFIED_AT, current_time( 'mysql' ) );

		self::sign_in( $user, $remember );

		Audit::log(
			$created ? 'auth.registered' : 'auth.login',
			array(
				'user_id'  => $user->ID,
				'actor_id' => $user->ID,
				'severity' => 'info',
				'context'  => array( 'phone' => Phone::mask( $canonical ) ),
			)
		);

		/**
		 * Fires after a successful mobile sign-in.
		 *
		 * @param \WP_User $user    The user.
		 * @param bool     $created Whether the account was just created.
		 */
		do_action( 'etehadyar_mobile_login', $user, $created );

		return array(
			'user_id'  => $user->ID,
			'created'  => $created,
			'redirect' => self::redirect_url( $user ),
		);
	}

	/**
	 * Create an account for a verified number.
	 *
	 * @param string $canonical Canonical number.
	 * @return \WP_User|\WP_Error
	 */
	protected static function create_user( $canonical ) {
		$login = Phone::to_username( $canonical );

		// Extremely unlikely, but a colliding login must not produce a fatal.
		if ( username_exists( $login ) ) {
			$login .= '-' . wp_generate_password( 4, false, false );
		}

		$user_id = wp_insert_user(
			array(
				'user_login'   => $login,
				'user_pass'    => wp_generate_password( 24, true, true ),
				'display_name' => Phone::to_local( $canonical ),
				'role'         => Capabilities::ROLE_MEMBER,
			)
		);

		if ( is_wp_error( $user_id ) ) {
			return new \WP_Error(
				'etehadyar_registration_failed',
				__( 'ساخت حساب کاربری انجام نشد. دوباره تلاش کنید.', 'etehadyar-core' ),
				array( 'status' => 500 )
			);
		}

		update_user_meta( $user_id, self::META_PHONE, $canonical );

		Tenant::ensure(
			$user_id,
			array( 'label' => Phone::mask( $canonical ) )
		);

		\Etehadyar\Billing\Wallet::ensure( $user_id );

		$bonus = (int) get_option( 'etehadyar_signup_bonus', 0 );

		if ( $bonus > 0 ) {
			\Etehadyar\Billing\Wallet::credit(
				$user_id,
				$bonus,
				array(
					'type'            => \Etehadyar\Billing\Wallet::TYPE_BONUS,
					'description'     => __( 'هدیهٔ خوش‌آمدگویی', 'etehadyar-core' ),
					'reference'       => 'signup',
					// Keyed per user so a replayed registration cannot double it.
					'idempotency_key' => 'signup-bonus:' . $user_id,
					'actor_id'        => 0,
				)
			);
		}

		return get_user_by( 'id', $user_id );
	}

	/**
	 * Set the auth cookie and current user.
	 *
	 * @param \WP_User $user     User.
	 * @param bool     $remember Long session.
	 */
	protected static function sign_in( $user, $remember ) {
		wp_set_current_user( $user->ID );
		wp_set_auth_cookie( $user->ID, (bool) $remember );
		do_action( 'wp_login', $user->user_login, $user );
	}

	/**
	 * Where to send the user after login.
	 *
	 * @param \WP_User $user User.
	 * @return string
	 */
	protected static function redirect_url( $user ) {
		$page_id = (int) get_option( 'etehadyar_page_dashboard' );
		$url     = $page_id ? get_permalink( $page_id ) : home_url( '/' );

		/**
		 * Filter the post-login redirect.
		 *
		 * @param string   $url  Destination.
		 * @param \WP_User $user User.
		 */
		return apply_filters( 'etehadyar_login_redirect', $url ?: home_url( '/' ), $user );
	}

	/**
	 * Whether new accounts may be created.
	 *
	 * @return bool
	 */
	public static function registration_open() {
		$open = 'closed' !== get_option( 'etehadyar_registration_mode', 'open' );

		/**
		 * Filter whether mobile registration is open.
		 *
		 * @param bool $open Default from settings.
		 */
		return (bool) apply_filters( 'etehadyar_registration_open', $open );
	}

	/**
	 * Attach a verified number to an existing account.
	 *
	 * @param int    $user_id User id.
	 * @param string $phone   Raw number.
	 * @param string $code    Submitted code.
	 * @return true|\WP_Error
	 */
	public static function attach_phone( $user_id, $phone, $code ) {
		$canonical = OTP_Service::verify( $phone, $code, 'attach' );

		if ( is_wp_error( $canonical ) ) {
			return $canonical;
		}

		$existing = self::find_user( $canonical );

		if ( $existing && (int) $existing->ID !== (int) $user_id ) {
			return new \WP_Error(
				'etehadyar_phone_taken',
				__( 'این شماره به حساب دیگری متصل است.', 'etehadyar-core' ),
				array( 'status' => 409 )
			);
		}

		update_user_meta( $user_id, self::META_PHONE, $canonical );
		update_user_meta( $user_id, self::META_VERIFIED_AT, current_time( 'mysql' ) );

		Audit::log(
			'auth.phone_attached',
			array(
				'user_id'  => $user_id,
				'actor_id' => $user_id,
				'context'  => array( 'phone' => Phone::mask( $canonical ) ),
			)
		);

		return true;
	}
}
