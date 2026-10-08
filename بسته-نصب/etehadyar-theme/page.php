<?php
/**
 * Pages. Platform pages get the panel treatment automatically, so a site
 * owner who pastes a shortcode into a normal page still gets a sane layout.
 *
 * @package Etehadyar_Theme
 */

defined( 'ABSPATH' ) || exit;

get_header();

while ( have_posts() ) :
	the_post();
	?>
	<article id="post-<?php the_ID(); ?>" <?php post_class( 'ey-entry' ); ?>>
		<h1 class="ey-page-title"><?php the_title(); ?></h1>
		<?php the_content(); ?>
	</article>
	<?php
endwhile;

get_footer();
