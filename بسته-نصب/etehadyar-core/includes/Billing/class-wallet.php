<?php
/**
 * Wallet balances and the append-only ledger.
 *
 * Money rules enforced here:
 *
 * - All amounts are whole Rial in signed integers. No floats anywhere.
 * - The ledger is append-only; balances are derived state, never the record.
 * - Debits are atomic: the balance check and the deduction happen in ONE
 *   conditional UPDATE, so two concurrent jobs cannot both pass a "do you have
 *   enough?" check and overdraw the account.
 * - Every mutation can carry an idempotency key. A retried payment callback or
 *   a re-queued job therefore credits or charges exactly once.
 *
 * @package Etehadyar\Billing
 */

namespace Etehadyar\Billing;

use Etehadyar\Core\Audit;
use Etehadyar\Core\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Wallet service.
 */
class Wallet {

	const TYPE_TOPUP    = 'topup';
	const TYPE_CHARGE   = 'charge';
	const TYPE_REFUND   = 'refund';
	const TYPE_ADJUST   = 'adjust';
	const TYPE_BONUS    = 'bonus';

	/**
	 * Ensure a wallet row exists.
	 *
	 * @param int $user_id User id.
	 * @return bool
	 */
	public static function ensure( $user_id ) {
		global $wpdb;

		$user_id = (int) $user_id;
		$table   = Schema::table( 'wallet' );

		if ( ! $user_id || ! Schema::table_exists( $table ) ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery
		$exists = $wpdb->get_var( $wpdb->prepare( "SELECT user_id FROM `{$table}` WHERE user_id = %d", $user_id ) );

		if ( $exists ) {
			return true;
		}

		// INSERT IGNORE: two simultaneous first-requests must not fatal.
		// phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery
		$wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO `{$table}` (user_id, balance, updated_at) VALUES (%d, 0, %s)",
				$user_id,
				current_time( 'mysql', true )
			)
		);

		return true;
	}

	/**
	 * Current balance in Rial.
	 *
	 * @param int $user_id User id.
	 * @return int
	 */
	public static function balance( $user_id ) {
		global $wpdb;

		$table = Schema::table( 'wallet' );

		if ( ! Schema::table_exists( $table ) ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT balance FROM `{$table}` WHERE user_id = %d", (int) $user_id )
		);
	}

	/**
	 * Full wallet record.
	 *
	 * @param int $user_id User id.
	 * @return array
	 */
	public static function get( $user_id ) {
		global $wpdb;

		$table = Schema::table( 'wallet' );

		if ( ! Schema::table_exists( $table ) ) {
			return array(
				'user_id'        => (int) $user_id,
				'balance'        => 0,
				'reserved'       => 0,
				'lifetime_topup' => 0,
				'lifetime_spend' => 0,
			);
		}

		self::ensure( $user_id );

		// phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM `{$table}` WHERE user_id = %d", (int) $user_id ),
			ARRAY_A
		);

		if ( ! $row ) {
			return array(
				'user_id'        => (int) $user_id,
				'balance'        => 0,
				'reserved'       => 0,
				'lifetime_topup' => 0,
				'lifetime_spend' => 0,
			);
		}

		return array(
			'user_id'        => (int) $row['user_id'],
			'balance'        => (int) $row['balance'],
			'reserved'       => (int) $row['reserved'],
			'lifetime_topup' => (int) $row['lifetime_topup'],
			'lifetime_spend' => (int) $row['lifetime_spend'],
			'updated_at'     => $row['updated_at'],
		);
	}

	/**
	 * Whether the wallet can cover an amount.
	 *
	 * Advisory only — never gate a charge on this. Between the check and the
	 * charge another request can spend the balance. Use `charge()`, which
	 * decides atomically.
	 *
	 * @param int $user_id User id.
	 * @param int $amount  Amount in Rial.
	 * @return bool
	 */
	public static function can_afford( $user_id, $amount ) {
		return self::balance( $user_id ) >= (int) $amount;
	}

	/**
	 * Add funds.
	 *
	 * @param int    $user_id User id.
	 * @param int    $amount  Positive amount in Rial.
	 * @param array  $args    Optional: type, reference, idempotency_key, description, actor_id, meta.
	 * @return array|\WP_Error Ledger entry.
	 */
	public static function credit( $user_id, $amount, $args = array() ) {
		global $wpdb;

		$user_id = (int) $user_id;
		$amount  = (int) $amount;

		if ( $amount <= 0 ) {
			return new \WP_Error(
				'etehadyar_wallet_bad_amount',
				__( 'مبلغ باید بزرگ‌تر از صفر باشد.', 'etehadyar-core' ),
				array( 'status' => 400 )
			);
		}

		$table = Schema::table( 'wallet' );

		if ( ! Schema::table_exists( $table ) ) {
			return new \WP_Error(
				'etehadyar_wallet_unavailable',
				__( 'کیف پول در دسترس نیست.', 'etehadyar-core' ),
				array( 'status' => 503 )
			);
		}

		$args = wp_parse_args(
			$args,
			array(
				'type'            => self::TYPE_TOPUP,
				'reference'       => '',
				'idempotency_key' => '',
				'description'     => '',
				'actor_id'        => get_current_user_id(),
				'meta'            => array(),
			)
		);

		// Replay guard: if this key was already processed, return the original
		// entry rather than crediting again.
		$existing = self::find_by_key( $args['idempotency_key'] );

		if ( $existing ) {
			return $existing;
		}

		self::ensure( $user_id );

		$topup_delta = self::TYPE_TOPUP === $args['type'] ? $amount : 0;

		// phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE `{$table}`
				 SET balance = balance + %d,
				     lifetime_topup = lifetime_topup + %d,
				     updated_at = %s
				 WHERE user_id = %d",
				$amount,
				$topup_delta,
				current_time( 'mysql', true ),
				$user_id
			)
		);

		if ( false === $updated ) {
			return new \WP_Error(
				'etehadyar_wallet_credit_failed',
				__( 'افزایش اعتبار انجام نشد.', 'etehadyar-core' ),
				array( 'status' => 500 )
			);
		}

		$entry = self::record( $user_id, $args['type'], $amount, $args );

		if ( empty( $entry ) ) {
			// The ledger row did not land. With a key present that can only
			// mean a concurrent request already recorded this exact credit, so
			// undo ours: without this, two parallel payment callbacks both
			// passed the find_by_key() check above and both moved the balance,
			// crediting one payment twice.
			self::reverse_balance( $table, $user_id, $amount, $topup_delta, 0 );

			$winner = self::find_by_key( $args['idempotency_key'] );

			Audit::log(
				'wallet.duplicate_credit_reversed',
				array(
					'user_id'  => $user_id,
					'severity' => 'warning',
					'context'  => array(
						'amount' => $amount,
						'key'    => substr( (string) $args['idempotency_key'], 0, 120 ),
					),
				)
			);

			if ( $winner ) {
				return $winner;
			}

			return new \WP_Error(
				'etehadyar_wallet_credit_failed',
				__( 'افزایش اعتبار انجام نشد. دوباره تلاش کنید.', 'etehadyar-core' ),
				array( 'status' => 500 )
			);
		}

		return $entry;
	}

	/**
	 * Undo a balance movement whose ledger row failed to record.
	 *
	 * @param string $table        Wallet table.
	 * @param int    $user_id      Wallet owner.
	 * @param int    $amount       Balance delta to subtract.
	 * @param int    $topup_delta  Lifetime top-up delta to subtract.
	 * @param int    $spend_delta  Lifetime spend delta to subtract.
	 */
	protected static function reverse_balance( $table, $user_id, $amount, $topup_delta, $spend_delta ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE `{$table}`
				 SET balance = balance - %d,
				     lifetime_topup = lifetime_topup - %d,
				     lifetime_spend = lifetime_spend - %d,
				     updated_at = %s
				 WHERE user_id = %d",
				$amount,
				$topup_delta,
				$spend_delta,
				current_time( 'mysql', true ),
				$user_id
			)
		);
	}

	/**
	 * Deduct funds, atomically.
	 *
	 * The whole point of this method: the sufficiency test lives inside the
	 * UPDATE's WHERE clause. The database decides, once, under its own row
	 * lock. If two workers charge the same wallet at the same moment, exactly
	 * one of them matches a row and the other is told there are insufficient
	 * funds — no negative balance is possible.
	 *
	 * @param int   $user_id User id.
	 * @param int   $amount  Positive amount in Rial.
	 * @param array $args    Optional: reference, idempotency_key, description, meta, allow_negative.
	 * @return array|\WP_Error Ledger entry.
	 */
	public static function charge( $user_id, $amount, $args = array() ) {
		global $wpdb;

		$user_id = (int) $user_id;
		$amount  = (int) $amount;

		if ( $amount <= 0 ) {
			return new \WP_Error(
				'etehadyar_wallet_bad_amount',
				__( 'مبلغ باید بزرگ‌تر از صفر باشد.', 'etehadyar-core' ),
				array( 'status' => 400 )
			);
		}

		$table = Schema::table( 'wallet' );

		if ( ! Schema::table_exists( $table ) ) {
			return new \WP_Error(
				'etehadyar_wallet_unavailable',
				__( 'کیف پول در دسترس نیست.', 'etehadyar-core' ),
				array( 'status' => 503 )
			);
		}

		$args = wp_parse_args(
			$args,
			array(
				'type'            => self::TYPE_CHARGE,
				'reference'       => '',
				'idempotency_key' => '',
				'description'     => '',
				'actor_id'        => get_current_user_id(),
				'meta'            => array(),
				'allow_negative'  => false,
			)
		);

		$existing = self::find_by_key( $args['idempotency_key'] );

		if ( $existing ) {
			return $existing;
		}

		self::ensure( $user_id );

		$guard = $args['allow_negative'] ? '' : ' AND balance >= %d';

		$sql = "UPDATE `{$table}`
				SET balance = balance - %d,
				    lifetime_spend = lifetime_spend + %d,
				    updated_at = %s
				WHERE user_id = %d" . $guard;

		$params = array( $amount, $amount, current_time( 'mysql', true ), $user_id );

		if ( ! $args['allow_negative'] ) {
			$params[] = $amount;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery
		$affected = $wpdb->query( $wpdb->prepare( $sql, $params ) );

		if ( ! $affected ) {
			Audit::log(
				'wallet.insufficient_funds',
				array(
					'user_id'  => $user_id,
					'severity' => 'notice',
					'context'  => array(
						'requested' => $amount,
						'balance'   => self::balance( $user_id ),
					),
				)
			);

			return new \WP_Error(
				'etehadyar_wallet_insufficient',
				__( 'اعتبار کیف پول کافی نیست. لطفاً حساب خود را شارژ کنید.', 'etehadyar-core' ),
				array(
					'status'  => 402,
					'balance' => self::balance( $user_id ),
					'needed'  => $amount,
				)
			);
		}

		$entry = self::record( $user_id, $args['type'], -$amount, $args );

		if ( empty( $entry ) ) {
			// Same reasoning as credit(): a concurrent charge with the same
			// idempotency key already recorded itself, so give this one back.
			// Passing -$amount makes reverse_balance() add it to the balance.
			self::reverse_balance( $table, $user_id, -$amount, 0, $amount );

			$winner = self::find_by_key( $args['idempotency_key'] );

			Audit::log(
				'wallet.duplicate_charge_reversed',
				array(
					'user_id'  => $user_id,
					'severity' => 'warning',
					'context'  => array(
						'amount' => $amount,
						'key'    => substr( (string) $args['idempotency_key'], 0, 120 ),
					),
				)
			);

			if ( $winner ) {
				return $winner;
			}

			return new \WP_Error(
				'etehadyar_wallet_charge_failed',
				__( 'کسر اعتبار انجام نشد. دوباره تلاش کنید.', 'etehadyar-core' ),
				array( 'status' => 500 )
			);
		}

		return $entry;
	}

	/**
	 * Return funds to a wallet.
	 *
	 * @param int    $user_id User id.
	 * @param int    $amount  Positive amount in Rial.
	 * @param string $reason  Why the refund happened.
	 * @param array  $args    Optional overrides.
	 * @return array|\WP_Error
	 */
	public static function refund( $user_id, $amount, $reason = '', $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'type'        => self::TYPE_REFUND,
				'description' => $reason,
			)
		);

		$args['type'] = self::TYPE_REFUND;

		return self::credit( $user_id, $amount, $args );
	}

	/**
	 * Write a ledger entry.
	 *
	 * @param int    $user_id User id.
	 * @param string $type    Entry type.
	 * @param int    $amount  Signed amount.
	 * @param array  $args    Entry metadata.
	 * @return array
	 */
	protected static function record( $user_id, $type, $amount, $args ) {
		global $wpdb;

		$table = Schema::table( 'transactions' );

		if ( ! Schema::table_exists( $table ) ) {
			return array();
		}

		$balance = self::balance( $user_id );

		$inserted = $wpdb->insert(
			$table,
			array(
				'user_id'         => $user_id,
				'type'            => substr( sanitize_key( $type ), 0, 20 ),
				'amount'          => (int) $amount,
				'balance_after'   => $balance,
				'reference'       => substr( (string) $args['reference'], 0, 80 ),
				'idempotency_key' => $args['idempotency_key'] ? substr( (string) $args['idempotency_key'], 0, 120 ) : null,
				'description'     => substr( (string) $args['description'], 0, 255 ),
				'actor_id'        => (int) $args['actor_id'],
				'meta'            => $args['meta'] ? wp_json_encode( $args['meta'] ) : null,
				'created_at'      => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%d', '%d', '%s', '%s', '%s', '%d', '%s', '%s' )
		);

		// The caller must know whether the ledger row landed. `idempotency_key`
		// is UNIQUE, so a concurrent request that got here first makes this
		// INSERT fail — and silently ignoring that would leave the balance
		// moved with no record of why. credit()/charge() reverse their own
		// balance change when this returns empty.
		if ( ! $inserted ) {
			return array();
		}

		$entry = array(
			'id'            => (int) $wpdb->insert_id,
			'user_id'       => $user_id,
			'type'          => $type,
			'amount'        => (int) $amount,
			'balance_after' => $balance,
			'reference'     => (string) $args['reference'],
			'description'   => (string) $args['description'],
		);

		Audit::log(
			'wallet.' . $type,
			array(
				'user_id'     => $user_id,
				'object_type' => 'wallet_txn',
				'object_id'   => $entry['id'],
				'context'     => array(
					'amount'  => (int) $amount,
					'balance' => $balance,
				),
			)
		);

		return $entry;
	}

	/**
	 * Look up a ledger entry by idempotency key.
	 *
	 * @param string $key Idempotency key.
	 * @return array|null
	 */
	public static function find_by_key( $key ) {
		global $wpdb;

		$key = trim( (string) $key );

		if ( '' === $key ) {
			return null;
		}

		$table = Schema::table( 'transactions' );

		if ( ! Schema::table_exists( $table ) ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM `{$table}` WHERE idempotency_key = %s", $key ),
			ARRAY_A
		);

		if ( ! $row ) {
			return null;
		}

		return array(
			'id'            => (int) $row['id'],
			'user_id'       => (int) $row['user_id'],
			'type'          => $row['type'],
			'amount'        => (int) $row['amount'],
			'balance_after' => (int) $row['balance_after'],
			'reference'     => (string) $row['reference'],
			'description'   => (string) $row['description'],
			'replayed'      => true,
		);
	}

	/**
	 * Ledger entries for a user.
	 *
	 * @param int   $user_id User id.
	 * @param array $args    page, per_page, type.
	 * @return array
	 */
	public static function history( $user_id, $args = array() ) {
		global $wpdb;

		$table = Schema::table( 'transactions' );

		if ( ! Schema::table_exists( $table ) ) {
			return array(
				'items' => array(),
				'total' => 0,
			);
		}

		$args = wp_parse_args(
			$args,
			array(
				'page'     => 1,
				'per_page' => 20,
				'type'     => '',
			)
		);

		$page     = max( 1, (int) $args['page'] );
		$per_page = min( 100, max( 1, (int) $args['per_page'] ) );
		$offset   = ( $page - 1 ) * $per_page;

		$where  = 'user_id = %d';
		$params = array( (int) $user_id );

		if ( $args['type'] ) {
			$where   .= ' AND type = %s';
			$params[] = sanitize_key( $args['type'] );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery
		$total = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` WHERE {$where}", $params )
		);

		$params[] = $per_page;
		$params[] = $offset;

		// phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, type, amount, balance_after, reference, description, created_at
				 FROM `{$table}` WHERE {$where} ORDER BY id DESC LIMIT %d OFFSET %d",
				$params
			),
			ARRAY_A
		);

		$items = array();

		foreach ( (array) $rows as $row ) {
			$items[] = array(
				'id'            => (int) $row['id'],
				'type'          => $row['type'],
				'type_label'    => self::type_label( $row['type'] ),
				'amount'        => (int) $row['amount'],
				'balance_after' => (int) $row['balance_after'],
				'reference'     => (string) $row['reference'],
				'description'   => (string) $row['description'],
				'created_at'    => $row['created_at'],
			);
		}

		return array(
			'items'    => $items,
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per_page,
		);
	}

	/**
	 * Persian label for a ledger entry type.
	 *
	 * @param string $type Entry type.
	 * @return string
	 */
	public static function type_label( $type ) {
		$labels = array(
			self::TYPE_TOPUP  => __( 'افزایش اعتبار', 'etehadyar-core' ),
			self::TYPE_CHARGE => __( 'مصرف', 'etehadyar-core' ),
			self::TYPE_REFUND => __( 'بازگشت وجه', 'etehadyar-core' ),
			self::TYPE_ADJUST => __( 'اصلاح دستی', 'etehadyar-core' ),
			self::TYPE_BONUS  => __( 'هدیه', 'etehadyar-core' ),
		);

		return $labels[ $type ] ?? $type;
	}

	/**
	 * Format Rial for display.
	 *
	 * @param int $amount Amount in Rial.
	 * @return string
	 */
	public static function format( $amount ) {
		return sprintf(
			/* translators: %s: formatted amount. */
			__( '%s ریال', 'etehadyar-core' ),
			number_format_i18n( (int) $amount )
		);
	}
}
