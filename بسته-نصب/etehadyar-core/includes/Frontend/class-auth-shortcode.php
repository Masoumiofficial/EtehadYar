<?php
/**
 * Front-end mobile login form.
 *
 * Renders as `[etehadyar_login]`. Two steps in one component: enter number,
 * then enter the code. Everything is namespaced and inline so it works on any
 * theme without extra asset files.
 *
 * @package Etehadyar\Frontend
 */

namespace Etehadyar\Frontend;

use Etehadyar\Auth\Mobile_Auth;
use Etehadyar\Auth\OTP_Service;

defined( 'ABSPATH' ) || exit;

/**
 * Login shortcode.
 */
class Auth_Shortcode {

	/**
	 * Register hooks.
	 */
	public static function boot() {
		add_shortcode( 'etehadyar_login', array( __CLASS__, 'render' ) );
	}

	/**
	 * Render the form.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public static function render( $atts = array() ) {
		$atts = shortcode_atts(
			array(
				'title'    => __( 'ورود یا ثبت‌نام', 'etehadyar-core' ),
				'redirect' => '',
			),
			$atts,
			'etehadyar_login'
		);

		if ( is_user_logged_in() ) {
			$user = wp_get_current_user();

			return sprintf(
				'<div class="etehadyar-auth etehadyar-auth--done"><p>%s</p><p><a class="etehadyar-auth__link" href="%s">%s</a></p></div>',
				esc_html(
					sprintf(
						/* translators: %s: display name. */
						__( 'شما با حساب %s وارد شده‌اید.', 'etehadyar-core' ),
						$user->display_name
					)
				),
				esc_url( wp_logout_url( home_url( '/' ) ) ),
				esc_html__( 'خروج از حساب', 'etehadyar-core' )
			);
		}

		$uid = 'etehadyar-auth-' . wp_generate_password( 6, false, false );

		ob_start();
		?>
		<div class="etehadyar-auth" id="<?php echo esc_attr( $uid ); ?>" dir="rtl"
			data-root="<?php echo esc_url( rest_url( 'etehadyar/v1' ) ); ?>"
			data-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>"
			data-redirect="<?php echo esc_url( $atts['redirect'] ); ?>"
			data-cooldown="<?php echo esc_attr( OTP_Service::RESEND_COOLDOWN ); ?>">

			<h2 class="etehadyar-auth__title"><?php echo esc_html( $atts['title'] ); ?></h2>

			<div class="etehadyar-auth__step" data-step="phone">
				<label class="etehadyar-auth__label" for="<?php echo esc_attr( $uid ); ?>-phone">
					<?php esc_html_e( 'شماره موبایل', 'etehadyar-core' ); ?>
				</label>
				<input class="etehadyar-auth__input" id="<?php echo esc_attr( $uid ); ?>-phone"
					type="tel" inputmode="numeric" autocomplete="tel" dir="ltr"
					placeholder="09123456789" maxlength="15">

				<div class="etehadyar-auth__hp" aria-hidden="true">
					<label><?php esc_html_e( 'این فیلد را خالی بگذارید', 'etehadyar-core' ); ?>
						<input type="text" data-field="website" tabindex="-1" autocomplete="off">
					</label>
				</div>

				<button type="button" class="etehadyar-auth__button" data-action="request">
					<?php esc_html_e( 'دریافت کد ورود', 'etehadyar-core' ); ?>
				</button>

				<?php if ( ! Mobile_Auth::registration_open() ) : ?>
					<p class="etehadyar-auth__hint"><?php esc_html_e( 'ثبت‌نام جدید بسته است؛ فقط کاربران موجود می‌توانند وارد شوند.', 'etehadyar-core' ); ?></p>
				<?php endif; ?>
			</div>

			<div class="etehadyar-auth__step" data-step="code" hidden>
				<p class="etehadyar-auth__sent" data-slot="sent"></p>

				<label class="etehadyar-auth__label" for="<?php echo esc_attr( $uid ); ?>-code">
					<?php esc_html_e( 'کد ارسال‌شده', 'etehadyar-core' ); ?>
				</label>
				<input class="etehadyar-auth__input etehadyar-auth__input--code" id="<?php echo esc_attr( $uid ); ?>-code"
					type="text" inputmode="numeric" autocomplete="one-time-code" dir="ltr"
					maxlength="8" placeholder="- - - - -">

				<button type="button" class="etehadyar-auth__button" data-action="verify">
					<?php esc_html_e( 'ورود', 'etehadyar-core' ); ?>
				</button>

				<div class="etehadyar-auth__row">
					<button type="button" class="etehadyar-auth__link" data-action="resend" disabled></button>
					<button type="button" class="etehadyar-auth__link" data-action="change">
						<?php esc_html_e( 'تغییر شماره', 'etehadyar-core' ); ?>
					</button>
				</div>
			</div>

			<p class="etehadyar-auth__message" data-slot="message" role="status" aria-live="polite"></p>
		</div>

		<style>
		#<?php echo esc_attr( $uid ); ?>.etehadyar-auth{max-width:380px;margin:0 auto;padding:24px;border:1px solid #e3e6ea;border-radius:14px;background:#fff;font-family:inherit;box-sizing:border-box}
		#<?php echo esc_attr( $uid ); ?> .etehadyar-auth__title{margin:0 0 18px;font-size:1.15rem;text-align:center}
		#<?php echo esc_attr( $uid ); ?> .etehadyar-auth__label{display:block;margin-bottom:6px;font-size:.88rem;color:#444}
		#<?php echo esc_attr( $uid ); ?> .etehadyar-auth__input{width:100%;padding:11px 13px;border:1px solid #cfd4da;border-radius:9px;font-size:1rem;box-sizing:border-box;text-align:center;letter-spacing:.04em}
		#<?php echo esc_attr( $uid ); ?> .etehadyar-auth__input:focus{outline:2px solid #2271b1;outline-offset:1px;border-color:#2271b1}
		#<?php echo esc_attr( $uid ); ?> .etehadyar-auth__input--code{letter-spacing:.5em;font-size:1.3rem;font-weight:600}
		#<?php echo esc_attr( $uid ); ?> .etehadyar-auth__button{width:100%;margin-top:14px;padding:12px;border:0;border-radius:9px;background:#2271b1;color:#fff;font-size:1rem;font-weight:600;cursor:pointer}
		#<?php echo esc_attr( $uid ); ?> .etehadyar-auth__button:hover{background:#185a8e}
		#<?php echo esc_attr( $uid ); ?> .etehadyar-auth__button[disabled]{opacity:.6;cursor:default}
		#<?php echo esc_attr( $uid ); ?> .etehadyar-auth__row{display:flex;justify-content:space-between;margin-top:12px;gap:8px}
		#<?php echo esc_attr( $uid ); ?> .etehadyar-auth__link{background:none;border:0;padding:0;color:#2271b1;font-size:.85rem;cursor:pointer;text-decoration:underline}
		#<?php echo esc_attr( $uid ); ?> .etehadyar-auth__link[disabled]{color:#888;cursor:default;text-decoration:none}
		#<?php echo esc_attr( $uid ); ?> .etehadyar-auth__message{margin:14px 0 0;font-size:.88rem;min-height:1.2em;text-align:center}
		#<?php echo esc_attr( $uid ); ?> .etehadyar-auth__message--error{color:#b32d2e}
		#<?php echo esc_attr( $uid ); ?> .etehadyar-auth__message--ok{color:#00794b}
		#<?php echo esc_attr( $uid ); ?> .etehadyar-auth__sent{font-size:.88rem;color:#555;text-align:center;margin:0 0 14px}
		#<?php echo esc_attr( $uid ); ?> .etehadyar-auth__hint{font-size:.8rem;color:#777;text-align:center;margin:10px 0 0}
		#<?php echo esc_attr( $uid ); ?> .etehadyar-auth__hp{position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden}
		</style>

		<script>
		(function () {
			var root = document.getElementById(<?php echo wp_json_encode( $uid ); ?>);
			if (!root) { return; }

			var api      = root.dataset.root;
			var nonce    = root.dataset.nonce;
			var cooldown = parseInt(root.dataset.cooldown, 10) || 60;
			var stepPhone = root.querySelector('[data-step="phone"]');
			var stepCode  = root.querySelector('[data-step="code"]');
			var phoneIn   = root.querySelector('input[type="tel"]');
			var codeIn    = root.querySelector('.etehadyar-auth__input--code');
			var hp        = root.querySelector('[data-field="website"]');
			var msg       = root.querySelector('[data-slot="message"]');
			var sent      = root.querySelector('[data-slot="sent"]');
			var resendBtn = root.querySelector('[data-action="resend"]');
			var timer     = null;

			// Persian and Arabic-Indic digits are converted client-side too, so
			// the field looks right to the user before it is ever submitted.
			function toAscii(value) {
				return value
					.replace(/[\u06F0-\u06F9]/g, function (d) { return d.charCodeAt(0) - 0x06F0; })
					.replace(/[\u0660-\u0669]/g, function (d) { return d.charCodeAt(0) - 0x0660; });
			}

			function say(text, kind) {
				msg.textContent = text || '';
				msg.className = 'etehadyar-auth__message' + (kind ? ' etehadyar-auth__message--' + kind : '');
			}

			function busy(on) {
				root.querySelectorAll('button[data-action="request"], button[data-action="verify"]').forEach(function (b) {
					b.disabled = on;
				});
			}

			function post(path, body) {
				return fetch(api + path, {
					method: 'POST',
					credentials: 'same-origin',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
					body: JSON.stringify(body)
				}).then(function (res) {
					return res.json().then(function (data) {
						if (!res.ok) {
							throw new Error((data && data.message) || <?php echo wp_json_encode( __( 'خطای غیرمنتظره رخ داد.', 'etehadyar-core' ) ); ?>);
						}
						return data;
					});
				});
			}

			function startCooldown(seconds) {
				var left = seconds;
				clearInterval(timer);
				resendBtn.disabled = true;

				function tick() {
					if (left <= 0) {
						clearInterval(timer);
						resendBtn.disabled = false;
						resendBtn.textContent = <?php echo wp_json_encode( __( 'ارسال دوبارهٔ کد', 'etehadyar-core' ) ); ?>;
						return;
					}
					resendBtn.textContent = <?php echo wp_json_encode( __( 'ارسال دوباره تا', 'etehadyar-core' ) ); ?> + ' ' + left + ' ' + <?php echo wp_json_encode( __( 'ثانیه', 'etehadyar-core' ) ); ?>;
					left--;
				}

				tick();
				timer = setInterval(tick, 1000);
			}

			function requestCode() {
				var phone = toAscii(phoneIn.value).trim();
				if (!phone) {
					say(<?php echo wp_json_encode( __( 'شماره موبایل را وارد کنید.', 'etehadyar-core' ) ); ?>, 'error');
					return;
				}

				busy(true);
				say(<?php echo wp_json_encode( __( 'در حال ارسال...', 'etehadyar-core' ) ); ?>);

				post('/auth/request-code', { phone: phone, website: hp ? hp.value : '' })
					.then(function (data) {
						stepPhone.hidden = true;
						stepCode.hidden = false;
						sent.textContent = <?php echo wp_json_encode( __( 'کد به شمارهٔ', 'etehadyar-core' ) ); ?> + ' ' + data.masked + ' ' + <?php echo wp_json_encode( __( 'ارسال شد.', 'etehadyar-core' ) ); ?>;
						say('');
						codeIn.focus();
						startCooldown(data.resend_in || cooldown);
					})
					.catch(function (err) { say(err.message, 'error'); })
					.then(function () { busy(false); });
			}

			function verify() {
				var code = toAscii(codeIn.value).replace(/\D/g, '');
				if (!code) {
					say(<?php echo wp_json_encode( __( 'کد را وارد کنید.', 'etehadyar-core' ) ); ?>, 'error');
					return;
				}

				busy(true);
				say(<?php echo wp_json_encode( __( 'در حال بررسی...', 'etehadyar-core' ) ); ?>);

				post('/auth/verify', { phone: toAscii(phoneIn.value).trim(), code: code, remember: true })
					.then(function (data) {
						say(<?php echo wp_json_encode( __( 'خوش آمدید! در حال انتقال...', 'etehadyar-core' ) ); ?>, 'ok');
						window.location.href = root.dataset.redirect || data.redirect || window.location.href;
					})
					.catch(function (err) {
						say(err.message, 'error');
						codeIn.select();
					})
					.then(function () { busy(false); });
			}

			root.addEventListener('click', function (e) {
				var action = e.target.getAttribute && e.target.getAttribute('data-action');
				if (!action) { return; }

				if (action === 'request' || action === 'resend') { requestCode(); }
				if (action === 'verify') { verify(); }
				if (action === 'change') {
					clearInterval(timer);
					stepCode.hidden = true;
					stepPhone.hidden = false;
					codeIn.value = '';
					say('');
					phoneIn.focus();
				}
			});

			root.addEventListener('keydown', function (e) {
				if (e.key !== 'Enter') { return; }
				if (e.target === phoneIn) { e.preventDefault(); requestCode(); }
				if (e.target === codeIn) { e.preventDefault(); verify(); }
			});
		})();
		</script>
		<?php

		return (string) ob_get_clean();
	}
}
