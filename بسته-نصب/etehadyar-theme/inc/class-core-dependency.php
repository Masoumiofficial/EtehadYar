<?php
/**
 * Hard dependency on the etehadyar-core plugin.
 *
 * @package Etehadyar_Theme
 */

defined( 'ABSPATH' ) || exit;

/**
 * The theme is a skin over the core plugin. Without the plugin there is no
 * auth, no wallet and no billing, so every front-end panel would either fatal
 * or — worse — render an empty shell that looks like a working product.
 *
 * Rather than bundle TGMPA (a large third-party library that ships its own
 * installer and update checker), this does the one thing that actually
 * matters: detect the plugin, refuse to pretend without it, and tell the
 * administrator exactly what to do. Members never see any of this.
 */
class Etehadyar_Core_Dependency {

	/**
	 * Minimum core version this theme was built against.
	 */
	const REQUIRED_VERSION = '1.8.0-phase9';

	/**
	 * Attach hooks.
	 */
	public static function boot() {
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
	}

	/**
	 * Whether the core plugin is loaded.
	 *
	 * @return bool
	 */
	public static function active() {
		return class_exists( '\\Etehadyar\\Core\\Plugin' ) && defined( 'ETEHADYAR_CORE_VERSION' );
	}

	/**
	 * Whether the loaded core is new enough.
	 *
	 * @return bool
	 */
	public static function version_ok() {
		if ( ! self::active() ) {
			return false;
		}

		return version_compare( ETEHADYAR_CORE_VERSION, self::REQUIRED_VERSION, '>=' );
	}

	/**
	 * Admin-side warning.
	 */
	public static function notice() {
		if ( ! current_user_can( 'install_plugins' ) ) {
			return;
		}

		if ( self::version_ok() ) {
			return;
		}

		$message = self::active()
			? sprintf(
				/* translators: 1: installed version, 2: required version */
				__( 'نسخهٔ افزونهٔ «هستهٔ اتحادیار» (%1$s) قدیمی‌تر از نیاز قالب (%2$s) است. لطفاً افزونه را به‌روزرسانی کنید.', 'etehadyar-theme' ),
				ETEHADYAR_CORE_VERSION,
				self::REQUIRED_VERSION
			)
			: __( 'قالب اتحادیار برای کار کردن به افزونهٔ «هستهٔ اتحادیار» نیاز دارد. تا زمانی که افزونه نصب و فعال نشود، ورود پیامکی، کیف پول و داشبورد کاربری در دسترس نخواهند بود.', 'etehadyar-theme' );

		printf(
			'<div class="notice notice-error"><p><strong>%s</strong> %s</p></div>',
			esc_html__( 'اتحادیار:', 'etehadyar-theme' ),
			esc_html( $message )
		);
	}

	/**
	 * Front-end placeholder shown where a core panel would have been.
	 *
	 * Visitors get a neutral "unavailable" message — never a stack trace and
	 * never a fake-looking empty dashboard. Administrators get the real cause.
	 *
	 * @return string
	 */
	public static function placeholder() {
		$text = current_user_can( 'install_plugins' )
			? __( 'افزونهٔ «هستهٔ اتحادیار» فعال نیست. این بخش تا نصب و فعال‌سازی افزونه نمایش داده نمی‌شود.', 'etehadyar-theme' )
			: __( 'این بخش موقتاً در دسترس نیست. لطفاً بعداً دوباره تلاش کنید.', 'etehadyar-theme' );

		return sprintf(
			'<div class="ey-notice ey-notice--warn">%s</div>',
			esc_html( $text )
		);
	}
}
