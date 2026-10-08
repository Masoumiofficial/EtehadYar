<?php
/**
 * Fallback template.
 *
 * @package Etehadyar_Theme
 */

defined( 'ABSPATH' ) || exit;

get_header();
?>

<?php if ( have_posts() ) : ?>

	<?php if ( ! is_front_page() && ( is_home() || is_archive() || is_search() ) ) : ?>
		<h1 class="ey-page-title">
			<?php
			if ( is_search() ) {
				printf(
					/* translators: %s: search term */
					esc_html__( 'نتایج جست‌وجو برای: %s', 'etehadyar-theme' ),
					'<span class="ey-ltr">' . esc_html( get_search_query() ) . '</span>'
				);
			} elseif ( is_archive() ) {
				echo esc_html( wp_strip_all_tags( get_the_archive_title() ) );
			} else {
				echo esc_html__( 'آخرین نوشته‌ها', 'etehadyar-theme' );
			}
			?>
		</h1>
	<?php endif; ?>

	<?php while ( have_posts() ) : the_post(); ?>
		<article id="post-<?php the_ID(); ?>" <?php post_class( 'ey-entry' ); ?> style="margin-bottom:22px">
			<h2 style="margin-top:0">
				<a href="<?php the_permalink(); ?>"><?php the_title(); ?></a>
			</h2>
			<?php the_excerpt(); ?>
		</article>
	<?php endwhile; ?>

	<?php
	the_posts_pagination(
		array(
			'prev_text' => esc_html__( 'قبلی', 'etehadyar-theme' ),
			'next_text' => esc_html__( 'بعدی', 'etehadyar-theme' ),
		)
	);
	?>

<?php else : ?>

	<div class="ey-entry">
		<p><?php esc_html_e( 'چیزی یافت نشد.', 'etehadyar-theme' ); ?></p>
		<?php get_search_form(); ?>
	</div>

<?php endif; ?>

<?php
get_footer();
