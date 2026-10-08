<?php
/**
 * Platform administration screen.
 *
 * @package Etehadyar\Admin
 */

namespace Etehadyar\Admin;

use Etehadyar\Compat\Legacy_Bridge;
use Etehadyar\Core\Audit;
use Etehadyar\Core\Capabilities;
use Etehadyar\Core\Migrator;
use Etehadyar\Core\Schema;
use Etehadyar\Core\Tenant;

defined( 'ABSPATH' ) || exit;

/**
 * Admin UI for platform status and tenant oversight.
 */
class Admin_Screen {

	/**
	 * Top-level menu slug. Submenus attach to this.
	 */
	const PAGE_SLUG = 'etehadyar-platform';

	/**
	 * Register the menu.
	 */
	public static function register() {
		add_menu_page(
			__( 'پلتفرم اتحادیار', 'etehadyar-core' ),
			__( 'پلتفرم اتحادیار', 'etehadyar-core' ),
			Capabilities::MANAGE_PLATFORM,
			self::PAGE_SLUG,
			array( __CLASS__, 'render' ),
			'dashicons-groups',
			29
		);
	}

	/**
	 * Render the screen.
	 */
	public static function render() {
		if ( ! current_user_can( Capabilities::MANAGE_PLATFORM ) ) {
			wp_die( esc_html__( 'دسترسی غیرمجاز.', 'etehadyar-core' ) );
		}

		$notice = '';

		if ( isset( $_POST['etehadyar_run_migrations'] ) && check_admin_referer( 'etehadyar_migrate' ) ) {
			$ran    = Migrator::run();
			$notice = $ran
				? sprintf(
					/* translators: %s: comma separated migration versions. */
					__( 'به‌روزرسانی انجام شد: %s', 'etehadyar-core' ),
					implode( '، ', $ran )
				)
				: __( 'همهٔ به‌روزرسانی‌ها از قبل اجرا شده بودند.', 'etehadyar-core' );
		}

		$counts  = Tenant::counts_by_status();
		$pending = Migrator::pending();
		$audit   = Audit::recent( array( 'limit' => 15 ) );

		echo '<div class="wrap" dir="rtl">';
		echo '<h1>' . esc_html__( 'پلتفرم اتحادیار — وضعیت هسته', 'etehadyar-core' ) . '</h1>';

		if ( $notice ) {
			echo '<div class="notice notice-success"><p>' . esc_html( $notice ) . '</p></div>';
		}

		self::render_status_cards( $counts, $pending );
		self::render_tables_panel();
		self::render_migration_form( $pending );
		self::render_audit_table( $audit );

		echo '</div>';
	}

	/**
	 * Summary cards.
	 *
	 * @param array $counts  Tenant counts by status.
	 * @param array $pending Pending migration versions.
	 */
	protected static function render_status_cards( $counts, $pending ) {
		$cards = array(
			__( 'کاربران فعال', 'etehadyar-core' )      => (int) ( $counts['active'] ?? 0 ),
			__( 'کاربران معلق', 'etehadyar-core' )      => (int) ( $counts['suspended'] ?? 0 ),
			__( 'در انتظار تأیید', 'etehadyar-core' )   => (int) ( $counts['pending'] ?? 0 ),
			__( 'مهاجرت‌های باقی‌مانده', 'etehadyar-core' ) => count( $pending ),
		);

		echo '<div style="display:flex;gap:16px;flex-wrap:wrap;margin:20px 0">';

		foreach ( $cards as $label => $value ) {
			printf(
				'<div style="flex:1;min-width:170px;background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:16px">
					<div style="font-size:2rem;font-weight:700;line-height:1.2">%s</div>
					<div style="color:#50575e;font-size:.9rem">%s</div>
				</div>',
				esc_html( number_format_i18n( $value ) ),
				esc_html( $label )
			);
		}

		echo '</div>';
	}

	/**
	 * Tenant-readiness of each data table.
	 */
	protected static function render_tables_panel() {
		echo '<h2>' . esc_html__( 'وضعیت جداول داده', 'etehadyar-core' ) . '</h2>';
		echo '<table class="widefat striped" style="max-width:820px"><thead><tr>';
		echo '<th>' . esc_html__( 'جدول', 'etehadyar-core' ) . '</th>';
		echo '<th>' . esc_html__( 'وجود دارد', 'etehadyar-core' ) . '</th>';
		echo '<th>' . esc_html__( 'ستون کاربر', 'etehadyar-core' ) . '</th>';
		echo '<th>' . esc_html__( 'ایندکس', 'etehadyar-core' ) . '</th>';
		echo '</tr></thead><tbody>';

		$all = array_merge( Schema::CORE_TABLES, Schema::LEGACY_TABLES );
		$yes = '<span style="color:#008a20">✔</span>';
		$no  = '<span style="color:#d63638">✕</span>';
		$na  = '<span style="color:#787c82">—</span>';

		foreach ( array_keys( $all ) as $key ) {
			$table  = Schema::table( $key );
			$exists = Schema::table_exists( $table );
			$core   = isset( Schema::CORE_TABLES[ $key ] );

			echo '<tr>';
			echo '<td><code>' . esc_html( $table ) . '</code></td>';
			echo '<td>' . wp_kses_post( $exists ? $yes : $no ) . '</td>';

			if ( $core ) {
				echo '<td>' . wp_kses_post( $na ) . '</td><td>' . wp_kses_post( $na ) . '</td>';
			} else {
				echo '<td>' . wp_kses_post( Schema::has_column( $table, 'user_id' ) ? $yes : $no ) . '</td>';
				echo '<td>' . wp_kses_post( Schema::has_index( $table, 'etehadyar_user_id' ) ? $yes : $no ) . '</td>';
			}

			echo '</tr>';
		}

		echo '</tbody></table>';

		if ( ! Legacy_Bridge::legacy_active() ) {
			echo '<p style="color:#996800">' . esc_html__( 'افزونهٔ هوش مصنوعی اتحادیار فعال نیست؛ جدول‌های قدیمی تا زمان فعال‌سازی آن ساخته نمی‌شوند.', 'etehadyar-core' ) . '</p>';
		}
	}

	/**
	 * Migration trigger.
	 *
	 * @param array $pending Pending versions.
	 */
	protected static function render_migration_form( $pending ) {
		echo '<h2>' . esc_html__( 'به‌روزرسانی پایگاه داده', 'etehadyar-core' ) . '</h2>';
		echo '<form method="post">';
		wp_nonce_field( 'etehadyar_migrate' );

		if ( $pending ) {
			printf(
				'<p>%s <code>%s</code></p>',
				esc_html__( 'مهاجرت‌های در انتظار:', 'etehadyar-core' ),
				esc_html( implode( ', ', $pending ) )
			);
		} else {
			echo '<p>' . esc_html__( 'پایگاه داده به‌روز است.', 'etehadyar-core' ) . '</p>';
		}

		submit_button( __( 'اجرای به‌روزرسانی', 'etehadyar-core' ), 'primary', 'etehadyar_run_migrations', false );
		echo '</form>';
	}

	/**
	 * Recent audit entries.
	 *
	 * @param array $audit Audit rows.
	 */
	protected static function render_audit_table( $audit ) {
		echo '<h2 style="margin-top:28px">' . esc_html__( 'رویدادهای اخیر', 'etehadyar-core' ) . '</h2>';

		if ( ! $audit ) {
			echo '<p>' . esc_html__( 'هنوز رویدادی ثبت نشده است.', 'etehadyar-core' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped" style="max-width:980px"><thead><tr>';
		echo '<th>' . esc_html__( 'زمان', 'etehadyar-core' ) . '</th>';
		echo '<th>' . esc_html__( 'رویداد', 'etehadyar-core' ) . '</th>';
		echo '<th>' . esc_html__( 'کاربر', 'etehadyar-core' ) . '</th>';
		echo '<th>' . esc_html__( 'سطح', 'etehadyar-core' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $audit as $row ) {
			$user = $row['user_id'] ? get_userdata( $row['user_id'] ) : null;

			echo '<tr>';
			echo '<td>' . esc_html( $row['created_at'] ) . '</td>';
			echo '<td><code>' . esc_html( $row['action'] ) . '</code></td>';
			echo '<td>' . esc_html( $user ? $user->display_name : '—' ) . '</td>';
			echo '<td>' . esc_html( $row['severity'] ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}
}
