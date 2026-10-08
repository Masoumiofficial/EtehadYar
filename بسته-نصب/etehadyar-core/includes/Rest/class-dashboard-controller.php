<?php
/**
 * REST API for the member dashboard.
 *
 * Every route is authenticated and tenant-scoped. There is no public endpoint
 * here by design: the legacy plugin exposed `permission_callback => __return_true`
 * routes that any anonymous visitor could hammer, and that pattern is not
 * carried forward.
 *
 * @package Etehadyar\Rest
 */

namespace Etehadyar\Rest;

use Etehadyar\Core\Audit;
use Etehadyar\Core\Capabilities;
use Etehadyar\Core\Tenant;
use Etehadyar\Core\Tenant_Context;

defined( 'ABSPATH' ) || exit;

/**
 * Dashboard controller.
 */
class Dashboard_Controller {

	const NAMESPACE_V1 = 'etehadyar/v1';

	/**
	 * Register routes.
	 */
	public function register_routes() {
		register_rest_route(
			self::NAMESPACE_V1,
			'/me',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_me' ),
				'permission_callback' => array( $this, 'can_use_platform' ),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/jobs',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_jobs' ),
				'permission_callback' => array( $this, 'can_use_platform' ),
				'args'                => array(
					'page'     => array(
						'type'              => 'integer',
						'default'           => 1,
						'sanitize_callback' => 'absint',
					),
					'per_page' => array(
						'type'              => 'integer',
						'default'           => 20,
						'sanitize_callback' => 'absint',
					),
					'status'   => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/jobs/(?P<id>\d+)',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_job' ),
				'permission_callback' => array( $this, 'can_use_platform' ),
				'args'                => array(
					'id' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/usage',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_usage' ),
				'permission_callback' => array( $this, 'can_view_billing' ),
				'args'                => array(
					'days' => array(
						'type'              => 'integer',
						'default'           => 30,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
	}

	/**
	 * Gate: must be an active platform member.
	 *
	 * @return bool|\WP_Error
	 */
	public function can_use_platform() {
		if ( ! is_user_logged_in() ) {
			return new \WP_Error(
				'etehadyar_unauthenticated',
				__( 'برای دسترسی باید وارد حساب کاربری شوید.', 'etehadyar-core' ),
				array( 'status' => 401 )
			);
		}

		if ( ! current_user_can( Capabilities::USE_PLATFORM ) ) {
			return new \WP_Error(
				'etehadyar_forbidden',
				__( 'حساب شما به پلتفرم دسترسی ندارد.', 'etehadyar-core' ),
				array( 'status' => 403 )
			);
		}

		$user_id = get_current_user_id();

		// An admin without a tenant row is still legitimate; provision lazily.
		Tenant::ensure( $user_id );

		if ( ! Tenant::is_active( $user_id ) ) {
			return new \WP_Error(
				'etehadyar_tenant_inactive',
				__( 'حساب کاربری شما فعال نیست. با پشتیبانی تماس بگیرید.', 'etehadyar-core' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Gate: billing surfaces.
	 *
	 * @return bool|\WP_Error
	 */
	public function can_view_billing() {
		$base = $this->can_use_platform();

		if ( is_wp_error( $base ) ) {
			return $base;
		}

		if ( ! current_user_can( Capabilities::VIEW_BILLING ) ) {
			return new \WP_Error(
				'etehadyar_forbidden',
				__( 'دسترسی به اطلاعات مالی ندارید.', 'etehadyar-core' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * GET /me
	 *
	 * @return \WP_REST_Response
	 */
	public function get_me() {
		$user_id = Tenant_Context::current_id();
		$tenant  = Tenant::get( $user_id );
		$user    = get_userdata( $user_id );

		return rest_ensure_response(
			array(
				'id'           => $user_id,
				'display_name' => $user ? $user->display_name : '',
				'status'       => $tenant['status'] ?? 'unknown',
				'plan'         => $tenant['plan_key'] ?? 'free',
				'capabilities' => array(
					'content' => current_user_can( Capabilities::GENERATE_CONTENT ),
					'image'   => current_user_can( Capabilities::GENERATE_IMAGE ),
					'voice'   => current_user_can( Capabilities::GENERATE_VOICE ),
					'chat'    => current_user_can( Capabilities::USE_CHAT ),
					'billing' => current_user_can( Capabilities::VIEW_BILLING ),
				),
				'member_since' => $tenant['created_at'] ?? null,
			)
		);
	}

	/**
	 * GET /jobs
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_jobs( $request ) {
		$repo = etehadyar_core()->get( 'jobs' );
		$args = array(
			'page'     => $request->get_param( 'page' ),
			'per_page' => $request->get_param( 'per_page' ),
		);

		$status = $request->get_param( 'status' );

		if ( $status ) {
			$args['where'] = array( 'status' => $status );
		}

		$items = $repo->list_for( null, $args );

		if ( is_wp_error( $items ) ) {
			return $items;
		}

		$summary = $repo->summary();

		return rest_ensure_response(
			array(
				'items'   => $items,
				'summary' => is_wp_error( $summary ) ? null : $summary,
			)
		);
	}

	/**
	 * GET /jobs/{id}
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_job( $request ) {
		$repo = etehadyar_core()->get( 'jobs' );
		$job  = $repo->find( $request->get_param( 'id' ) );

		if ( is_wp_error( $job ) ) {
			return $job;
		}

		if ( ! $job ) {
			return new \WP_Error(
				'etehadyar_job_not_found',
				__( 'این کار پیدا نشد.', 'etehadyar-core' ),
				array( 'status' => 404 )
			);
		}

		return rest_ensure_response( $job );
	}

	/**
	 * GET /usage
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_usage( $request ) {
		$repo = etehadyar_core()->get( 'usage' );
		$days = max( 1, min( 90, (int) $request->get_param( 'days' ) ) );

		$summary = $repo->summary( null, $days );

		if ( is_wp_error( $summary ) ) {
			return $summary;
		}

		$daily = $repo->daily( null, min( 30, $days ) );

		return rest_ensure_response(
			array(
				'summary' => $summary,
				'daily'   => is_wp_error( $daily ) ? array() : $daily,
			)
		);
	}
}
