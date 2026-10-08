<?php
/**
 * REST endpoints for the wallet.
 *
 * Every route is authenticated and scoped to the calling user. There is no
 * way to read or move another tenant's money through this controller: the
 * user id is always taken from the session, never from a parameter.
 *
 * @package Etehadyar\Rest
 */

namespace Etehadyar\Rest;

use Etehadyar\Billing\Orders;
use Etehadyar\Billing\Pricing;
use Etehadyar\Billing\Wallet;
use Etehadyar\Core\Capabilities;
use Etehadyar\Core\Tenant;

defined( 'ABSPATH' ) || exit;

/**
 * Wallet controller.
 */
class Wallet_Controller {

	const NAMESPACE_V1 = 'etehadyar/v1';

	/**
	 * Register routes.
	 */
	public function register_routes() {
		register_rest_route(
			self::NAMESPACE_V1,
			'/wallet',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_wallet' ),
				'permission_callback' => array( $this, 'can_view_billing' ),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/wallet/transactions',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_transactions' ),
				'permission_callback' => array( $this, 'can_view_billing' ),
				'args'                => array(
					'page'     => array(
						'type'    => 'integer',
						'default' => 1,
					),
					'per_page' => array(
						'type'    => 'integer',
						'default' => 20,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/wallet/topup',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'topup' ),
				'permission_callback' => array( $this, 'can_view_billing' ),
				'args'                => array(
					'amount' => array(
						'required' => true,
						'type'     => 'integer',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/pricing',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_pricing' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Gate for wallet routes.
	 *
	 * @return true|\WP_Error
	 */
	public function can_view_billing() {
		if ( ! is_user_logged_in() ) {
			return new \WP_Error(
				'etehadyar_not_logged_in',
				__( 'برای مشاهدهٔ کیف پول وارد شوید.', 'etehadyar-core' ),
				array( 'status' => 401 )
			);
		}

		if ( ! current_user_can( Capabilities::VIEW_BILLING ) ) {
			return new \WP_Error(
				'etehadyar_forbidden',
				__( 'دسترسی لازم را ندارید.', 'etehadyar-core' ),
				array( 'status' => 403 )
			);
		}

		$tenant = Tenant::get( get_current_user_id() );

		if ( $tenant && 'suspended' === ( $tenant['status'] ?? '' ) ) {
			return new \WP_Error(
				'etehadyar_tenant_inactive',
				__( 'حساب شما غیرفعال است.', 'etehadyar-core' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * GET /wallet
	 *
	 * @return \WP_REST_Response
	 */
	public function get_wallet() {
		$user_id = get_current_user_id();
		$wallet  = Wallet::get( $user_id );

		return rest_ensure_response(
			array(
				'balance'           => $wallet['balance'],
				'balance_formatted' => Wallet::format( $wallet['balance'] ),
				'lifetime_topup'    => $wallet['lifetime_topup'],
				'lifetime_spend'    => $wallet['lifetime_spend'],
				'min_topup'         => Orders::min_amount(),
				'max_topup'         => Orders::max_amount(),
			)
		);
	}

	/**
	 * GET /wallet/transactions
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function get_transactions( $request ) {
		$history = Wallet::history(
			get_current_user_id(),
			array(
				'page'     => (int) $request->get_param( 'page' ),
				'per_page' => (int) $request->get_param( 'per_page' ),
			)
		);

		foreach ( $history['items'] as &$item ) {
			$item['amount_formatted'] = Wallet::format( abs( $item['amount'] ) );
			$item['direction']        = $item['amount'] >= 0 ? 'in' : 'out';
		}

		return rest_ensure_response( $history );
	}

	/**
	 * POST /wallet/topup
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function topup( $request ) {
		$result = Orders::checkout( get_current_user_id(), (int) $request->get_param( 'amount' ) );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * GET /pricing
	 *
	 * Public: prospective customers should be able to see what things cost
	 * before creating an account.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_pricing() {
		$out = array();

		foreach ( Pricing::all() as $key => $rate ) {
			$out[] = array(
				'key'             => $key,
				'label'           => $rate['label'],
				'unit'            => $rate['unit'],
				'price'           => (int) $rate['price'],
				'price_formatted' => Wallet::format( $rate['price'] ),
			);
		}

		return rest_ensure_response( array( 'items' => $out ) );
	}
}
