<?php
/**
 * Semantic index management screen.
 *
 * @package Etehadyar\Admin
 */

namespace Etehadyar\Admin;

use Etehadyar\Brain\Embeddings;
use Etehadyar\Brain\Indexer;
use Etehadyar\Brain\Semantic_Search;
use Etehadyar\Core\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Lets an administrator build and inspect the semantic index.
 */
class Brain_Screen {

	/**
	 * Menu slug.
	 */
	const PAGE_SLUG = 'etehadyar-brain';

	/**
	 * Register the submenu.
	 */
	public static function register() {
		add_submenu_page(
			Admin_Screen::PAGE_SLUG,
			__( 'حافظهٔ معنایی', 'etehadyar-core' ),
			__( 'حافظهٔ معنایی', 'etehadyar-core' ),
			Capabilities::MANAGE_PLATFORM,
			self::PAGE_SLUG,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Handle form posts.
	 */
	public static function handle_post() {
		if ( ! is_admin() || empty( $_POST['etehadyar_brain_submit'] ) ) {
			return;
		}

		if ( ! current_user_can( Capabilities::MANAGE_PLATFORM ) ) {
			wp_die( esc_html__( 'دسترسی غیرمجاز.', 'etehadyar-core' ) );
		}

		check_admin_referer( 'etehadyar_brain' );

		$action = sanitize_key( wp_unslash( $_POST['etehadyar_brain_submit'] ) );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified above.
		if ( 'save' === $action ) {
			$model = sanitize_text_field( wp_unslash( $_POST['embedding_model'] ?? '' ) );
			$dims  = absint( wp_unslash( $_POST['embedding_dims'] ?? 0 ) );
			$url   = esc_url_raw( wp_unslash( $_POST['embedding_endpoint'] ?? '' ) );

			if ( '' !== $model ) {
				update_option( Embeddings::OPTION_MODEL, $model );
			}

			if ( $dims > 0 ) {
				update_option( Embeddings::OPTION_DIMS, $dims );
			}

			if ( '' !== $url ) {
				update_option( Embeddings::OPTION_ENDPOINT, $url );
			}

			self::redirect( 'saved' );
		}

		if ( 'purge' === $action ) {
			Indexer::purge_vectors();
			self::redirect( 'purged' );
		}

		if ( 'embed' === $action ) {
			$result = Indexer::embed_pending( 20 );

			if ( is_wp_error( $result ) ) {
				update_option( 'etehadyar_brain_last_error', $result->get_error_message(), false );
				self::redirect( 'embed_failed' );
			}

			delete_option( 'etehadyar_brain_last_error' );
			self::redirect( $result['done'] ? 'embed_done' : 'embed_progress' );
		}
		// phpcs:enable
	}

	/**
	 * Redirect back with a status flag.
	 *
	 * @param string $status Status key.
	 */
	protected static function redirect( $status ) {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'             => self::PAGE_SLUG,
					'etehadyar_status' => $status,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Render the screen.
	 */
	public static function render() {
		if ( ! current_user_can( Capabilities::MANAGE_PLATFORM ) ) {
			wp_die( esc_html__( 'دسترسی غیرمجاز.', 'etehadyar-core' ) );
		}

		$available = Embeddings::is_available();
		$indexed   = Indexer::indexed_count();
		$stale     = Indexer::index_is_stale();
		$error     = (string) get_option( 'etehadyar_brain_last_error', '' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only notice.
		$status    = isset( $_GET['etehadyar_status'] ) ? sanitize_key( wp_unslash( $_GET['etehadyar_status'] ) ) : '';
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'حافظهٔ معنایی سایت', 'etehadyar-core' ); ?></h1>

			<?php if ( 'embed_done' === $status ) : ?>
				<div class="notice notice-success"><p><?php esc_html_e( 'ساخت ایندکس کامل شد.', 'etehadyar-core' ); ?></p></div>
			<?php elseif ( 'embed_progress' === $status ) : ?>
				<div class="notice notice-info"><p><?php esc_html_e( 'یک دسته پردازش شد. برای ادامه دوباره بزنید.', 'etehadyar-core' ); ?></p></div>
			<?php elseif ( 'embed_failed' === $status ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( $error ? $error : __( 'ساخت ایندکس ناموفق بود.', 'etehadyar-core' ) ); ?></p></div>
			<?php elseif ( 'purged' === $status ) : ?>
				<div class="notice notice-success"><p><?php esc_html_e( 'بردارهای قدیمی پاک شدند.', 'etehadyar-core' ); ?></p></div>
			<?php elseif ( 'saved' === $status ) : ?>
				<div class="notice notice-success"><p><?php esc_html_e( 'تنظیمات ذخیره شد.', 'etehadyar-core' ); ?></p></div>
			<?php endif; ?>

			<div class="notice notice-warning inline">
				<p>
					<strong><?php esc_html_e( 'دربارهٔ نسخه‌های پیشین:', 'etehadyar-core' ); ?></strong>
					<?php esc_html_e( 'بردارهای «معنایی» قبلی از md5 ساخته می‌شدند. افزودن یک نقطه به جمله، شباهت را به اندازهٔ یک موضوع کاملاً بی‌ربط کاهش می‌داد؛ یعنی جست‌وجوی معنایی عملاً فقط متن کاملاً یکسان را پیدا می‌کرد. اگر ایندکس قدیمی دارید، یک‌بار آن را پاک و دوباره بسازید.', 'etehadyar-core' ); ?>
				</p>
			</div>

			<h2><?php esc_html_e( 'وضعیت', 'etehadyar-core' ); ?></h2>
			<table class="widefat striped" style="max-width:640px">
				<tr>
					<td><?php esc_html_e( 'سرویس بردارسازی', 'etehadyar-core' ); ?></td>
					<td>
						<?php if ( $available ) : ?>
							<span style="color:#008a20;font-weight:600;"><?php esc_html_e( 'آماده', 'etehadyar-core' ); ?></span>
						<?php else : ?>
							<span style="color:#d63638;font-weight:600;"><?php esc_html_e( 'کلید API تنظیم نشده — جست‌وجو کلیدواژه‌ای خواهد بود', 'etehadyar-core' ); ?></span>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<td><?php esc_html_e( 'تکه‌های بردارشده', 'etehadyar-core' ); ?></td>
					<td><?php echo esc_html( number_format_i18n( $indexed ) ); ?></td>
				</tr>
				<tr>
					<td><?php esc_html_e( 'وضعیت ایندکس', 'etehadyar-core' ); ?></td>
					<td>
						<?php if ( $stale ) : ?>
							<span style="color:#d63638;font-weight:600;"><?php esc_html_e( 'کهنه یا ناسازگار — نیاز به بازسازی', 'etehadyar-core' ); ?></span>
						<?php else : ?>
							<span style="color:#008a20;font-weight:600;"><?php esc_html_e( 'سازگار با مدل فعلی', 'etehadyar-core' ); ?></span>
						<?php endif; ?>
					</td>
				</tr>
			</table>

			<form method="post" action="">
				<?php wp_nonce_field( 'etehadyar_brain' ); ?>
				<h2><?php esc_html_e( 'تنظیمات مدل', 'etehadyar-core' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="embedding_model"><?php esc_html_e( 'مدل', 'etehadyar-core' ); ?></label></th>
						<td><input name="embedding_model" id="embedding_model" type="text" class="regular-text" dir="ltr"
							value="<?php echo esc_attr( Embeddings::model() ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="embedding_dims"><?php esc_html_e( 'ابعاد بردار', 'etehadyar-core' ); ?></label></th>
						<td>
							<input name="embedding_dims" id="embedding_dims" type="number" min="64" max="3072" class="small-text"
								value="<?php echo esc_attr( (string) Embeddings::dimensions() ); ?>" />
							<p class="description"><?php esc_html_e( 'تغییر این مقدار ایندکس فعلی را ناسازگار می‌کند و باید بازسازی شود.', 'etehadyar-core' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="embedding_endpoint"><?php esc_html_e( 'نشانی سرویس', 'etehadyar-core' ); ?></label></th>
						<td>
							<input name="embedding_endpoint" id="embedding_endpoint" type="text" class="large-text" dir="ltr"
								value="<?php echo esc_attr( Embeddings::endpoint() ); ?>" />
							<p class="description"><?php esc_html_e( 'برای سرویس‌های سازگار با OpenAI (مانند گپ‌جی‌پی‌تی) قابل تغییر است.', 'etehadyar-core' ); ?></p>
						</td>
					</tr>
				</table>

				<p class="submit">
					<button type="submit" name="etehadyar_brain_submit" value="save" class="button"><?php esc_html_e( 'ذخیره', 'etehadyar-core' ); ?></button>
					<button type="submit" name="etehadyar_brain_submit" value="embed" class="button button-primary" <?php disabled( ! $available ); ?>>
						<?php esc_html_e( 'ساخت ایندکس (یک دسته)', 'etehadyar-core' ); ?>
					</button>
					<button type="submit" name="etehadyar_brain_submit" value="purge" class="button button-link-delete">
						<?php esc_html_e( 'پاک‌کردن بردارهای قدیمی', 'etehadyar-core' ); ?>
					</button>
				</p>
			</form>
		</div>
		<?php
	}
}
