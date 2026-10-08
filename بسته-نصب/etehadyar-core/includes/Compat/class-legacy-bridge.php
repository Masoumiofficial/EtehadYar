<?php
/**
 * Bridge between the legacy Etehadyar AI plugin and the multi-tenant core.
 *
 * The legacy plugin writes rows without any notion of ownership. Rather than
 * forking it — which would make future upstream fixes unmergeable — this bridge
 * stamps ownership onto rows as they are created and blocks members from
 * reaching admin-only AJAX endpoints.
 *
 * @package Etehadyar\Compat
 */

namespace Etehadyar\Compat;

use Etehadyar\Core\Audit;
use Etehadyar\Core\Capabilities;
use Etehadyar\Core\Schema;
use Etehadyar\Core\Tenant;
use Etehadyar\Core\Tenant_Context;

defined( 'ABSPATH' ) || exit;

/**
 * Legacy compatibility layer.
 */
class Legacy_Bridge {

	/**
	 * Legacy AJAX actions a member may never call directly.
	 *
	 * These operate on global site state (settings, cron, other tenants' data)
	 * and are reachable by any logged-in user through admin-ajax.php if the
	 * legacy capability checks are loosened.
	 *
	 * @var string[]
	 */
	const ADMIN_ONLY_ACTIONS = array(
		'eaiw_social_save',
		'eaiw_social_test_telegram',
		'eaiw_ai_test',
		'eaiw_health_check',
		'eaiw_health_repair_cron',
		'eaiw_activity_clear',
		'eaiw_theme_save',
		'eaiw_supernatural_toggle',
		'eaiw_nexus_create',
		'eaiw_nexus_delete',
		'eaiw_nexus_toggle',
		'eaiw_agent_toggle',
		'eaiw_brain_index',
		'eaiw_report_pdf',
		'eaiw_report_excel',
	);

	/**
	 * Whether the legacy plugin is present.
	 *
	 * @return bool
	 */
	public static function legacy_active() {
		return defined( 'EAIW_VERSION' );
	}

	/**
	 * Attach hooks.
	 */
	public static function boot() {
		add_filter( 'query', array( __CLASS__, 'stamp_owner_on_insert' ) );
		add_action( 'admin_init', array( __CLASS__, 'guard_admin_ajax' ), 1 );
	}

	/**
	 * Stamp `user_id` onto legacy INSERTs that omit it.
	 *
	 * Hooking `query` is a blunt instrument, so the matching is deliberately
	 * narrow: only INSERTs, only into known legacy tables, only when the
	 * column is genuinely absent, and only when a tenant is resolvable.
	 * Anything else is returned untouched.
	 *
	 * @param string $query SQL about to run.
	 * @return string
	 */
	public static function stamp_owner_on_insert( $query ) {
		global $wpdb;

		if ( ! is_string( $query ) || 0 !== stripos( ltrim( $query ), 'INSERT' ) ) {
			return $query;
		}

		if ( false !== stripos( $query, '`user_id`' ) || preg_match( '/[(,]\s*user_id\s*[,)]/i', $query ) ) {
			return $query;
		}

		$tenant = Tenant_Context::current_id();

		if ( ! $tenant ) {
			return $query;
		}

		foreach ( array( 'jobs', 'usage', 'chat_logs', 'support' ) as $key ) {
			$table = Schema::table( $key );

			if ( ! $table || false === stripos( $query, $table ) ) {
				continue;
			}

			if ( ! Schema::has_column( $table, 'user_id' ) ) {
				continue;
			}

			// Match: INSERT INTO `table` (`a`,`b`) VALUES ('x','y')
			$pattern = '/^(\s*INSERT\s+(?:IGNORE\s+)?INTO\s+`?' . preg_quote( $table, '/' ) . '`?\s*\()(.+?)(\)\s*VALUES\s*\()(.+)$/is';

			if ( preg_match( $pattern, $query, $m ) ) {
				return $m[1] . '`user_id`, ' . $m[2] . $m[3] . (int) $tenant . ', ' . $m[4];
			}
		}

		return $query;
	}

	/**
	 * Block members from calling admin-only legacy AJAX actions.
	 *
	 * Runs before the legacy handlers so a hardened member account cannot
	 * reach site-wide configuration even if an upstream check regresses.
	 */
	public static function guard_admin_ajax() {
		if ( ! wp_doing_ajax() ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';

		if ( ! $action || ! in_array( $action, self::ADMIN_ONLY_ACTIONS, true ) ) {
			return;
		}

		if ( current_user_can( 'manage_options' ) || current_user_can( Capabilities::MANAGE_PLATFORM ) ) {
			return;
		}

		Audit::log(
			'security.blocked_admin_action',
			array(
				'severity' => 'warning',
				'context'  => array( 'action' => $action ),
			)
		);

		wp_send_json_error(
			array( 'message' => __( 'این عملیات فقط برای مدیر پلتفرم مجاز است.', 'etehadyar-core' ) ),
			403
		);
	}

	/**
	 * Run a legacy callable on behalf of a tenant.
	 *
	 * Background workers have no current user, so the job's owner must be
	 * restored before the legacy code writes any rows.
	 *
	 * @param int      $tenant_id Tenant ID.
	 * @param callable $callback  Legacy work.
	 * @return mixed
	 */
	public static function run_as( $tenant_id, callable $callback ) {
		if ( ! Tenant::is_active( $tenant_id ) ) {
			return new \WP_Error(
				'etehadyar_tenant_inactive',
				__( 'حساب کاربری مالک این کار فعال نیست.', 'etehadyar-core' ),
				array( 'status' => 403 )
			);
		}

		return Tenant_Context::as_tenant( $tenant_id, $callback );
	}
}
