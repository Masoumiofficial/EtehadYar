<?php
/**
 * Wraps the legacy AJAX handlers in wallet billing.
 *
 * The legacy plugin's handlers end in `wp_send_json_success()` /
 * `wp_send_json_error()`, which call `wp_die()` — so there is no return value
 * to inspect and no code path after them. That rules out a simple "call the
 * handler and check the result" wrapper.
 *
 * The approach used here:
 *
 *   1. On `admin_init` (before the AJAX action fires) reserve the estimated
 *      cost. If funds are short, the request is rejected right there and the
 *      handler never runs — no provider cost is incurred.
 *   2. Hook `wp_die_ajax_handler` so the terminating JSON response passes
 *      through us on its way out. A failure response triggers a refund; a
 *      success response settles the charge and reconciles against the real
 *      quantity when the payload reports one.
 *
 * This means billing works without editing a single line of the legacy plugin,
 * which matters because the legacy plugin is still upgraded independently.
 *
 * @package Etehadyar\Billing
 */

namespace Etehadyar\Billing;

use Etehadyar\Core\Audit;
use Etehadyar\Core\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * AJAX billing interceptor.
 */
class Ajax_Billing {

	/**
	 * Map of AJAX action => billing definition.
	 *
	 * `operation` is a Pricing key. `estimate` is a callable receiving $_POST
	 * and returning the quantity in that operation's units.
	 *
	 * @return array<string, array{operation:string, estimate:callable, capability:string}>
	 */
	public static function billable_actions() {
		$map = array(
			'eaiw_factory_generate'  => array(
				'operation'  => 'content_word',
				'capability' => Capabilities::GENERATE_CONTENT,
				'estimate'   => static function ( $post ) {
					// The form asks for a target length in words.
					$length = (int) ( $post['length'] ?? 1200 );

					return Estimator::content_units( $length ?: 1200 );
				},
			),
			'eaiw_vision_generate'   => array(
				'operation'  => 'image',
				'capability' => Capabilities::GENERATE_IMAGE,
				'estimate'   => static function () {
					return 1;
				},
			),
			'eaiw_flux_generate'     => array(
				'operation'  => 'image',
				'capability' => Capabilities::GENERATE_IMAGE,
				'estimate'   => static function () {
					return 1;
				},
			),
			'eaiw_architect_generate' => array(
				'operation'  => 'image',
				'capability' => Capabilities::GENERATE_IMAGE,
				'estimate'   => static function () {
					return 1;
				},
			),
			'eaiw_tts_generate'      => array(
				'operation'  => 'voice_minute',
				'capability' => Capabilities::GENERATE_VOICE,
				'estimate'   => static function ( $post ) {
					return Estimator::tts_units( (string) ( $post['text'] ?? '' ) );
				},
			),
			'eaiw_video_build'       => array(
				'operation'  => 'video_minute',
				'capability' => Capabilities::GENERATE_CONTENT,
				'estimate'   => static function ( $post ) {
					return Estimator::video_units( (int) ( $post['duration'] ?? 0 ) );
				},
			),
		);

		/**
		 * Filter the billable AJAX actions.
		 *
		 * @param array $map Action definitions.
		 */
		return apply_filters( 'etehadyar_billable_ajax_actions', $map );
	}

	/**
	 * State for the in-flight request.
	 *
	 * @var array|null
	 */
	protected static $pending = null;

	/**
	 * Register hooks.
	 */
	public static function boot() {
		// Priority 5: after Legacy_Bridge's capability guard (priority 1) but
		// before the AJAX action itself is dispatched.
		add_action( 'admin_init', array( __CLASS__, 'maybe_reserve' ), 5 );
	}

	/**
	 * Charge for the current AJAX request, if it is billable.
	 */
	public static function maybe_reserve() {
		if ( ! wp_doing_ajax() ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- the handler verifies its own nonce; this only reads the routing key.
		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';

		if ( ! $action ) {
			return;
		}

		$map = self::billable_actions();

		if ( ! isset( $map[ $action ] ) ) {
			return;
		}

		$user_id = get_current_user_id();

		if ( ! $user_id ) {
			return;
		}

		// Platform staff are not billed: an administrator testing the system
		// should not need a funded wallet.
		if ( self::is_exempt( $user_id ) ) {
			return;
		}

		$config = $map[ $action ];

		if ( ! empty( $config['capability'] ) && ! current_user_can( $config['capability'] ) ) {
			wp_send_json_error(
				array( 'message' => __( 'شما به این امکان دسترسی ندارید.', 'etehadyar-core' ) ),
				403
			);
		}

		// Quota is checked BEFORE the wallet is touched, so a request that is
		// going to be rejected never costs the customer anything.
		$quota = Quota::check( $user_id );

		if ( is_wp_error( $quota ) ) {
			wp_send_json_error(
				array(
					'message' => $quota->get_error_message(),
					'code'    => $quota->get_error_code(),
				),
				429
			);
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- estimation only; the handler validates the values it uses.
		$quantity = (float) call_user_func( $config['estimate'], wp_unslash( $_POST ) );

		// A non-positive quantity is invalid input, not a free job. The
		// estimators clamp their input with max( 0, … ), so `length=-1` used to
		// arrive here as 0 units → 0 cost → the `return` below, which let the
		// legacy handler run without touching the wallet at all. Combined with
		// `background=1` (where reconcile() deliberately steps aside) that was
		// unlimited free generation at the platform's expense.
		if ( $quantity <= 0 ) {
			Audit::log(
				'billing.rejected_invalid_quantity',
				array(
					'user_id'  => $user_id,
					'severity' => 'warning',
					'context'  => array(
						'action'    => $action,
						'operation' => $config['operation'],
						'quantity'  => $quantity,
					),
				)
			);

			wp_send_json_error(
				array(
					'message' => __( 'مقدار درخواست‌شده برای این عملیات نامعتبر است.', 'etehadyar-core' ),
					'code'    => 'etehadyar_billing_invalid_quantity',
				),
				400
			);
		}

		$cost = Pricing::cost( $config['operation'], $quantity );

		if ( $cost <= 0 ) {
			// Quantity is valid but this operation is priced at zero — an
			// administrator's deliberate free tier. Run it, keep no ledger row.
			return;
		}

		$charge = Wallet::charge(
			$user_id,
			$cost,
			array(
				'reference'   => $action,
				'description' => self::describe( $config['operation'], $quantity ),
				'meta'        => array(
					'operation' => $config['operation'],
					'quantity'  => $quantity,
					'action'    => $action,
					'estimated' => true,
				),
			)
		);

		if ( is_wp_error( $charge ) ) {
			// Stop here. The handler never runs, so no upstream API call is
			// made and the platform pays nothing for an unfunded request.
			wp_send_json_error(
				array(
					'message' => $charge->get_error_message(),
					'code'    => $charge->get_error_code(),
					'balance' => Wallet::balance( $user_id ),
					'needed'  => $cost,
				),
				402
			);
		}

		Quota::record( $user_id );

		self::$pending = array(
			'user_id'   => $user_id,
			'operation' => $config['operation'],
			'quantity'  => $quantity,
			'charged'   => $cost,
			'action'    => $action,
			'entry_id'  => $charge['id'] ?? 0,
		);

		// Intercept the response on its way out so we can settle or refund.
		add_filter( 'wp_die_ajax_handler', array( __CLASS__, 'capture_die_handler' ), 99 );
	}

	/**
	 * Whether a user is exempt from billing.
	 *
	 * @param int $user_id User id.
	 * @return bool
	 */
	protected static function is_exempt( $user_id ) {
		if ( user_can( $user_id, Capabilities::MANAGE_PLATFORM ) ) {
			return true;
		}

		/**
		 * Filter whether a user is exempt from wallet billing.
		 *
		 * @param bool $exempt  Default based on platform management rights.
		 * @param int  $user_id User id.
		 */
		return (bool) apply_filters( 'etehadyar_billing_exempt', false, $user_id );
	}

	/**
	 * Swap in our own wp_die handler for this request.
	 *
	 * @param callable $handler Original handler.
	 * @return callable
	 */
	public static function capture_die_handler( $handler ) {
		return static function ( $message, $title = '', $args = array() ) use ( $handler ) {
			Ajax_Billing::settle_from_buffer();

			return call_user_func( $handler, $message, $title, $args );
		};
	}

	/**
	 * Inspect the buffered JSON response and settle the charge.
	 *
	 * `wp_send_json_*` echoes the payload and then calls `wp_die()`, so at this
	 * point the JSON is sitting in the output buffer waiting to be flushed.
	 * Reading it is what lets us tell success from failure without modifying
	 * the legacy handler.
	 */
	public static function settle_from_buffer() {
		$pending = self::$pending;

		if ( ! $pending ) {
			return;
		}

		// Guard against re-entry: refunding twice would be worse than not
		// refunding at all.
		self::$pending = null;

		$payload = self::read_buffer();

		// A response we cannot parse is treated as a failure. Refunding on an
		// ambiguous outcome is the safe direction to be wrong in: the customer
		// keeps their money and the platform can investigate.
		$succeeded = is_array( $payload ) && ! empty( $payload['success'] );

		// Some legacy generators report success while quietly returning a
		// placeholder: Vision_Studio falls back to a locally drawn SVG when
		// the provider errors or no API key is configured, and still answers
		// wp_send_json_success(). The customer asked for an AI image and got
		// a coloured rectangle, so charging full price for it is indefensible.
		if ( $succeeded && self::is_degraded( $payload ) ) {
			$succeeded = false;
		}

		if ( ! $succeeded ) {
			Wallet::refund(
				$pending['user_id'],
				$pending['charged'],
				__( 'بازگشت وجه به دلیل ناموفق بودن عملیات', 'etehadyar-core' ),
				array(
					'reference' => $pending['action'],
					'meta'      => array( 'operation' => $pending['operation'] ),
				)
			);

			Audit::log(
				'billing.refunded_failed_job',
				array(
					'user_id'  => $pending['user_id'],
					'severity' => 'notice',
					'context'  => array(
						'action' => $pending['action'],
						'amount' => $pending['charged'],
					),
				)
			);

			return;
		}

		self::reconcile( $pending, $payload );
	}

	/**
	 * Whether a "successful" response is actually a degraded placeholder.
	 *
	 * @param array $payload Decoded response.
	 * @return bool
	 */
	protected static function is_degraded( $payload ) {
		$data = $payload['data'] ?? array();

		if ( ! is_array( $data ) ) {
			return false;
		}

		/**
		 * Result modes that mean "we did not really do the work".
		 *
		 * @param string[] $modes Mode values treated as a failed job.
		 */
		$modes = apply_filters(
			'etehadyar_degraded_result_modes',
			array( 'placeholder', 'error_fallback', 'fallback', 'stub' )
		);

		if ( isset( $data['mode'] ) && in_array( (string) $data['mode'], $modes, true ) ) {
			return true;
		}

		// A generator that hands back an explicit error string alongside a
		// success flag is also telling us the real work did not happen.
		return ! empty( $data['error'] );
	}

	/**
	 * Adjust the charge to the real quantity reported by the handler.
	 *
	 * @param array $pending Reservation state.
	 * @param array $payload Decoded response.
	 */
	protected static function reconcile( $pending, $payload ) {
		$data = $payload['data'] ?? array();

		if ( ! is_array( $data ) ) {
			return;
		}

		/*
		 * A queued job has not done the work yet, so its outcome cannot be
		 * judged from this response. Hand the charge to Job_Settlement, which
		 * listens to the worker's `eaiw_job_result` hook and refunds it if the
		 * job finally fails. Without this hand-off the comment below used to be
		 * a promise nothing kept: the legacy worker has no billing code, so the
		 * charge simply stood even for a job that never produced anything.
		 */
		if ( ! empty( $data['queued'] ) ) {
			$job_id = (int) ( $data['job_id'] ?? 0 );

			if ( $job_id > 0 ) {
				Job_Settlement::reserve( $job_id, $pending );
			} else {
				// The handler said "queued" but did not tell us which job, so
				// nothing can ever settle this charge. Refunding now is the
				// safe direction: the work has not happened yet either.
				Audit::log(
					'billing.unreservable_queued_job',
					array(
						'user_id'  => (int) $pending['user_id'],
						'severity' => 'warning',
						'context'  => array(
							'action'  => (string) $pending['action'],
							'charged' => (int) $pending['charged'],
						),
					)
				);

				Wallet::refund(
					(int) $pending['user_id'],
					(int) $pending['charged'],
					__( 'بازگشت وجه: کار صف‌شده قابل پیگیری نیست.', 'etehadyar-core' ),
					array(
						'reference' => (string) $pending['action'],
						'meta'      => array( 'operation' => (string) $pending['operation'] ),
					)
				);
			}

			return;
		}

		$actual = self::actual_quantity( $pending['operation'], $data );

		if ( null === $actual ) {
			return;
		}

		$actual_cost = Pricing::cost( $pending['operation'], $actual );
		$difference  = $actual_cost - $pending['charged'];

		if ( 0 === $difference ) {
			return;
		}

		if ( $difference < 0 ) {
			Wallet::refund(
				$pending['user_id'],
				abs( $difference ),
				__( 'تعدیل هزینه بر اساس مصرف واقعی', 'etehadyar-core' ),
				array( 'reference' => $pending['action'] )
			);

			return;
		}

		Wallet::charge(
			$pending['user_id'],
			$difference,
			array(
				'reference'      => $pending['action'],
				'description'    => __( 'تعدیل هزینه بر اساس مصرف واقعی', 'etehadyar-core' ),
				'allow_negative' => true,
				'meta'           => array( 'operation' => $pending['operation'] ),
			)
		);
	}

	/**
	 * Derive the real quantity from a handler's response payload.
	 *
	 * @param string $operation Pricing key.
	 * @param array  $data      Response data.
	 * @return float|null Null when the payload says nothing useful.
	 */
	protected static function actual_quantity( $operation, $data ) {
		switch ( $operation ) {
			case 'content_word':
				// The Factory already reports a word count; prefer it so both
				// sides of the system agree on one number.
				if ( isset( $data['words'] ) && is_numeric( $data['words'] ) && $data['words'] > 0 ) {
					return Estimator::content_units( (int) $data['words'] );
				}

				foreach ( array( 'content', 'text', 'body', 'html' ) as $key ) {
					if ( ! empty( $data[ $key ] ) && is_string( $data[ $key ] ) ) {
						$words = Estimator::word_count( wp_strip_all_tags( $data[ $key ] ) );

						// A zero count means we failed to find the text, not
						// that the job produced nothing — do not zero the bill.
						return $words > 0 ? Estimator::content_units( $words ) : null;
					}
				}

				return null;

			case 'voice_minute':
				if ( isset( $data['duration'] ) && is_numeric( $data['duration'] ) ) {
					return (float) $data['duration'] / 60;
				}

				return null;

			case 'video_minute':
				if ( isset( $data['duration'] ) && is_numeric( $data['duration'] ) ) {
					return (float) $data['duration'] / 60;
				}

				return null;

			default:
				return null;
		}
	}

	/**
	 * Read the pending JSON response out of the output buffer.
	 *
	 * @return array|null
	 */
	protected static function read_buffer() {
		if ( ! ob_get_level() ) {
			return null;
		}

		$contents = ob_get_contents();

		if ( ! is_string( $contents ) || '' === trim( $contents ) ) {
			return null;
		}

		$decoded = json_decode( trim( $contents ), true );

		return is_array( $decoded ) ? $decoded : null;
	}

	/**
	 * Ledger description for an estimated charge.
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
