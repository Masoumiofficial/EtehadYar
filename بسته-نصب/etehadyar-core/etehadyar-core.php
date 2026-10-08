<?php
/**
 * Plugin Name:       اتحادیار هسته | Etehadyar Core
 * Plugin URI:        https://etehadyar.ir
 * Description:       هستهٔ پلتفرم اتحادیار — لایهٔ چندمستأجری، مدیریت دسترسی، جداسازی دادهٔ کاربران و API پنل کاربری. این افزونه پیش‌نیاز قالب پلتفرم است.
 * Version:           1.8.0-phase9
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            اتحاد وردپرس
 * Author URI:        https://etehadwp.com
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       etehadyar-core
 * Domain Path:       /languages
 *
 * @package Etehadyar\Core
 */

defined( 'ABSPATH' ) || exit;

define( 'ETEHADYAR_CORE_VERSION', '1.8.0-phase9' );
define( 'ETEHADYAR_CORE_FILE', __FILE__ );
define( 'ETEHADYAR_CORE_PATH', plugin_dir_path( __FILE__ ) );
define( 'ETEHADYAR_CORE_URL', plugin_dir_url( __FILE__ ) );
define( 'ETEHADYAR_CORE_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Minimum requirements gate.
 */
if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
	add_action(
		'admin_notices',
		static function () {
			echo '<div class="notice notice-error"><p><b>اتحادیار هسته</b> به PHP نسخهٔ ۷.۴ یا بالاتر نیاز دارد. نسخهٔ فعلی: ' . esc_html( PHP_VERSION ) . '</p></div>';
		}
	);
	return;
}

/**
 * PSR-4 style autoloader.
 *
 * Etehadyar\Data\Jobs_Repository  ->  includes/Data/class-jobs-repository.php
 */
spl_autoload_register(
	static function ( $class ) {
		$prefix = 'Etehadyar\\';
		if ( 0 !== strpos( $class, $prefix ) ) {
			return;
		}

		$relative = substr( $class, strlen( $prefix ) );
		$parts    = explode( '\\', $relative );
		$name     = array_pop( $parts );
		$file     = 'class-' . strtolower( str_replace( '_', '-', $name ) ) . '.php';
		$path     = ETEHADYAR_CORE_PATH . 'includes/' . ( $parts ? implode( '/', $parts ) . '/' : '' ) . $file;

		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
);

register_activation_hook( __FILE__, array( 'Etehadyar\\Core\\Installer', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Etehadyar\\Core\\Installer', 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function () {
		load_plugin_textdomain( 'etehadyar-core', false, dirname( ETEHADYAR_CORE_BASENAME ) . '/languages' );
		Etehadyar\Core\Plugin::instance();
	},
	5
);

/**
 * Global accessor for the core container.
 *
 * @return Etehadyar\Core\Plugin
 */
function etehadyar_core() {
	return Etehadyar\Core\Plugin::instance();
}
