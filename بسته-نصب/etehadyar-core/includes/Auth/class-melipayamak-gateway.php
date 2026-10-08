<?php
/**
 * Melipayamak (ملی پیامک) SMS gateway.
 *
 * Implements the provider's REST API at rest.payamak-panel.com.
 *
 * Three delivery modes are supported, in the order you should prefer them:
 *
 * 1. `pattern`  — BaseServiceNumber (خط خدماتی اشتراکی). The important one for
 *                 Iran: messages sent through an approved pattern reach numbers
 *                 that are on the carrier blacklist (لیست سیاه مخابرات). With
 *                 an ordinary line a large share of real users simply never
 *                 receive the code, which looks like "the site is broken".
 * 2. `otp`      — SendOtp. Convenient, no pattern approval needed, but the body
 *                 text is fixed by the provider and blacklisted numbers fail.
 * 3. `simple`   — SendSMS from a dedicated line. Full control of the text,
 *                 same blacklist limitation.
 *
 * @package Etehadyar\Auth
 */

namespace Etehadyar\Auth;

use Etehadyar\Core\Secrets;

defined( 'ABSPATH' ) || exit;

/**
 * Melipayamak REST gateway.
 */
class Melipayamak_Gateway implements SMS_Gateway {

	const API_BASE = 'https://rest.payamak-panel.com/api/SendSMS/';

	const OPTION_USERNAME = 'etehadyar_sms_melipayamak_username';
	const OPTION_MODE     = 'etehadyar_sms_melipayamak_mode';
	const OPTION_FROM     = 'etehadyar_sms_melipayamak_from';
	const OPTION_BODY_ID  = 'etehadyar_sms_melipayamak_body_id';

	const SECRET_PASSWORD = 'melipayamak_password';

	/**
	 * Provider return codes that are errors rather than a recId.
	 *
	 * Taken from the official documentation. Mapped to Persian messages an
	 * administrator can act on, because "0" on its own tells nobody anything.
	 *
	 * @var array<int, string>
	 */
	const ERROR_CODES = array(
		0    => 'نام کاربری یا رمز عبور سامانه پیامک نادرست است.',
		2    => 'اعتبار پیامکی کافی نیست. پنل ملی پیامک را شارژ کنید.',
		3    => 'محدودیت در ارسال روزانه.',
		4    => 'محدودیت در حجم ارسال.',
		5    => 'شماره فرستنده معتبر نیست.',
		6    => 'سامانهٔ ملی پیامک در حال به‌روزرسانی است.',
		7    => 'متن پیامک حاوی کلمهٔ فیلترشده است.',
		9    => 'ارسال از خطوط عمومی از طریق وب‌سرویس ممکن نیست.',
		10   => 'کاربر سامانهٔ پیامک فعال نیست.',
		11   => 'پیامک ارسال نشد.',
		12   => 'مدارک حساب ملی پیامک کامل نیست.',
		14   => 'متن پیامک حاوی لینک است.',
		15   => 'ارسال به بیش از یک شماره بدون درج «لغو۱۱» ممکن نیست.',
		16   => 'شماره گیرنده یافت نشد.',
		17   => 'متن پیامک خالی است.',
		18   => 'شماره گیرنده نامعتبر است.',
		19   => 'از محدودیت ساعتی فراتر رفته‌اید.',
		35   => 'شماره گیرنده در لیست سیاه مخابرات است. برای رسیدن پیامک باید از «خط خدماتی اشتراکی» (پترن) استفاده کنید.',
		-1   => 'دسترسی این وب‌سرویس غیرفعال است. با پشتیبانی ملی پیامک تماس بگیرید.',
		-2   => 'محدودیت تعداد شماره — هر بار فقط یک شماره مجاز است.',
		-3   => 'خط ارسالی در سامانه تعریف نشده است.',
		-4   => 'کد پترن نادرست است یا توسط مدیر سامانه تأیید نشده است.',
		-5   => 'متن ارسالی با متغیرهای تعریف‌شده در پترن همخوانی ندارد.',
		-6   => 'خطای داخلی سامانهٔ پیامک.',
		-7   => 'خطا در شماره فرستنده.',
		-10  => 'متغیرهای ارسالی حاوی لینک هستند.',
		-108 => 'IP سرور به دلیل تلاش‌های ناموفق مسدود شده است.',
		-109 => 'تنظیم IP مجاز برای استفاده از API الزامی است.',
		-110 => 'استفاده از ApiKey به جای رمز عبور الزامی است.',
		-111 => 'IP درخواست‌کننده نامعتبر است.',
	);

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'melipayamak';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label() {
		return __( 'ملی پیامک', 'etehadyar-core' );
	}

	/**
	 * Configured delivery mode.
	 *
	 * @return string One of pattern|otp|simple.
	 */
	public function mode() {
		$mode = get_option( self::OPTION_MODE, 'pattern' );

		return in_array( $mode, array( 'pattern', 'otp', 'simple' ), true ) ? $mode : 'pattern';
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_configured() {
		if ( ! get_option( self::OPTION_USERNAME ) || ! Secrets::has( self::SECRET_PASSWORD ) ) {
			return false;
		}

		if ( 'pattern' === $this->mode() ) {
			return (bool) absint( get_option( self::OPTION_BODY_ID ) );
		}

		return (bool) trim( (string) get_option( self::OPTION_FROM, '' ) );
	}

	/**
	 * Provider codes that mean "this delivery mode will never work as configured".
	 *
	 * When pattern mode hits one of these, the pattern is not usable: the code
	 * was never approved, was deleted, or the variables do not match. Failing
	 * hard would lock every user out of the site, so the gateway falls back to
	 * the provider's built-in OTP method and flags the problem for the admin.
	 *
	 * @var int[]
	 */
	const PATTERN_FATAL_CODES = array( -4, -5, -3 );

	/**
	 * Option flag raised when pattern mode had to be bypassed.
	 */
	const OPTION_PATTERN_ALERT = 'etehadyar_sms_pattern_alert';

	/**
	 * {@inheritDoc}
	 */
	public function send_otp( $phone, $code ) {
		$local = Phone::to_local( $phone );

		if ( ! $local ) {
			return new \WP_Error( 'etehadyar_sms_bad_number', __( 'شماره موبایل نامعتبر است.', 'etehadyar-core' ) );
		}

		if ( ! $this->is_configured() ) {
			return new \WP_Error(
				'etehadyar_sms_not_configured',
				__( 'سرویس پیامک پیکربندی نشده است. تنظیمات ملی پیامک را کامل کنید.', 'etehadyar-core' )
			);
		}

		switch ( $this->mode() ) {
			case 'otp':
				return $this->send_via_otp( $local, $code );

			case 'simple':
				return $this->send_via_simple( $local, $code );

			case 'pattern':
			default:
				return $this->send_with_pattern_fallback( $local, $code );
		}
	}

	/**
	 * Pattern send, degrading to SendOtp if the pattern itself is unusable.
	 *
	 * An unapproved or mistyped bodyId would otherwise mean nobody can log in
	 * at all. Delivery to blacklisted numbers is lost in the fallback, which is
	 * bad — but a site where *some* users cannot log in beats a site where
	 * *no* users can.
	 *
	 * @param string $local Local format number.
	 * @param string $code  OTP code.
	 * @return true|\WP_Error
	 */
	protected function send_with_pattern_fallback( $local, $code ) {
		$result = $this->send_via_pattern( $local, $code );

		if ( ! is_wp_error( $result ) ) {
			// Clear a previous alert once the pattern starts working again.
			if ( get_option( self::OPTION_PATTERN_ALERT ) ) {
				delete_option( self::OPTION_PATTERN_ALERT );
			}

			return $result;
		}

		$fatal = false;

		foreach ( self::PATTERN_FATAL_CODES as $candidate ) {
			if ( 'etehadyar_sms_' . abs( $candidate ) === $result->get_error_code() ) {
				$fatal = true;
				break;
			}
		}

		if ( ! $fatal ) {
			return $result;
		}

		// Without a sender line there is nothing to fall back to.
		if ( ! trim( (string) get_option( self::OPTION_FROM, '' ) ) ) {
			update_option( self::OPTION_PATTERN_ALERT, $result->get_error_message(), false );

			return $result;
		}

		update_option( self::OPTION_PATTERN_ALERT, $result->get_error_message(), false );

		/**
		 * Fires when pattern delivery failed and the gateway fell back.
		 *
		 * @param \WP_Error $error The pattern error.
		 */
		do_action( 'etehadyar_sms_pattern_fallback', $result );

		return $this->send_via_otp( $local, $code );
	}

	/**
	 * Shared service line (pattern). Reaches blacklisted numbers.
	 *
	 * @param string $local Local format number.
	 * @param string $code  OTP code.
	 * @return true|\WP_Error
	 */
	protected function send_via_pattern( $local, $code ) {
		$body_id = absint( get_option( self::OPTION_BODY_ID ) );

		/**
		 * Filter the pattern arguments sent to Melipayamak.
		 *
		 * Arguments are joined with `;` and must match the approved pattern
		 * exactly, in order. A single-variable pattern such as
		 * "کد ورود شما: {0}" needs just the code.
		 *
		 * @param array  $args  Ordered pattern variables.
		 * @param string $code  The OTP code.
		 * @param string $local Recipient number.
		 */
		$args = apply_filters( 'etehadyar_sms_pattern_args', array( $code ), $code, $local );

		$response = $this->request(
			'BaseServiceNumber',
			array(
				'text'   => implode( ';', array_map( 'strval', (array) $args ) ),
				'to'     => $local,
				'bodyId' => $body_id,
			)
		);

		return $this->interpret( $response );
	}

	/**
	 * Provider's built-in OTP method with fixed body text.
	 *
	 * @param string $local Local format number.
	 * @param string $code  OTP code.
	 * @return true|\WP_Error
	 */
	protected function send_via_otp( $local, $code ) {
		$response = $this->request(
			'SendOtp',
			array(
				'to'   => $local,
				'from' => trim( (string) get_option( self::OPTION_FROM, '' ) ),
				'code' => $code,
			)
		);

		return $this->interpret( $response );
	}

	/**
	 * Plain SMS from a dedicated line.
	 *
	 * @param string $local Local format number.
	 * @param string $code  OTP code.
	 * @return true|\WP_Error
	 */
	protected function send_via_simple( $local, $code ) {
		$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );

		/**
		 * Filter the plain OTP message body.
		 *
		 * @param string $text Message body.
		 * @param string $code OTP code.
		 */
		$text = apply_filters(
			'etehadyar_sms_otp_text',
			sprintf(
				/* translators: 1: site name, 2: OTP code. */
				__( "%1\$s\nکد ورود شما: %2\$s\nاین کد را در اختیار کسی قرار ندهید.", 'etehadyar-core' ),
				$site,
				$code
			),
			$code
		);

		$response = $this->request(
			'SendSMS',
			array(
				'to'      => $local,
				'from'    => trim( (string) get_option( self::OPTION_FROM, '' ) ),
				'text'    => $text,
				'isflash' => 'false',
			)
		);

		return $this->interpret( $response );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_credit() {
		$response = $this->request( 'GetCredit', array() );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$value = $response['Value'] ?? null;

		if ( ! is_numeric( $value ) ) {
			return new \WP_Error(
				'etehadyar_sms_credit_failed',
				__( 'دریافت موجودی پیامک انجام نشد. نام کاربری و رمز عبور را بررسی کنید.', 'etehadyar-core' )
			);
		}

		return (float) $value;
	}

	/**
	 * Perform a REST call.
	 *
	 * @param string $method Endpoint name.
	 * @param array  $data   Payload (credentials are added automatically).
	 * @return array|\WP_Error Decoded response.
	 */
	protected function request( $method, $data ) {
		$payload = array_merge(
			array(
				'username' => (string) get_option( self::OPTION_USERNAME, '' ),
				'password' => Secrets::get( self::SECRET_PASSWORD ),
			),
			$data
		);

		$response = wp_remote_post(
			self::API_BASE . $method,
			array(
				'headers'   => array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
				'body'      => $payload,
				'timeout'   => 20,
				'sslverify' => true,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new \WP_Error(
				'etehadyar_sms_http',
				__( 'ارتباط با سامانهٔ پیامک برقرار نشد. اتصال اینترنت سرور را بررسی کنید.', 'etehadyar-core' )
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		if ( 200 !== $code ) {
			return new \WP_Error(
				'etehadyar_sms_http_status',
				sprintf(
					/* translators: %d: HTTP status code. */
					__( 'سامانهٔ پیامک با وضعیت %d پاسخ داد.', 'etehadyar-core' ),
					$code
				)
			);
		}

		$decoded = json_decode( $body, true );

		if ( ! is_array( $decoded ) ) {
			// Some endpoints return a bare scalar rather than a JSON object.
			return array( 'Value' => trim( (string) $body ) );
		}

		return $decoded;
	}

	/**
	 * Turn a provider response into success or a meaningful error.
	 *
	 * The provider signals success by returning a recId — a long numeric
	 * string (documented as more than 15 digits). Small integers are status
	 * codes, and negative numbers are always errors. Anything unrecognised is
	 * treated as a failure rather than assumed to be fine: silently believing
	 * an OTP was delivered is worse than reporting an error.
	 *
	 * @param array|\WP_Error $response Raw response.
	 * @return true|\WP_Error
	 */
	protected function interpret( $response ) {
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$value  = $response['Value'] ?? '';
		$status = $response['RetStatus'] ?? null;

		$value = is_scalar( $value ) ? trim( (string) $value ) : '';

		if ( '' === $value ) {
			return new \WP_Error(
				'etehadyar_sms_empty_response',
				__( 'پاسخ سامانهٔ پیامک قابل تفسیر نبود.', 'etehadyar-core' )
			);
		}

		if ( ! is_numeric( $value ) ) {
			return new \WP_Error( 'etehadyar_sms_unknown', __( 'ارسال پیامک انجام نشد.', 'etehadyar-core' ) );
		}

		// A recId is a long positive number; treat it as success.
		if ( strlen( $value ) > 10 && 0 !== strpos( $value, '-' ) ) {
			return true;
		}

		$numeric = (int) $value;

		if ( array_key_exists( $numeric, self::ERROR_CODES ) ) {
			return new \WP_Error( 'etehadyar_sms_' . abs( $numeric ), self::ERROR_CODES[ $numeric ] );
		}

		// RetStatus of 1 with a short positive value is still a success signal.
		if ( 1 === (int) $status && $numeric > 0 ) {
			return true;
		}

		return new \WP_Error(
			'etehadyar_sms_code_' . abs( $numeric ),
			sprintf(
				/* translators: %s: provider status code. */
				__( 'ارسال پیامک انجام نشد (کد پاسخ: %s).', 'etehadyar-core' ),
				$value
			)
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function settings_fields() {
		return array(
			self::OPTION_USERNAME => array(
				'label' => __( 'نام کاربری ملی پیامک', 'etehadyar-core' ),
				'type'  => 'text',
			),
			self::SECRET_PASSWORD => array(
				'label'  => __( 'رمز عبور ملی پیامک', 'etehadyar-core' ),
				'type'   => 'password',
				'secret' => true,
				'help'   => __( 'رمزنگاری‌شده ذخیره می‌شود.', 'etehadyar-core' ),
			),
			self::OPTION_MODE     => array(
				'label' => __( 'روش ارسال', 'etehadyar-core' ),
				'type'  => 'select',
				'help'  => __( 'پترن (خط خدماتی اشتراکی) پیشنهاد می‌شود؛ فقط این روش به شماره‌های داخل لیست سیاه مخابرات می‌رسد.', 'etehadyar-core' ),
			),
			self::OPTION_BODY_ID  => array(
				'label' => __( 'کد پترن (bodyId)', 'etehadyar-core' ),
				'type'  => 'number',
				'help'  => __( 'برای روش پترن لازم است. متن پترن باید شامل یک متغیر برای کد باشد.', 'etehadyar-core' ),
			),
			self::OPTION_FROM     => array(
				'label' => __( 'شماره فرستنده', 'etehadyar-core' ),
				'type'  => 'text',
				'help'  => __( 'برای روش‌های رمز یک‌بارمصرف و پیامک ساده لازم است.', 'etehadyar-core' ),
			),
		);
	}
}
