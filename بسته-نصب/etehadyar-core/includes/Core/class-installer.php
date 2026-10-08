<?php
/**
 * Activation, deactivation and provisioning.
 *
 * @package Etehadyar\Core
 */

namespace Etehadyar\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Lifecycle handler.
 */
class Installer {

	const OPTION_OWNER      = 'etehadyar_platform_owner';
	const OPTION_ACTIVATED  = 'etehadyar_core_activated_at';
	const OPTION_AUTO_ENROL = 'etehadyar_auto_enrol_members';

	/**
	 * Run on activation.
	 */
	public static function activate() {
		if ( ! get_option( self::OPTION_OWNER ) ) {
			update_option( self::OPTION_OWNER, get_current_user_id(), false );
		}

		add_option( self::OPTION_AUTO_ENROL, 1, '', false );
		add_option( self::OPTION_ACTIVATED, time(), '', false );

		Capabilities::install();
		Migrator::run();
		self::provision_owner();

		if ( ! wp_next_scheduled( 'etehadyar_daily_maintenance' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'etehadyar_daily_maintenance' );
		}

		Audit::log(
			'platform.activated',
			array(
				'severity' => 'info',
				'context'  => array( 'version' => ETEHADYAR_CORE_VERSION ),
			)
		);

		flush_rewrite_rules();
	}

	/**
	 * Run on deactivation.
	 *
	 * Roles and data are intentionally preserved: deactivation is not the same
	 * as uninstall, and destroying paying members' access on a plugin toggle
	 * would be unforgivable.
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( 'etehadyar_daily_maintenance' );

		Audit::log( 'platform.deactivated', array( 'severity' => 'warning' ) );
		flush_rewrite_rules();
	}

	/**
	 * The account that owns pre-platform legacy data.
	 *
	 * @return int
	 */
	public static function platform_owner_id() {
		$owner = (int) get_option( self::OPTION_OWNER, 0 );

		if ( $owner && get_userdata( $owner ) ) {
			return $owner;
		}

		$admins = get_users(
			array(
				'role'    => 'administrator',
				'number'  => 1,
				'orderby' => 'ID',
				'order'   => 'ASC',
				'fields'  => 'ID',
			)
		);

		return $admins ? (int) $admins[0] : 0;
	}

	/**
	 * Ensure the owner has a tenant profile.
	 */
	protected static function provision_owner() {
		$owner = self::platform_owner_id();

		if ( $owner ) {
			Tenant::ensure( $owner, array( 'plan_key' => 'owner' ) );
		}
	}

	/**
	 * Whether new users should automatically become tenants.
	 *
	 * @return bool
	 */
	public static function auto_enrol_enabled() {
		return (bool) get_option( self::OPTION_AUTO_ENROL, 1 );
	}
}
