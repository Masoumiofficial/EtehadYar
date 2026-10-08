<?php
/**
 * Ordered, idempotent migration runner.
 *
 * This deliberately replaces the pattern used by the legacy plugin, where
 * migration blocks were written in descending order and each one overwrote the
 * stored DB version — meaning only the first block ever ran on an existing
 * site. Here every migration has an integer version, migrations always run in
 * ascending order, and each applied version is recorded individually so a
 * partially-migrated site can always be brought forward safely.
 *
 * @package Etehadyar\Core
 */

namespace Etehadyar\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Versioned schema migrator.
 */
class Migrator {

	const OPTION_APPLIED = 'etehadyar_core_applied_migrations';
	const OPTION_VERSION = 'etehadyar_core_db_version';
	const OPTION_LOCK    = 'etehadyar_core_migration_lock';

	/**
	 * Registry of migrations: version => callable.
	 *
	 * @return array<int, callable>
	 */
	public static function registry() {
		$migrations = array(
			100 => array( __CLASS__, 'migration_100_core_tables' ),
			110 => array( __CLASS__, 'migration_110_tenant_columns' ),
			120 => array( __CLASS__, 'migration_120_backfill_owner' ),
			130 => array( __CLASS__, 'migration_130_otp_table' ),
			140 => array( __CLASS__, 'migration_140_wallet_tables' ),
			150 => array( __CLASS__, 'migration_150_support_audio_columns' ),
		);

		ksort( $migrations, SORT_NUMERIC );

		return $migrations;
	}

	/**
	 * Run every pending migration in ascending order.
	 *
	 * @return int[] Versions applied during this run.
	 */
	public static function run() {
		if ( get_transient( self::OPTION_LOCK ) ) {
			return array();
		}

		set_transient( self::OPTION_LOCK, 1, 5 * MINUTE_IN_SECONDS );

		$applied = self::applied();
		$ran     = array();

		try {
			foreach ( self::registry() as $version => $callback ) {
				if ( in_array( (int) $version, $applied, true ) ) {
					continue;
				}

				if ( ! is_callable( $callback ) ) {
					continue;
				}

				call_user_func( $callback );

				$applied[] = (int) $version;
				$ran[]     = (int) $version;

				update_option( self::OPTION_APPLIED, array_values( array_unique( $applied ) ), false );
				update_option( self::OPTION_VERSION, max( $applied ), false );
			}
		} finally {
			delete_transient( self::OPTION_LOCK );
		}

		return $ran;
	}

	/**
	 * Versions already applied.
	 *
	 * @return int[]
	 */
	public static function applied() {
		$applied = get_option( self::OPTION_APPLIED, array() );

		if ( ! is_array( $applied ) ) {
			$applied = array();
		}

		return array_map( 'intval', $applied );
	}

	/**
	 * Versions still pending.
	 *
	 * @return int[]
	 */
	public static function pending() {
		return array_values( array_diff( array_keys( self::registry() ), self::applied() ) );
	}

	/**
	 * Create the core platform tables.
	 */
	public static function migration_100_core_tables() {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		foreach ( Schema::all_core_ddl() as $ddl ) {
			dbDelta( $ddl );
		}
	}

	/**
	 * Make the inherited AI tables tenant-aware.
	 *
	 * Adds `user_id` plus an index to every legacy table that stores
	 * user-generated data. Safe to run on a site where the legacy plugin was
	 * never installed: missing tables are simply skipped and the migration is
	 * re-evaluated by migration 111 style follow-ups if needed.
	 */
	public static function migration_110_tenant_columns() {
		global $wpdb;

		foreach ( array_keys( Schema::LEGACY_TABLES ) as $key ) {
			$table = Schema::table( $key );

			if ( ! Schema::table_exists( $table ) ) {
				continue;
			}

			if ( ! Schema::has_column( $table, 'user_id' ) ) {
				// phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery
				$wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN `user_id` bigint(20) unsigned NOT NULL DEFAULT 0" );
			}

			if ( ! Schema::has_index( $table, 'etehadyar_user_id' ) ) {
				// phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery
				$wpdb->query( "ALTER TABLE `{$table}` ADD INDEX `etehadyar_user_id` (`user_id`)" );
			}
		}
	}

	/**
	 * Assign pre-existing legacy rows to the platform owner.
	 *
	 * Data created before the platform existed belongs to whoever ran the site.
	 * Leaving it at user_id = 0 would make it globally visible, so it is
	 * explicitly claimed by the configured owner account.
	 */
	public static function migration_120_backfill_owner() {
		global $wpdb;

		$owner = Installer::platform_owner_id();

		if ( ! $owner ) {
			return;
		}

		foreach ( array( 'jobs', 'usage', 'chat_logs', 'support' ) as $key ) {
			$table = Schema::table( $key );

			if ( ! Schema::table_exists( $table ) || ! Schema::has_column( $table, 'user_id' ) ) {
				continue;
			}

			// phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery
			$wpdb->query( $wpdb->prepare( "UPDATE `{$table}` SET user_id = %d WHERE user_id = 0", $owner ) );
		}

		/*
		 * Vectors intentionally stay at user_id = 0: the site knowledge base is
		 * platform-global content, not the private property of one member.
		 */
	}

	/**
	 * Create the one-time password table used by mobile sign-in.
	 */
	public static function migration_130_otp_table() {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta( Schema::ddl_otp() );
	}

	/**
	 * Create the wallet, ledger and order tables.
	 */
	public static function migration_140_wallet_tables() {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta( Schema::ddl_wallet() );
		dbDelta( Schema::ddl_transactions() );
		dbDelta( Schema::ddl_orders() );
	}

	/**
	 * Add the support columns the legacy plugin writes to but never created.
	 *
	 * `EAIW_Support_Request::create()` inserts an `audio_url` value and
	 * `update_transcript()` updates a `transcript` column, but neither
	 * appears in any CREATE TABLE statement in the plugin. Every voice
	 * support request therefore fails at the database layer: the row is
	 * rejected, the caller still gets a ticket id back, and the audio the
	 * customer recorded is lost. Adding the columns makes the existing code
	 * work instead of rewriting it.
	 */
	public static function migration_150_support_audio_columns() {
		global $wpdb;

		$table = Schema::table( 'support' );

		if ( ! Schema::table_exists( $table ) ) {
			// The legacy plugin is not installed; nothing to repair.
			return;
		}

		if ( ! Schema::has_column( $table, 'audio_url' ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			$wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN `audio_url` varchar(500) NOT NULL DEFAULT ''" );
		}

		if ( ! Schema::has_column( $table, 'transcript' ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			$wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN `transcript` longtext NULL" );
		}
	}
}
