<?php
/**
 * Per-tenant concurrency and rate limits.
 *
 * The legacy queue caps concurrency per job *type*, globally. On a single-owner
 * site that is fine; on a shared platform it means one customer queuing fifty
 * jobs stalls everybody else's work. Limits here are per user, so one tenant's
 * appetite cannot starve the rest.
 *
 * @package Etehadyar\Billing
 */

namespace Etehadyar\Billing;

use Etehadyar\Core\Audit;
use Etehadyar\Core\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Quota enforcement.
 */
class Quota {

	const OPTION_MAX_CONCURRENT = 'etehadyar_quota_concurrent';
	const OPTION_MAX_HOURLY     = 'etehadyar_quota_hourly';

	/**
	 * Maximum simultaneous queued or running jobs per tenant.
	 *
	 * @return int
	 */
	public static function max_concurrent() {
		return max( 1, (int) get_option( self::OPTION_MAX_CONCURRENT, 3 ) );
	}

	/**
	 * Maximum jobs started per tenant per hour.
	 *
	 * @return int
	 */
	public static function max_hourly() {
		return max( 1, (int) get_option( self::OPTION_MAX_HOURLY, 60 ) );
	}

	/**
	 * Whether a tenant may start another job.
	 *
	 * @param int $user_id Tenant id.
	 * @return true|\WP_Error
	 */
	public static function check( $user_id ) {
		$user_id = (int) $user_id;

		if ( ! $user_id ) {
			return true;
		}

		// Staff bypass the queue limits; they are operating the platform, not
		// consuming it.
		if ( user_can( $user_id, Capabilities::MANAGE_PLATFORM ) ) {
			return true;
		}

		$plugin = function_exists( 'etehadyar_core' ) ? etehadyar_core() : null;

		if ( $plugin ) {
			$jobs = $plugin->get( 'jobs' );

			if ( $jobs && $jobs->is_available() ) {
				$active = $jobs->count_active( $user_id );

				if ( ! is_wp_error( $active ) && $active >= self::max_concurrent() ) {
					return new \WP_Error(
						'etehadyar_quota_concurrent',
						sprintf(
							/* translators: %d: concurrent job limit. */
							__( 'هم‌زمان حداکثر %d کار می‌توانید در صف داشته باشید. تا پایان کارهای فعلی صبر کنید.', 'etehadyar-core' ),
							self::max_concurrent()
						),
						array( 'status' => 429 )
					);
				}
			}
		}

		$key   = 'etehadyar_quota_h_' . $user_id;
		$count = (int) get_transient( $key );

		if ( $count >= self::max_hourly() ) {
			Audit::log(
				'quota.hourly_exceeded',
				array(
					'user_id'  => $user_id,
					'severity' => 'notice',
					'context'  => array( 'limit' => self::max_hourly() ),
				)
			);

			return new \WP_Error(
				'etehadyar_quota_hourly',
				sprintf(
					/* translators: %d: hourly job limit. */
					__( 'در هر ساعت حداکثر %d کار مجاز است. کمی بعد دوباره تلاش کنید.', 'etehadyar-core' ),
					self::max_hourly()
				),
				array( 'status' => 429 )
			);
		}

		return true;
	}

	/**
	 * Record that a tenant started a job.
	 *
	 * @param int $user_id Tenant id.
	 */
	public static function record( $user_id ) {
		$user_id = (int) $user_id;

		if ( ! $user_id ) {
			return;
		}

		$key   = 'etehadyar_quota_h_' . $user_id;
		$count = (int) get_transient( $key );

		// The window is intentionally a simple rolling hour rather than a
		// sliding log: it is cheap, and the limit is a fairness guard, not a
		// security boundary.
		set_transient( $key, $count + 1, HOUR_IN_SECONDS );
	}
}
