<?php
/**
 * Tenant profile model.
 *
 * @package Etehadyar\Core
 */

namespace Etehadyar\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Read/write access to the tenant profile table.
 */
class Tenant {

	const STATUS_ACTIVE    = 'active';
	const STATUS_SUSPENDED = 'suspended';
	const STATUS_PENDING   = 'pending';

	/**
	 * Allowed lifecycle states.
	 *
	 * @return string[]
	 */
	public static function statuses() {
		return array( self::STATUS_ACTIVE, self::STATUS_SUSPENDED, self::STATUS_PENDING );
	}

	/**
	 * Fetch a tenant profile.
	 *
	 * @param int $user_id User ID.
	 * @return array|null
	 */
	public static function get( $user_id ) {
		global $wpdb;

		$user_id = (int) $user_id;
		$table   = Schema::table( 'tenants' );

		if ( ! $user_id || ! Schema::table_exists( $table ) ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE user_id = %d", $user_id ), ARRAY_A );

		if ( ! $row ) {
			return null;
		}

		$row['user_id'] = (int) $row['user_id'];
		$row['meta']    = $row['meta'] ? json_decode( $row['meta'], true ) : array();

		return $row;
	}

	/**
	 * Create a tenant profile if one does not already exist.
	 *
	 * @param int   $user_id User ID.
	 * @param array $args    Optional overrides.
	 * @return array|null The tenant profile.
	 */
	public static function ensure( $user_id, $args = array() ) {
		global $wpdb;

		$user_id = (int) $user_id;
		$table   = Schema::table( 'tenants' );

		if ( ! $user_id || ! Schema::table_exists( $table ) ) {
			return null;
		}

		$existing = self::get( $user_id );

		if ( $existing ) {
			return $existing;
		}

		$defaults = array(
			'status'   => self::STATUS_ACTIVE,
			'plan_key' => 'free',
			'label'    => '',
			'meta'     => array(),
		);

		$args   = wp_parse_args( $args, $defaults );
		$status = in_array( $args['status'], self::statuses(), true ) ? $args['status'] : self::STATUS_ACTIVE;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->insert(
			$table,
			array(
				'user_id'    => $user_id,
				'status'     => $status,
				'plan_key'   => sanitize_key( $args['plan_key'] ),
				'label'      => sanitize_text_field( $args['label'] ),
				'meta'       => wp_json_encode( $args['meta'], JSON_UNESCAPED_UNICODE ),
				'created_at' => current_time( 'mysql' ),
				'updated_at' => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		return self::get( $user_id );
	}

	/**
	 * Update a tenant's lifecycle status.
	 *
	 * @param int    $user_id User ID.
	 * @param string $status  New status.
	 * @param string $reason  Optional reason, stored for suspensions.
	 * @return bool
	 */
	public static function set_status( $user_id, $status, $reason = '' ) {
		global $wpdb;

		$user_id = (int) $user_id;
		$table   = Schema::table( 'tenants' );

		if ( ! $user_id || ! in_array( $status, self::statuses(), true ) || ! Schema::table_exists( $table ) ) {
			return false;
		}

		self::ensure( $user_id );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$updated = $wpdb->update(
			$table,
			array(
				'status'           => $status,
				'suspended_reason' => self::STATUS_SUSPENDED === $status ? sanitize_text_field( $reason ) : null,
				'updated_at'       => current_time( 'mysql' ),
			),
			array( 'user_id' => $user_id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);

		if ( false !== $updated ) {
			Audit::log(
				'tenant.status_changed',
				array(
					'user_id'     => $user_id,
					'object_type' => 'tenant',
					'object_id'   => $user_id,
					'severity'    => self::STATUS_SUSPENDED === $status ? 'warning' : 'info',
					'context'     => array(
						'status' => $status,
						'reason' => $reason,
					),
				)
			);
		}

		return false !== $updated;
	}

	/**
	 * Whether a tenant is currently allowed to consume platform features.
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public static function is_active( $user_id ) {
		$tenant = self::get( $user_id );

		if ( ! $tenant ) {
			return false;
		}

		return self::STATUS_ACTIVE === $tenant['status'];
	}

	/**
	 * Count tenants grouped by status.
	 *
	 * @return array<string, int>
	 */
	public static function counts_by_status() {
		global $wpdb;

		$table  = Schema::table( 'tenants' );
		$counts = array_fill_keys( self::statuses(), 0 );

		if ( ! Schema::table_exists( $table ) ) {
			return $counts;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( "SELECT status, COUNT(*) AS total FROM `{$table}` GROUP BY status", ARRAY_A );

		foreach ( (array) $rows as $row ) {
			$counts[ $row['status'] ] = (int) $row['total'];
		}

		return $counts;
	}
}
