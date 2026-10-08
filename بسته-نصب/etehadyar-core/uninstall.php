<?php
/**
 * Uninstall routine.
 *
 * Member data is preserved by default. Destroying tenant profiles, usage
 * history and audit trails on a plugin removal would erase the billing record
 * of paying customers, so it only happens when an administrator has explicitly
 * opted in.
 *
 * @package Etehadyar\Core
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( ! get_option( 'etehadyar_core_delete_data_on_uninstall', 0 ) ) {
	return;
}

global $wpdb;

require_once __DIR__ . '/includes/Core/class-capabilities.php';

// Drop only the tables this plugin owns. Legacy AI tables belong to the AI
// plugin and are left untouched, including the user_id column added to them.
$tables = array(
	'etehadyar_tenants',
	'etehadyar_audit',
	'etehadyar_otp',
	'etehadyar_wallet',
	'etehadyar_wallet_txn',
	'etehadyar_orders',
);

foreach ( $tables as $table ) {
	// phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery
	$wpdb->query( 'DROP TABLE IF EXISTS `' . $wpdb->prefix . $table . '`' );
}

$options = array(
	'etehadyar_core_applied_migrations',
	'etehadyar_core_db_version',
	'etehadyar_platform_owner',
	'etehadyar_core_activated_at',
	'etehadyar_auto_enrol_members',
	'etehadyar_core_delete_data_on_uninstall',
	'etehadyar_sms_gateway',
	'etehadyar_sms_melipayamak_username',
	'etehadyar_sms_melipayamak_mode',
	'etehadyar_sms_melipayamak_from',
	'etehadyar_sms_melipayamak_body_id',
	'etehadyar_registration_mode',
	'etehadyar_page_dashboard',
	'etehadyar_page_wallet',
	'etehadyar_sms_pattern_alert',
	'etehadyar_pricing_rates',
	'etehadyar_zarinpal_sandbox',
	'etehadyar_topup_min',
	'etehadyar_topup_max',
	'etehadyar_signup_bonus',
	'etehadyar_quota_concurrent',
	'etehadyar_quota_hourly',
	'etehadyar_page_studio',
	'etehadyar_gsc_site_url',
	'etehadyar_gsc_connected_at',
	'etehadyar_gsc_last_error',
	'etehadyar_gsc_state',
	'etehadyar_embedding_model',
	'etehadyar_embedding_endpoint',
	'etehadyar_embedding_dims',
	'etehadyar_index_model',
	'etehadyar_index_dims',
	'etehadyar_brain_last_error',
);

foreach ( $options as $option ) {
	delete_option( $option );
}

// Encrypted credentials must go too — leaving them behind would keep the SMS
// panel password in the database after the plugin is gone.
// phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery
$secrets = $wpdb->get_col(
	"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'etehadyar\\_secret\\_%'"
);

foreach ( (array) $secrets as $secret ) {
	delete_option( $secret );
}

// Transients: quota counters, OTP throttles and the migration lock are all
// stored as options and survive deactivation unless removed explicitly.
// phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery
$transients = $wpdb->get_col(
	"SELECT option_name FROM {$wpdb->options}
	 WHERE option_name LIKE '\\_transient\\_etehadyar\\_%'
	    OR option_name LIKE '\\_transient\\_timeout\\_etehadyar\\_%'"
);

foreach ( (array) $transients as $transient ) {
	delete_option( $transient );
}

// Phone numbers stored against user accounts.
delete_metadata( 'user', 0, 'etehadyar_phone', '', true );
delete_metadata( 'user', 0, 'etehadyar_phone_verified_at', '', true );

Etehadyar\Core\Capabilities::uninstall();
