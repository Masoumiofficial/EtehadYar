<?php
/**
 * Template Name: پنل کاربری اتحادیار
 *
 * @package Etehadyar_Theme
 */

defined( 'ABSPATH' ) || exit;

get_header();

if ( ! is_user_logged_in() ) {
	// Not an error — just send them to log in and come back.
	?>
	<div class="ey-notice ey-notice--info">
		<?php esc_html_e( 'برای دیدن پنل کاربری ابتدا وارد شوید.', 'etehadyar-theme' ); ?>
	</div>
	<div class="ey-shortcode-slot">
		<?php echo etehadyar_theme_panel( 'etehadyar_login' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- shortcode output. ?>
	</div>
	<?php
} else {
	?>
	<h1 class="ey-page-title"><?php the_title(); ?></h1>

	<?php
	while ( have_posts() ) {
		the_post();
		$content = trim( get_the_content() );

		// If the editor content is just the shortcode, the panel below already
		// covers it; only render bespoke copy.
		if ( '' !== $content && false === strpos( $content, '[etehadyar_dashboard]' ) ) {
			echo '<div class="ey-entry" style="margin-bottom:22px">';
			the_content();
			echo '</div>';
		}
	}
	?>

	<div class="ey-shortcode-slot">
		<?php echo etehadyar_theme_panel( 'etehadyar_dashboard' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- shortcode output. ?>
	</div>
	<?php
}

get_footer();
