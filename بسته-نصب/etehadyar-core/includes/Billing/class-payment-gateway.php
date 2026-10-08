<?php
/**
 * Payment gateway contract.
 *
 * @package Etehadyar\Billing
 */

namespace Etehadyar\Billing;

defined( 'ABSPATH' ) || exit;

/**
 * Interface every payment provider must satisfy.
 */
interface Payment_Gateway {

	/**
	 * Machine name.
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
	 * Whether credentials are present.
	 *
	 * @return bool
	 */
	public function is_configured();

	/**
	 * Open a payment and return the URL to send the customer to.
	 *
	 * @param array $order Order row (id, user_id, amount).
	 * @return array|\WP_Error { redirect:string, authority:string }
	 */
	public function start( $order );

	/**
	 * Confirm a payment with the provider.
	 *
	 * @param array $order   Order row.
	 * @param array $request Query parameters from the return URL.
	 * @return array|\WP_Error { ref_id:string, card_pan:string }
	 */
	public function verify( $order, $request );
}
