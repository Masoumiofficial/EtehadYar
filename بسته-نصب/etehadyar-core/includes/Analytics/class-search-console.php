<?php
/**
 * Google Search Console client.
 *
 * @package Etehadyar\Analytics
 */

namespace Etehadyar\Analytics;

use Etehadyar\Core\Audit;
use Etehadyar\Core\Secrets;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Reads real search performance data from the Search Console API.
 *
 * Only `searchanalytics.query` is used, with the read-only scope. The plugin
 * never needs to write to a Search Console property, so asking for more would
 * be an unnecessary risk to the site owner's Google account.
 *
 * Credentials live in the encrypted Secrets store rather than plain options:
 * a refresh token is a long-lived credential to someone's Google account, and
 * `wp_options` is readable by every plugin on the site and lands in every
 * database backup.
 */
class Search_Console {

	/**
	 * OAuth scope. Read-only on purpose.
	 */
	const SCOPE = 'https://www.googleapis.com/auth/webmasters.readonly';

	/**
	 * Google's OAuth endpoints.
	 */
	const AUTH_URL  = 'https://accounts.google.com/o/oauth2/v2/auth';
	const TOKEN_URL = 'https://oauth2.googleapis.com/token';

	/**
	 * Search Console API base.
	 */
	const API_BASE = 'https://www.googleapis.com/webmasters/v3';

	/**
	 * Secret names.
	 */
	const SECRET_CLIENT_ID     = 'gsc_client_id';
	const SECRET_CLIENT_SECRET = 'gsc_client_secret';
	const SECRET_REFRESH_TOKEN = 'gsc_refresh_token';

	/**
	 * Options.
	 */
	const OPTION_SITE_URL   = 'etehadyar_gsc_site_url';
	const OPTION_CONNECTED  = 'etehadyar_gsc_connected_at';
	const OPTION_LAST_ERROR = 'etehadyar_gsc_last_error';

	/**
	 * Cache keys.
	 */
	const CACHE_PAGES = 'etehadyar_gsc_pages';
	const CACHE_TOKEN = 'etehadyar_gsc_access_token';

	/**
	 * Is the integration fully configured?
	 *
	 * @return bool
	 */
	public static function is_connected() {
		return Secrets::has( self::SECRET_CLIENT_ID )
			&& Secrets::has( self::SECRET_CLIENT_SECRET )
			&& Secrets::has( self::SECRET_REFRESH_TOKEN )
			&& '' !== (string) get_option( self::OPTION_SITE_URL, '' );
	}

	/**
	 * Build the consent URL the administrator must visit.
	 *
	 * `access_type=offline` plus `prompt=consent` is what makes Google return
	 * a refresh token. Without both, a re-authorisation returns only an access
	 * token and the connection silently dies after an hour.
	 *
	 * @param string $redirect_uri Redirect target.
	 * @param string $state        CSRF state value.
	 * @return string
	 */
	public static function consent_url( $redirect_uri, $state ) {
		$client_id = Secrets::get( self::SECRET_CLIENT_ID );

		if ( ! $client_id ) {
			return '';
		}

		// Values are passed raw: add_query_arg() encodes them itself, and
		// pre-encoding would double-escape the redirect URI and scope
		// (`%3A` becoming `%253A`), which Google rejects as a mismatch.
		return add_query_arg(
			array(
				'client_id'              => $client_id,
				'redirect_uri'           => $redirect_uri,
				'response_type'          => 'code',
				'scope'                  => self::SCOPE,
				'access_type'            => 'offline',
				'prompt'                 => 'consent',
				'include_granted_scopes' => 'true',
				'state'                  => $state,
			),
			self::AUTH_URL
		);
	}

	/**
	 * Exchange an authorisation code for tokens and store them.
	 *
	 * @param string $code         Authorisation code from Google.
	 * @param string $redirect_uri Must match the one used for consent.
	 * @return true|WP_Error
	 */
	public static function exchange_code( $code, $redirect_uri ) {
		$client_id     = Secrets::get( self::SECRET_CLIENT_ID );
		$client_secret = Secrets::get( self::SECRET_CLIENT_SECRET );

		if ( ! $client_id || ! $client_secret ) {
			return new WP_Error(
				'etehadyar_gsc_no_client',
				__( 'ابتدا شناسه و رمز کلاینت گوگل را ذخیره کنید.', 'etehadyar-core' )
			);
		}

		$response = wp_remote_post(
			self::TOKEN_URL,
			array(
				'timeout' => 20,
				'body'    => array(
					'code'          => $code,
					'client_id'     => $client_id,
					'client_secret' => $client_secret,
					'redirect_uri'  => $redirect_uri,
					'grant_type'    => 'authorization_code',
				),
			)
		);

		$parsed = self::parse_token_response( $response );

		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}

		if ( empty( $parsed['refresh_token'] ) ) {
			// Google omits the refresh token when the user has already granted
			// consent and `prompt=consent` was not sent. Without it the
			// connection would expire in an hour with no way to renew.
			return new WP_Error(
				'etehadyar_gsc_no_refresh_token',
				__( 'گوگل توکن تمدید نداد. در صفحهٔ دسترسی‌های حساب گوگل، دسترسی این برنامه را حذف کنید و دوباره وصل شوید.', 'etehadyar-core' )
			);
		}

		Secrets::set( self::SECRET_REFRESH_TOKEN, $parsed['refresh_token'] );
		update_option( self::OPTION_CONNECTED, time() );
		delete_option( self::OPTION_LAST_ERROR );

		if ( ! empty( $parsed['access_token'] ) ) {
			self::cache_access_token( $parsed );
		}

		Audit::log( 'analytics.gsc_connected', array( 'severity' => 'info' ) );

		return true;
	}

	/**
	 * Obtain a usable access token, refreshing if necessary.
	 *
	 * @return string|WP_Error
	 */
	public static function access_token() {
		$cached = get_transient( self::CACHE_TOKEN );

		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		$client_id     = Secrets::get( self::SECRET_CLIENT_ID );
		$client_secret = Secrets::get( self::SECRET_CLIENT_SECRET );
		$refresh       = Secrets::get( self::SECRET_REFRESH_TOKEN );

		if ( ! $client_id || ! $client_secret || ! $refresh ) {
			return new WP_Error(
				'etehadyar_gsc_not_connected',
				__( 'اتصال به سرچ کنسول برقرار نیست.', 'etehadyar-core' )
			);
		}

		$response = wp_remote_post(
			self::TOKEN_URL,
			array(
				'timeout' => 20,
				'body'    => array(
					'client_id'     => $client_id,
					'client_secret' => $client_secret,
					'refresh_token' => $refresh,
					'grant_type'    => 'refresh_token',
				),
			)
		);

		$parsed = self::parse_token_response( $response );

		if ( is_wp_error( $parsed ) ) {
			// `invalid_grant` means the user revoked access or the token
			// expired for good. Surface that clearly instead of retrying
			// forever against a dead credential.
			if ( 'invalid_grant' === $parsed->get_error_data() ) {
				update_option( self::OPTION_LAST_ERROR, __( 'دسترسی گوگل لغو شده است؛ لازم است دوباره وصل شوید.', 'etehadyar-core' ) );
			}

			return $parsed;
		}

		if ( empty( $parsed['access_token'] ) ) {
			return new WP_Error( 'etehadyar_gsc_no_token', __( 'گوگل توکن دسترسی نداد.', 'etehadyar-core' ) );
		}

		self::cache_access_token( $parsed );

		return $parsed['access_token'];
	}

	/**
	 * Cache an access token slightly ahead of its real expiry.
	 *
	 * @param array $parsed Token payload.
	 */
	protected static function cache_access_token( array $parsed ) {
		$expires = isset( $parsed['expires_in'] ) ? (int) $parsed['expires_in'] : 3600;

		// Expire our copy a minute early so a request never starts with a
		// token that dies mid-flight.
		set_transient( self::CACHE_TOKEN, $parsed['access_token'], max( 60, $expires - 60 ) );
	}

	/**
	 * Decode a token endpoint response.
	 *
	 * @param array|WP_Error $response HTTP response.
	 * @return array|WP_Error
	 */
	protected static function parse_token_response( $response ) {
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'etehadyar_gsc_http', $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $body ) ) {
			return new WP_Error( 'etehadyar_gsc_bad_json', __( 'پاسخ گوگل قابل خواندن نبود.', 'etehadyar-core' ) );
		}

		if ( $code >= 400 || isset( $body['error'] ) ) {
			$reason = isset( $body['error'] ) ? (string) $body['error'] : 'http_' . $code;

			return new WP_Error(
				'etehadyar_gsc_oauth_error',
				sprintf(
					/* translators: %s: error code from Google. */
					__( 'گوگل درخواست را رد کرد: %s', 'etehadyar-core' ),
					$reason
				),
				$reason
			);
		}

		return $body;
	}

	/**
	 * Fetch the top pages by clicks.
	 *
	 * @param int $days  Trailing window in days.
	 * @param int $limit Row limit.
	 * @return array|WP_Error List of rows.
	 */
	public static function top_pages( $days = 28, $limit = 10 ) {
		$token = self::access_token();

		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$site = (string) get_option( self::OPTION_SITE_URL, '' );

		if ( '' === $site ) {
			return new WP_Error( 'etehadyar_gsc_no_site', __( 'نشانی سایت در سرچ کنسول تعیین نشده است.', 'etehadyar-core' ) );
		}

		// Search Console finalises data with a lag, so the last few days are
		// incomplete. Ending the window three days back avoids showing the
		// owner a phantom traffic collapse that is only missing data.
		$end   = gmdate( 'Y-m-d', time() - ( 3 * DAY_IN_SECONDS ) );
		$start = gmdate( 'Y-m-d', time() - ( ( (int) $days + 3 ) * DAY_IN_SECONDS ) );

		$response = wp_remote_post(
			self::API_BASE . '/sites/' . rawurlencode( $site ) . '/searchAnalytics/query',
			array(
				'timeout' => 25,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'startDate'  => $start,
						'endDate'    => $end,
						'dimensions' => array( 'page' ),
						'rowLimit'   => max( 1, min( 100, (int) $limit ) ),
						'type'       => 'web',
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'etehadyar_gsc_http', $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code ) {
			$message = isset( $body['error']['message'] )
				? (string) $body['error']['message']
				: sprintf( 'HTTP %d', $code );

			update_option( self::OPTION_LAST_ERROR, $message );

			return new WP_Error( 'etehadyar_gsc_api_error', $message );
		}

		delete_option( self::OPTION_LAST_ERROR );

		return self::shape_rows( is_array( $body ) ? $body : array() );
	}

	/**
	 * Normalise API rows into the shape the dashboard consumes.
	 *
	 * @param array $body Decoded API response.
	 * @return array
	 */
	public static function shape_rows( array $body ) {
		$rows = isset( $body['rows'] ) && is_array( $body['rows'] ) ? $body['rows'] : array();
		$out  = array();

		foreach ( $rows as $row ) {
			$url = isset( $row['keys'][0] ) ? (string) $row['keys'][0] : '';

			if ( '' === $url ) {
				continue;
			}

			$out[] = array(
				'url'         => esc_url_raw( $url ),
				'title'       => self::title_for( $url ),
				'clicks'      => (int) round( (float) ( $row['clicks'] ?? 0 ) ),
				'impressions' => (int) round( (float) ( $row['impressions'] ?? 0 ) ),
				// The API returns CTR as a fraction; the UI shows a percentage.
				'ctr'         => round( ( (float) ( $row['ctr'] ?? 0 ) ) * 100, 1 ),
				'position'    => round( (float) ( $row['position'] ?? 0 ), 1 ),
			);
		}

		return $out;
	}

	/**
	 * Resolve a local post title for a URL, falling back to the path.
	 *
	 * @param string $url Page URL.
	 * @return string
	 */
	protected static function title_for( $url ) {
		$post_id = function_exists( 'url_to_postid' ) ? url_to_postid( $url ) : 0;

		if ( $post_id ) {
			$title = get_the_title( $post_id );

			if ( $title ) {
				return $title;
			}
		}

		$path = wp_parse_url( $url, PHP_URL_PATH );

		return $path ? trim( $path, '/' ) : $url;
	}

	/**
	 * Cached page data for dashboards.
	 *
	 * @param bool $force Bypass the cache.
	 * @return array|WP_Error
	 */
	public static function pages( $force = false ) {
		if ( ! $force ) {
			$cached = get_transient( self::CACHE_PAGES );

			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$rows = self::top_pages();

		if ( is_wp_error( $rows ) ) {
			return $rows;
		}

		set_transient( self::CACHE_PAGES, $rows, 6 * HOUR_IN_SECONDS );

		return $rows;
	}

	/**
	 * Forget every stored credential and cached value.
	 */
	public static function disconnect() {
		Secrets::delete( self::SECRET_REFRESH_TOKEN );
		delete_option( self::OPTION_CONNECTED );
		delete_option( self::OPTION_LAST_ERROR );
		delete_transient( self::CACHE_TOKEN );
		delete_transient( self::CACHE_PAGES );

		Audit::log( 'analytics.gsc_disconnected', array( 'severity' => 'info' ) );
	}
}
