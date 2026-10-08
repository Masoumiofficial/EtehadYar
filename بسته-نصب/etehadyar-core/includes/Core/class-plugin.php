<?php
/**
 * Core container and hook wiring.
 *
 * @package Etehadyar\Core
 */

namespace Etehadyar\Core;

use Etehadyar\Admin\Admin_Screen;
use Etehadyar\Admin\Billing_Screen;
use Etehadyar\Admin\Settings_Screen;
use Etehadyar\Auth\OTP_Service;
use Etehadyar\Compat\Capability_Bridge;
use Etehadyar\Security\Endpoint_Guard;
use Etehadyar\Security\Temp_Sweeper;
use Etehadyar\Admin\Analytics_Screen;
use Etehadyar\Admin\Brain_Screen;
use Etehadyar\Compat\Legacy_Bridge;
use Etehadyar\Billing\Ajax_Billing;
use Etehadyar\Billing\Orders;
use Etehadyar\Frontend\Auth_Shortcode;
use Etehadyar\Frontend\Dashboard_Shortcode;
use Etehadyar\Frontend\Payment_Handler;
use Etehadyar\Frontend\Studio_Shortcode;
use Etehadyar\Frontend\Wallet_Shortcode;
use Etehadyar\Rest\Auth_Controller;
use Etehadyar\Rest\Wallet_Controller;
use Etehadyar\Data\Jobs_Repository;
use Etehadyar\Data\Support_Repository;
use Etehadyar\Data\Usage_Repository;
use Etehadyar\Rest\Dashboard_Controller;
use Etehadyar\Rest\Studio_Controller;

defined( 'ABSPATH' ) || exit;

/**
 * Singleton container.
 */
class Plugin {

	/**
	 * Instance.
	 *
	 * @var Plugin|null
	 */
	protected static $instance = null;

	/**
	 * Lazily built services.
	 *
	 * @var array<string, object>
	 */
	protected $services = array();

	/**
	 * Accessor.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Wire hooks.
	 */
	protected function __construct() {
		add_action( 'init', array( $this, 'maybe_migrate' ), 1 );
		add_action( 'user_register', array( $this, 'on_user_register' ) );
		add_action( 'rest_api_init', array( $this, 'register_rest' ) );
		add_action( 'etehadyar_daily_maintenance', array( $this, 'purge_otp' ) );

		if ( is_admin() ) {
			add_action( 'admin_menu', array( Admin_Screen::class, 'register' ) );
			add_action( 'admin_menu', array( Settings_Screen::class, 'register' ), 11 );
			add_action( 'admin_menu', array( Billing_Screen::class, 'register' ), 12 );
			add_action( 'admin_menu', array( Analytics_Screen::class, 'register' ), 13 );
			add_action( 'admin_menu', array( Brain_Screen::class, 'register' ), 14 );
			add_action( 'admin_init', array( Settings_Screen::class, 'handle_post' ) );
			add_action( 'admin_init', array( Billing_Screen::class, 'handle_post' ) );
			add_action( 'admin_init', array( Analytics_Screen::class, 'handle_post' ) );
			add_action( 'admin_init', array( Brain_Screen::class, 'handle_post' ) );
			add_action( 'admin_notices', array( $this, 'migration_notice' ) );
		}

		Auth_Shortcode::boot();
		Wallet_Shortcode::boot();
		Dashboard_Shortcode::boot();
		Studio_Shortcode::boot();
		Payment_Handler::boot();
		Legacy_Bridge::boot();
		Capability_Bridge::boot();
		Endpoint_Guard::boot();
		Temp_Sweeper::boot();
		Ajax_Billing::boot();
	}

	/**
	 * Repository accessor.
	 *
	 * @param string $key Service key.
	 * @return object|null
	 */
	public function get( $key ) {
		if ( isset( $this->services[ $key ] ) ) {
			return $this->services[ $key ];
		}

		$map = array(
			'jobs'    => Jobs_Repository::class,
			'usage'   => Usage_Repository::class,
			'support' => Support_Repository::class,
		);

		if ( ! isset( $map[ $key ] ) ) {
			return null;
		}

		$this->services[ $key ] = new $map[ $key ]();

		return $this->services[ $key ];
	}

	/**
	 * Run pending migrations when the plugin is updated in place.
	 */
	public function maybe_migrate() {
		if ( Migrator::pending() ) {
			Migrator::run();
		}
	}

	/**
	 * Warn admins when the schema is behind.
	 */
	public function migration_notice() {
		if ( ! current_user_can( Capabilities::MANAGE_PLATFORM ) ) {
			return;
		}

		$pending = Migrator::pending();

		if ( ! $pending ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p><b>%s</b> %s</p></div>',
			esc_html__( 'اتحادیار هسته:', 'etehadyar-core' ),
			esc_html(
				sprintf(
					/* translators: %d: number of pending migrations. */
					__( '%d به‌روزرسانی پایگاه داده در انتظار اجراست. صفحهٔ پلتفرم را باز کنید تا اجرا شود.', 'etehadyar-core' ),
					count( $pending )
				)
			)
		);
	}

	/**
	 * Enrol newly registered users as tenants.
	 *
	 * @param int $user_id New user ID.
	 */
	public function on_user_register( $user_id ) {
		if ( ! Installer::auto_enrol_enabled() ) {
			return;
		}

		$user = get_userdata( $user_id );

		if ( ! $user ) {
			return;
		}

		// Administrators keep their own role; everyone else becomes a member.
		if ( ! user_can( $user_id, 'manage_options' ) ) {
			$user->add_role( Capabilities::ROLE_MEMBER );
		}

		Tenant::ensure( $user_id );

		Audit::log(
			'tenant.registered',
			array(
				'user_id'     => $user_id,
				'actor_id'    => $user_id,
				'object_type' => 'tenant',
				'object_id'   => $user_id,
			)
		);
	}

	/**
	 * Register REST routes.
	 */
	public function register_rest() {
		( new Dashboard_Controller() )->register_routes();
		( new Auth_Controller() )->register_routes();
		( new Wallet_Controller() )->register_routes();
		Studio_Controller::register_routes();
	}

	/**
	 * Daily housekeeping.
	 *
	 * Without this the OTP table grows without bound and keeps hashes of every
	 * login attempt the site has ever seen, while abandoned checkouts sit in
	 * `pending` forever and make the orders report meaningless.
	 */
	public function purge_otp() {
		OTP_Service::purge( 2 );
		Orders::expire_stale( 24 );
	}
}
