<?php
/**
 * Front-end wallet panel.
 *
 * Renders as `[etehadyar_wallet]`: balance, top-up form with preset amounts,
 * and a transaction history table.
 *
 * @package Etehadyar\Frontend
 */

namespace Etehadyar\Frontend;

use Etehadyar\Billing\Orders;
use Etehadyar\Billing\Wallet;

defined( 'ABSPATH' ) || exit;

/**
 * Wallet shortcode.
 */
class Wallet_Shortcode {

	/**
	 * Register hooks.
	 */
	public static function boot() {
		add_shortcode( 'etehadyar_wallet', array( __CLASS__, 'render' ) );
	}

	/**
	 * Render the panel.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public static function render( $atts = array() ) {
		if ( ! is_user_logged_in() ) {
			return sprintf(
				'<div class="etehadyar-wallet etehadyar-wallet--guest"><p>%s</p></div>',
				esc_html__( 'برای مشاهدهٔ کیف پول ابتدا وارد شوید.', 'etehadyar-core' )
			);
		}

		$user_id = get_current_user_id();
		$wallet  = Wallet::get( $user_id );
		$history = Wallet::history( $user_id, array( 'per_page' => 10 ) );
		$uid     = 'etehadyar-wallet-' . wp_generate_password( 6, false, false );

		$presets = apply_filters(
			'etehadyar_topup_presets',
			array( 500000, 1000000, 2000000, 5000000 )
		);

		ob_start();
		?>
		<div class="etehadyar-wallet" id="<?php echo esc_attr( $uid ); ?>" dir="rtl"
			data-root="<?php echo esc_url( rest_url( 'etehadyar/v1' ) ); ?>"
			data-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>">

			<?php self::render_notice(); ?>

			<div class="etehadyar-wallet__balance">
				<span class="etehadyar-wallet__balance-label"><?php esc_html_e( 'موجودی کیف پول', 'etehadyar-core' ); ?></span>
				<strong class="etehadyar-wallet__balance-value"><?php echo esc_html( Wallet::format( $wallet['balance'] ) ); ?></strong>
			</div>

			<div class="etehadyar-wallet__topup">
				<h3 class="etehadyar-wallet__heading"><?php esc_html_e( 'افزایش اعتبار', 'etehadyar-core' ); ?></h3>

				<div class="etehadyar-wallet__presets">
					<?php foreach ( $presets as $preset ) : ?>
						<button type="button" class="etehadyar-wallet__preset" data-amount="<?php echo esc_attr( $preset ); ?>">
							<?php echo esc_html( number_format_i18n( $preset ) ); ?>
						</button>
					<?php endforeach; ?>
				</div>

				<label class="etehadyar-wallet__label" for="<?php echo esc_attr( $uid ); ?>-amount">
					<?php esc_html_e( 'مبلغ به ریال', 'etehadyar-core' ); ?>
				</label>
				<input class="etehadyar-wallet__input" id="<?php echo esc_attr( $uid ); ?>-amount"
					type="text" inputmode="numeric" dir="ltr"
					value="<?php echo esc_attr( Orders::min_amount() ); ?>">

				<p class="etehadyar-wallet__hint">
					<?php
					printf(
						/* translators: 1: minimum, 2: maximum. */
						esc_html__( 'حداقل %1$s و حداکثر %2$s', 'etehadyar-core' ),
						esc_html( Wallet::format( Orders::min_amount() ) ),
						esc_html( Wallet::format( Orders::max_amount() ) )
					);
					?>
				</p>

				<button type="button" class="etehadyar-wallet__button" data-action="topup">
					<?php esc_html_e( 'پرداخت و شارژ', 'etehadyar-core' ); ?>
				</button>

				<p class="etehadyar-wallet__message" data-slot="message" role="status" aria-live="polite"></p>
			</div>

			<div class="etehadyar-wallet__history">
				<h3 class="etehadyar-wallet__heading"><?php esc_html_e( 'تراکنش‌های اخیر', 'etehadyar-core' ); ?></h3>

				<?php if ( empty( $history['items'] ) ) : ?>
					<p class="etehadyar-wallet__empty"><?php esc_html_e( 'هنوز تراکنشی ثبت نشده است.', 'etehadyar-core' ); ?></p>
				<?php else : ?>
					<table class="etehadyar-wallet__table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'نوع', 'etehadyar-core' ); ?></th>
								<th><?php esc_html_e( 'مبلغ', 'etehadyar-core' ); ?></th>
								<th><?php esc_html_e( 'مانده', 'etehadyar-core' ); ?></th>
								<th><?php esc_html_e( 'تاریخ', 'etehadyar-core' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $history['items'] as $item ) : ?>
								<tr>
									<td><?php echo esc_html( $item['type_label'] ); ?></td>
									<td class="<?php echo $item['amount'] >= 0 ? 'is-in' : 'is-out'; ?>">
										<?php echo esc_html( ( $item['amount'] >= 0 ? '+' : '−' ) . number_format_i18n( abs( $item['amount'] ) ) ); ?>
									</td>
									<td><?php echo esc_html( number_format_i18n( $item['balance_after'] ) ); ?></td>
									<td><?php echo esc_html( mysql2date( 'Y/m/d H:i', $item['created_at'] ) ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>
		</div>

		<style>
		#<?php echo esc_attr( $uid ); ?>.etehadyar-wallet{max-width:720px;margin:0 auto;font-family:inherit;box-sizing:border-box}
		#<?php echo esc_attr( $uid ); ?> *{box-sizing:border-box}
		#<?php echo esc_attr( $uid ); ?> .etehadyar-wallet__balance{display:flex;flex-direction:column;gap:6px;padding:22px;border-radius:14px;background:linear-gradient(135deg,#2271b1,#154c7d);color:#fff;margin-bottom:22px}
		#<?php echo esc_attr( $uid ); ?> .etehadyar-wallet__balance-label{font-size:.85rem;opacity:.85}
		#<?php echo esc_attr( $uid ); ?> .etehadyar-wallet__balance-value{font-size:1.7rem;font-weight:700}
		#<?php echo esc_attr( $uid ); ?> .etehadyar-wallet__heading{font-size:1rem;margin:0 0 12px}
		#<?php echo esc_attr( $uid ); ?> .etehadyar-wallet__topup,#<?php echo esc_attr( $uid ); ?> .etehadyar-wallet__history{padding:20px;border:1px solid #e3e6ea;border-radius:14px;background:#fff;margin-bottom:18px}
		#<?php echo esc_attr( $uid ); ?> .etehadyar-wallet__presets{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:14px}
		#<?php echo esc_attr( $uid ); ?> .etehadyar-wallet__preset{padding:8px 14px;border:1px solid #cfd4da;border-radius:8px;background:#f6f7f8;cursor:pointer;font-size:.9rem}
		#<?php echo esc_attr( $uid ); ?> .etehadyar-wallet__preset:hover{border-color:#2271b1;color:#2271b1}
		#<?php echo esc_attr( $uid ); ?> .etehadyar-wallet__label{display:block;margin-bottom:6px;font-size:.85rem;color:#444}
		#<?php echo esc_attr( $uid ); ?> .etehadyar-wallet__input{width:100%;padding:11px 13px;border:1px solid #cfd4da;border-radius:9px;font-size:1.05rem;text-align:center;letter-spacing:.03em}
		#<?php echo esc_attr( $uid ); ?> .etehadyar-wallet__input:focus{outline:2px solid #2271b1;outline-offset:1px}
		#<?php echo esc_attr( $uid ); ?> .etehadyar-wallet__hint{font-size:.78rem;color:#777;margin:8px 0 0;text-align:center}
		#<?php echo esc_attr( $uid ); ?> .etehadyar-wallet__button{width:100%;margin-top:14px;padding:12px;border:0;border-radius:9px;background:#2271b1;color:#fff;font-size:1rem;font-weight:600;cursor:pointer}
		#<?php echo esc_attr( $uid ); ?> .etehadyar-wallet__button:hover{background:#185a8e}
		#<?php echo esc_attr( $uid ); ?> .etehadyar-wallet__button[disabled]{opacity:.6;cursor:default}
		#<?php echo esc_attr( $uid ); ?> .etehadyar-wallet__message{margin:12px 0 0;font-size:.86rem;min-height:1.1em;text-align:center}
		#<?php echo esc_attr( $uid ); ?> .etehadyar-wallet__message--error{color:#b32d2e}
		#<?php echo esc_attr( $uid ); ?> .etehadyar-wallet__table{width:100%;border-collapse:collapse;font-size:.87rem}
		#<?php echo esc_attr( $uid ); ?> .etehadyar-wallet__table th,#<?php echo esc_attr( $uid ); ?> .etehadyar-wallet__table td{padding:9px 6px;border-bottom:1px solid #eef0f2;text-align:right}
		#<?php echo esc_attr( $uid ); ?> .etehadyar-wallet__table th{font-weight:600;color:#555;font-size:.8rem}
		#<?php echo esc_attr( $uid ); ?> .etehadyar-wallet__table .is-in{color:#00794b;font-weight:600}
		#<?php echo esc_attr( $uid ); ?> .etehadyar-wallet__table .is-out{color:#b32d2e;font-weight:600}
		#<?php echo esc_attr( $uid ); ?> .etehadyar-wallet__empty{color:#777;font-size:.88rem;margin:0}
		#<?php echo esc_attr( $uid ); ?> .etehadyar-wallet__notice{padding:12px 16px;border-radius:10px;margin-bottom:18px;font-size:.9rem}
		#<?php echo esc_attr( $uid ); ?> .etehadyar-wallet__notice--success{background:#e6f4ec;color:#00794b;border:1px solid #b7e0c9}
		#<?php echo esc_attr( $uid ); ?> .etehadyar-wallet__notice--error{background:#fbeaea;color:#b32d2e;border:1px solid #f0c2c2}
		</style>

		<script>
		(function () {
			var root = document.getElementById(<?php echo wp_json_encode( $uid ); ?>);
			if (!root) { return; }

			var api    = root.dataset.root;
			var nonce  = root.dataset.nonce;
			var amount = root.querySelector('.etehadyar-wallet__input');
			var msg    = root.querySelector('[data-slot="message"]');
			var btn    = root.querySelector('[data-action="topup"]');

			function toAscii(v) {
				return v.replace(/[\u06F0-\u06F9]/g, function (d) { return d.charCodeAt(0) - 0x06F0; })
				        .replace(/[\u0660-\u0669]/g, function (d) { return d.charCodeAt(0) - 0x0660; });
			}

			function say(text, kind) {
				msg.textContent = text || '';
				msg.className = 'etehadyar-wallet__message' + (kind ? ' etehadyar-wallet__message--' + kind : '');
			}

			root.addEventListener('click', function (e) {
				var preset = e.target.getAttribute && e.target.getAttribute('data-amount');
				if (preset) { amount.value = preset; say(''); return; }

				if (e.target !== btn) { return; }

				var value = parseInt(toAscii(amount.value).replace(/\D/g, ''), 10);
				if (!value) {
					say(<?php echo wp_json_encode( __( 'مبلغ را وارد کنید.', 'etehadyar-core' ) ); ?>, 'error');
					return;
				}

				btn.disabled = true;
				say(<?php echo wp_json_encode( __( 'در حال انتقال به درگاه...', 'etehadyar-core' ) ); ?>);

				fetch(api + '/wallet/topup', {
					method: 'POST',
					credentials: 'same-origin',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
					body: JSON.stringify({ amount: value })
				}).then(function (res) {
					return res.json().then(function (data) {
						if (!res.ok) { throw new Error((data && data.message) || 'error'); }
						return data;
					});
				}).then(function (data) {
					window.location.href = data.redirect;
				}).catch(function (err) {
					say(err.message, 'error');
					btn.disabled = false;
				});
			});
		})();
		</script>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Print the post-payment notice.
	 */
	protected static function render_notice() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only display of a redirect flag.
		$payment = isset( $_GET['payment'] ) ? sanitize_key( wp_unslash( $_GET['payment'] ) ) : '';
		$reason  = isset( $_GET['reason'] ) ? sanitize_text_field( rawurldecode( wp_unslash( $_GET['reason'] ) ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( 'success' === $payment ) {
			printf(
				'<div class="etehadyar-wallet__notice etehadyar-wallet__notice--success">%s</div>',
				esc_html__( 'پرداخت با موفقیت انجام شد و اعتبار شما افزایش یافت.', 'etehadyar-core' )
			);
		} elseif ( 'processing' === $payment ) {
			/*
			 * Another request owns this payment and is settling it right now.
			 * Neither "failed" (customer pays again) nor "success" (wallet is
			 * not credited yet) would be true.
			 */
			printf(
				'<div class="etehadyar-wallet__notice etehadyar-wallet__notice--info">%s %s</div>',
				esc_html__( 'پرداخت در حال پردازش است.', 'etehadyar-core' ),
				esc_html__( 'کیف‌پول شما لحظاتی دیگر به‌روزرسانی می‌شود؛ از پرداخت مجدد خودداری کنید.', 'etehadyar-core' )
			);
		} elseif ( 'failed' === $payment ) {
			printf(
				'<div class="etehadyar-wallet__notice etehadyar-wallet__notice--error">%s %s</div>',
				esc_html__( 'پرداخت ناموفق بود.', 'etehadyar-core' ),
				esc_html( $reason )
			);
		}
	}
}
