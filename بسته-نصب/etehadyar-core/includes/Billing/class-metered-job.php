<?php
/**
 * Charge-before-execute wrapper for billable work.
 *
 * This class exists to fix a real flaw in the original plugin: it ran the AI
 * job first and recorded usage afterwards. With a prepaid wallet that ordering
 * lets a customer with zero balance keep consuming paid API calls — every one
 * of which costs the platform owner real money at the provider.
 *
 * The order here is deliberate:
 *
 *   1. Estimate the cost and DEBIT the wallet. If funds are short, stop now —
 *      before a single token is spent upstream.
 *   2. Run the work.
 *   3. Reconcile: if the job failed, refund in full. If the real cost differs
 *      from the estimate, settle the difference.
 *
 * A job that throws still refunds, because the reconciliation lives in a
 * `finally`-guarded path rather than depending on the callback returning
 * normally.
 *
 * @package Etehadyar\Billing
 */

namespace Etehadyar\Billing;

use Etehadyar\Core\Audit;

defined( 'ABSPATH' ) || exit;

/**
 * Metered execution helper.
 */
class Metered_Job {

	/**
	 * Run a billable operation.
	 *
	 * @param int      $user_id    Tenant id.
	 * @param string   $operation  Pricing key.
	 * @param float    $quantity   Estimated units.
	 * @param callable $callback   The work. Receives no arguments. May return
	 *                             a WP_Error to signal failure, or an array
	 *                             with an `actual_quantity` key to reconcile.
	 * @param array    $args       reference, description, idempotency_key.
	 * @return mixed|\WP_Error The callback's return value, or an error.
	 */
	public static function run( $user_id, $operation, $quantity, $callback, $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'reference'       => '',
				'description'     => '',
				'idempotency_key' => '',
			)
		);

		$estimate = Pricing::cost( $operation, $quantity );

		// Free operations still execute; they simply skip the ledger.
		if ( $estimate <= 0 ) {
			return call_user_func( $callback );
		}

		$charge = Wallet::charge(
			$user_id,
			$estimate,
			array(
				'reference'       => $args['reference'] ?: $operation,
				'description'     => $args['description'] ?: self::describe( $operation, $quantity ),
				'idempotency_key' => $args['idempotency_key'],
				'meta'            => array(
					'operation' => $operation,
					'quantity'  => $quantity,
					'estimated' => true,
				),
			)
		);

		if ( is_wp_error( $charge ) ) {
			return $charge;
		}

		$result    = null;
		$completed = false;

		try {
			$result    = call_user_func( $callback );
			$completed = true;
		} finally {
			if ( ! $completed ) {
				// The callback threw. Refund before the exception propagates,
				// so a crash never silently keeps the customer's money.
				Wallet::refund(
					$user_id,
					$estimate,
					__( 'بازگشت وجه به دلیل خطای اجرا', 'etehadyar-core' ),
					array(
						'reference' => $args['reference'] ?: $operation,
						'meta'      => array( 'operation' => $operation ),
					)
				);

				Audit::log(
					'billing.refunded_on_exception',
					array(
						'user_id'  => $user_id,
						'severity' => 'error',
						'context'  => array(
							'operation' => $operation,
							'amount'    => $estimate,
						),
					)
				);
			}
		}

		// The job reported failure without throwing.
		if ( is_wp_error( $result ) ) {
			Wallet::refund(
				$user_id,
				$estimate,
				__( 'بازگشت وجه به دلیل ناموفق بودن عملیات', 'etehadyar-core' ),
				array(
					'reference' => $args['reference'] ?: $operation,
					'meta'      => array(
						'operation' => $operation,
						'reason'    => $result->get_error_code(),
					),
				)
			);

			return $result;
		}

		self::reconcile( $user_id, $operation, $quantity, $estimate, $result, $args );

		return $result;
	}

	/**
	 * Settle the difference between estimate and actual usage.
	 *
	 * @param int    $user_id   Tenant id.
	 * @param string $operation Pricing key.
	 * @param float  $estimated Estimated quantity.
	 * @param int    $charged   Amount already taken.
	 * @param mixed  $result    Callback result.
	 * @param array  $args      Original arguments.
	 */
	protected static function reconcile( $user_id, $operation, $estimated, $charged, $result, $args ) {
		if ( ! is_array( $result ) || ! isset( $result['actual_quantity'] ) ) {
			return;
		}

		$actual_cost = Pricing::cost( $operation, (float) $result['actual_quantity'] );
		$difference  = $actual_cost - $charged;

		if ( 0 === $difference ) {
			return;
		}

		if ( $difference < 0 ) {
			Wallet::refund(
				$user_id,
				abs( $difference ),
				__( 'تعدیل هزینه بر اساس مصرف واقعی', 'etehadyar-core' ),
				array( 'reference' => $args['reference'] ?: $operation )
			);

			return;
		}

		// Under-charged. Take the remainder, allowing the balance to dip
		// negative: the work is already done and the platform already paid the
		// provider for it. Refusing here would mean eating the cost.
		Wallet::charge(
			$user_id,
			$difference,
			array(
				'reference'      => $args['reference'] ?: $operation,
				'description'    => __( 'تعدیل هزینه بر اساس مصرف واقعی', 'etehadyar-core' ),
				'allow_negative' => true,
				'meta'           => array( 'operation' => $operation ),
			)
		);
	}

	/**
	 * Human description for a ledger entry.
	 *
	 * @param string $operation Pricing key.
	 * @param float  $quantity  Units.
	 * @return string
	 */
	protected static function describe( $operation, $quantity ) {
		$rates = Pricing::all();
		$label = $rates[ $operation ]['label'] ?? $operation;

		return sprintf( '%s × %s', $label, number_format_i18n( ceil( $quantity ) ) );
	}
}
