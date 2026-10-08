<?php
/**
 * Template Name: ورود اتحادیار
 *
 * @package Etehadyar_Theme
 */

defined( 'ABSPATH' ) || exit;

get_header();

if ( is_user_logged_in() ) {
	?>
	<div class="ey-card" style="text-align:center;max-width:420px;margin:0 auto">
		<p><?php esc_html_e( 'شما وارد شده‌اید.', 'etehadyar-theme' ); ?></p>
		<a class="ey-btn" href="<?php echo esc_url( Etehadyar_Theme_Pages::url( 'dashboard' ) ); ?>">
			<?php esc_html_e( 'رفتن به پنل کاربری', 'etehadyar-theme' ); ?>
		</a>
	</div>
	<?php
} else {
	?>
	<div class="ey-shortcode-slot" style="max-width:420px">
		<?php echo etehadyar_theme_panel( 'etehadyar_login' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- shortcode output. ?>
	</div>
	<?php
}

get_footer();
