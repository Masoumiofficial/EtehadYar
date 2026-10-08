<?php
/**
 * SMS gateway contract.
 *
 * Providers are swappable: the OTP service only ever talks to this interface,
 * so moving from one Iranian panel to another is a settings change plus one
 * new class, not a rewrite.
 *
 * @package Etehadyar\Auth
 */

namespace Etehadyar\Auth;

defined( 'ABSPATH' ) || exit;

/**
 * Interface every SMS provider must satisfy.
 */
interface SMS_Gateway {

	/**
	 * Machine name, e.g. `melipayamak`.
	 *
	 * @return string
	 */
	public function id();

	/**
	 * Human-readable label.
	 *
	 * @return string
	 */
	public function label();

	/**
	 * Whether the gateway has everything it needs to send.
	 *
	 * @return bool
	 */
	public function is_configured();

	/**
	 * Send a one-time password.
	 *
	 * @param string $phone Canonical 10-digit number (9XXXXXXXXX).
	 * @param string $code  The OTP code.
	 * @return true|\WP_Error True on success.
	 */
	public function send_otp( $phone, $code );

	/**
	 * Remaining provider credit, for the admin dashboard.
	 *
	 * @return float|\WP_Error
	 */
	public function get_credit();

	/**
	 * Settings fields this gateway needs.
	 *
	 * @return array<string, array{label:string, type:string, secret?:bool, help?:string}>
	 */
	public function settings_fields();
}
