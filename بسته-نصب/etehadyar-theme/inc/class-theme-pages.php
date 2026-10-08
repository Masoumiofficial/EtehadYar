<?php
/**
 * Creates and tracks the front-end pages the platform needs.
 *
 * @package Etehadyar_Theme
 */

defined( 'ABSPATH' ) || exit;

/**
 * The core plugin reads `etehadyar_page_dashboard` and `etehadyar_page_wallet`
 * to decide where to send people after login and after payment, but nothing
 * ever creates those pages. On a bare WordPress install the redirects would
 * land on the home page and the product would look broken.
 *
 * The theme fills that gap on activation: it creates the pages, drops the
 * right shortcode into each, and records the ids. Existing pages are reused
 * rather than duplicated, so switching the theme away and back does not
 * litter the site with copies.
 */
class Etehadyar_Theme_Pages {

	/**
	 * Page definitions: key => [ option, title, shortcode ].
	 *
	 * @return array
	 */
	public static function definitions() {
		return array(
			'dashboard' => array(
				'option'    => 'etehadyar_page_dashboard',
				'title'     => __( 'پنل کاربری', 'etehadyar-theme' ),
				'slug'      => 'dashboard',
				'shortcode' => '[etehadyar_dashboard]',
				'template'  => 'page-templates/template-dashboard.php',
			),
			'studio'    => array(
				'option'    => 'etehadyar_page_studio',
				'title'     => __( 'ابزارها', 'etehadyar-theme' ),
				'slug'      => 'studio',
				'shortcode' => '[etehadyar_studio]',
				'template'  => 'page-templates/template-studio.php',
			),
			'wallet'    => array(
				'option'    => 'etehadyar_page_wallet',
				'title'     => __( 'کیف پول', 'etehadyar-theme' ),
				'slug'      => 'wallet',
				'shortcode' => '[etehadyar_wallet]',
				'template'  => 'page-templates/template-wallet.php',
			),
			'login'     => array(
				'option'    => 'etehadyar_page_login',
				'title'     => __( 'ورود و ثبت‌نام', 'etehadyar-theme' ),
				'slug'      => 'login',
				'shortcode' => '[etehadyar_login]',
				'template'  => 'page-templates/template-login.php',
			),
		);
	}

	/**
	 * Attach hooks.
	 */
	public static function boot() {
		add_action( 'after_switch_theme', array( __CLASS__, 'install' ) );
	}

	/**
	 * Create any missing pages. Idempotent.
	 */
	public static function install() {
		foreach ( self::definitions() as $def ) {
			$existing = (int) get_option( $def['option'] );

			if ( $existing && 'page' === get_post_type( $existing ) && 'trash' !== get_post_status( $existing ) ) {
				continue;
			}

			// Someone may have made the page by hand before switching themes.
			$found = get_page_by_path( $def['slug'] );

			if ( $found ) {
				update_option( $def['option'], (int) $found->ID );
				update_post_meta( $found->ID, '_wp_page_template', $def['template'] );
				continue;
			}

			$page_id = wp_insert_post(
				array(
					'post_type'      => 'page',
					'post_title'     => $def['title'],
					'post_name'      => $def['slug'],
					'post_content'   => $def['shortcode'],
					'post_status'    => 'publish',
					'comment_status' => 'closed',
					'ping_status'    => 'closed',
					'meta_input'     => array( '_wp_page_template' => $def['template'] ),
				)
			);

			if ( $page_id && ! is_wp_error( $page_id ) ) {
				update_option( $def['option'], (int) $page_id );
			}
		}
	}

	/**
	 * URL of a platform page, falling back to the home page.
	 *
	 * @param string $key Page key.
	 * @return string
	 */
	public static function url( $key ) {
		$defs = self::definitions();

		if ( ! isset( $defs[ $key ] ) ) {
			return home_url( '/' );
		}

		$id = (int) get_option( $defs[ $key ]['option'] );

		if ( $id && 'publish' === get_post_status( $id ) ) {
			return (string) get_permalink( $id );
		}

		return home_url( '/' );
	}

	/**
	 * Whether the current request is one of the platform pages.
	 *
	 * @param string $key Page key.
	 * @return bool
	 */
	public static function is_page( $key ) {
		$defs = self::definitions();

		if ( ! isset( $defs[ $key ] ) ) {
			return false;
		}

		$id = (int) get_option( $defs[ $key ]['option'] );

		return $id && is_page( $id );
	}
}
