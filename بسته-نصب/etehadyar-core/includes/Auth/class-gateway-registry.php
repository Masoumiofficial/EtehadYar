<?php
/**
 * Registry of available SMS gateways.
 *
 * @package Etehadyar\Auth
 */

namespace Etehadyar\Auth;

defined( 'ABSPATH' ) || exit;

/**
 * Gateway locator.
 */
class Gateway_Registry {

	const OPTION_ACTIVE = 'etehadyar_sms_gateway';

	/**
	 * Cached instances.
	 *
	 * @var array<string, SMS_Gateway>
	 */
	protected static $instances = array();

	/**
	 * All registered gateways.
	 *
	 * @return array<string, SMS_Gateway>
	 */
	public static function all() {
		if ( empty( self::$instances ) ) {
			$gateways = array( new Melipayamak_Gateway() );

			/**
			 * Filter the registered SMS gateways.
			 *
			 * @param SMS_Gateway[] $gateways Gateway instances.
			 */
			$gateways = apply_filters( 'etehadyar_sms_gateways', $gateways );

			foreach ( $gateways as $gateway ) {
				if ( $gateway instanceof SMS_Gateway ) {
					self::$instances[ $gateway->id() ] = $gateway;
				}
			}
		}

		return self::$instances;
	}

	/**
	 * Get a gateway by id.
	 *
	 * @param string $id Gateway id.
	 * @return SMS_Gateway|null
	 */
	public static function get( $id ) {
		$all = self::all();

		return $all[ $id ] ?? null;
	}

	/**
	 * The gateway selected in settings.
	 *
	 * @return SMS_Gateway|null
	 */
	public static function active() {
		$id  = get_option( self::OPTION_ACTIVE, 'melipayamak' );
		$all = self::all();

		if ( isset( $all[ $id ] ) ) {
			return $all[ $id ];
		}

		return $all ? reset( $all ) : null;
	}

	/**
	 * Reset the instance cache. Test helper.
	 */
	public static function flush() {
		self::$instances = array();
	}
}
