<?php
/**
 * Resolves and holds the tenant whose data the current request may touch.
 *
 * Every repository query funnels through this class. Centralising the answer to
 * "whose data is this?" is what prevents one member's content leaking into
 * another member's dashboard — the single highest-risk failure mode of turning
 * a single-user plugin into a platform.
 *
 * @package Etehadyar\Core
 */

namespace Etehadyar\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Request-scoped tenant resolver.
 */
class Tenant_Context {

	/**
	 * Explicitly overridden tenant, used by CLI/cron/background workers.
	 *
	 * @var int|null
	 */
	protected static $override = null;

	/**
	 * Resolve the tenant ID for the current request.
	 *
	 * @return int Zero when there is no tenant (logged-out request).
	 */
	public static function current_id() {
		if ( null !== self::$override ) {
			return (int) self::$override;
		}

		return (int) get_current_user_id();
	}

	/**
	 * Run a callback as a specific tenant, then restore the previous context.
	 *
	 * Background jobs have no logged-in user, so the worker must state which
	 * tenant the job belongs to. Restoring in a `finally` block guarantees the
	 * context cannot leak into the next job even if the callback throws.
	 *
	 * @param int      $tenant_id Tenant user ID.
	 * @param callable $callback  Work to perform.
	 * @return mixed
	 */
	public static function as_tenant( $tenant_id, callable $callback ) {
		$previous       = self::$override;
		self::$override = (int) $tenant_id;

		try {
			return $callback();
		} finally {
			self::$override = $previous;
		}
	}

	/**
	 * Clear any override.
	 */
	public static function reset() {
		self::$override = null;
	}

	/**
	 * Whether the current actor may read/write data owned by a tenant.
	 *
	 * @param int $tenant_id Tenant user ID.
	 * @return bool
	 */
	public static function can_access( $tenant_id ) {
		$tenant_id = (int) $tenant_id;

		if ( ! $tenant_id ) {
			return false;
		}

		if ( null !== self::$override ) {
			return (int) self::$override === $tenant_id;
		}

		return Capabilities::can_act_for( $tenant_id );
	}

	/**
	 * Resolve a requested tenant ID, falling back to the current one.
	 *
	 * Returns a WP_Error when the actor is not allowed to act for the requested
	 * tenant, so callers can surface a 403 instead of silently reading the
	 * wrong rows.
	 *
	 * @param int|null $requested Requested tenant ID, or null for "me".
	 * @return int|\WP_Error
	 */
	public static function resolve( $requested = null ) {
		$current = self::current_id();

		if ( null === $requested || '' === $requested ) {
			if ( ! $current ) {
				return new \WP_Error(
					'etehadyar_no_tenant',
					__( 'برای این عملیات باید وارد حساب کاربری شوید.', 'etehadyar-core' ),
					array( 'status' => 401 )
				);
			}

			return $current;
		}

		$requested = (int) $requested;

		if ( ! self::can_access( $requested ) ) {
			return new \WP_Error(
				'etehadyar_forbidden_tenant',
				__( 'اجازهٔ دسترسی به دادهٔ این کاربر را ندارید.', 'etehadyar-core' ),
				array( 'status' => 403 )
			);
		}

		return $requested;
	}
}
