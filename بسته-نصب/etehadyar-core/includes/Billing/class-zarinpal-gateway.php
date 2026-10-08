<?php
/**
 * ZarinPal payment gateway (REST v4).
 *
 * Two provider behaviours drive the design here:
 *
 * - Code 101 means "already verified". It is a SUCCESS, not an error. A
 *   customer who refreshes the return page, or a browser that retries the
 *   request, must not be told their payment failed — they already paid.
 * - Code -33 means the amount did not match. The amount is therefore always
 *   read back from our own orders table and never taken from the callback URL,
 *   so a tampered return link cannot credit a wallet with more than was paid.
 *
 * @package Etehadyar\Billing
 */

namespace Etehadyar\Billing;

use Etehadyar\Core\Secrets;

defined( 'ABSPATH' ) || exit;

/**
 * ZarinPal REST v4 gateway.
 */
class Zarinpal_Gateway implements Payment_Gateway {

	const LIVE_API     = 'https://payment.zarinpal.com/pg/v4/payment/';
	const LIVE_START   = 'https://payment.zarinpal.com/pg/StartPay/';
	const SANDBOX_API  = 'https://sandbox.zarinpal.com/pg/v4/payment/';
	const SANDBOX_START = 'https://sandbox.zarinpal.com/pg/StartPay/';

	const OPTION_SANDBOX  = 'etehadyar_zarinpal_sandbox';
	const SECRET_MERCHANT = 'zarinpal_merchant_id';

	/**
	 * Provider error codes mapped to Persian.
	 *
	 * @var array<int, string>
	 */
	const ERROR_CODES = array(
		-9   => 'خطای اعتبارسنجی — اطلاعات ارسالی ناقص یا نادرست است.',
		-10  => 'آی‌پی یا مرچنت کد پذیرنده صحیح نیست.',
		-11  => 'مرچنت کد فعال نیست. با پشتیبانی زرین‌پال تماس بگیرید.',
		-12  => 'تلاش بیش از حد مجاز در بازهٔ زمانی کوتاه.',
		-15  => 'درگاه پرداخت تعلیق شده است.',
		-16  => 'سطح تأیید پذیرنده پایین‌تر از حد مجاز است.',
		-17  => 'محدودیت پذیرنده در سطح آبی.',
		-30  => 'اجازهٔ دسترسی به تسویه اشتراکی وجود ندارد.',
		-31  => 'حساب بانکی تسویه را به پنل اضافه کنید.',
		-33  => 'مبلغ تراکنش با مبلغ پرداخت‌شده مطابقت ندارد.',
		-34  => 'سقف تقسیم تراکنش از نظر مبلغ یا تعداد عبور کرده است.',
		-40  => 'اجازهٔ دسترسی به متد مربوطه وجود ندارد.',
		-50  => 'مبلغ پرداخت‌شده با مبلغ ارسالی در متد وریفای متفاوت است.',
		-51  => 'پرداخت ناموفق بود.',
		-52  => 'خطای غیرمنتظره در سامانهٔ پرداخت.',
		-53  => 'این پرداخت متعلق به پذیرندهٔ دیگری است.',
		-54  => 'درخواست مورد نظر آرشیو شده است.',
	);

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'zarinpal';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label() {
		return __( 'زرین‌پال', 'etehadyar-core' );
	}

	/**
	 * Whether sandbox mode is on.
	 *
	 * @return bool
	 */
	public function is_sandbox() {
		return (bool) get_option( self::OPTION_SANDBOX, 0 );
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_configured() {
		return '' !== $this->merchant_id();
	}

	/**
	 * The merchant id.
	 *
	 * @return string
	 */
	protected function merchant_id() {
		return trim( Secrets::get( self::SECRET_MERCHANT ) );
	}

	/**
	 * API base for the active environment.
	 *
	 * @return string
	 */
	protected function api_base() {
		return $this->is_sandbox() ? self::SANDBOX_API : self::LIVE_API;
	}

	/**
	 * Checkout base for the active environment.
	 *
	 * @return string
	 */
	protected function start_base() {
		return $this->is_sandbox() ? self::SANDBOX_START : self::LIVE_START;
	}

	/**
	 * {@inheritDoc}
	 */
	public function start( $order ) {
		if ( ! $this->is_configured() ) {
			return new \WP_Error(
				'etehadyar_pay_not_configured',
				__( 'درگاه پرداخت پیکربندی نشده است.', 'etehadyar-core' ),
				array( 'status' => 503 )
			);
		}

		$amount = (int) $order['amount'];

		if ( $amount <= 0 ) {
			return new \WP_Error(
				'etehadyar_pay_bad_amount',
				__( 'مبلغ پرداخت نامعتبر است.', 'etehadyar-core' ),
				array( 'status' => 400 )
			);
		}

		$phone = get_user_meta( (int) $order['user_id'], 'etehadyar_phone', true );

		$payload = array(
			'merchant_id'  => $this->merchant_id(),
			'amount'       => $amount,
			'callback_url' => Orders::callback_url(),
			'description'  => sprintf(
				/* translators: %d: order id. */
				__( 'افزایش اعتبار کیف پول — سفارش %d', 'etehadyar-core' ),
				(int) $order['id']
			),
			'metadata'     => array_filter(
				array(
					'mobile'   => $phone ? '0' . $phone : '',
					'order_id' => (string) (int) $order['id'],
				)
			),
		);

		$response = $this->request( 'request.json', $payload );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code      = (int) ( $response['data']['code'] ?? 0 );
		$authority = (string) ( $response['data']['authority'] ?? '' );

		if ( 100 !== $code || '' === $authority ) {
			return $this->error_from( $response, __( 'ایجاد تراکنش ناموفق بود.', 'etehadyar-core' ) );
		}

		return array(
			'authority' => $authority,
			'redirect'  => $this->start_base() . $authority,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function verify( $order, $request ) {
		$status    = strtoupper( (string) ( $request['Status'] ?? $request['status'] ?? '' ) );
		$authority = (string) ( $request['Authority'] ?? $request['authority'] ?? '' );

		if ( 'OK' !== $status ) {
			return new \WP_Error(
				'etehadyar_pay_cancelled',
				__( 'پرداخت توسط شما لغو شد.', 'etehadyar-core' ),
				array( 'status' => 400 )
			);
		}

		if ( ! $authority || ! hash_equals( (string) $order['authority'], $authority ) ) {
			return new \WP_Error(
				'etehadyar_pay_authority_mismatch',
				__( 'اطلاعات بازگشتی از درگاه با سفارش مطابقت ندارد.', 'etehadyar-core' ),
				array( 'status' => 400 )
			);
		}

		// The amount comes from our own record, never from the request.
		$response = $this->request(
			'verify.json',
			array(
				'merchant_id' => $this->merchant_id(),
				'amount'      => (int) $order['amount'],
				'authority'   => $authority,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) ( $response['data']['code'] ?? 0 );

		// 100 = verified now. 101 = already verified on an earlier attempt.
		// Both mean the money arrived.
		if ( 100 !== $code && 101 !== $code ) {
			return $this->error_from( $response, __( 'تأیید پرداخت ناموفق بود.', 'etehadyar-core' ) );
		}

		return array(
			'ref_id'          => (string) ( $response['data']['ref_id'] ?? '' ),
			'card_pan'        => (string) ( $response['data']['card_pan'] ?? '' ),
			'already_settled' => 101 === $code,
		);
	}

	/**
	 * Perform a JSON API call.
	 *
	 * @param string $endpoint Endpoint file name.
	 * @param array  $payload  Request body.
	 * @return array|\WP_Error
	 */
	protected function request( $endpoint, $payload ) {
		$response = wp_remote_post(
			$this->api_base() . $endpoint,
			array(
				'headers'   => array(
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				),
				'body'      => wp_json_encode( $payload ),
				'timeout'   => 30,
				'sslverify' => true,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new \WP_Error(
				'etehadyar_pay_http',
				__( 'ارتباط با درگاه پرداخت برقرار نشد.', 'etehadyar-core' ),
				array( 'status' => 502 )
			);
		}

		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $decoded ) ) {
			return new \WP_Error(
				'etehadyar_pay_bad_response',
				__( 'پاسخ درگاه پرداخت قابل تفسیر نبود.', 'etehadyar-core' ),
				array( 'status' => 502 )
			);
		}

		return $decoded;
	}

	/**
	 * Build an error from a provider response.
	 *
	 * @param array  $response Decoded response.
	 * @param string $fallback Default message.
	 * @return \WP_Error
	 */
	protected function error_from( $response, $fallback ) {
		$errors = $response['errors'] ?? array();
		$code   = 0;

		if ( is_array( $errors ) && isset( $errors['code'] ) ) {
			$code = (int) $errors['code'];
		} elseif ( is_array( $errors ) && isset( $errors[0]['code'] ) ) {
			$code = (int) $errors[0]['code'];
		} elseif ( isset( $response['data']['code'] ) ) {
			$code = (int) $response['data']['code'];
		}

		$message = self::ERROR_CODES[ $code ] ?? $fallback;

		return new \WP_Error(
			'etehadyar_pay_' . abs( $code ),
			$message,
			array(
				'status'        => 402,
				'provider_code' => $code,
			)
		);
	}
}
