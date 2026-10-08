<?php
/**
 * Audit trail writer.
 *
 * @package Etehadyar\Core
 */

namespace Etehadyar\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Records privileged and cross-tenant actions.
 */
class Audit {

	/**
	 * Keys stripped from context before storage.
	 *
	 * @var string[]
	 */
	const REDACT_KEYS = array(
		'key',
		'api_key',
		'token',
		'access_token',
		'password',
		'pass',
		'secret',
		'code',
		'otp',
		'nonce',
		'authorization',
	);

	/**
	 * Write an audit entry.
	 *
	 * @param string $action Dot-namespaced action, e.g. tenant.suspended.
	 * @param array  $args   Entry arguments.
	 * @return int Inserted ID, or 0 on failure.
	 */
	public static function log( $action, $args = array() ) {
		global $wpdb;

		$table = Schema::table( 'audit' );

		if ( ! Schema::table_exists( $table ) ) {
			return 0;
		}

		$args = wp_parse_args(
			$args,
			array(
				'user_id'     => Tenant_Context::current_id(),
				'actor_id'    => get_current_user_id(),
				'object_type' => '',
				'object_id'   => 0,
				'severity'    => 'info',
				'context'     => array(),
			)
		);

		$severity = in_array( $args['severity'], array( 'info', 'warning', 'error', 'critical' ), true )
			? $args['severity']
			: 'info';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ok = $wpdb->insert(
			$table,
			array(
				'user_id'     => (int) $args['user_id'],
				'actor_id'    => (int) $args['actor_id'],
				'action'      => sanitize_key( str_replace( '.', '_', $action ) ) ? substr( $action, 0, 80 ) : 'unknown',
				'object_type' => sanitize_key( $args['object_type'] ),
				'object_id'   => (int) $args['object_id'],
				'severity'    => $severity,
				'ip'          => self::client_ip(),
				'context'     => wp_json_encode( self::redact( $args['context'] ), JSON_UNESCAPED_UNICODE ),
				'created_at'  => current_time( 'mysql' ),
			),
			array( '%d', '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s' )
		);

		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Recent entries for a tenant, or platform-wide for admins.
	 *
	 * @param array $args Query arguments.
	 * @return array
	 */
	public static function recent( $args = array() ) {
		global $wpdb;

		$table = Schema::table( 'audit' );

		if ( ! Schema::table_exists( $table ) ) {
			return array();
		}

		$args = wp_parse_args(
			$args,
			array(
				'tenant_id' => null,
				'limit'     => 50,
				'action'    => '',
			)
		);

		$limit  = max( 1, min( 200, (int) $args['limit'] ) );
		$clause = array( '1=1' );
		$values = array();

		$scoped = ! current_user_can( Capabilities::MANAGE_PLATFORM ) || null !== $args['tenant_id'];

		if ( $scoped ) {
			$tenant = Tenant_Context::resolve( $args['tenant_id'] );

			if ( is_wp_error( $tenant ) ) {
				return array();
			}

			$clause[] = 'user_id = %d';
			$values[] = $tenant;
		}

		if ( $args['action'] ) {
			$clause[] = 'action = %s';
			$values[] = substr( $args['action'], 0, 80 );
		}

		$values[] = $limit;

		$sql = "SELECT * FROM `{$table}` WHERE " . implode( ' AND ', $clause ) . ' ORDER BY id DESC LIMIT %d';

		// phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $values ), ARRAY_A );

		foreach ( $rows as &$row ) {
			$row['context'] = $row['context'] ? json_decode( $row['context'], true ) : array();
		}

		return (array) $rows;
	}

	/**
	 * Strip secrets from a context payload, recursively.
	 *
	 * @param mixed $context Raw context.
	 * @param int   $depth   Current recursion depth.
	 * @return mixed
	 */
	protected static function redact( $context, $depth = 0 ) {
		if ( $depth > 4 || ! is_array( $context ) ) {
			return is_scalar( $context ) ? $context : array();
		}

		$clean = array();

		foreach ( $context as $key => $value ) {
			$needle = is_string( $key ) ? strtolower( $key ) : '';

			foreach ( self::REDACT_KEYS as $bad ) {
				if ( $needle && false !== strpos( $needle, $bad ) ) {
					$clean[ $key ] = '[redacted]';
					continue 2;
				}
			}

			$clean[ $key ] = is_array( $value ) ? self::redact( $value, $depth + 1 ) : $value;
		}

		return $clean;
	}

	/**
	 * Best-effort client IP.
	 *
	 * Only REMOTE_ADDR is trusted by default; forwarded headers are spoofable
	 * unless a known proxy is in front, which is a site-specific decision.
	 *
	 * @return string
	 */
	protected static function client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		/**
		 * Filter the resolved client IP for audit entries.
		 *
		 * @param string $ip Detected IP address.
		 */
		$ip = apply_filters( 'etehadyar_audit_client_ip', $ip );

		return substr( (string) $ip, 0, 45 );
	}
}
