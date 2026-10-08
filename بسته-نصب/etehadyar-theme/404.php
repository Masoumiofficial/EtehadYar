<?php
/**
 * 404.
 *
 * @package Etehadyar_Theme
 */

defined( 'ABSPATH' ) || exit;

get_header();
?>

<div class="ey-entry" style="text-align:center">
	<h1 class="ey-page-title"><?php esc_html_e( 'صفحه پیدا نشد', 'etehadyar-theme' ); ?></h1>
	<p><?php esc_html_e( 'نشانی‌ای که دنبال آن بودید وجود ندارد یا جابه‌جا شده است.', 'etehadyar-theme' ); ?></p>
	<p style="margin-top:22px">
		<a class="ey-btn" href="<?php echo esc_url( home_url( '/' ) ); ?>">
			<?php esc_html_e( 'بازگشت به خانه', 'etehadyar-theme' ); ?>
		</a>
	</p>
</div>

<?php
get_footer();
