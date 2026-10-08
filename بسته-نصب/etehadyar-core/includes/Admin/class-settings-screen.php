<?php
/**
 * Settings screen for SMS and registration.
 *
 * All configuration lives on the WordPress dashboard, as the platform brief
 * requires: administrators configure, members only consume.
 *
 * @package Etehadyar\Admin
 */

namespace Etehadyar\Admin;

use Etehadyar\Auth\Gateway_Registry;
use Etehadyar\Auth\Melipayamak_Gateway;
use Etehadyar\Auth\OTP_Service;
use Etehadyar\Auth\Phone;
use Etehadyar\Core\Audit;
use Etehadyar\Core\Capabilities;
use Etehadyar\Core\Secrets;

defined( 'ABSPATH' ) || exit;

/**
 * SMS and registration settings.
 */
class Settings_Screen {

	const PAGE_SLUG = 'etehadyar-settings';

	/**
	 * Register the submenu.
	 */
	public static function register() {
		add_submenu_page(
			'etehadyar-platform',
			__( 'تنظیمات پیامک و ورود', 'etehadyar-core' ),
			__( 'تنظیمات', 'etehadyar-core' ),
			Capabilities::MANAGE_PLATFORM,
			self::PAGE_SLUG,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Handle form submissions before any output.
	 *
	 * Runs on `admin_init` so redirects work and the POST/redirect/GET pattern
	 * is preserved.
	 */
	public static function handle_post() {
		if ( empty( $_POST['etehadyar_settings_submit'] ) ) {
			return;
		}

		if ( ! current_user_can( Capabilities::MANAGE_PLATFORM ) ) {
			wp_die( esc_html__( 'دسترسی غیرمجاز.', 'etehadyar-core' ) );
		}

		check_admin_referer( 'etehadyar_settings' );

		$action = sanitize_key( wp_unslash( $_POST['etehadyar_settings_submit'] ) );

		if ( 'test' === $action ) {
			self::handle_test();
			return;
		}

		self::save();
	}

	/**
	 * Persist submitted settings.
	 */
	protected static function save() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in handle_post().
		update_option(
			Gateway_Registry::OPTION_ACTIVE,
			sanitize_key( wp_unslash( $_POST['gateway'] ?? 'melipayamak' ) )
		);

		update_option(
			Melipayamak_Gateway::OPTION_USERNAME,
			sanitize_text_field( wp_unslash( $_POST['mp_username'] ?? '' ) )
		);

		$mode = sanitize_key( wp_unslash( $_POST['mp_mode'] ?? 'pattern' ) );
		update_option(
			Melipayamak_Gateway::OPTION_MODE,
			in_array( $mode, array( 'pattern', 'otp', 'simple' ), true ) ? $mode : 'pattern'
		);

		update_option(
			Melipayamak_Gateway::OPTION_BODY_ID,
			absint( wp_unslash( $_POST['mp_body_id'] ?? 0 ) )
		);

		update_option(
			Melipayamak_Gateway::OPTION_FROM,
			sanitize_text_field( wp_unslash( $_POST['mp_from'] ?? '' ) )
		);

		// An empty password field means "leave unchanged", so an admin editing
		// the sender number does not silently wipe the stored credential.
		$password = (string) wp_unslash( $_POST['mp_password'] ?? '' );

		if ( '' !== trim( $password ) ) {
			Secrets::set( Melipayamak_Gateway::SECRET_PASSWORD, trim( $password ) );
		}

		$reg_mode = sanitize_key( wp_unslash( $_POST['registration_mode'] ?? 'open' ) );
		update_option(
			'etehadyar_registration_mode',
			in_array( $reg_mode, array( 'open', 'closed' ), true ) ? $reg_mode : 'open'
		);
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		Audit::log(
			'settings.updated',
			array(
				'severity' => 'notice',
				'context'  => array( 'section' => 'sms' ),
			)
		);

		self::redirect( 'saved' );
	}

	/**
	 * Send a live test message.
	 */
	protected static function handle_test() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in handle_post().
		$phone = sanitize_text_field( wp_unslash( $_POST['test_phone'] ?? '' ) );

		if ( ! Phone::is_valid( $phone ) ) {
			self::redirect( 'test_bad_number' );
		}

		$result = OTP_Service::request( $phone, 'test' );

		if ( is_wp_error( $result ) ) {
			self::redirect( 'test_failed', $result->get_error_message() );
		}

		self::redirect( 'test_sent' );
	}

	/**
	 * Redirect back to the settings screen with a notice.
	 *
	 * @param string $notice  Notice key.
	 * @param string $message Optional detail.
	 */
	protected static function redirect( $notice, $message = '' ) {
		$args = array(
			'page'              => self::PAGE_SLUG,
			'etehadyar_notice'  => $notice,
		);

		if ( $message ) {
			$args['etehadyar_detail'] = rawurlencode( $message );
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Render the screen.
	 */
	public static function render() {
		if ( ! current_user_can( Capabilities::MANAGE_PLATFORM ) ) {
			wp_die( esc_html__( 'دسترسی غیرمجاز.', 'etehadyar-core' ) );
		}

		$gateway = Gateway_Registry::active();
		$mode    = get_option( Melipayamak_Gateway::OPTION_MODE, 'pattern' );

		?>
		<div class="wrap" dir="rtl">
			<h1><?php esc_html_e( 'تنظیمات پیامک و ورود', 'etehadyar-core' ); ?></h1>

			<?php self::render_notice(); ?>
			<?php self::render_pattern_alert(); ?>
			<?php self::render_status( $gateway ); ?>

			<form method="post" action="">
				<?php wp_nonce_field( 'etehadyar_settings' ); ?>

				<h2 class="title"><?php esc_html_e( 'سرویس پیامک', 'etehadyar-core' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="mp_username"><?php esc_html_e( 'نام کاربری', 'etehadyar-core' ); ?></label></th>
						<td>
							<input name="mp_username" id="mp_username" type="text" class="regular-text" dir="ltr"
								value="<?php echo esc_attr( get_option( Melipayamak_Gateway::OPTION_USERNAME, '' ) ); ?>">
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="mp_password"><?php esc_html_e( 'رمز عبور', 'etehadyar-core' ); ?></label></th>
						<td>
							<input name="mp_password" id="mp_password" type="password" class="regular-text" dir="ltr"
								autocomplete="new-password"
								placeholder="<?php echo Secrets::has( Melipayamak_Gateway::SECRET_PASSWORD ) ? esc_attr__( '•••••••• (ذخیره شده)', 'etehadyar-core' ) : ''; ?>">
							<p class="description">
								<?php esc_html_e( 'رمزنگاری‌شده ذخیره می‌شود. برای حفظ رمز فعلی این فیلد را خالی بگذارید.', 'etehadyar-core' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'روش ارسال', 'etehadyar-core' ); ?></th>
						<td>
							<fieldset>
								<label>
									<input type="radio" name="mp_mode" value="pattern" <?php checked( $mode, 'pattern' ); ?>>
									<strong><?php esc_html_e( 'خط خدماتی اشتراکی (پترن)', 'etehadyar-core' ); ?></strong>
								</label>
								<p class="description" style="margin-inline-start:24px">
									<?php esc_html_e( 'پیشنهاد می‌شود. تنها روشی که پیامک را به شماره‌های داخل «لیست سیاه مخابرات» هم می‌رساند. نیازمند ثبت و تأیید پترن در پنل ملی پیامک است.', 'etehadyar-core' ); ?>
								</p>
								<br>
								<label>
									<input type="radio" name="mp_mode" value="otp" <?php checked( $mode, 'otp' ); ?>>
									<?php esc_html_e( 'رمز یک‌بارمصرف آماده (SendOtp)', 'etehadyar-core' ); ?>
								</label>
								<p class="description" style="margin-inline-start:24px">
									<?php esc_html_e( 'نیازی به تأیید پترن ندارد، اما متن پیامک ثابت است و به شماره‌های لیست سیاه نمی‌رسد.', 'etehadyar-core' ); ?>
								</p>
								<br>
								<label>
									<input type="radio" name="mp_mode" value="simple" <?php checked( $mode, 'simple' ); ?>>
									<?php esc_html_e( 'پیامک ساده از خط اختصاصی', 'etehadyar-core' ); ?>
								</label>
								<p class="description" style="margin-inline-start:24px">
									<?php esc_html_e( 'متن قابل تنظیم است، اما به شماره‌های لیست سیاه نمی‌رسد.', 'etehadyar-core' ); ?>
								</p>
							</fieldset>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="mp_body_id"><?php esc_html_e( 'کد پترن (bodyId)', 'etehadyar-core' ); ?></label></th>
						<td>
							<input name="mp_body_id" id="mp_body_id" type="number" class="small-text" dir="ltr"
								value="<?php echo esc_attr( get_option( Melipayamak_Gateway::OPTION_BODY_ID, '' ) ); ?>">
							<p class="description">
								<?php esc_html_e( 'فقط برای روش پترن. متن پترن باید دقیقاً یک متغیر داشته باشد، مثال: «کد ورود شما: {0}».', 'etehadyar-core' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="mp_from"><?php esc_html_e( 'شماره فرستنده', 'etehadyar-core' ); ?></label></th>
						<td>
							<input name="mp_from" id="mp_from" type="text" class="regular-text" dir="ltr"
								value="<?php echo esc_attr( get_option( Melipayamak_Gateway::OPTION_FROM, '' ) ); ?>">
							<p class="description"><?php esc_html_e( 'فقط برای روش‌های رمز یک‌بارمصرف و پیامک ساده.', 'etehadyar-core' ); ?></p>
						</td>
					</tr>
				</table>

				<h2 class="title"><?php esc_html_e( 'ثبت‌نام', 'etehadyar-core' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'وضعیت ثبت‌نام', 'etehadyar-core' ); ?></th>
						<td>
							<?php $reg = get_option( 'etehadyar_registration_mode', 'open' ); ?>
							<label>
								<input type="radio" name="registration_mode" value="open" <?php checked( $reg, 'open' ); ?>>
								<?php esc_html_e( 'باز — هر شمارهٔ تأییدشده می‌تواند حساب بسازد', 'etehadyar-core' ); ?>
							</label><br>
							<label>
								<input type="radio" name="registration_mode" value="closed" <?php checked( $reg, 'closed' ); ?>>
								<?php esc_html_e( 'بسته — فقط کاربران موجود می‌توانند وارد شوند', 'etehadyar-core' ); ?>
							</label>
						</td>
					</tr>
				</table>

				<input type="hidden" name="gateway" value="melipayamak">
				<p class="submit">
					<button type="submit" name="etehadyar_settings_submit" value="save" class="button button-primary">
						<?php esc_html_e( 'ذخیره تنظیمات', 'etehadyar-core' ); ?>
					</button>
				</p>
			</form>

			<hr>

			<h2 class="title"><?php esc_html_e( 'آزمایش ارسال', 'etehadyar-core' ); ?></h2>
			<form method="post" action="">
				<?php wp_nonce_field( 'etehadyar_settings' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="test_phone"><?php esc_html_e( 'شماره آزمایشی', 'etehadyar-core' ); ?></label></th>
						<td>
							<input name="test_phone" id="test_phone" type="text" class="regular-text" dir="ltr" placeholder="09123456789">
							<p class="description"><?php esc_html_e( 'یک کد واقعی به این شماره ارسال می‌شود و از اعتبار پنل کسر می‌گردد.', 'etehadyar-core' ); ?></p>
						</td>
					</tr>
				</table>
				<p class="submit">
					<button type="submit" name="etehadyar_settings_submit" value="test" class="button">
						<?php esc_html_e( 'ارسال پیامک آزمایشی', 'etehadyar-core' ); ?>
					</button>
				</p>
			</form>

			<hr>
			<h2 class="title"><?php esc_html_e( 'راهنمای نصب فرم ورود', 'etehadyar-core' ); ?></h2>
			<p><?php esc_html_e( 'کد کوتاه زیر را در هر برگه‌ای قرار دهید تا فرم ورود با موبایل نمایش داده شود:', 'etehadyar-core' ); ?></p>
			<p><code dir="ltr">[etehadyar_login]</code></p>
		</div>
		<?php
	}

	/**
	 * Warn when pattern delivery silently degraded.
	 */
	protected static function render_pattern_alert() {
		$alert = get_option( Melipayamak_Gateway::OPTION_PATTERN_ALERT );

		if ( ! $alert ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p><strong>%1$s</strong><br>%2$s<br>%3$s</p></div>',
			esc_html__( 'ارسال با پترن ناموفق بود و موقتاً به روش «رمز یک‌بارمصرف» تغییر کرد.', 'etehadyar-core' ),
			esc_html( $alert ),
			esc_html__( 'ورود کاربران قطع نشده است، اما تا رفع این مشکل پیامک به شماره‌های داخل لیست سیاه مخابرات نمی‌رسد. کد پترن را در پنل ملی پیامک بررسی کنید.', 'etehadyar-core' )
		);
	}

	/**
	 * Connection status card.
	 *
	 * @param \Etehadyar\Auth\SMS_Gateway|null $gateway Active gateway.
	 */
	protected static function render_status( $gateway ) {
		if ( ! $gateway ) {
			return;
		}

		$configured = $gateway->is_configured();
		?>
		<div class="card" style="max-width:640px;padding:12px 16px">
			<h2 style="margin-top:0"><?php esc_html_e( 'وضعیت اتصال', 'etehadyar-core' ); ?></h2>
			<p>
				<strong><?php esc_html_e( 'سرویس:', 'etehadyar-core' ); ?></strong>
				<?php echo esc_html( $gateway->label() ); ?>
				&nbsp;|&nbsp;
				<strong><?php esc_html_e( 'پیکربندی:', 'etehadyar-core' ); ?></strong>
				<?php if ( $configured ) : ?>
					<span style="color:#00794b">✔ <?php esc_html_e( 'کامل', 'etehadyar-core' ); ?></span>
				<?php else : ?>
					<span style="color:#b32d2e">✖ <?php esc_html_e( 'ناقص', 'etehadyar-core' ); ?></span>
				<?php endif; ?>
			</p>
			<?php
			if ( $configured ) {
				$credit = $gateway->get_credit();
				?>
				<p>
					<strong><?php esc_html_e( 'اعتبار پنل:', 'etehadyar-core' ); ?></strong>
					<?php if ( is_wp_error( $credit ) ) : ?>
						<span style="color:#b32d2e"><?php echo esc_html( $credit->get_error_message() ); ?></span>
					<?php else : ?>
						<?php echo esc_html( number_format_i18n( $credit ) ); ?>
					<?php endif; ?>
				</p>
				<?php
			}
			?>
		</div>
		<?php
	}

	/**
	 * Print the redirect notice.
	 */
	protected static function render_notice() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only display.
		$notice = isset( $_GET['etehadyar_notice'] ) ? sanitize_key( wp_unslash( $_GET['etehadyar_notice'] ) ) : '';
		$detail = isset( $_GET['etehadyar_detail'] ) ? sanitize_text_field( rawurldecode( wp_unslash( $_GET['etehadyar_detail'] ) ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( ! $notice ) {
			return;
		}

		$map = array(
			'saved'          => array( 'success', __( 'تنظیمات ذخیره شد.', 'etehadyar-core' ) ),
			'test_sent'      => array( 'success', __( 'پیامک آزمایشی ارسال شد.', 'etehadyar-core' ) ),
			'test_bad_number' => array( 'error', __( 'شماره آزمایشی معتبر نیست.', 'etehadyar-core' ) ),
			'test_failed'    => array( 'error', __( 'ارسال آزمایشی ناموفق بود.', 'etehadyar-core' ) ),
		);

		if ( ! isset( $map[ $notice ] ) ) {
			return;
		}

		list( $type, $message ) = $map[ $notice ];

		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s %3$s</p></div>',
			esc_attr( $type ),
			esc_html( $message ),
			$detail ? esc_html( '— ' . $detail ) : ''
		);
	}
}
