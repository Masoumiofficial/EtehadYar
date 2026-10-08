<?php
/**
 * Member dashboard.
 *
 * Renders as `[etehadyar_dashboard]`: balance, recent jobs, usage summary and
 * the price list, so a customer can see what they have spent and what things
 * cost without contacting support.
 *
 * @package Etehadyar\Frontend
 */

namespace Etehadyar\Frontend;

use Etehadyar\Billing\Pricing;
use Etehadyar\Billing\Wallet;
use Etehadyar\Core\Tenant;

defined( 'ABSPATH' ) || exit;

/**
 * Dashboard shortcode.
 */
class Dashboard_Shortcode {

	/**
	 * Register hooks.
	 */
	public static function boot() {
		add_shortcode( 'etehadyar_dashboard', array( __CLASS__, 'render' ) );
	}

	/**
	 * Render the dashboard.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public static function render( $atts = array() ) {
		if ( ! is_user_logged_in() ) {
			return sprintf(
				'<div class="etehadyar-dash etehadyar-dash--guest"><p>%s</p></div>',
				esc_html__( 'برای مشاهدهٔ پنل کاربری وارد شوید.', 'etehadyar-core' )
			);
		}

		$user_id = get_current_user_id();
		$tenant  = Tenant::get( $user_id );

		if ( $tenant && 'suspended' === ( $tenant['status'] ?? '' ) ) {
			return sprintf(
				'<div class="etehadyar-dash etehadyar-dash--suspended"><p>%s</p></div>',
				esc_html__( 'حساب شما موقتاً غیرفعال است. لطفاً با پشتیبانی تماس بگیرید.', 'etehadyar-core' )
			);
		}

		$wallet  = Wallet::get( $user_id );
		$plugin  = etehadyar_core();
		$jobs    = array();
		$usage   = array();

		// The jobs and usage repositories only exist when the legacy tables are
		// present; a fresh install should show an empty dashboard, not a fatal.
		if ( $plugin ) {
			$jobs_repo = $plugin->get( 'jobs' );

			if ( $jobs_repo ) {
				$list = $jobs_repo->list_for( $user_id, array( 'per_page' => 8 ) );

				if ( ! is_wp_error( $list ) && ! empty( $list['items'] ) ) {
					$jobs = $list['items'];
				}
			}

			$usage_repo = $plugin->get( 'usage' );

			if ( $usage_repo ) {
				$summary = $usage_repo->summary( $user_id, 30 );

				if ( ! is_wp_error( $summary ) && is_array( $summary ) ) {
					$usage = $summary;
				}
			}
		}

		$uid = 'etehadyar-dash-' . wp_generate_password( 6, false, false );

		ob_start();
		?>
		<div class="etehadyar-dash" id="<?php echo esc_attr( $uid ); ?>" dir="rtl">

			<div class="etehadyar-dash__cards">
				<div class="etehadyar-dash__card etehadyar-dash__card--primary">
					<span class="etehadyar-dash__card-label"><?php esc_html_e( 'موجودی کیف پول', 'etehadyar-core' ); ?></span>
					<strong class="etehadyar-dash__card-value"><?php echo esc_html( Wallet::format( $wallet['balance'] ) ); ?></strong>
					<?php if ( $wallet['balance'] <= 0 ) : ?>
						<span class="etehadyar-dash__card-warn"><?php esc_html_e( 'برای استفاده از امکانات، حساب خود را شارژ کنید.', 'etehadyar-core' ); ?></span>
					<?php endif; ?>
				</div>

				<div class="etehadyar-dash__card">
					<span class="etehadyar-dash__card-label"><?php esc_html_e( 'مجموع شارژ', 'etehadyar-core' ); ?></span>
					<strong class="etehadyar-dash__card-value"><?php echo esc_html( number_format_i18n( $wallet['lifetime_topup'] ) ); ?></strong>
				</div>

				<div class="etehadyar-dash__card">
					<span class="etehadyar-dash__card-label"><?php esc_html_e( 'مجموع مصرف', 'etehadyar-core' ); ?></span>
					<strong class="etehadyar-dash__card-value"><?php echo esc_html( number_format_i18n( $wallet['lifetime_spend'] ) ); ?></strong>
				</div>
			</div>

			<div class="etehadyar-dash__section">
				<h3 class="etehadyar-dash__heading"><?php esc_html_e( 'کارهای اخیر', 'etehadyar-core' ); ?></h3>

				<?php if ( empty( $jobs ) ) : ?>
					<p class="etehadyar-dash__empty"><?php esc_html_e( 'هنوز کاری ثبت نشده است.', 'etehadyar-core' ); ?></p>
				<?php else : ?>
					<table class="etehadyar-dash__table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'نوع', 'etehadyar-core' ); ?></th>
								<th><?php esc_html_e( 'وضعیت', 'etehadyar-core' ); ?></th>
								<th><?php esc_html_e( 'تاریخ', 'etehadyar-core' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $jobs as $job ) : ?>
								<tr>
									<td><?php echo esc_html( $job['type'] ?? '—' ); ?></td>
									<td><?php echo esc_html( $job['status_label'] ?? ( $job['status'] ?? '—' ) ); ?></td>
									<td><?php echo esc_html( isset( $job['created_at'] ) ? mysql2date( 'Y/m/d H:i', $job['created_at'] ) : '—' ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>

			<div class="etehadyar-dash__section">
				<h3 class="etehadyar-dash__heading"><?php esc_html_e( 'تعرفهٔ خدمات', 'etehadyar-core' ); ?></h3>
				<table class="etehadyar-dash__table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'خدمت', 'etehadyar-core' ); ?></th>
							<th><?php esc_html_e( 'واحد', 'etehadyar-core' ); ?></th>
							<th><?php esc_html_e( 'قیمت', 'etehadyar-core' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( Pricing::all() as $rate ) : ?>
							<tr>
								<td><?php echo esc_html( $rate['label'] ); ?></td>
								<td><?php echo esc_html( $rate['unit'] ); ?></td>
								<td><?php echo esc_html( number_format_i18n( $rate['price'] ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>

		<style>
		#<?php echo esc_attr( $uid ); ?>.etehadyar-dash{max-width:860px;margin:0 auto;font-family:inherit}
		#<?php echo esc_attr( $uid ); ?> *{box-sizing:border-box}
		#<?php echo esc_attr( $uid ); ?> .etehadyar-dash__cards{display:flex;flex-wrap:wrap;gap:14px;margin-bottom:22px}
		#<?php echo esc_attr( $uid ); ?> .etehadyar-dash__card{flex:1 1 180px;padding:18px;border:1px solid #e3e6ea;border-radius:13px;background:#fff;display:flex;flex-direction:column;gap:6px}
		#<?php echo esc_attr( $uid ); ?> .etehadyar-dash__card--primary{background:linear-gradient(135deg,#2271b1,#154c7d);color:#fff;border:0}
		#<?php echo esc_attr( $uid ); ?> .etehadyar-dash__card-label{font-size:.82rem;opacity:.85}
		#<?php echo esc_attr( $uid ); ?> .etehadyar-dash__card-value{font-size:1.35rem;font-weight:700}
		#<?php echo esc_attr( $uid ); ?> .etehadyar-dash__card-warn{font-size:.76rem;opacity:.95;line-height:1.5}
		#<?php echo esc_attr( $uid ); ?> .etehadyar-dash__section{padding:20px;border:1px solid #e3e6ea;border-radius:13px;background:#fff;margin-bottom:18px}
		#<?php echo esc_attr( $uid ); ?> .etehadyar-dash__heading{font-size:1rem;margin:0 0 12px}
		#<?php echo esc_attr( $uid ); ?> .etehadyar-dash__table{width:100%;border-collapse:collapse;font-size:.87rem}
		#<?php echo esc_attr( $uid ); ?> .etehadyar-dash__table th,#<?php echo esc_attr( $uid ); ?> .etehadyar-dash__table td{padding:9px 6px;border-bottom:1px solid #eef0f2;text-align:right}
		#<?php echo esc_attr( $uid ); ?> .etehadyar-dash__table th{font-weight:600;color:#555;font-size:.8rem}
		#<?php echo esc_attr( $uid ); ?> .etehadyar-dash__empty{color:#777;font-size:.88rem;margin:0}
		</style>
		<?php

		return (string) ob_get_clean();
	}
}
