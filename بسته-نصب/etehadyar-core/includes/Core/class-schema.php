<?php
/**
 * Central table registry and DDL definitions.
 *
 * @package Etehadyar\Core
 */

namespace Etehadyar\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Knows every table the platform touches — both the new core tables and the
 * legacy tables inherited from the Etehadyar AI plugin.
 */
class Schema {

	/**
	 * Core tables owned by this plugin.
	 */
	const CORE_TABLES = array(
		'tenants'      => 'etehadyar_tenants',
		'audit'        => 'etehadyar_audit',
		'otp'          => 'etehadyar_otp',
		'wallet'       => 'etehadyar_wallet',
		'transactions' => 'etehadyar_wallet_txn',
		'orders'       => 'etehadyar_orders',
	);

	/**
	 * Legacy tables inherited from the AI plugin that must become tenant-aware.
	 */
	const LEGACY_TABLES = array(
		'jobs'      => 'eaiw_jobs',
		'usage'     => 'eaiw_usage',
		'chat_logs' => 'eaiw_chatsoul_logs',
		'support'   => 'eaiw_support_requests',
		'vectors'   => 'eaiw_vectors',
	);

	/**
	 * Resolve a logical key to a fully prefixed table name.
	 *
	 * @param string $key Logical table key.
	 * @return string
	 */
	public static function table( $key ) {
		global $wpdb;

		$map = array_merge( self::CORE_TABLES, self::LEGACY_TABLES );

		if ( ! isset( $map[ $key ] ) ) {
			return '';
		}

		return $wpdb->prefix . $map[ $key ];
	}

	/**
	 * Whether a table physically exists.
	 *
	 * @param string $table Full table name.
	 * @return bool
	 */
	public static function table_exists( $table ) {
		global $wpdb;

		if ( ! $table ) {
			return false;
		}

		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
	}

	/**
	 * Whether a column exists on a table.
	 *
	 * @param string $table  Full table name.
	 * @param string $column Column name.
	 * @return bool
	 */
	public static function has_column( $table, $column ) {
		global $wpdb;

		if ( ! self::table_exists( $table ) ) {
			return false;
		}

		$found = $wpdb->get_results( $wpdb->prepare( "SHOW COLUMNS FROM `{$table}` LIKE %s", $column ) ); // phpcs:ignore WordPress.DB.PreparedSQL

		return ! empty( $found );
	}

	/**
	 * Whether an index exists on a table.
	 *
	 * @param string $table Full table name.
	 * @param string $index Index name.
	 * @return bool
	 */
	public static function has_index( $table, $index ) {
		global $wpdb;

		if ( ! self::table_exists( $table ) ) {
			return false;
		}

		$found = $wpdb->get_results( $wpdb->prepare( "SHOW INDEX FROM `{$table}` WHERE Key_name = %s", $index ) ); // phpcs:ignore WordPress.DB.PreparedSQL

		return ! empty( $found );
	}

	/**
	 * DDL for the tenant profile table.
	 *
	 * One row per platform member. Keeps platform state out of usermeta so it
	 * can be reported on, joined and indexed.
	 *
	 * @return string
	 */
	public static function ddl_tenants() {
		global $wpdb;

		$table   = self::table( 'tenants' );
		$charset = $wpdb->get_charset_collate();

		return "CREATE TABLE {$table} (
			user_id bigint(20) unsigned NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'active',
			plan_key varchar(40) NOT NULL DEFAULT 'free',
			label varchar(160) NULL,
			suspended_reason varchar(255) NULL,
			meta longtext NULL,
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (user_id),
			KEY status (status),
			KEY plan_key (plan_key)
		) {$charset};";
	}

	/**
	 * DDL for the audit trail.
	 *
	 * Every cross-tenant or privileged action is recorded here. In a paid
	 * multi-user platform this is the difference between "we think" and
	 * "we know" when something goes wrong.
	 *
	 * @return string
	 */
	public static function ddl_audit() {
		global $wpdb;

		$table   = self::table( 'audit' );
		$charset = $wpdb->get_charset_collate();

		return "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			actor_id bigint(20) unsigned NOT NULL DEFAULT 0,
			action varchar(80) NOT NULL,
			object_type varchar(40) NULL,
			object_id bigint(20) unsigned NOT NULL DEFAULT 0,
			severity varchar(12) NOT NULL DEFAULT 'info',
			ip varchar(45) NULL,
			context longtext NULL,
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY user_id (user_id),
			KEY actor_id (actor_id),
			KEY action (action),
			KEY created_at (created_at)
		) {$charset};";
	}

	/**
	 * DDL for one-time password challenges.
	 *
	 * Codes are never stored in readable form — only a salted hash. A stolen
	 * database therefore cannot be replayed to log in as somebody else, and
	 * nobody with database access can read a live code out of a support
	 * screen.
	 *
	 * @return string
	 */
	public static function ddl_otp() {
		global $wpdb;

		$table   = self::table( 'otp' );
		$charset = $wpdb->get_charset_collate();

		return "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			phone varchar(15) NOT NULL,
			code_hash char(64) NOT NULL,
			purpose varchar(30) NOT NULL DEFAULT 'login',
			attempts tinyint(3) unsigned NOT NULL DEFAULT 0,
			resend_count tinyint(3) unsigned NOT NULL DEFAULT 0,
			ip varchar(45) NULL,
			user_agent varchar(255) NULL,
			consumed_at datetime NULL,
			expires_at datetime NOT NULL,
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY phone (phone),
			KEY expires_at (expires_at),
			KEY phone_purpose (phone, purpose)
		) {$charset};";
	}

	/**
	 * DDL for wallet balances.
	 *
	 * Balances are stored in whole Rial as a signed bigint, never as a float.
	 * Floating point cannot represent money exactly, and a platform that
	 * charges customers must never lose or invent a Rial to rounding.
	 *
	 * @return string
	 */
	public static function ddl_wallet() {
		global $wpdb;

		$table   = self::table( 'wallet' );
		$charset = $wpdb->get_charset_collate();

		return "CREATE TABLE {$table} (
			user_id bigint(20) unsigned NOT NULL,
			balance bigint(20) NOT NULL DEFAULT 0,
			reserved bigint(20) NOT NULL DEFAULT 0,
			lifetime_topup bigint(20) NOT NULL DEFAULT 0,
			lifetime_spend bigint(20) NOT NULL DEFAULT 0,
			updated_at datetime DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (user_id),
			KEY balance (balance)
		) {$charset};";
	}

	/**
	 * DDL for the wallet ledger.
	 *
	 * Append-only. Rows are never updated or deleted, so the balance can always
	 * be reconstructed and any discrepancy can be traced to the entry that
	 * caused it. `balance_after` is stored to make auditing cheap.
	 *
	 * `idempotency_key` is UNIQUE: it is what stops a double-submitted payment
	 * callback or a retried job from charging a customer twice.
	 *
	 * @return string
	 */
	public static function ddl_transactions() {
		global $wpdb;

		$table   = self::table( 'transactions' );
		$charset = $wpdb->get_charset_collate();

		return "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL,
			type varchar(20) NOT NULL,
			amount bigint(20) NOT NULL,
			balance_after bigint(20) NOT NULL DEFAULT 0,
			reference varchar(80) NULL,
			idempotency_key varchar(120) NULL,
			description varchar(255) NULL,
			actor_id bigint(20) unsigned NOT NULL DEFAULT 0,
			meta longtext NULL,
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY idempotency_key (idempotency_key),
			KEY user_id (user_id),
			KEY type (type),
			KEY created_at (created_at)
		) {$charset};";
	}

	/**
	 * DDL for top-up orders.
	 *
	 * The authoritative record of what a customer was asked to pay. The amount
	 * is read back from here at verification time rather than trusted from the
	 * gateway callback, so a tampered return URL cannot credit more than was
	 * actually paid.
	 *
	 * @return string
	 */
	public static function ddl_orders() {
		global $wpdb;

		$table   = self::table( 'orders' );
		$charset = $wpdb->get_charset_collate();

		return "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL,
			amount bigint(20) NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'pending',
			gateway varchar(40) NOT NULL DEFAULT 'zarinpal',
			authority varchar(120) NULL,
			ref_id varchar(60) NULL,
			card_pan varchar(30) NULL,
			fail_reason varchar(255) NULL,
			ip varchar(45) NULL,
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			paid_at datetime NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY authority (authority),
			KEY user_id (user_id),
			KEY status (status),
			KEY created_at (created_at)
		) {$charset};";
	}

	/**
	 * All core DDL statements, in creation order.
	 *
	 * @return string[]
	 */
	public static function all_core_ddl() {
		return array(
			self::ddl_tenants(),
			self::ddl_audit(),
			self::ddl_otp(),
			self::ddl_wallet(),
			self::ddl_transactions(),
			self::ddl_orders(),
		);
	}
}
