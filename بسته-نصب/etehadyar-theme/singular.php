<?php
/**
 * Single posts and pages.
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
		<?php
		the_content();

		wp_link_pages(
			array(
				'before' => '<nav class="ey-pages">',
				'after'  => '</nav>',
			)
		);
		?>
	</article>
	<?php

	if ( comments_open() || get_comments_number() ) {
		comments_template();
	}

endwhile;

get_footer();
