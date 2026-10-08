<?php
/**
 * REST endpoints backing the front-end studio.
 *
 * @package Etehadyar\Rest
 */

namespace Etehadyar\Rest;

use Etehadyar\Core\Capabilities;
use Etehadyar\Core\Schema;
use Etehadyar\Core\Tenant;
use WP_Error;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * The legacy plugin exposes `eaiw_job_status`, but it demands
 * `manage_options` — so a member can never poll the queued job they just paid
 * for. Worse, `Job_Queue::status()` looks a job up by id with no ownership
 * test, so simply relaxing that capability would let any customer read any
 * other customer's job payload and result.
 *
 * This controller is the member-safe replacement: it reads the same table but
 * scopes every query to the caller's own `user_id`, the column the tenant
 * migration added and Legacy_Bridge stamps onto new rows.
 */
class Studio_Controller {

	const NAMESPACE_V1 = 'etehadyar/v1';

	/**
	 * Register routes.
	 *
	 * Called from Plugin::register_rest() on `rest_api_init`, matching the
	 * other controllers — registering its own hook here would double-register
	 * every route.
	 */
	public static function register_routes() {
		register_rest_route(
			self::NAMESPACE_V1,
			'/studio/job/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'job' ),
				'permission_callback' => array( __CLASS__, 'can_use' ),
				'args'                => array(
					'id' => array(
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/studio/operations',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'operations' ),
				'permission_callback' => array( __CLASS__, 'can_use' ),
			)
		);
	}

	/**
	 * Only active members may reach the studio.
	 *
	 * @return bool|WP_Error
	 */
	public static function can_use() {
		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'etehadyar_not_logged_in',
				__( 'برای استفاده از این بخش وارد شوید.', 'etehadyar-core' ),
				array( 'status' => 401 )
			);
		}

		if ( ! current_user_can( Capabilities::USE_PLATFORM ) ) {
			return new WP_Error(
				'etehadyar_forbidden',
				__( 'شما به این بخش دسترسی ندارید.', 'etehadyar-core' ),
				array( 'status' => 403 )
			);
		}

		if ( ! Tenant::is_active( get_current_user_id() ) ) {
			return new WP_Error(
				'etehadyar_suspended',
				__( 'حساب شما موقتاً غیرفعال است.', 'etehadyar-core' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Status of one of the caller's own jobs.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function job( $request ) {
		global $wpdb;

		$id    = absint( $request['id'] );
		$table = Schema::table( 'jobs' );

		if ( ! Schema::table_exists( $table ) ) {
			return new WP_Error(
				'etehadyar_no_queue',
				__( 'صف کارها در دسترس نیست.', 'etehadyar-core' ),
				array( 'status' => 503 )
			);
		}

		$user_id = get_current_user_id();

		// The ownership condition is part of the lookup, not a check applied
		// to the result: a job belonging to someone else is simply not found.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, job_type, status, attempts, result, error_text, created_at, finished_at
				 FROM `{$table}` WHERE id = %d AND user_id = %d",
				$id,
				$user_id
			),
			ARRAY_A
		);

		if ( ! $row ) {
			return new WP_Error(
				'etehadyar_job_not_found',
				__( 'کار پیدا نشد.', 'etehadyar-core' ),
				array( 'status' => 404 )
			);
		}

		return new WP_REST_Response( self::shape( $row ) );
	}

	/**
	 * Normalise a queue row for the browser.
	 *
	 * @param array $row Raw row.
	 * @return array
	 */
	protected static function shape( $row ) {
		$result = null;

		if ( ! empty( $row['result'] ) ) {
			$decoded = json_decode( (string) $row['result'], true );
			$result  = is_array( $decoded ) ? $decoded : null;
		}

		$status = (string) $row['status'];

		// Only ever hand back the fields the UI renders. The raw payload can
		// contain provider responses and internal traces.
		$safe = array();

		foreach ( array( 'url', 'title', 'html', 'words', 'attachment_id', 'mode', 'note' ) as $key ) {
			if ( isset( $result[ $key ] ) ) {
				$safe[ $key ] = $result[ $key ];
			}
		}

		return array(
			'id'          => (int) $row['id'],
			'type'        => (string) $row['job_type'],
			'status'      => $status,
			'is_final'    => in_array( $status, array( 'completed', 'failed' ), true ),
			'attempts'    => (int) $row['attempts'],
			'error'       => $row['error_text'] ? (string) $row['error_text'] : '',
			'result'      => $safe,
			'created_at'  => (string) $row['created_at'],
			'finished_at' => $row['finished_at'] ? (string) $row['finished_at'] : '',
		);
	}

	/**
	 * Operations the caller may run, with current prices.
	 *
	 * Drives the studio UI so the tool list and the price list can never
	 * drift apart.
	 *
	 * @return WP_REST_Response
	 */
	public static function operations() {
		return new WP_REST_Response(
			array( 'operations' => \Etehadyar\Frontend\Studio_Shortcode::available_tools() )
		);
	}
}
