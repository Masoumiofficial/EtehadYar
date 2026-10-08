<?php
/**
 * `[etehadyar_studio]` — the front-end execution UI.
 *
 * @package Etehadyar\Frontend
 */

namespace Etehadyar\Frontend;

use Etehadyar\Billing\Estimator;
use Etehadyar\Billing\Pricing;
use Etehadyar\Billing\Wallet;
use Etehadyar\Core\Capabilities;
use Etehadyar\Core\Tenant;

defined( 'ABSPATH' ) || exit;

/**
 * Until now the AI tools existed only as admin screens, so a paying customer
 * had a wallet and a dashboard but nowhere to actually spend it. This
 * shortcode is that missing surface.
 *
 * It deliberately posts to the legacy `admin-ajax.php` actions rather than
 * reimplementing generation: those handlers are where the provider logic
 * lives, and admin-ajax runs `admin_init`, which is where Phase 4's billing
 * interceptor and Phase 5's capability bridge are attached. Going through the
 * front door means every request is metered, quota-checked and refunded on
 * failure without duplicating a single line of that logic.
 */
class Studio_Shortcode {

	/**
	 * Attach hooks.
	 */
	public static function boot() {
		add_shortcode( 'etehadyar_studio', array( __CLASS__, 'render' ) );
	}

	/**
	 * Tool definitions.
	 *
	 * @return array
	 */
	public static function available_tools() {
		$tools = array(
			'content' => array(
				'action'     => 'eaiw_factory_generate',
				'label'      => __( 'تولید محتوا', 'etehadyar-core' ),
				'icon'       => '✍️',
				'capability' => Capabilities::GENERATE_CONTENT,
				'operation'  => 'content_word',
				'unit'       => __( 'هر ۱۰۰ کلمه', 'etehadyar-core' ),
				'hint'       => __( 'موضوع مقاله را بنویسید؛ خروجی، متن کامل فارسی است.', 'etehadyar-core' ),
			),
			'image'   => array(
				'action'     => 'eaiw_vision_generate',
				'label'      => __( 'ساخت تصویر', 'etehadyar-core' ),
				'icon'       => '🖼️',
				'capability' => Capabilities::GENERATE_IMAGE,
				'operation'  => 'image',
				'unit'       => __( 'هر تصویر', 'etehadyar-core' ),
				'hint'       => __( 'تصویر را با جزئیات توصیف کنید — هرچه دقیق‌تر، بهتر.', 'etehadyar-core' ),
			),
			'voice'   => array(
				'action'     => 'eaiw_tts_generate',
				'label'      => __( 'تبدیل متن به صدا', 'etehadyar-core' ),
				'icon'       => '🎙️',
				'capability' => Capabilities::GENERATE_VOICE,
				'operation'  => 'voice_minute',
				'unit'       => __( 'هر دقیقه', 'etehadyar-core' ),
				'hint'       => __( 'متن را بچسبانید؛ خروجی یک فایل صوتی MP3 است.', 'etehadyar-core' ),
			),
		);

		foreach ( $tools as $key => $tool ) {
			$tools[ $key ]['price']       = Pricing::price( $tool['operation'] );
			$tools[ $key ]['price_label'] = Wallet::format( Pricing::price( $tool['operation'] ) );
			$tools[ $key ]['allowed']     = current_user_can( $tool['capability'] );
		}

		/**
		 * Tools shown in the studio.
		 *
		 * @param array $tools Tool definitions.
		 */
		return apply_filters( 'etehadyar_studio_tools', $tools );
	}

	/**
	 * Render the studio.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public static function render( $atts = array() ) {
		unset( $atts );

		if ( ! is_user_logged_in() ) {
			return sprintf(
				'<div class="etehadyar-studio etehadyar-studio--guest"><p>%s</p></div>',
				esc_html__( 'برای استفاده از ابزارها ابتدا وارد شوید.', 'etehadyar-core' )
			);
		}

		$user_id = get_current_user_id();

		if ( ! current_user_can( Capabilities::USE_PLATFORM ) ) {
			return sprintf(
				'<div class="etehadyar-studio etehadyar-studio--guest"><p>%s</p></div>',
				esc_html__( 'حساب شما به ابزارهای پلتفرم دسترسی ندارد.', 'etehadyar-core' )
			);
		}

		if ( ! Tenant::is_active( $user_id ) ) {
			return sprintf(
				'<div class="etehadyar-studio etehadyar-studio--suspended"><p>%s</p></div>',
				esc_html__( 'حساب شما موقتاً غیرفعال است. برای پیگیری با پشتیبانی تماس بگیرید.', 'etehadyar-core' )
			);
		}

		$tools   = self::available_tools();
		$balance = Wallet::balance( $user_id );
		$uid     = 'etehadyar-studio-' . wp_generate_password( 6, false, false );

		ob_start();
		?>
		<div class="etehadyar-studio" id="<?php echo esc_attr( $uid ); ?>" dir="rtl"
			data-ajax="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>"
			data-root="<?php echo esc_url( rest_url( 'etehadyar/v1' ) ); ?>"
			data-nonce="<?php echo esc_attr( wp_create_nonce( 'eaiw_nonce' ) ); ?>"
			data-rest-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>">

			<div class="etehadyar-studio__bar">
				<span class="etehadyar-studio__balance">
					<?php esc_html_e( 'موجودی:', 'etehadyar-core' ); ?>
					<strong data-slot="balance"><?php echo esc_html( Wallet::format( $balance ) ); ?></strong>
				</span>
				<a class="etehadyar-studio__topup"
					href="<?php echo esc_url( self::wallet_url() ); ?>">
					<?php esc_html_e( 'شارژ کیف پول', 'etehadyar-core' ); ?>
				</a>
			</div>

			<div class="etehadyar-studio__tabs" role="tablist">
				<?php $first = true; ?>
				<?php foreach ( $tools as $key => $tool ) : ?>
					<button type="button" role="tab"
						class="etehadyar-studio__tab<?php echo $first ? ' is-active' : ''; ?>"
						data-tab="<?php echo esc_attr( $key ); ?>"
						aria-selected="<?php echo $first ? 'true' : 'false'; ?>"
						<?php disabled( ! $tool['allowed'] ); ?>>
						<span aria-hidden="true"><?php echo esc_html( $tool['icon'] ); ?></span>
						<?php echo esc_html( $tool['label'] ); ?>
					</button>
					<?php $first = false; ?>
				<?php endforeach; ?>
			</div>

			<?php $first = true; ?>
			<?php foreach ( $tools as $key => $tool ) : ?>
				<section class="etehadyar-studio__panel<?php echo $first ? ' is-active' : ''; ?>"
					data-panel="<?php echo esc_attr( $key ); ?>"
					data-action="<?php echo esc_attr( $tool['action'] ); ?>"
					data-operation="<?php echo esc_attr( $tool['operation'] ); ?>"
					data-price="<?php echo esc_attr( (string) $tool['price'] ); ?>"
					<?php echo $first ? '' : 'hidden'; ?>>

					<?php if ( ! $tool['allowed'] ) : ?>
						<p class="etehadyar-studio__locked">
							<?php esc_html_e( 'این ابزار برای حساب شما فعال نیست.', 'etehadyar-core' ); ?>
						</p>
					<?php else : ?>

						<p class="etehadyar-studio__hint"><?php echo esc_html( $tool['hint'] ); ?></p>

						<label class="etehadyar-studio__label" for="<?php echo esc_attr( $uid . '-' . $key ); ?>">
							<?php
							echo 'voice' === $key
								? esc_html__( 'متن برای خوانده شدن', 'etehadyar-core' )
								: esc_html__( 'توضیح شما', 'etehadyar-core' );
							?>
						</label>
						<textarea class="etehadyar-studio__input"
							id="<?php echo esc_attr( $uid . '-' . $key ); ?>"
							data-field="prompt" rows="<?php echo 'voice' === $key ? 6 : 4; ?>"></textarea>

						<?php if ( 'content' === $key ) : ?>
							<div class="etehadyar-studio__row">
								<label class="etehadyar-studio__label" for="<?php echo esc_attr( $uid ); ?>-len">
									<?php esc_html_e( 'طول تقریبی (کلمه)', 'etehadyar-core' ); ?>
								</label>
								<input class="etehadyar-studio__number" type="number"
									id="<?php echo esc_attr( $uid ); ?>-len" data-field="length"
									value="800" min="100" max="5000" step="100" dir="ltr">
							</div>
						<?php endif; ?>

						<div class="etehadyar-studio__footer">
							<span class="etehadyar-studio__cost">
								<?php esc_html_e( 'هزینهٔ تقریبی:', 'etehadyar-core' ); ?>
								<strong data-slot="estimate">—</strong>
							</span>
							<button type="button" class="etehadyar-studio__run" data-action="run">
								<?php esc_html_e( 'اجرا', 'etehadyar-core' ); ?>
							</button>
						</div>

						<p class="etehadyar-studio__status" data-slot="status" role="status" aria-live="polite"></p>
						<div class="etehadyar-studio__result" data-slot="result" hidden></div>

					<?php endif; ?>
				</section>
				<?php $first = false; ?>
			<?php endforeach; ?>
		</div>

		<style>
		#<?php echo esc_attr( $uid ); ?>.etehadyar-studio{max-width:720px;margin:0 auto;font-family:inherit;box-sizing:border-box}
		#<?php echo esc_attr( $uid ); ?> *{box-sizing:border-box}
		#<?php echo esc_attr( $uid ); ?> .etehadyar-studio__bar{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:12px 16px;margin-bottom:14px;background:#eef6ff;border:1px solid #d7e7fb;border-radius:11px;font-size:.9rem;flex-wrap:wrap}
		#<?php echo esc_attr( $uid ); ?> .etehadyar-studio__topup{font-weight:600;color:#1a5fcc;text-decoration:none}
		#<?php echo esc_attr( $uid ); ?> .etehadyar-studio__tabs{display:flex;gap:6px;margin-bottom:0;flex-wrap:wrap}
		#<?php echo esc_attr( $uid ); ?> .etehadyar-studio__tab{padding:10px 16px;border:1px solid #e3e6ea;border-bottom:none;border-radius:11px 11px 0 0;background:#f7f8fa;font:inherit;font-size:.92rem;cursor:pointer;display:flex;align-items:center;gap:6px}
		#<?php echo esc_attr( $uid ); ?> .etehadyar-studio__tab.is-active{background:#fff;font-weight:700}
		#<?php echo esc_attr( $uid ); ?> .etehadyar-studio__tab:disabled{opacity:.45;cursor:not-allowed}
		#<?php echo esc_attr( $uid ); ?> .etehadyar-studio__panel{background:#fff;border:1px solid #e3e6ea;border-radius:0 11px 11px 11px;padding:22px}
		#<?php echo esc_attr( $uid ); ?> .etehadyar-studio__panel:not(.is-active){display:none}
		#<?php echo esc_attr( $uid ); ?> .etehadyar-studio__hint{margin:0 0 14px;color:#667085;font-size:.88rem}
		#<?php echo esc_attr( $uid ); ?> .etehadyar-studio__label{display:block;margin-bottom:6px;font-size:.88rem;color:#444}
		#<?php echo esc_attr( $uid ); ?> .etehadyar-studio__input{width:100%;padding:11px 13px;border:1px solid #cfd4da;border-radius:9px;font:inherit;font-size:.96rem;resize:vertical}
		#<?php echo esc_attr( $uid ); ?> .etehadyar-studio__input:focus,#<?php echo esc_attr( $uid ); ?> .etehadyar-studio__number:focus{outline:2px solid #1f6feb;outline-offset:1px;border-color:#1f6feb}
		#<?php echo esc_attr( $uid ); ?> .etehadyar-studio__row{display:flex;align-items:center;gap:10px;margin-top:12px}
		#<?php echo esc_attr( $uid ); ?> .etehadyar-studio__row .etehadyar-studio__label{margin:0}
		#<?php echo esc_attr( $uid ); ?> .etehadyar-studio__number{width:110px;padding:9px 11px;border:1px solid #cfd4da;border-radius:9px;font:inherit}
		#<?php echo esc_attr( $uid ); ?> .etehadyar-studio__footer{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-top:16px;flex-wrap:wrap}
		#<?php echo esc_attr( $uid ); ?> .etehadyar-studio__cost{font-size:.9rem;color:#444}
		#<?php echo esc_attr( $uid ); ?> .etehadyar-studio__run{padding:11px 26px;border:none;border-radius:10px;background:#1f6feb;color:#fff;font:inherit;font-weight:600;cursor:pointer}
		#<?php echo esc_attr( $uid ); ?> .etehadyar-studio__run:disabled{opacity:.6;cursor:progress}
		#<?php echo esc_attr( $uid ); ?> .etehadyar-studio__status{margin:14px 0 0;font-size:.9rem;min-height:1.2em}
		#<?php echo esc_attr( $uid ); ?> .etehadyar-studio__status.is-error{color:#b42318}
		#<?php echo esc_attr( $uid ); ?> .etehadyar-studio__status.is-ok{color:#15803d}
		#<?php echo esc_attr( $uid ); ?> .etehadyar-studio__result{margin-top:16px;padding:16px;background:#f9fafb;border:1px solid #e3e6ea;border-radius:10px;max-height:460px;overflow:auto}
		#<?php echo esc_attr( $uid ); ?> .etehadyar-studio__result img{max-width:100%;height:auto;border-radius:8px}
		#<?php echo esc_attr( $uid ); ?> .etehadyar-studio__locked{margin:0;color:#b45309}
		</style>

		<script>
		(function () {
			var root = document.getElementById('<?php echo esc_js( $uid ); ?>');
			if (!root) { return; }

			var ajaxUrl = root.dataset.ajax,
				restRoot = root.dataset.root,
				nonce = root.dataset.nonce,
				restNonce = root.dataset.restNonce;

			function t(el, sel) { return el.querySelector('[data-slot="' + sel + '"]'); }

			function money(n) {
				return new Intl.NumberFormat('fa-IR').format(Math.round(n)) + ' <?php echo esc_js( __( 'ریال', 'etehadyar-core' ) ); ?>';
			}

			// Mirrors Estimator::word_count() so the quoted price matches what
			// the server will actually charge.
			function words(s) {
				s = (s || '').replace(/[\u200c\u200d]/g, '');
				var parts = s.split(/[\s\u00a0]+/), n = 0;
				for (var i = 0; i < parts.length; i++) {
					if (/[\p{L}\p{N}]/u.test(parts[i])) { n++; }
				}
				return n;
			}

			function estimate(panel) {
				var op = panel.dataset.operation,
					price = parseInt(panel.dataset.price, 10) || 0,
					prompt = panel.querySelector('[data-field="prompt"]'),
					slot = t(panel, 'estimate'),
					units = 0;

				if (!slot) { return; }

				if (op === 'content_word') {
					var len = panel.querySelector('[data-field="length"]');
					units = (parseInt(len && len.value, 10) || 0) / 100;
				} else if (op === 'voice_minute') {
					units = Math.ceil(((prompt && prompt.value.length) || 0) / <?php echo (int) Estimator::CHARS_PER_SPOKEN_MINUTE; ?>);
				} else {
					units = 1;
				}

				slot.innerHTML = units > 0 ? money(Math.ceil(units) * price) : '—';
			}

			function setStatus(panel, msg, kind) {
				var s = t(panel, 'status');
				if (!s) { return; }
				s.textContent = msg || '';
				s.className = 'etehadyar-studio__status' + (kind ? ' is-' + kind : '');
			}

			function showResult(panel, data) {
				var box = t(panel, 'result');
				if (!box) { return; }

				box.textContent = '';
				box.hidden = false;

				if (data.url && panel.dataset.operation === 'image') {
					var img = document.createElement('img');
					img.src = data.url;
					img.alt = '';
					box.appendChild(img);
				} else if (data.url && panel.dataset.operation === 'voice_minute') {
					var audio = document.createElement('audio');
					audio.controls = true;
					audio.src = data.url;
					box.appendChild(audio);
				} else if (data.html || data.article) {
					// Server-generated markup is inserted as text first, then
					// parsed, so a malformed provider response cannot inject
					// script into the page.
					var art = document.createElement('div');
					art.innerHTML = (data.html || (data.article && data.article.html) || '')
						.replace(/<script[\s\S]*?<\/script>/gi, '');
					box.appendChild(art);
				} else {
					box.textContent = JSON.stringify(data, null, 2);
				}
			}

			function refreshBalance() {
				fetch(restRoot + '/wallet', { headers: { 'X-WP-Nonce': restNonce }, credentials: 'same-origin' })
					.then(function (r) { return r.ok ? r.json() : null; })
					.then(function (d) {
						var slot = t(root, 'balance');
						if (d && slot && typeof d.balance !== 'undefined') {
							slot.innerHTML = money(d.balance);
						}
					})
					.catch(function () {});
			}

			function poll(panel, jobId, tries) {
				if (tries <= 0) {
					setStatus(panel, '<?php echo esc_js( __( 'کار هنوز در صف است. نتیجه در پنل کاربری نمایش داده می‌شود.', 'etehadyar-core' ) ); ?>', '');
					return;
				}

				fetch(restRoot + '/studio/job/' + jobId, {
					headers: { 'X-WP-Nonce': restNonce },
					credentials: 'same-origin'
				})
					.then(function (r) { return r.json(); })
					.then(function (job) {
						if (!job || !job.status) { throw new Error('bad'); }

						if (!job.is_final) {
							setStatus(panel, '<?php echo esc_js( __( 'در حال پردازش…', 'etehadyar-core' ) ); ?>', '');
							setTimeout(function () { poll(panel, jobId, tries - 1); }, 3000);
							return;
						}

						if (job.status === 'completed') {
							setStatus(panel, '<?php echo esc_js( __( 'انجام شد.', 'etehadyar-core' ) ); ?>', 'ok');
							showResult(panel, job.result || {});
						} else {
							setStatus(panel, job.error || '<?php echo esc_js( __( 'کار ناموفق بود؛ مبلغ بازگردانده می‌شود.', 'etehadyar-core' ) ); ?>', 'error');
						}
						refreshBalance();
					})
					.catch(function () {
						setStatus(panel, '<?php echo esc_js( __( 'خطا در دریافت وضعیت کار.', 'etehadyar-core' ) ); ?>', 'error');
					});
			}

			function run(panel) {
				var btn = panel.querySelector('[data-action="run"]'),
					prompt = panel.querySelector('[data-field="prompt"]'),
					value = (prompt && prompt.value || '').trim();

				if (!value) {
					setStatus(panel, '<?php echo esc_js( __( 'لطفاً ابتدا متن را وارد کنید.', 'etehadyar-core' ) ); ?>', 'error');
					return;
				}

				var body = new FormData();
				body.append('action', panel.dataset.action);
				body.append('_ajax_nonce', nonce);

				if (panel.dataset.operation === 'voice_minute') {
					body.append('text', value);
				} else {
					body.append('prompt', value);
				}

				var len = panel.querySelector('[data-field="length"]');
				if (len) { body.append('length', len.value); }

				btn.disabled = true;
				setStatus(panel, '<?php echo esc_js( __( 'در حال اجرا… این کار ممکن است تا یک دقیقه طول بکشد.', 'etehadyar-core' ) ); ?>', '');
				var box = t(panel, 'result');
				if (box) { box.hidden = true; }

				fetch(ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' })
					.then(function (r) { return r.json().catch(function () { return null; }); })
					.then(function (res) {
						btn.disabled = false;

						if (!res) {
							setStatus(panel, '<?php echo esc_js( __( 'پاسخ نامعتبر بود؛ در صورت کسر، مبلغ بازگردانده می‌شود.', 'etehadyar-core' ) ); ?>', 'error');
							refreshBalance();
							return;
						}

						if (!res.success) {
							var d = res.data;
							setStatus(panel, (d && (d.message || d)) || '<?php echo esc_js( __( 'عملیات ناموفق بود.', 'etehadyar-core' ) ); ?>', 'error');
							refreshBalance();
							return;
						}

						var data = res.data || {};

						if (data.queued && data.job_id) {
							setStatus(panel, '<?php echo esc_js( __( 'کار در صف قرار گرفت…', 'etehadyar-core' ) ); ?>', '');
							poll(panel, data.job_id, 40);
							return;
						}

						setStatus(panel, '<?php echo esc_js( __( 'انجام شد.', 'etehadyar-core' ) ); ?>', 'ok');
						showResult(panel, data);
						refreshBalance();
					})
					.catch(function () {
						btn.disabled = false;
						setStatus(panel, '<?php echo esc_js( __( 'ارتباط با سرور برقرار نشد.', 'etehadyar-core' ) ); ?>', 'error');
					});
			}

			root.addEventListener('click', function (e) {
				var tab = e.target.closest('.etehadyar-studio__tab');
				if (tab) {
					var key = tab.dataset.tab;
					root.querySelectorAll('.etehadyar-studio__tab').forEach(function (b) {
						var on = b === tab;
						b.classList.toggle('is-active', on);
						b.setAttribute('aria-selected', on ? 'true' : 'false');
					});
					root.querySelectorAll('.etehadyar-studio__panel').forEach(function (p) {
						var on = p.dataset.panel === key;
						p.classList.toggle('is-active', on);
						p.hidden = !on;
					});
					return;
				}

				var runBtn = e.target.closest('[data-action="run"]');
				if (runBtn) {
					run(runBtn.closest('.etehadyar-studio__panel'));
				}
			});

			root.addEventListener('input', function (e) {
				var panel = e.target.closest('.etehadyar-studio__panel');
				if (panel) { estimate(panel); }
			});

			root.querySelectorAll('.etehadyar-studio__panel').forEach(estimate);
		}());
		</script>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Wallet page URL, falling back to the home page.
	 *
	 * @return string
	 */
	protected static function wallet_url() {
		$page_id = (int) get_option( 'etehadyar_page_wallet' );

		if ( $page_id && 'publish' === get_post_status( $page_id ) ) {
			return (string) get_permalink( $page_id );
		}

		return home_url( '/' );
	}
}
