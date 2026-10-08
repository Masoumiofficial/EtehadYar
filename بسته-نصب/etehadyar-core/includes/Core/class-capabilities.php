<?php
/**
 * Platform capability and role definitions.
 *
 * The legacy plugin gated every action behind `manage_options` or `edit_posts`.
 * Handing either of those to a paying customer would give them the WordPress
 * admin. This class introduces a dedicated capability set so a platform member
 * can use AI features without gaining any WordPress authoring or admin rights.
 *
 * @package Etehadyar\Core
 */

namespace Etehadyar\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Roles and capabilities for the platform.
 */
class Capabilities {

	/** Member role slug. */
	const ROLE_MEMBER = 'etehadyar_member';

	/** Operator (staff) role slug. */
	const ROLE_OPERATOR = 'etehadyar_operator';

	/** Baseline: may sign in and open the dashboard. */
	const USE_PLATFORM = 'etehadyar_use_platform';

	/** May run content generation jobs. */
	const GENERATE_CONTENT = 'etehadyar_generate_content';

	/** May run image generation jobs. */
	const GENERATE_IMAGE = 'etehadyar_generate_image';

	/** May run voice/TTS jobs. */
	const GENERATE_VOICE = 'etehadyar_generate_voice';

	/** May use the assistant chat. */
	const USE_CHAT = 'etehadyar_use_chat';

	/** May view own wallet and transactions (phase 3 surface). */
	const VIEW_BILLING = 'etehadyar_view_billing';

	/** Staff: may read other tenants' support tickets. */
	const MANAGE_SUPPORT = 'etehadyar_manage_support';

	/** Staff/admin: may administer the platform itself. */
	const MANAGE_PLATFORM = 'etehadyar_manage_platform';

	/**
	 * Capabilities granted to a standard member.
	 *
	 * @return string[]
	 */
	public static function member_caps() {
		return array(
			self::USE_PLATFORM,
			self::GENERATE_CONTENT,
			self::GENERATE_IMAGE,
			self::GENERATE_VOICE,
			self::USE_CHAT,
			self::VIEW_BILLING,
		);
	}

	/**
	 * Capabilities granted to an operator.
	 *
	 * @return string[]
	 */
	public static function operator_caps() {
		return array_merge(
			self::member_caps(),
			array( self::MANAGE_SUPPORT )
		);
	}

	/**
	 * Every capability this plugin defines.
	 *
	 * @return string[]
	 */
	public static function all_caps() {
		return array_values(
			array_unique(
				array_merge(
					self::operator_caps(),
					array( self::MANAGE_PLATFORM )
				)
			)
		);
	}

	/**
	 * Register roles and grant admin capabilities. Idempotent.
	 */
	public static function install() {
		remove_role( self::ROLE_MEMBER );
		add_role(
			self::ROLE_MEMBER,
			__( 'کاربر اتحادیار', 'etehadyar-core' ),
			array_merge(
				array( 'read' => true ),
				array_fill_keys( self::member_caps(), true )
			)
		);

		remove_role( self::ROLE_OPERATOR );
		add_role(
			self::ROLE_OPERATOR,
			__( 'کارشناس اتحادیار', 'etehadyar-core' ),
			array_merge(
				array( 'read' => true ),
				array_fill_keys( self::operator_caps(), true )
			)
		);

		$admin = get_role( 'administrator' );

		if ( $admin ) {
			foreach ( self::all_caps() as $cap ) {
				$admin->add_cap( $cap );
			}
		}
	}

	/**
	 * Remove roles and capabilities. Used on uninstall, not deactivation.
	 */
	public static function uninstall() {
		remove_role( self::ROLE_MEMBER );
		remove_role( self::ROLE_OPERATOR );

		$admin = get_role( 'administrator' );

		if ( $admin ) {
			foreach ( self::all_caps() as $cap ) {
				$admin->remove_cap( $cap );
			}
		}
	}

	/**
	 * Whether the current request may act on behalf of the given tenant.
	 *
	 * @param int $tenant_id Tenant user ID.
	 * @return bool
	 */
	public static function can_act_for( $tenant_id ) {
		$tenant_id = (int) $tenant_id;
		$current   = get_current_user_id();

		if ( ! $tenant_id || ! $current ) {
			return false;
		}

		if ( $tenant_id === $current ) {
			return true;
		}

		return current_user_can( self::MANAGE_PLATFORM );
	}
}
