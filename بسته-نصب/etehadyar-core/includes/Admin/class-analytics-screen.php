<?php
/**
 * Search Console connection screen.
 *
 * @package Etehadyar\Admin
 */

namespace Etehadyar\Admin;

use Etehadyar\Analytics\SEO_Insights;
use Etehadyar\Analytics\Search_Console;
use Etehadyar\Core\Capabilities;
use Etehadyar\Core\Secrets;

defined( 'ABSPATH' ) || exit;

/**
 * Lets an administrator connect the site to Google Search Console.
 */
class Analytics_Screen {

	/**
	 * Menu slug.
	 */
	const PAGE_SLUG = 'etehadyar-analytics';

	/**
	 * Register the submenu.
	 */
	public static function register() {
		add_submenu_page(
			Admin_Screen::PAGE_SLUG,
			__( 'اتصال به گوگل', 'etehadyar-core' ),
			__( 'اتصال به گوگل', 'etehadyar-core' ),
			Capabilities::MANAGE_PLATFORM,
			self::PAGE_SLUG,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * The redirect URI registered with Google.
	 *
	 * @return string
	 */
	public static function redirect_uri() {
		return admin_url( 'admin.php?page=' . self::PAGE_SLUG );
	}

	/**
	 * Handle form posts and the OAuth return leg.
	 */
	public static function handle_post() {
		if ( ! is_admin() ) {
			return;
		}

		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

		if ( self::PAGE_SLUG !== $page ) {
			return;
		}

		if ( ! current_user_can( Capabilities::MANAGE_PLATFORM ) ) {
			return;
		}

		// --- OAuth return leg -------------------------------------------
		if ( isset( $_GET['code'] ) || isset( $_GET['error'] ) ) {
			self::handle_callback();
			return;
		}

		if ( empty( $_POST['etehadyar_analytics_submit'] ) ) {
			return;
		}

		check_admin_referer( 'etehadyar_analytics' );

		$action = sanitize_key( wp_unslash( $_POST['etehadyar_analytics_submit'] ) );

		if ( 'disconnect' === $action ) {
			Search_Console::disconnect();
			self::redirect( 'disconnected' );
			return;
		}

		if ( 'refresh' === $action ) {
			$rows = Search_Console::pages( true );
			self::redirect( is_wp_error( $rows ) ? 'fetch_failed' : 'refreshed' );
			return;
		}

		// --- Save credentials -------------------------------------------
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified above.
		$client_id = sanitize_text_field( wp_unslash( $_POST['gsc_client_id'] ?? '' ) );

		if ( '' !== $client_id ) {
			Secrets::set( Search_Console::SECRET_CLIENT_ID, $client_id );
		}

		// An empty secret field means "leave the stored value alone", so the
		// admin can re-save the site URL without retyping the secret.
		$client_secret = trim( (string) wp_unslash( $_POST['gsc_client_secret'] ?? '' ) );

		if ( '' !== $client_secret ) {
			Secrets::set( Search_Console::SECRET_CLIENT_SECRET, $client_secret );
		}

		update_option(
			Search_Console::OPTION_SITE_URL,
			esc_url_raw( wp_unslash( $_POST['gsc_site_url'] ?? '' ) )
		);
		// phpcs:enable

		if ( 'connect' === $action ) {
			$state = wp_create_nonce( 'etehadyar_gsc_state' );
			update_option( 'etehadyar_gsc_state', $state, false );

			$url = Search_Console::consent_url( self::redirect_uri(), $state );

			if ( '' === $url ) {
				self::redirect( 'missing_client' );
				return;
			}

			wp_redirect( $url );
			exit;
		}

		self::redirect( 'saved' );
	}

	/**
	 * Process Google's redirect back to the site.
	 */
	protected static function handle_callback() {
		if ( isset( $_GET['error'] ) ) {
			self::redirect( 'denied' );
			return;
		}

		// The state value proves this redirect follows a request this admin
		// actually started, rather than a link someone sent them.
		$state    = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		$expected = (string) get_option( 'etehadyar_gsc_state', '' );

		if ( '' === $state || '' === $expected || ! hash_equals( $expected, $state ) ) {
			self::redirect( 'bad_state' );
			return;
		}

		delete_option( 'etehadyar_gsc_state' );

		$code   = sanitize_text_field( wp_unslash( $_GET['code'] ) );
		$result = Search_Console::exchange_code( $code, self::redirect_uri() );

		if ( is_wp_error( $result ) ) {
			update_option( Search_Console::OPTION_LAST_ERROR, $result->get_error_message() );
			self::redirect( 'exchange_failed' );
			return;
		}

		self::redirect( 'connected' );
	}

	/**
	 * Redirect back to the screen with a status flag.
	 *
	 * @param string $status Status key.
	 */
	protected static function redirect( $status ) {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'              => self::PAGE_SLUG,
					'etehadyar_status'  => $status,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Human text for a status key.
	 *
	 * @param string $status Status key.
	 * @return array{type:string,text:string}|null
	 */
	public static function status_message( $status ) {
		$map = array(
			'connected'       => array( 'success', __( 'اتصال برقرار شد. از این پس آمار واقعی نمایش داده می‌شود.', 'etehadyar-core' ) ),
			'disconnected'    => array( 'info', __( 'اتصال قطع شد و توکن ذخیره‌شده پاک شد.', 'etehadyar-core' ) ),
			'saved'           => array( 'success', __( 'تنظیمات ذخیره شد.', 'etehadyar-core' ) ),
			'refreshed'       => array( 'success', __( 'داده‌ها از گوگل تازه‌سازی شد.', 'etehadyar-core' ) ),
			'denied'          => array( 'error', __( 'دسترسی در گوگل تأیید نشد.', 'etehadyar-core' ) ),
			'bad_state'       => array( 'error', __( 'اعتبارسنجی بازگشت از گوگل ناموفق بود؛ دوباره تلاش کنید.', 'etehadyar-core' ) ),
			'exchange_failed' => array( 'error', __( 'تبادل توکن با گوگل ناموفق بود.', 'etehadyar-core' ) ),
			'fetch_failed'    => array( 'error', __( 'دریافت داده از گوگل ناموفق بود.', 'etehadyar-core' ) ),
			'missing_client'  => array( 'error', __( 'ابتدا شناسه و رمز کلاینت را وارد کنید.', 'etehadyar-core' ) ),
		);

		if ( ! isset( $map[ $status ] ) ) {
			return null;
		}

		return array(
			'type' => $map[ $status ][0],
			'text' => $map[ $status ][1],
		);
	}

	/**
	 * Render the screen.
	 */
	public static function render() {
		if ( ! current_user_can( Capabilities::MANAGE_PLATFORM ) ) {
			wp_die( esc_html__( 'دسترسی غیرمجاز.', 'etehadyar-core' ) );
		}

		$connected = Search_Console::is_connected();
		$site_url  = (string) get_option( Search_Console::OPTION_SITE_URL, '' );
		$error     = (string) get_option( Search_Console::OPTION_LAST_ERROR, '' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only notice.
		$status    = isset( $_GET['etehadyar_status'] ) ? sanitize_key( wp_unslash( $_GET['etehadyar_status'] ) ) : '';
		$message   = $status ? self::status_message( $status ) : null;
		$insights  = SEO_Insights::get();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'اتصال به گوگل سرچ کنسول', 'etehadyar-core' ); ?></h1>

			<?php if ( $message ) : ?>
				<div class="notice notice-<?php echo esc_attr( 'error' === $message['type'] ? 'error' : $message['type'] ); ?>">
					<p><?php echo esc_html( $message['text'] ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( '' !== $error ) : ?>
				<div class="notice notice-warning">
					<p><strong><?php esc_html_e( 'آخرین خطای گوگل:', 'etehadyar-core' ); ?></strong> <?php echo esc_html( $error ); ?></p>
				</div>
			<?php endif; ?>

			<div class="notice notice-info inline">
				<p>
					<?php esc_html_e( 'تا زمانی که این اتصال برقرار نباشد، بخش سئو هیچ عددی دربارهٔ کلیک، بازدید یا جایگاه نشان نمی‌دهد. پیش‌تر در این بخش اعداد ساختگی نمایش داده می‌شد؛ آن رفتار حذف شده است.', 'etehadyar-core' ); ?>
				</p>
			</div>

			<h2><?php esc_html_e( 'وضعیت', 'etehadyar-core' ); ?></h2>
			<p>
				<?php if ( $connected ) : ?>
					<span style="color:#008a20;font-weight:600;">●</span>
					<?php esc_html_e( 'متصل — داده‌های نمایش‌داده‌شده واقعی هستند.', 'etehadyar-core' ); ?>
				<?php else : ?>
					<span style="color:#d63638;font-weight:600;">●</span>
					<?php esc_html_e( 'متصل نیست — هیچ آمار ترافیکی نمایش داده نمی‌شود.', 'etehadyar-core' ); ?>
				<?php endif; ?>
			</p>

			<form method="post" action="">
				<?php wp_nonce_field( 'etehadyar_analytics' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="gsc_site_url"><?php esc_html_e( 'نشانی پراپرتی', 'etehadyar-core' ); ?></label></th>
						<td>
							<input name="gsc_site_url" id="gsc_site_url" type="text" class="regular-text" dir="ltr"
								value="<?php echo esc_attr( $site_url ); ?>"
								placeholder="<?php echo esc_attr( trailingslashit( home_url() ) ); ?>" />
							<p class="description">
								<?php esc_html_e( 'دقیقاً همان‌طور که در سرچ کنسول ثبت شده. برای پراپرتی دامنه‌ای از قالب sc-domain:example.com استفاده کنید.', 'etehadyar-core' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="gsc_client_id"><?php esc_html_e( 'شناسهٔ کلاینت', 'etehadyar-core' ); ?></label></th>
						<td>
							<input name="gsc_client_id" id="gsc_client_id" type="text" class="regular-text" dir="ltr"
								value="<?php echo esc_attr( Secrets::get( Search_Console::SECRET_CLIENT_ID ) ); ?>" />
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="gsc_client_secret"><?php esc_html_e( 'رمز کلاینت', 'etehadyar-core' ); ?></label></th>
						<td>
							<input name="gsc_client_secret" id="gsc_client_secret" type="password" class="regular-text" dir="ltr"
								autocomplete="new-password"
								placeholder="<?php echo Secrets::has( Search_Console::SECRET_CLIENT_SECRET ) ? esc_attr__( '— ذخیره شده —', 'etehadyar-core' ) : ''; ?>" />
							<p class="description">
								<?php esc_html_e( 'رمزنگاری‌شده ذخیره می‌شود. برای حفظ مقدار فعلی، این فیلد را خالی بگذارید.', 'etehadyar-core' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'نشانی بازگشت', 'etehadyar-core' ); ?></th>
						<td>
							<code dir="ltr"><?php echo esc_html( self::redirect_uri() ); ?></code>
							<p class="description"><?php esc_html_e( 'این نشانی را در بخش Authorized redirect URIs کنسول گوگل ثبت کنید.', 'etehadyar-core' ); ?></p>
						</td>
					</tr>
				</table>

				<p class="submit">
					<button type="submit" name="etehadyar_analytics_submit" value="save" class="button">
						<?php esc_html_e( 'ذخیره', 'etehadyar-core' ); ?>
					</button>
					<button type="submit" name="etehadyar_analytics_submit" value="connect" class="button button-primary">
						<?php esc_html_e( 'ذخیره و اتصال به گوگل', 'etehadyar-core' ); ?>
					</button>
					<?php if ( $connected ) : ?>
						<button type="submit" name="etehadyar_analytics_submit" value="refresh" class="button">
							<?php esc_html_e( 'تازه‌سازی داده', 'etehadyar-core' ); ?>
						</button>
						<button type="submit" name="etehadyar_analytics_submit" value="disconnect" class="button button-link-delete">
							<?php esc_html_e( 'قطع اتصال', 'etehadyar-core' ); ?>
						</button>
					<?php endif; ?>
				</p>
			</form>

			<h2><?php esc_html_e( 'پیش‌نمایش داده', 'etehadyar-core' ); ?></h2>
			<p><em><?php echo esc_html( $insights['notice'] ); ?></em></p>

			<?php if ( 'search_console' === $insights['source'] && ! empty( $insights['rows'] ) ) : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'صفحه', 'etehadyar-core' ); ?></th>
							<th><?php esc_html_e( 'کلیک', 'etehadyar-core' ); ?></th>
							<th><?php esc_html_e( 'نمایش', 'etehadyar-core' ); ?></th>
							<th><?php esc_html_e( 'نرخ کلیک', 'etehadyar-core' ); ?></th>
							<th><?php esc_html_e( 'جایگاه', 'etehadyar-core' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $insights['rows'] as $row ) : ?>
							<tr>
								<td><a href="<?php echo esc_url( $row['url'] ); ?>" target="_blank" rel="noreferrer"><?php echo esc_html( $row['title'] ); ?></a></td>
								<td><?php echo esc_html( number_format_i18n( $row['clicks'] ) ); ?></td>
								<td><?php echo esc_html( number_format_i18n( $row['impressions'] ) ); ?></td>
								<td><?php echo esc_html( $row['ctr'] ); ?>%</td>
								<td><?php echo esc_html( $row['position'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}
}
