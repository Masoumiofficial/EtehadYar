<?php
/**
 * Bridges platform capabilities onto the WordPress primitives the legacy
 * plugin checks for.
 *
 * @package Etehadyar\Compat
 */

namespace Etehadyar\Compat;

use Etehadyar\Billing\Ajax_Billing;
use Etehadyar\Core\Audit;
use Etehadyar\Core\Capabilities;
use Etehadyar\Core\Tenant;

defined( 'ABSPATH' ) || exit;

/**
 * The legacy AI handlers gate on WordPress authoring primitives —
 * `edit_posts`, `upload_files`, `edit_pages` — because they were written for
 * an admin-only plugin where every operator was an editor.
 *
 * A paying member is NOT an editor. Granting those capabilities to the member
 * role outright would hand every customer the post editor, the media library
 * and other tenants' drafts. Instead this bridge grants the single primitive
 * a given operation needs, for the duration of one already-authorised AJAX
 * request, and takes it straight back afterwards.
 *
 * The grant requires all of:
 *   1. an AJAX request for a known billable action,
 *   2. the platform capability for that operation,
 *   3. an active (non-suspended) tenant.
 */
class Capability_Bridge {

	/**
	 * Which WordPress primitive each billable action demands.
	 *
	 * Mirrors the `current_user_can()` call at the top of each legacy handler.
	 *
	 * @var array<string,string>
	 */
	const PRIMITIVE_MAP = array(
		'eaiw_factory_generate'   => 'edit_posts',
		'eaiw_tts_generate'       => 'edit_posts',
		'eaiw_video_build'        => 'edit_posts',
		'eaiw_vision_generate'    => 'upload_files',
		'eaiw_flux_generate'      => 'upload_files',
		'eaiw_architect_generate' => 'edit_pages',
	);

	/**
	 * Attach hooks.
	 */
	public static function boot() {
		// Priority 2: after Legacy_Bridge's guard (1), before billing (5).
		add_action( 'admin_init', array( __CLASS__, 'guard_object_ids' ), 2 );
		add_filter( 'user_has_cap', array( __CLASS__, 'grant_primitive' ), 10, 4 );
		add_filter( 'wp_insert_post_data', array( __CLASS__, 'force_ownership' ), 10, 2 );
	}

	/**
	 * The billable action being requested, or an empty string.
	 *
	 * @return string
	 */
	public static function current_action() {
		if ( ! wp_doing_ajax() ) {
			return '';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- routing key only; the handler verifies its own nonce.
		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';

		return isset( self::PRIMITIVE_MAP[ $action ] ) ? $action : '';
	}

	/**
	 * Grant the one primitive capability the current operation needs.
	 *
	 * @param array   $allcaps Effective capabilities.
	 * @param array   $caps    Required primitive capabilities.
	 * @param array   $args    Context: [ cap, user_id, object_id ].
	 * @param \WP_User $user   The user being tested.
	 * @return array
	 */
	public static function grant_primitive( $allcaps, $caps, $args, $user ) {
		$action = self::current_action();

		if ( ! $action ) {
			return $allcaps;
		}

		if ( empty( $user->ID ) || get_current_user_id() !== (int) $user->ID ) {
			return $allcaps;
		}

		// Never widen the reach of someone who already has the run of the site.
		if ( ! empty( $allcaps[ Capabilities::MANAGE_PLATFORM ] ) ) {
			return $allcaps;
		}

		$map      = Ajax_Billing::billable_actions();
		$required = isset( $map[ $action ]['capability'] ) ? $map[ $action ]['capability'] : '';

		// The platform capability is the real authorisation decision.
		if ( ! $required || empty( $allcaps[ $required ] ) ) {
			return $allcaps;
		}

		// A suspended tenant keeps their data but loses the tools.
		if ( ! Tenant::is_active( (int) $user->ID ) ) {
			return $allcaps;
		}

		$primitive = self::PRIMITIVE_MAP[ $action ];

		/**
		 * Filter whether a member may be lent a WordPress primitive for one
		 * billable AJAX action.
		 *
		 * The grant is request-wide, not scoped to a single call, so a site
		 * that does not need a given tool in members' hands can switch it off
		 * here instead of forking the bridge. Example — keep the video builder
		 * (which writes files into the uploads directory) for staff only:
		 *
		 *     add_filter( 'etehadyar_grant_primitive', function ( $grant, $primitive, $action ) {
		 *         return 'eaiw_video_build' === $action ? false : $grant;
		 *     }, 10, 3 );
		 *
		 * @param bool   $grant     Whether to grant. Default true.
		 * @param string $primitive The WordPress capability about to be lent.
		 * @param string $action    The billable AJAX action being requested.
		 * @param int    $user_id   The member being tested.
		 */
		$grant = apply_filters(
			'etehadyar_grant_primitive',
			true,
			$primitive,
			$action,
			(int) $user->ID
		);

		if ( ! $grant ) {
			return $allcaps;
		}

		$allcaps[ $primitive ] = true;

		return $allcaps;
	}

	/**
	 * Strip object ids the caller does not own.
	 *
	 * `ajax_factory_generate` forwards `post_id` into `generate_full()`, which
	 * calls `get_post()` and copies the title and body into the prompt with no
	 * ownership test at all. On a single-tenant admin plugin that was
	 * harmless; on a multi-tenant platform it lets any member read any post on
	 * the site — including other customers' private drafts — by guessing ids.
	 *
	 * Rather than fail the request, the id is dropped: the member gets a
	 * generation from their own prompt instead of a silent data leak.
	 */
	public static function guard_object_ids() {
		if ( ! self::current_action() ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- ownership filter; the handler verifies its own nonce.
		$post_id = isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) ) : 0;

		if ( ! $post_id ) {
			return;
		}

		$user_id = get_current_user_id();

		if ( ! $user_id || user_can( $user_id, Capabilities::MANAGE_PLATFORM ) ) {
			return;
		}

		$post = get_post( $post_id );

		if ( $post && (int) $post->post_author === (int) $user_id ) {
			return;
		}

		unset( $_POST['post_id'], $_REQUEST['post_id'] );

		Audit::log(
			'security.blocked_foreign_post',
			array(
				'post_id' => $post_id,
				'owner'   => $post ? (int) $post->post_author : 0,
			),
			$user_id
		);
	}

	/**
	 * Keep generated drafts owned by, and private to, the member who paid.
	 *
	 * `generate_full()` inserts the draft with no author and no guard against
	 * being published, so without this the post would land under whoever the
	 * current user resolves to and could be pushed live.
	 *
	 * @param array $data    Slashed post data.
	 * @param array $postarr Raw post array.
	 * @return array
	 */
	public static function force_ownership( $data, $postarr ) {
		unset( $postarr );

		if ( ! self::current_action() ) {
			return $data;
		}

		$user_id = get_current_user_id();

		if ( ! $user_id || user_can( $user_id, Capabilities::MANAGE_PLATFORM ) ) {
			return $data;
		}

		$data['post_author'] = $user_id;

		// A member may generate drafts, never publish them.
		if ( ! in_array( $data['post_status'], array( 'draft', 'auto-draft', 'inherit' ), true ) ) {
			$data['post_status'] = 'draft';
		}

		return $data;
	}
}
