<?php
/**
 * Site footer.
 *
 * @package Etehadyar_Theme
 */

defined( 'ABSPATH' ) || exit;
?>
	</div><!-- .ey-wrap -->
</main>

<footer class="ey-footer">
	<div class="ey-wrap ey-footer__inner">
		<p style="margin:0">
			<?php
			printf(
				/* translators: 1: year, 2: site name */
				esc_html__( '© %1$s %2$s — تمام حقوق محفوظ است.', 'etehadyar-theme' ),
				esc_html( wp_date( 'Y' ) ),
				esc_html( get_bloginfo( 'name' ) )
			);
			?>
		</p>

		<?php
		if ( has_nav_menu( 'footer' ) ) {
			wp_nav_menu(
				array(
					'theme_location' => 'footer',
					'container'      => false,
					'depth'          => 1,
					'fallback_cb'    => false,
				)
			);
		}
		?>
	</div>
</footer>

<?php wp_footer(); ?>
</body>
</html>
