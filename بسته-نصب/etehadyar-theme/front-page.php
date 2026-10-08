<?php
/**
 * Front page: marketing for guests, a shortcut to the panel for members.
 *
 * @package Etehadyar_Theme
 */

defined( 'ABSPATH' ) || exit;

get_header();

// A logged-in customer does not need the sales pitch.
if ( is_user_logged_in() ) :
	?>
	<div class="ey-hero" style="padding:38px 24px">
		<h1><?php esc_html_e( 'خوش آمدید', 'etehadyar-theme' ); ?></h1>
		<p><?php esc_html_e( 'از پنل کاربری به همهٔ ابزارهای هوش مصنوعی دسترسی دارید.', 'etehadyar-theme' ); ?></p>
		<div class="ey-hero__cta">
			<a class="ey-btn" href="<?php echo esc_url( Etehadyar_Theme_Pages::url( 'studio' ) ); ?>">
				<?php esc_html_e( 'شروع ساخت', 'etehadyar-theme' ); ?>
			</a>
			<a class="ey-btn ey-btn--ghost" href="<?php echo esc_url( Etehadyar_Theme_Pages::url( 'dashboard' ) ); ?>">
				<?php esc_html_e( 'پنل کاربری', 'etehadyar-theme' ); ?>
			</a>
			<a class="ey-btn ey-btn--ghost" href="<?php echo esc_url( Etehadyar_Theme_Pages::url( 'wallet' ) ); ?>">
				<?php esc_html_e( 'شارژ کیف پول', 'etehadyar-theme' ); ?>
			</a>
		</div>
	</div>
	<?php
else :
	?>
	<div class="ey-hero">
		<h1><?php bloginfo( 'name' ); ?></h1>
		<p>
			<?php
			$tagline = get_bloginfo( 'description' );
			echo esc_html(
				$tagline
					? $tagline
					: __( 'تولید محتوا، تصویر و صدا با هوش مصنوعی — با شمارهٔ موبایل وارد شوید، کیف پولتان را شارژ کنید و فقط بابت آنچه مصرف می‌کنید بپردازید.', 'etehadyar-theme' )
			);
			?>
		</p>
		<div class="ey-hero__cta">
			<a class="ey-btn" href="<?php echo esc_url( Etehadyar_Theme_Pages::url( 'login' ) ); ?>">
				<?php esc_html_e( 'شروع کنید', 'etehadyar-theme' ); ?>
			</a>
		</div>
	</div>

	<div class="ey-features">
		<?php
		$features = array(
			array( '✍️', __( 'تولید محتوا', 'etehadyar-theme' ), __( 'مقالهٔ کامل فارسی با لحن دلخواه شما، آمادهٔ انتشار.', 'etehadyar-theme' ) ),
			array( '🖼️', __( 'تصویرسازی', 'etehadyar-theme' ), __( 'تصویر اختصاصی برای مقاله، شبکهٔ اجتماعی یا فروشگاه.', 'etehadyar-theme' ) ),
			array( '🎙️', __( 'تبدیل متن به صدا', 'etehadyar-theme' ), __( 'روایت طبیعی فارسی برای پادکست و ویدیو.', 'etehadyar-theme' ) ),
			array( '💳', __( 'پرداخت منصفانه', 'etehadyar-theme' ), __( 'هزینه بر اساس مصرف واقعی؛ کار ناموفق، بازگشت کامل وجه.', 'etehadyar-theme' ) ),
		);

		foreach ( $features as $f ) :
			?>
			<div class="ey-card ey-feature">
				<div class="ey-feature__icon" aria-hidden="true"><?php echo esc_html( $f[0] ); ?></div>
				<h3><?php echo esc_html( $f[1] ); ?></h3>
				<p><?php echo esc_html( $f[2] ); ?></p>
			</div>
		<?php endforeach; ?>
	</div>

	<div class="ey-card" style="text-align:center">
		<h2 style="margin-top:0"><?php esc_html_e( 'ثبت‌نام فقط با شمارهٔ موبایل', 'etehadyar-theme' ); ?></h2>
		<p style="color:var(--ey-muted);max-width:560px;margin:0 auto 18px">
			<?php esc_html_e( 'بدون رمز عبور و بدون ایمیل. شماره‌تان را وارد کنید، کد پیامکی را بزنید و همان لحظه شروع کنید.', 'etehadyar-theme' ); ?>
		</p>
		<a class="ey-btn" href="<?php echo esc_url( Etehadyar_Theme_Pages::url( 'login' ) ); ?>">
			<?php esc_html_e( 'ورود / ثبت‌نام', 'etehadyar-theme' ); ?>
		</a>
	</div>
	<?php
endif;

get_footer();
