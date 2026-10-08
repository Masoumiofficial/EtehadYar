<?php
/**
 * Template Name: ابزارهای اتحادیار
 *
 * @package Etehadyar_Theme
 */

defined( 'ABSPATH' ) || exit;

get_header();

if ( ! is_user_logged_in() ) {
	?>
	<div class="ey-notice ey-notice--info">
		<?php esc_html_e( 'برای استفاده از ابزارها ابتدا وارد شوید.', 'etehadyar-theme' ); ?>
	</div>
	<div class="ey-shortcode-slot" style="max-width:420px">
		<?php echo etehadyar_theme_panel( 'etehadyar_login' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- shortcode output. ?>
	</div>
	<?php
} else {
	?>
	<h1 class="ey-page-title"><?php the_title(); ?></h1>
	<div class="ey-shortcode-slot">
		<?php echo etehadyar_theme_panel( 'etehadyar_studio' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- shortcode output. ?>
	</div>
	<?php
}

get_footer();
