<?php
/**
 * Theme supports, menus and assets.
 *
 * @package Etehadyar_Theme
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers the standard WordPress theme surface.
 */
class Etehadyar_Theme_Setup {

	/**
	 * Attach hooks.
	 */
	public static function boot() {
		add_action( 'after_setup_theme', array( __CLASS__, 'setup' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'body_class', array( __CLASS__, 'body_class' ) );
		add_filter( 'nav_menu_css_class', array( __CLASS__, 'trim_menu_classes' ), 10, 2 );

		// Members have no business in wp-admin; the platform lives on the
		// front end. Administrators and operators keep their access.
		add_action( 'admin_init', array( __CLASS__, 'block_admin_access' ) );
		add_filter( 'show_admin_bar', array( __CLASS__, 'admin_bar_visibility' ) );
	}

	/**
	 * Declare theme support.
	 */
	public static function setup() {
		load_theme_textdomain( 'etehadyar-theme', get_template_directory() . '/languages' );

		add_theme_support( 'title-tag' );
		add_theme_support( 'post-thumbnails' );
		add_theme_support( 'automatic-feed-links' );
		add_theme_support( 'responsive-embeds' );
		add_theme_support( 'wp-block-styles' );
		add_theme_support( 'align-wide' );
		add_theme_support(
			'html5',
			array( 'search-form', 'comment-form', 'comment-list', 'gallery', 'caption', 'style', 'script' )
		);
		add_theme_support(
			'custom-logo',
			array( 'height' => 48, 'width' => 180, 'flex-height' => true, 'flex-width' => true )
		);

		register_nav_menus(
			array(
				'primary' => __( 'فهرست اصلی', 'etehadyar-theme' ),
				'footer'  => __( 'فهرست پاورقی', 'etehadyar-theme' ),
			)
		);
	}

	/**
	 * Enqueue the stylesheet.
	 */
	public static function assets() {
		wp_enqueue_style(
			'etehadyar-theme',
			get_stylesheet_uri(),
			array(),
			ETEHADYAR_THEME_VERSION
		);

		// Deliberately NOT calling wp_style_add_data( ..., 'rtl', 'replace' ):
		// that makes WordPress swap style.css for style-rtl.css on an RTL
		// locale, and this theme ships no such file — the Persian site it is
		// built for would load with no styling at all. The stylesheet is
		// RTL-native (logical properties, dir="rtl" in the template), so the
		// same file serves both directions.
	}

	/**
	 * Useful state classes for styling.
	 *
	 * @param string[] $classes Body classes.
	 * @return string[]
	 */
	public static function body_class( $classes ) {
		$classes[] = is_user_logged_in() ? 'ey-authed' : 'ey-guest';

		if ( ! Etehadyar_Core_Dependency::active() ) {
			$classes[] = 'ey-core-missing';
		}

		return $classes;
	}

	/**
	 * Drop WordPress's very long default menu classes.
	 *
	 * @param string[] $classes Menu item classes.
	 * @param object   $item    Menu item.
	 * @return string[]
	 */
	public static function trim_menu_classes( $classes, $item ) {
		unset( $item );

		return array_intersect(
			$classes,
			array( 'current-menu-item', 'current-menu-parent', 'menu-item-has-children' )
		);
	}

	/**
	 * Keep members out of the dashboard.
	 *
	 * A customer who lands in wp-admin sees a confusing, mostly empty
	 * WordPress dashboard. Their home is the front-end panel.
	 */
	public static function block_admin_access() {
		if ( wp_doing_ajax() || ! is_user_logged_in() ) {
			return;
		}

		if ( current_user_can( 'edit_posts' ) || current_user_can( 'manage_options' ) ) {
			return;
		}

		wp_safe_redirect( Etehadyar_Theme_Pages::url( 'dashboard' ) );
		exit;
	}

	/**
	 * Hide the admin bar from members.
	 *
	 * @param bool $show Current visibility.
	 * @return bool
	 */
	public static function admin_bar_visibility( $show ) {
		if ( ! is_user_logged_in() ) {
			return $show;
		}

		return current_user_can( 'edit_posts' ) || current_user_can( 'manage_options' );
	}
}
