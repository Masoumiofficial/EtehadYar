<?php
/**
 * Site header.
 *
 * @package Etehadyar_Theme
 */

defined( 'ABSPATH' ) || exit;
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?> dir="rtl">
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<?php wp_head(); ?>
</head>

<body <?php body_class(); ?>>
<?php wp_body_open(); ?>

<a class="ey-skip" href="#ey-main"><?php esc_html_e( 'پرش به محتوای اصلی', 'etehadyar-theme' ); ?></a>

<header class="ey-header">
	<div class="ey-wrap ey-header__inner">

		<?php if ( has_custom_logo() ) : ?>
			<?php the_custom_logo(); ?>
		<?php else : ?>
			<a class="ey-brand" href="<?php echo esc_url( home_url( '/' ) ); ?>" rel="home">
				<span class="ey-brand__mark" aria-hidden="true">ا</span>
				<span><?php bloginfo( 'name' ); ?></span>
			</a>
		<?php endif; ?>

		<button class="ey-menu-toggle" type="button"
			aria-expanded="false" aria-controls="ey-primary-nav"
			onclick="var n=document.getElementById('ey-primary-nav');var o=n.classList.toggle('is-open');this.setAttribute('aria-expanded',o?'true':'false');">
			<span class="screen-reader-text"><?php esc_html_e( 'فهرست', 'etehadyar-theme' ); ?></span>
			<span aria-hidden="true">☰</span>
		</button>

		<nav class="ey-nav" id="ey-primary-nav" aria-label="<?php esc_attr_e( 'فهرست اصلی', 'etehadyar-theme' ); ?>">
			<?php
			if ( has_nav_menu( 'primary' ) ) {
				wp_nav_menu(
					array(
						'theme_location' => 'primary',
						'container'      => false,
						'depth'          => 2,
						'fallback_cb'    => false,
					)
				);
			} else {
				// A bare install has no menu yet; show the platform pages so
				// the site is navigable from the first minute.
				?>
				<ul>
					<li><a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'خانه', 'etehadyar-theme' ); ?></a></li>
					<?php if ( is_user_logged_in() ) : ?>
						<li><a href="<?php echo esc_url( Etehadyar_Theme_Pages::url( 'dashboard' ) ); ?>"><?php esc_html_e( 'پنل کاربری', 'etehadyar-theme' ); ?></a></li>
						<li><a href="<?php echo esc_url( Etehadyar_Theme_Pages::url( 'studio' ) ); ?>"><?php esc_html_e( 'ابزارها', 'etehadyar-theme' ); ?></a></li>
						<li><a href="<?php echo esc_url( Etehadyar_Theme_Pages::url( 'wallet' ) ); ?>"><?php esc_html_e( 'کیف پول', 'etehadyar-theme' ); ?></a></li>
					<?php endif; ?>
				</ul>
				<?php
			}
			?>
		</nav>

		<div class="ey-header__actions">
			<?php if ( is_user_logged_in() ) : ?>

				<?php $balance = etehadyar_theme_balance_label(); ?>
				<?php if ( '' !== $balance ) : ?>
					<a class="ey-balance <?php echo etehadyar_theme_balance_is_low() ? 'ey-balance--low' : ''; ?>"
						href="<?php echo esc_url( Etehadyar_Theme_Pages::url( 'wallet' ) ); ?>">
						<span><?php esc_html_e( 'موجودی:', 'etehadyar-theme' ); ?></span>
						<span><?php echo esc_html( $balance ); ?></span>
					</a>
				<?php endif; ?>

				<a class="ey-btn ey-btn--ghost" href="<?php echo esc_url( wp_logout_url( home_url( '/' ) ) ); ?>">
					<?php esc_html_e( 'خروج', 'etehadyar-theme' ); ?>
				</a>

			<?php else : ?>

				<a class="ey-btn" href="<?php echo esc_url( Etehadyar_Theme_Pages::url( 'login' ) ); ?>">
					<?php esc_html_e( 'ورود / ثبت‌نام', 'etehadyar-theme' ); ?>
				</a>

			<?php endif; ?>
		</div>

	</div>
</header>

<main class="ey-main" id="ey-main">
	<div class="ey-wrap">
