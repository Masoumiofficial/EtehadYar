<?php
/**
 * Small presentation helpers used by the templates.
 *
 * @package Etehadyar_Theme
 */

defined( 'ABSPATH' ) || exit;

/**
 * Current user's wallet balance, formatted, or an empty string.
 *
 * Reads through the core plugin's public API and never touches the billing
 * tables directly — the ledger is the plugin's business.
 *
 * @return string
 */
function etehadyar_theme_balance_label() {
	if ( ! is_user_logged_in() || ! Etehadyar_Core_Dependency::active() ) {
		return '';
	}

	if ( ! class_exists( '\\Etehadyar\\Billing\\Wallet' ) ) {
		return '';
	}

	$balance = \Etehadyar\Billing\Wallet::balance( get_current_user_id() );

	return \Etehadyar\Billing\Wallet::format( $balance );
}

/**
 * Whether the balance is low enough to warrant nudging the user.
 *
 * @return bool
 */
function etehadyar_theme_balance_is_low() {
	if ( ! is_user_logged_in() || ! class_exists( '\\Etehadyar\\Billing\\Wallet' ) ) {
		return false;
	}

	$balance = \Etehadyar\Billing\Wallet::balance( get_current_user_id() );

	/**
	 * Threshold, in Rial, under which the header balance turns amber.
	 *
	 * @param int $threshold Default 100,000 Rial.
	 */
	$threshold = (int) apply_filters( 'etehadyar_theme_low_balance', 100000 );

	return $balance < $threshold;
}

/**
 * Render a shortcode, or a placeholder if the core plugin is missing.
 *
 * @param string $shortcode Shortcode tag, e.g. 'etehadyar_wallet'.
 * @return string
 */
function etehadyar_theme_panel( $shortcode ) {
	if ( ! Etehadyar_Core_Dependency::active() || ! shortcode_exists( $shortcode ) ) {
		return Etehadyar_Core_Dependency::placeholder();
	}

	return do_shortcode( '[' . $shortcode . ']' );
}

/**
 * Whether the current visitor may use the AI tools.
 *
 * @return bool
 */
function etehadyar_theme_can_use_platform() {
	return is_user_logged_in()
		&& class_exists( '\\Etehadyar\\Core\\Capabilities' )
		&& current_user_can( \Etehadyar\Core\Capabilities::USE_PLATFORM );
}
