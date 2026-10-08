<?php
/**
 * Settles the money behind queued legacy jobs.
 *
 * `Ajax_Billing` charges the wallet when a request arrives. For synchronous
 * handlers it can read the JSON response on the way out and refund or
 * reconcile on the spot. For `background=1` the handler answers
 * `{ job_id, queued: true }` and the real work happens later, in cron —
 * `reconcile()` steps aside with a comment promising the worker will bill it.
 *
 * The legacy worker has no billing code at all, so before this class the
 * promise was empty in both directions:
 *
 *   - a job that failed after three attempts kept the customer's money;
 *   - cron has no current user, so everything the job wrote was owned by
 *     `user_id = 0` — posts with `post_author = 0`, ledger rows nobody could
 *     see, and tenant guards that resolve "whose data is this?" to "nobody's".
 *
 * Both are fixed from here rather than by editing the legacy plugin, which is
 * still upgraded independently. The legacy queue exposes two seams for it:
 * `eaiw_job_executor` (wrap execution) and `eaiw_job_result` (how it ended).
 *
 * @package Etehadyar\Billing
 */

namespace Etehadyar\Billing;

use Etehadyar\Compat\Legacy_Bridge;
use Etehadyar\Core\Audit;

defined( 'ABSPATH' ) || exit;

/**
 * Queued-job settlement.
 */
class Job_Settlement {

	/**
	 * Option prefix holding one charge reservation per queued job.
	 *
	 * A single array option would need read-modify-write, and two workers
	 * settling different jobs at the same time would lose one of the writes —
	 * which is exactly the direction that costs a customer their refund. One
	 * option row per job means every write stands on its own.
	 */
	const OPTION_PREFIX = 'etehadyar_job_charge_';

	/**
	 * How long a reservation is kept before the daily sweep drops it.
	 *
	 * Jobs normally run within minutes. A week covers a site whose cron was
	 * down, without letting the options table collect dust forever.
	 */
	const RESERVE_TTL = WEEK_IN_SECONDS;

	/**
	 * Register hooks.
	 */
	public static function boot() {
		add_filter( 'eaiw_job_executor', array( __CLASS__, 'wrap_executor' ), 10, 3 );
		add_action( 'eaiw_job_result', array( __CLASS__, 'on_result' ), 10, 4 );
		add_action( 'etehadyar_daily_maintenance', array( __CLASS__, 'prune' ) );
	}

	/**
	 * Remember what a queued job cost, so its outcome can be settled.
	 *
	 * Called by Ajax_Billing::reconcile() on the `queued` branch, which is the
	 * only place that knows both the job id and the charge.
	 *
	 * @param int   $job_id  Legacy job id.
	 * @param array $pending Reservation state from Ajax_Billing.
	 * @return bool Whether the reservation was stored.
	 */
	public static function reserve( $job_id, $pending ) {
		$job_id = (int) $job_id;

		if ( $job_id <= 0 ) {
			return false;
		}

		$data = array(
			'user_id'    => (int) ( $pending['user_id'] ?? 0 ),
			'amount'     => (int) ( $pending['charged'] ?? 0 ),
			'operation'  => (string) ( $pending['operation'] ?? '' ),
			'action'     => (string) ( $pending['action'] ?? '' ),
			'entry_id'   => (int) ( $pending['entry_id'] ?? 0 ),
			'created_at' => time(),
		);

		if ( $data['user_id'] <= 0 || $data['amount'] <= 0 ) {
			// Nothing was charged (exempt user, free tier), so there is
			// nothing to settle. Storing it would only create noise.
			return false;
		}

		// Autoload 'no': these are read once, by cron, on a known key.
		return add_option( self::OPTION_PREFIX . $job_id, $data, '', 'no' );
	}

	/**
	 * Read a reservation.
	 *
	 * @param int $job_id Legacy job id.
	 * @return array|null
	 */
	public static function get( $job_id ) {
		$data = get_option( self::OPTION_PREFIX . (int) $job_id, null );

		return is_array( $data ) ? $data : null;
	}

	/**
	 * Drop a reservation once its job has been settled.
	 *
	 * @param int $job_id Legacy job id.
	 */
	public static function forget( $job_id ) {
		delete_option( self::OPTION_PREFIX . (int) $job_id );
	}

	/**
	 * Run the job as its owner.
	 *
	 * `Tenant_Context::as_tenant()` restores the previous context in a
	 * `finally` block, which is why this wraps the executor instead of using
	 * paired before/after hooks: a job that throws cannot leak the next job's
	 * identity, and one worker process handles many jobs in a row.
	 *
	 * @param callable $executor Runs the job, returns its result, throws on failure.
	 * @param array    $job      Job row.
	 * @param array    $payload  Decoded payload.
	 * @return callable
	 */
	public static function wrap_executor( $executor, $job, $payload ) {
		$user_id = (int) ( $job['user_id'] ?? 0 );

		// Standalone legacy installs have no user_id column, and site-wide jobs
		// (agents, guardian) legitimately belong to nobody. Those run exactly as
		// they did before.
		if ( $user_id <= 0 || ! is_callable( $executor ) ) {
			return $executor;
		}

		return static function () use ( $executor, $user_id ) {
			$result = Legacy_Bridge::run_as( $user_id, $executor );

			// An inactive tenant means the work must not happen at all. Throwing
			// hands the job to the queue's own failure path, so it is retried,
			// recorded and — through on_result() — refunded.
			if ( is_wp_error( $result ) && 'etehadyar_tenant_inactive' === $result->get_error_code() ) {
				throw new \Exception( $result->get_error_message() );
			}

			return $result;
		};
	}

	/**
	 * Settle a finished job.
	 *
	 * @param array  $job    Job row.
	 * @param mixed  $result Handler result on success, null on failure.
	 * @param string $error  Error text, empty on success.
	 * @param bool   $final  Whether retries are exhausted.
	 */
	public static function on_result( $job, $result, $error, $final ) {
		$job_id = (int) ( $job['id'] ?? 0 );

		if ( $job_id <= 0 ) {
			return;
		}

		$reserved = self::get( $job_id );

		if ( ! $reserved ) {
			return;
		}

		// Still retrying: the money stays reserved until the job either
		// succeeds or gives up for good.
		if ( ! $final ) {
			return;
		}

		if ( '' === (string) $error ) {
			// Succeeded. The estimate charged at request time stands; forget the
			// reservation so it cannot be refunded later by mistake.
			self::forget( $job_id );

			return;
		}

		self::refund( $job_id, $reserved, (string) $error );
	}

	/**
	 * Give a failed job's charge back.
	 *
	 * @param int    $job_id   Legacy job id.
	 * @param array  $reserved Reservation data.
	 * @param string $error    Failure reason, for the ledger and the audit trail.
	 */
	protected static function refund( $job_id, $reserved, $error ) {
		$user_id = (int) $reserved['user_id'];
		$amount  = (int) $reserved['amount'];

		/*
		 * The idempotency key is what makes this safe to call more than once.
		 * recover_stale() and run_next() can both report the same job as
		 * finally failed, and Wallet::credit() honours the UNIQUE key — so the
		 * second attempt returns the first entry instead of paying twice.
		 */
		$entry = Wallet::refund(
			$user_id,
			$amount,
			sprintf(
				/* translators: %s: job failure reason. */
				__( 'بازگشت وجه به دلیل ناموفق بودن کار پس‌زمینه: %s', 'etehadyar-core' ),
				$error
			),
			array(
				'reference'       => 'job:' . $job_id,
				'idempotency_key' => 'job:' . $job_id . ':refund',
				'actor_id'        => 0,
				'meta'            => array(
					'job_id'    => $job_id,
					'operation' => (string) $reserved['operation'],
					'action'    => (string) $reserved['action'],
					'entry_id'  => (int) $reserved['entry_id'],
					'error'     => $error,
				),
			)
		);

		if ( is_wp_error( $entry ) ) {
			// Keep the reservation so the next attempt can try again; losing it
			// would silently swallow the customer's refund.
			Audit::log(
				'billing.job_refund_failed',
				array(
					'user_id'     => $user_id,
					'object_type' => 'job',
					'object_id'   => $job_id,
					'severity'    => 'error',
					'context'     => array(
						'amount' => $amount,
						'reason' => $entry->get_error_code(),
					),
				)
			);

			return;
		}

		self::forget( $job_id );

		Audit::log(
			'billing.refunded_failed_queued_job',
			array(
				'user_id'     => $user_id,
				'object_type' => 'job',
				'object_id'   => $job_id,
				'severity'    => 'notice',
				'context'     => array(
					'amount'    => $amount,
					'operation' => (string) $reserved['operation'],
					'error'     => $error,
				),
			)
		);
	}

	/**
	 * Drop reservations whose job is long gone.
	 *
	 * A job deleted from the queue, or one that ran while the core plugin was
	 * deactivated, leaves its reservation behind forever. The refund itself is
	 * still correct to keep — the charge was real — but a week without an
	 * outcome means nobody is going to report one.
	 *
	 * @return int Rows removed.
	 */
	public static function prune() {
		global $wpdb;

		$like = $wpdb->esc_like( self::OPTION_PREFIX ) . '%';

		// phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name, option_value FROM `{$wpdb->options}` WHERE option_name LIKE %s",
				$like
			),
			ARRAY_A
		);

		$cutoff = time() - self::RESERVE_TTL;
		$count  = 0;

		foreach ( (array) $rows as $row ) {
			$data = maybe_unserialize( (string) $row['option_value'] );

			if ( ! is_array( $data ) ) {
				delete_option( (string) $row['option_name'] );
				++$count;
				continue;
			}

			if ( (int) ( $data['created_at'] ?? 0 ) < $cutoff ) {
				Audit::log(
					'billing.job_reservation_expired',
					array(
						'user_id'  => (int) ( $data['user_id'] ?? 0 ),
						'severity' => 'warning',
						'context'  => array(
							'option' => (string) $row['option_name'],
							'amount' => (int) ( $data['amount'] ?? 0 ),
						),
					)
				);

				delete_option( (string) $row['option_name'] );
				++$count;
			}
		}

		return $count;
	}
}
