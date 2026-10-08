<?php
/**
 * Top-up orders: create, send to gateway, verify, credit.
 *
 * The money-critical path of the platform. Two invariants:
 *
 * 1. The amount charged and credited is always the one stored in our own
 *    orders row. Nothing from the browser or the callback URL is trusted.
 * 2. Crediting is idempotent, keyed on the order id. A refreshed return page,
 *    a duplicated callback, or a manual re-verification all converge on the
 *    same single ledger entry.
 *
 * @package Etehadyar\Billing
 */

namespace Etehadyar\Billing;

use Etehadyar\Core\Audit;
use Etehadyar\Core\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Order manager.
 */
class Orders {

	const STATUS_PENDING  = 'pending';
	const STATUS_PAID     = 'paid';
	const STATUS_FAILED   = 'failed';
	const STATUS_CANCELLED = 'cancelled';

	/**
	 * Claimed by one request that is talking to the gateway right now.
	 *
	 * This state is the mutex. The gateway round-trip takes up to 30 seconds,
	 * and during that window every other return for the same Authority used to
	 * see `pending` and start settling too. Claiming with a single conditional
	 * UPDATE lets the database pick exactly one winner.
	 */
	const STATUS_SETTLING = 'settling';

	/**
	 * How long a claim is considered alive, in seconds.
	 *
	 * The gateway round-trip is the only thing that happens under a claim and
	 * it is capped well below this. Once the transient expires, a row still in
	 * `settling` can only mean the claiming request died (fatal error, PHP
	 * timeout, deploy) and the claim is safe to take over.
	 *
	 * The transient is only the freshness oracle — the conditional UPDATE in
	 * claim_pending() stays the sole arbiter of who wins, so two requests
	 * noticing the same expired marker cannot both settle.
	 */
	const CLAIM_TTL = 120;

	const OPTION_MIN = 'etehadyar_topup_min';
	const OPTION_MAX = 'etehadyar_topup_max';

	/**
	 * Transient key marking a live claim on an order.
	 *
	 * @param int $order_id Order id.
	 * @return string
	 */
	protected static function claim_key( $order_id ) {
		return 'etehadyar_order_claim_' . (int) $order_id;
	}

	/**
	 * Try to take ownership of a pending order.
	 *
	 * @param string $table    Orders table.
	 * @param int    $order_id Order id.
	 * @return int Rows affected — exactly 1 for the winner, 0 for everybody else.
	 */
	protected static function claim_pending( $table, $order_id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE `{$table}` SET status = %s WHERE id = %d AND status = %s",
				self::STATUS_SETTLING,
				(int) $order_id,
				self::STATUS_PENDING
			)
		);
	}

	/**
	 * Minimum allowed top-up in Rial.
	 *
	 * @return int
	 */
	public static function min_amount() {
		return max( 1000, (int) get_option( self::OPTION_MIN, 100000 ) );
	}

	/**
	 * Maximum allowed top-up in Rial.
	 *
	 * A ceiling protects against a typo turning a 100,000 Rial top-up into
	 * 100,000,000 and against card-testing abuse.
	 *
	 * @return int
	 */
	public static function max_amount() {
		return max( self::min_amount(), (int) get_option( self::OPTION_MAX, 500000000 ) );
	}

	/**
	 * The URL ZarinPal returns the customer to.
	 *
	 * @return string
	 */
	public static function callback_url() {
		return add_query_arg( 'etehadyar_payment', 'return', home_url( '/' ) );
	}

	/**
	 * Create a pending order.
	 *
	 * @param int $user_id User id.
	 * @param int $amount  Amount in Rial.
	 * @return array|\WP_Error
	 */
	public static function create( $user_id, $amount ) {
		global $wpdb;

		$user_id = (int) $user_id;
		$amount  = (int) $amount;
		$table   = Schema::table( 'orders' );

		if ( ! Schema::table_exists( $table ) ) {
			return new \WP_Error(
				'etehadyar_orders_unavailable',
				__( 'سامانهٔ پرداخت در دسترس نیست.', 'etehadyar-core' ),
				array( 'status' => 503 )
			);
		}

		if ( $amount < self::min_amount() ) {
			return new \WP_Error(
				'etehadyar_amount_too_low',
				sprintf(
					/* translators: %s: formatted minimum. */
					__( 'حداقل مبلغ شارژ %s است.', 'etehadyar-core' ),
					Wallet::format( self::min_amount() )
				),
				array( 'status' => 400 )
			);
		}

		if ( $amount > self::max_amount() ) {
			return new \WP_Error(
				'etehadyar_amount_too_high',
				sprintf(
					/* translators: %s: formatted maximum. */
					__( 'حداکثر مبلغ شارژ %s است.', 'etehadyar-core' ),
					Wallet::format( self::max_amount() )
				),
				array( 'status' => 400 )
			);
		}

		$inserted = $wpdb->insert(
			$table,
			array(
				'user_id'    => $user_id,
				'amount'     => $amount,
				'status'     => self::STATUS_PENDING,
				'gateway'    => 'zarinpal',
				'ip'         => \Etehadyar\Auth\OTP_Service::client_ip(),
				'created_at' => current_time( 'mysql', true ),
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s' )
		);

		if ( ! $inserted ) {
			return new \WP_Error(
				'etehadyar_order_failed',
				__( 'ثبت سفارش انجام نشد.', 'etehadyar-core' ),
				array( 'status' => 500 )
			);
		}

		return self::get( (int) $wpdb->insert_id );
	}

	/**
	 * Fetch an order by id.
	 *
	 * @param int $id Order id.
	 * @return array|null
	 */
	public static function get( $id ) {
		global $wpdb;

		$table = Schema::table( 'orders' );

		if ( ! Schema::table_exists( $table ) ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM `{$table}` WHERE id = %d", (int) $id ),
			ARRAY_A
		);

		return $row ?: null;
	}

	/**
	 * Fetch an order by gateway authority.
	 *
	 * @param string $authority Gateway authority.
	 * @return array|null
	 */
	public static function get_by_authority( $authority ) {
		global $wpdb;

		$authority = trim( (string) $authority );
		$table     = Schema::table( 'orders' );

		if ( '' === $authority || ! Schema::table_exists( $table ) ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM `{$table}` WHERE authority = %s", $authority ),
			ARRAY_A
		);

		return $row ?: null;
	}

	/**
	 * Begin checkout: create the order and get the redirect URL.
	 *
	 * @param int $user_id User id.
	 * @param int $amount  Amount in Rial.
	 * @return array|\WP_Error { redirect:string, order_id:int }
	 */
	public static function checkout( $user_id, $amount ) {
		global $wpdb;

		$order = self::create( $user_id, $amount );

		if ( is_wp_error( $order ) ) {
			return $order;
		}

		$gateway = new Zarinpal_Gateway();
		$started = $gateway->start( $order );

		if ( is_wp_error( $started ) ) {
			self::mark_failed( (int) $order['id'], $started->get_error_message() );

			return $started;
		}

		$wpdb->update(
			Schema::table( 'orders' ),
			array( 'authority' => $started['authority'] ),
			array( 'id' => (int) $order['id'] ),
			array( '%s' ),
			array( '%d' )
		);

		Audit::log(
			'billing.checkout_started',
			array(
				'user_id'     => $user_id,
				'object_type' => 'order',
				'object_id'   => (int) $order['id'],
				'context'     => array( 'amount' => (int) $order['amount'] ),
			)
		);

		return array(
			'order_id' => (int) $order['id'],
			'redirect' => $started['redirect'],
		);
	}

	/**
	 * Handle the customer's return from the gateway.
	 *
	 * @param array $request Query parameters.
	 * @return array|\WP_Error { order_id:int, amount:int, ref_id:string, balance:int }
	 */
	public static function handle_return( $request ) {
		global $wpdb;

		$authority = (string) ( $request['Authority'] ?? $request['authority'] ?? '' );
		$order     = self::get_by_authority( $authority );

		if ( ! $order ) {
			return new \WP_Error(
				'etehadyar_order_not_found',
				__( 'سفارش مربوط به این پرداخت یافت نشد.', 'etehadyar-core' ),
				array( 'status' => 404 )
			);
		}

		// Already settled — report success rather than confusing the customer.
		if ( self::STATUS_PAID === $order['status'] ) {
			return array(
				'order_id' => (int) $order['id'],
				'amount'   => (int) $order['amount'],
				'ref_id'   => (string) $order['ref_id'],
				'balance'  => Wallet::balance( (int) $order['user_id'] ),
				'repeat'   => true,
			);
		}

		// Claim the order before touching the network. Only the request whose
		// conditional UPDATE actually matched a row may settle it; everybody
		// else gets an honest "still being processed" instead of crediting the
		// same payment a second time.
		$table     = Schema::table( 'orders' );
		$claim_key = self::claim_key( $order['id'] );
		$claimed   = self::claim_pending( $table, $order['id'] );

		// Take over a claim whose owner is provably gone. Without this the row
		// would sit in `settling` until somebody noticed, and the customer —
		// who did pay — would be told "still processing" on every reload. The
		// freshness marker expiring is the evidence; the conditional UPDATE
		// below still decides the winner, so this cannot reopen the race.
		if ( 1 !== $claimed && self::STATUS_SETTLING === $order['status'] && ! get_transient( $claim_key ) ) {
			Audit::log(
				'billing.stale_claim_reclaimed',
				array(
					'user_id'     => (int) $order['user_id'],
					'object_type' => 'order',
					'object_id'   => (int) $order['id'],
					'severity'    => 'warning',
					'context'     => array( 'ttl' => self::CLAIM_TTL ),
				)
			);

			self::release_claim( (int) $order['id'] );

			$claimed = self::claim_pending( $table, $order['id'] );
		}

		if ( 1 !== $claimed ) {
			Audit::log(
				'billing.return_race_blocked',
				array(
					'user_id'     => (int) $order['user_id'],
					'object_type' => 'order',
					'object_id'   => (int) $order['id'],
					'severity'    => 'warning',
					'context'     => array( 'status' => (string) $order['status'] ),
				)
			);

			// A cancelled/failed order is a genuine terminal state, so report
			// it as such rather than as "in progress".
			if ( in_array( $order['status'], array( self::STATUS_FAILED, self::STATUS_CANCELLED ), true ) ) {
				return new \WP_Error(
					'etehadyar_order_closed',
					__( 'این سفارش پیش‌تر بسته شده است. برای شارژ، سفارش جدیدی ثبت کنید.', 'etehadyar-core' ),
					array( 'status' => 409 )
				);
			}

			return new \WP_Error(
				'etehadyar_payment_in_progress',
				__( 'پرداخت شما در حال پردازش است. کمی دیگر موجودی کیف پول را بررسی کنید.', 'etehadyar-core' ),
				array( 'status' => 409 )
			);
		}

		// Mark the claim alive before the network call, not after: the window
		// that matters is the one where a dead request would leave no trace.
		set_transient( $claim_key, time(), self::CLAIM_TTL );

		$gateway  = new Zarinpal_Gateway();
		$verified = $gateway->verify( $order, $request );

		if ( is_wp_error( $verified ) ) {
			delete_transient( $claim_key );

			self::mark_failed( (int) $order['id'], $verified->get_error_message() );

			Audit::log(
				'billing.payment_failed',
				array(
					'user_id'     => (int) $order['user_id'],
					'object_type' => 'order',
					'object_id'   => (int) $order['id'],
					'severity'    => 'warning',
					'context'     => array( 'reason' => $verified->get_error_code() ),
				)
			);

			return $verified;
		}

		// Credit the wallet. The idempotency key is derived from the order id,
		// so no sequence of retries can credit it twice.
		$entry = Wallet::credit(
			(int) $order['user_id'],
			(int) $order['amount'],
			array(
				'type'            => Wallet::TYPE_TOPUP,
				'reference'       => 'order:' . (int) $order['id'],
				'idempotency_key' => 'order:' . (int) $order['id'],
				'description'     => __( 'افزایش اعتبار از درگاه زرین‌پال', 'etehadyar-core' ),
				'actor_id'        => (int) $order['user_id'],
				'meta'            => array(
					'ref_id'   => $verified['ref_id'],
					'card_pan' => $verified['card_pan'],
				),
			)
		);

		if ( is_wp_error( $entry ) ) {
			// The money arrived at the gateway but the wallet could not be
			// credited. Release the claim so a later return (or a support
			// re-verify) can settle it, instead of stranding the order in
			// `settling` forever. The marker goes too: with the row back in
			// `pending` there is nothing left to guard, and a stale marker
			// would only mislead the next reader.
			delete_transient( $claim_key );

			self::release_claim( (int) $order['id'] );

			Audit::log(
				'billing.credit_failed',
				array(
					'user_id'     => (int) $order['user_id'],
					'object_type' => 'order',
					'object_id'   => (int) $order['id'],
					'severity'    => 'error',
					'context'     => array(
						'reason' => $entry->get_error_code(),
						'amount' => (int) $order['amount'],
					),
				)
			);

			return $entry;
		}

		$wpdb->update(
			Schema::table( 'orders' ),
			array(
				'status'   => self::STATUS_PAID,
				'ref_id'   => substr( (string) $verified['ref_id'], 0, 60 ),
				'card_pan' => substr( (string) $verified['card_pan'], 0, 30 ),
				'paid_at'  => current_time( 'mysql', true ),
			),
			array( 'id' => (int) $order['id'] ),
			array( '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);

		delete_transient( $claim_key );

		Audit::log(
			'billing.payment_succeeded',
			array(
				'user_id'     => (int) $order['user_id'],
				'object_type' => 'order',
				'object_id'   => (int) $order['id'],
				'context'     => array(
					'amount' => (int) $order['amount'],
					'ref_id' => $verified['ref_id'],
				),
			)
		);

		/**
		 * Fires after a top-up is credited.
		 *
		 * @param array $order Order row.
		 * @param array $entry Ledger entry.
		 */
		do_action( 'etehadyar_topup_completed', $order, $entry );

		return array(
			'order_id' => (int) $order['id'],
			'amount'   => (int) $order['amount'],
			'ref_id'   => (string) $verified['ref_id'],
			'balance'  => Wallet::balance( (int) $order['user_id'] ),
			'repeat'   => false,
		);
	}

	/**
	 * Give back a claim that could not be settled.
	 *
	 * Only ever moves `settling` back to `pending`: a terminal state must not
	 * be resurrected by a late callback.
	 *
	 * @param int $id Order id.
	 * @return int Rows affected.
	 */
	public static function release_claim( $id ) {
		global $wpdb;

		$table = Schema::table( 'orders' );

		if ( ! Schema::table_exists( $table ) ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE `{$table}` SET status = %s WHERE id = %d AND status = %s",
				self::STATUS_PENDING,
				(int) $id,
				self::STATUS_SETTLING
			)
		);
	}

	/**
	 * Mark an order failed.
	 *
	 * @param int    $id     Order id.
	 * @param string $reason Failure reason.
	 */
	public static function mark_failed( $id, $reason = '' ) {
		global $wpdb;

		$table = Schema::table( 'orders' );

		if ( ! Schema::table_exists( $table ) ) {
			return;
		}

		$wpdb->update(
			$table,
			array(
				'status'      => self::STATUS_FAILED,
				'fail_reason' => substr( (string) $reason, 0, 255 ),
			),
			array( 'id' => (int) $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Orders belonging to a user.
	 *
	 * @param int   $user_id User id.
	 * @param array $args    page, per_page.
	 * @return array
	 */
	public static function for_user( $user_id, $args = array() ) {
		global $wpdb;

		$table = Schema::table( 'orders' );

		if ( ! Schema::table_exists( $table ) ) {
			return array(
				'items' => array(),
				'total' => 0,
			);
		}

		$args     = wp_parse_args( $args, array( 'page' => 1, 'per_page' => 20 ) );
		$page     = max( 1, (int) $args['page'] );
		$per_page = min( 100, max( 1, (int) $args['per_page'] ) );
		$offset   = ( $page - 1 ) * $per_page;

		// phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery
		$total = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` WHERE user_id = %d", (int) $user_id )
		);

		// phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, amount, status, ref_id, card_pan, created_at, paid_at
				 FROM `{$table}` WHERE user_id = %d ORDER BY id DESC LIMIT %d OFFSET %d",
				(int) $user_id,
				$per_page,
				$offset
			),
			ARRAY_A
		);

		$items = array();

		foreach ( (array) $rows as $row ) {
			$items[] = array(
				'id'           => (int) $row['id'],
				'amount'       => (int) $row['amount'],
				'status'       => $row['status'],
				'status_label' => self::status_label( $row['status'] ),
				'ref_id'       => (string) $row['ref_id'],
				'card_pan'     => (string) $row['card_pan'],
				'created_at'   => $row['created_at'],
				'paid_at'      => $row['paid_at'],
			);
		}

		return array(
			'items' => $items,
			'total' => $total,
		);
	}

	/**
	 * Persian label for an order status.
	 *
	 * @param string $status Status key.
	 * @return string
	 */
	public static function status_label( $status ) {
		$labels = array(
			self::STATUS_PENDING   => __( 'در انتظار پرداخت', 'etehadyar-core' ),
			self::STATUS_SETTLING  => __( 'در حال پردازش پرداخت', 'etehadyar-core' ),
			self::STATUS_PAID      => __( 'پرداخت‌شده', 'etehadyar-core' ),
			self::STATUS_FAILED    => __( 'ناموفق', 'etehadyar-core' ),
			self::STATUS_CANCELLED => __( 'لغو شده', 'etehadyar-core' ),
		);

		return $labels[ $status ] ?? $status;
	}

	/**
	 * Expire stale pending orders.
	 *
	 * @param int $hours Age threshold.
	 * @return int Rows affected.
	 */
	public static function expire_stale( $hours = 24 ) {
		global $wpdb;

		$table = Schema::table( 'orders' );

		if ( ! Schema::table_exists( $table ) ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery
		$expired = (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE `{$table}` SET status = %s, fail_reason = %s
				 WHERE status = %s AND created_at < %s",
				self::STATUS_CANCELLED,
				__( 'مهلت پرداخت به پایان رسید.', 'etehadyar-core' ),
				self::STATUS_PENDING,
				gmdate( 'Y-m-d H:i:s', time() - ( max( 1, (int) $hours ) * HOUR_IN_SECONDS ) )
			)
		);

		/*
		 * Stranded claims are reported, never swept.
		 *
		 * An order left in `settling` means a request died after claiming it
		 * but before settling or releasing — and the customer may well have
		 * paid. Reopening it to `pending` here would be actively harmful: the
		 * UPDATE above runs in the same call, so a claim older than the
		 * threshold would be released and then cancelled moments later,
		 * recording a paid top-up as an abandoned checkout. Expiry must only
		 * ever touch orders that were never claimed.
		 *
		 * handle_return() reclaims abandoned claims on its own (see
		 * CLAIM_TTL), so what reaches this point is a claim nobody came back
		 * for: surface it for support instead of guessing.
		 */
		// phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery
		$stranded = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM `{$table}` WHERE status = %s AND created_at < %s",
				self::STATUS_SETTLING,
				gmdate( 'Y-m-d H:i:s', time() - ( self::CLAIM_TTL * 2 ) )
			)
		);

		if ( $stranded > 0 ) {
			Audit::log(
				'billing.stranded_claims',
				array(
					'severity' => 'error',
					'context'  => array(
						'count'   => $stranded,
						'message' => 'Orders stuck in settling; payment may have been captured. Verify with the gateway before acting.',
					),
				)
			);
		}

		return $expired;
	}
}
