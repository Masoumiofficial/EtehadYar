<?php
/**
 * Comments.
 *
 * @package Etehadyar_Theme
 */

defined( 'ABSPATH' ) || exit;

if ( post_password_required() ) {
	return;
}
?>

<section class="ey-entry" id="comments" style="margin-top:22px">
	<?php if ( have_comments() ) : ?>
		<h2 style="margin-top:0"><?php esc_html_e( 'دیدگاه‌ها', 'etehadyar-theme' ); ?></h2>
		<ol style="list-style:none;padding:0">
			<?php
			wp_list_comments(
				array(
					'style'      => 'ol',
					'short_ping' => true,
					'avatar_size' => 40,
				)
			);
			?>
		</ol>
		<?php
		the_comments_pagination(
			array(
				'prev_text' => esc_html__( 'قبلی', 'etehadyar-theme' ),
				'next_text' => esc_html__( 'بعدی', 'etehadyar-theme' ),
			)
		);
		?>
	<?php endif; ?>

	<?php
	comment_form(
		array(
			'title_reply' => esc_html__( 'دیدگاه شما', 'etehadyar-theme' ),
			'class_submit' => 'ey-btn',
		)
	);
	?>
</section>
