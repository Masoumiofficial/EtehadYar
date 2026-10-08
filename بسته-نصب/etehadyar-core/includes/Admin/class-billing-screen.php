<?php
/**
 * Billing administration: gateway settings, price list, manual adjustments.
 *
 * @package Etehadyar\Admin
 */

namespace Etehadyar\Admin;

use Etehadyar\Billing\Orders;
use Etehadyar\Billing\Pricing;
use Etehadyar\Billing\Wallet;
use Etehadyar\Billing\Zarinpal_Gateway;
use Etehadyar\Core\Audit;
use Etehadyar\Core\Capabilities;
use Etehadyar\Core\Schema;
use Etehadyar\Core\Secrets;

defined( 'ABSPATH' ) || exit;

/**
 * Billing settings screen.
 */
class Billing_Screen {

	const PAGE_SLUG = 'etehadyar-billing';

	/**
	 * Register the submenu.
	 */
	public static function register() {
		add_submenu_page(
			'etehadyar-platform',
			__( 'مالی و کیف پول', 'etehadyar-core' ),
			__( 'مالی و کیف پول', 'etehadyar-core' ),
			Capabilities::MANAGE_PLATFORM,
			self::PAGE_SLUG,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Handle submissions.
	 */
	public static function handle_post() {
		if ( empty( $_POST['etehadyar_billing_submit'] ) ) {
			return;
		}

		if ( ! current_user_can( Capabilities::MANAGE_PLATFORM ) ) {
			wp_die( esc_html__( 'دسترسی غیرمجاز.', 'etehadyar-core' ) );
		}

		check_admin_referer( 'etehadyar_billing' );

		$action = sanitize_key( wp_unslash( $_POST['etehadyar_billing_submit'] ) );

		if ( 'adjust' === $action ) {
			self::handle_adjustment();
			return;
		}

		self::save();
	}

	/**
	 * Persist gateway and pricing settings.
	 */
	protected static function save() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in handle_post().
		$merchant = trim( (string) wp_unslash( $_POST['zp_merchant'] ?? '' ) );

		if ( '' !== $merchant ) {
			Secrets::set( Zarinpal_Gateway::SECRET_MERCHANT, $merchant );
		}

		update_option( Zarinpal_Gateway::OPTION_SANDBOX, empty( $_POST['zp_sandbox'] ) ? 0 : 1, false );
		update_option( Orders::OPTION_MIN, absint( wp_unslash( $_POST['topup_min'] ?? 100000 ) ), false );
		update_option( Orders::OPTION_MAX, absint( wp_unslash( $_POST['topup_max'] ?? 500000000 ) ), false );

		$rates = isset( $_POST['rates'] ) && is_array( $_POST['rates'] )
			? array_map( 'absint', wp_unslash( $_POST['rates'] ) )
			: array();

		Pricing::save( $rates );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		Audit::log(
			'settings.updated',
			array(
				'severity' => 'notice',
				'context'  => array( 'section' => 'billing' ),
			)
		);

		self::redirect( 'saved' );
	}

	/**
	 * Apply a manual balance adjustment.
	 */
	protected static function handle_adjustment() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in handle_post().
		$user_id = absint( wp_unslash( $_POST['adjust_user'] ?? 0 ) );
		$amount  = (int) wp_unslash( $_POST['adjust_amount'] ?? 0 );
		$note    = sanitize_text_field( wp_unslash( $_POST['adjust_note'] ?? '' ) );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( ! $user_id || ! get_userdata( $user_id ) ) {
			self::redirect( 'adjust_bad_user' );
		}

		if ( 0 === $amount ) {
			self::redirect( 'adjust_bad_amount' );
		}

		$args = array(
			'type'        => Wallet::TYPE_ADJUST,
			'description' => $note ?: __( 'اصلاح دستی توسط مدیر', 'etehadyar-core' ),
			'reference'   => 'manual',
			'actor_id'    => get_current_user_id(),
		);

		// A negative adjustment is allowed to push the balance below zero: the
		// admin is correcting a known discrepancy, and silently refusing would
		// leave the books wrong.
		if ( $amount > 0 ) {
			$result = Wallet::credit( $user_id, $amount, $args );
		} else {
			$args['allow_negative'] = true;
			$result                 = Wallet::charge( $user_id, abs( $amount ), $args );
		}

		if ( is_wp_error( $result ) ) {
			self::redirect( 'adjust_failed', $result->get_error_message() );
		}

		self::redirect( 'adjusted' );
	}

	/**
	 * Redirect with a notice.
	 *
	 * @param string $notice  Notice key.
	 * @param string $message Optional detail.
	 */
	protected static function redirect( $notice, $message = '' ) {
		$args = array(
			'page'             => self::PAGE_SLUG,
			'etehadyar_notice' => $notice,
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

		$gateway = new Zarinpal_Gateway();
		$stats   = self::stats();
		?>
		<div class="wrap" dir="rtl">
			<h1><?php esc_html_e( 'مالی و کیف پول', 'etehadyar-core' ); ?></h1>

			<?php self::render_notice(); ?>

			<div class="card" style="max-width:820px;padding:12px 16px">
				<h2 style="margin-top:0"><?php esc_html_e( 'خلاصهٔ مالی', 'etehadyar-core' ); ?></h2>
				<p>
					<strong><?php esc_html_e( 'مجموع موجودی کاربران:', 'etehadyar-core' ); ?></strong>
					<?php echo esc_html( Wallet::format( $stats['total_balance'] ) ); ?>
					&nbsp;|&nbsp;
					<strong><?php esc_html_e( 'پرداخت موفق (۳۰ روز):', 'etehadyar-core' ); ?></strong>
					<?php echo esc_html( Wallet::format( $stats['paid_30'] ) ); ?>
					&nbsp;|&nbsp;
					<strong><?php esc_html_e( 'درگاه:', 'etehadyar-core' ); ?></strong>
					<?php if ( $gateway->is_configured() ) : ?>
						<span style="color:#00794b">✔ <?php esc_html_e( 'پیکربندی‌شده', 'etehadyar-core' ); ?></span>
					<?php else : ?>
						<span style="color:#b32d2e">✖ <?php esc_html_e( 'ناقص', 'etehadyar-core' ); ?></span>
					<?php endif; ?>
					<?php if ( $gateway->is_sandbox() ) : ?>
						<span style="color:#b26200">(<?php esc_html_e( 'حالت آزمایشی', 'etehadyar-core' ); ?>)</span>
					<?php endif; ?>
				</p>
			</div>

			<form method="post" action="">
				<?php wp_nonce_field( 'etehadyar_billing' ); ?>

				<h2 class="title"><?php esc_html_e( 'درگاه پرداخت زرین‌پال', 'etehadyar-core' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="zp_merchant"><?php esc_html_e( 'مرچنت کد', 'etehadyar-core' ); ?></label></th>
						<td>
							<input name="zp_merchant" id="zp_merchant" type="text" class="regular-text" dir="ltr"
								autocomplete="off"
								placeholder="<?php echo Secrets::has( Zarinpal_Gateway::SECRET_MERCHANT ) ? esc_attr__( '•••• (ذخیره شده)', 'etehadyar-core' ) : 'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx'; ?>">
							<p class="description"><?php esc_html_e( 'کد ۳۶ کاراکتری پذیرنده. رمزنگاری‌شده ذخیره می‌شود. برای حفظ مقدار فعلی خالی بگذارید.', 'etehadyar-core' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'حالت آزمایشی', 'etehadyar-core' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="zp_sandbox" value="1" <?php checked( $gateway->is_sandbox() ); ?>>
								<?php esc_html_e( 'استفاده از سرویس sandbox زرین‌پال (پول واقعی جابه‌جا نمی‌شود)', 'etehadyar-core' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="topup_min"><?php esc_html_e( 'حداقل شارژ (ریال)', 'etehadyar-core' ); ?></label></th>
						<td><input name="topup_min" id="topup_min" type="number" class="regular-text" dir="ltr" value="<?php echo esc_attr( Orders::min_amount() ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="topup_max"><?php esc_html_e( 'حداکثر شارژ (ریال)', 'etehadyar-core' ); ?></label></th>
						<td>
							<input name="topup_max" id="topup_max" type="number" class="regular-text" dir="ltr" value="<?php echo esc_attr( Orders::max_amount() ); ?>">
							<p class="description"><?php esc_html_e( 'سقف، هم جلوی اشتباه تایپی کاربر را می‌گیرد و هم سوءاستفادهٔ تست کارت را محدود می‌کند.', 'etehadyar-core' ); ?></p>
						</td>
					</tr>
				</table>

				<h2 class="title"><?php esc_html_e( 'نرخ‌نامه', 'etehadyar-core' ); ?></h2>
				<table class="form-table" role="presentation">
					<?php foreach ( Pricing::all() as $key => $rate ) : ?>
						<tr>
							<th scope="row">
								<label for="rate-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $rate['label'] ); ?></label>
							</th>
							<td>
								<input name="rates[<?php echo esc_attr( $key ); ?>]" id="rate-<?php echo esc_attr( $key ); ?>"
									type="number" class="small-text" dir="ltr" value="<?php echo esc_attr( $rate['price'] ); ?>">
								<span class="description"><?php echo esc_html( __( 'ریال', 'etehadyar-core' ) . ' — ' . $rate['unit'] ); ?></span>
							</td>
						</tr>
					<?php endforeach; ?>
				</table>

				<p class="submit">
					<button type="submit" name="etehadyar_billing_submit" value="save" class="button button-primary">
						<?php esc_html_e( 'ذخیره تنظیمات', 'etehadyar-core' ); ?>
					</button>
				</p>
			</form>

			<hr>

			<h2 class="title"><?php esc_html_e( 'اصلاح دستی موجودی', 'etehadyar-core' ); ?></h2>
			<form method="post" action="">
				<?php wp_nonce_field( 'etehadyar_billing' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="adjust_user"><?php esc_html_e( 'شناسهٔ کاربر', 'etehadyar-core' ); ?></label></th>
						<td><input name="adjust_user" id="adjust_user" type="number" class="small-text" dir="ltr"></td>
					</tr>
					<tr>
						<th scope="row"><label for="adjust_amount"><?php esc_html_e( 'مبلغ (ریال)', 'etehadyar-core' ); ?></label></th>
						<td>
							<input name="adjust_amount" id="adjust_amount" type="number" class="regular-text" dir="ltr">
							<p class="description"><?php esc_html_e( 'مقدار مثبت افزایش و مقدار منفی کاهش می‌دهد. هر اصلاح در دفتر کل و لاگ حسابرسی ثبت می‌شود.', 'etehadyar-core' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="adjust_note"><?php esc_html_e( 'توضیح', 'etehadyar-core' ); ?></label></th>
						<td><input name="adjust_note" id="adjust_note" type="text" class="regular-text"></td>
					</tr>
				</table>
				<p class="submit">
					<button type="submit" name="etehadyar_billing_submit" value="adjust" class="button">
						<?php esc_html_e( 'اعمال اصلاح', 'etehadyar-core' ); ?>
					</button>
				</p>
			</form>

			<hr>
			<h2 class="title"><?php esc_html_e( 'کدهای کوتاه', 'etehadyar-core' ); ?></h2>
			<p><code dir="ltr">[etehadyar_wallet]</code> — <?php esc_html_e( 'پنل کیف پول و شارژ', 'etehadyar-core' ); ?></p>
			<p><code dir="ltr">[etehadyar_login]</code> — <?php esc_html_e( 'فرم ورود با موبایل', 'etehadyar-core' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Aggregate figures for the summary card.
	 *
	 * @return array
	 */
	protected static function stats() {
		global $wpdb;

		$out = array(
			'total_balance' => 0,
			'paid_30'       => 0,
		);

		$wallet = Schema::table( 'wallet' );
		$orders = Schema::table( 'orders' );

		if ( Schema::table_exists( $wallet ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery
			$out['total_balance'] = (int) $wpdb->get_var( "SELECT COALESCE(SUM(balance),0) FROM `{$wallet}`" );
		}

		if ( Schema::table_exists( $orders ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery
			$out['paid_30'] = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COALESCE(SUM(amount),0) FROM `{$orders}` WHERE status = %s AND paid_at > %s",
					Orders::STATUS_PAID,
					gmdate( 'Y-m-d H:i:s', time() - ( 30 * DAY_IN_SECONDS ) )
				)
			);
		}

		return $out;
	}

	/**
	 * Print the redirect notice.
	 */
	protected static function render_notice() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only display.
		$notice = isset( $_GET['etehadyar_notice'] ) ? sanitize_key( wp_unslash( $_GET['etehadyar_notice'] ) ) : '';
		$detail = isset( $_GET['etehadyar_detail'] ) ? sanitize_text_field( rawurldecode( wp_unslash( $_GET['etehadyar_detail'] ) ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$map = array(
			'saved'             => array( 'success', __( 'تنظیمات ذخیره شد.', 'etehadyar-core' ) ),
			'adjusted'          => array( 'success', __( 'موجودی اصلاح شد.', 'etehadyar-core' ) ),
			'adjust_bad_user'   => array( 'error', __( 'کاربر یافت نشد.', 'etehadyar-core' ) ),
			'adjust_bad_amount' => array( 'error', __( 'مبلغ نمی‌تواند صفر باشد.', 'etehadyar-core' ) ),
			'adjust_failed'     => array( 'error', __( 'اصلاح موجودی انجام نشد.', 'etehadyar-core' ) ),
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
