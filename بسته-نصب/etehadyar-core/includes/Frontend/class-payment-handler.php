<?php
/**
 * Gateway return handler.
 *
 * ZarinPal sends the customer back to the site with query parameters. This
 * runs early on `template_redirect`, settles the payment, and redirects to the
 * wallet page with a clean result flag — so a refresh never re-posts anything
 * and the raw gateway parameters do not linger in the address bar.
 *
 * @package Etehadyar\Frontend
 */

namespace Etehadyar\Frontend;

use Etehadyar\Billing\Orders;

defined( 'ABSPATH' ) || exit;

/**
 * Payment return handler.
 */
class Payment_Handler {

	/**
	 * Register hooks.
	 */
	public static function boot() {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_handle' ), 1 );
	}

	/**
	 * Detect and process a gateway return.
	 */
	public static function maybe_handle() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- gateway callback, verified server-side against the provider.
		if ( ! isset( $_GET['etehadyar_payment'] ) || 'return' !== $_GET['etehadyar_payment'] ) {
			return;
		}

		$request = array(
			'Authority' => isset( $_GET['Authority'] ) ? sanitize_text_field( wp_unslash( $_GET['Authority'] ) ) : '',
			'Status'    => isset( $_GET['Status'] ) ? sanitize_text_field( wp_unslash( $_GET['Status'] ) ) : '',
		);
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$result = Orders::handle_return( $request );

		$target = self::wallet_url();

		if ( is_wp_error( $result ) ) {
			// Another request is settling this payment right now. Saying
			// "failed" would be a lie the customer acts on (paying twice), and
			// saying "success" would be a lie too — the wallet is not credited
			// yet. It gets its own neutral state.
			$flag = 'etehadyar_payment_in_progress' === $result->get_error_code()
				? 'processing'
				: 'failed';

			wp_safe_redirect(
				add_query_arg(
					array(
						'payment' => $flag,
						'reason'  => rawurlencode( $result->get_error_message() ),
					),
					$target
				)
			);
			exit;
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'payment' => 'success',
					'order'   => (int) $result['order_id'],
				),
				$target
			)
		);
		exit;
	}

	/**
	 * Where to send the customer afterwards.
	 *
	 * @return string
	 */
	public static function wallet_url() {
		$page_id = (int) get_option( 'etehadyar_page_wallet' );
		$url     = $page_id ? get_permalink( $page_id ) : home_url( '/' );

		/**
		 * Filter the post-payment destination.
		 *
		 * @param string $url Destination URL.
		 */
		return apply_filters( 'etehadyar_wallet_url', $url ?: home_url( '/' ) );
	}
}
