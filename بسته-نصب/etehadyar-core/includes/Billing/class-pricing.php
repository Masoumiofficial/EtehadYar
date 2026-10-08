<?php
/**
 * Price list for billable operations.
 *
 * Prices are whole Rial per unit. Administrators edit them from the dashboard;
 * defaults exist so the platform is never in a state where an operation has no
 * price and silently runs for free.
 *
 * @package Etehadyar\Billing
 */

namespace Etehadyar\Billing;

defined( 'ABSPATH' ) || exit;

/**
 * Pricing table.
 */
class Pricing {

	const OPTION_RATES = 'etehadyar_pricing_rates';

	/**
	 * Default rates in Rial.
	 *
	 * @return array<string, array{label:string, unit:string, price:int}>
	 */
	public static function defaults() {
		return array(
			'content_word'   => array(
				'label' => __( 'تولید محتوا', 'etehadyar-core' ),
				'unit'  => __( 'به ازای هر ۱۰۰ کلمه', 'etehadyar-core' ),
				'price' => 5000,
			),
			'image'          => array(
				'label' => __( 'تولید تصویر', 'etehadyar-core' ),
				'unit'  => __( 'هر تصویر', 'etehadyar-core' ),
				'price' => 20000,
			),
			'voice_minute'   => array(
				'label' => __( 'تبدیل متن به گفتار', 'etehadyar-core' ),
				'unit'  => __( 'هر دقیقه', 'etehadyar-core' ),
				'price' => 15000,
			),
			'transcribe_min' => array(
				'label' => __( 'پیاده‌سازی صوت', 'etehadyar-core' ),
				'unit'  => __( 'هر دقیقه', 'etehadyar-core' ),
				'price' => 12000,
			),
			'chat_message'   => array(
				'label' => __( 'پیام گفت‌وگو', 'etehadyar-core' ),
				'unit'  => __( 'هر پیام', 'etehadyar-core' ),
				'price' => 2000,
			),
			'video_minute'   => array(
				'label' => __( 'تولید ویدیو', 'etehadyar-core' ),
				'unit'  => __( 'هر دقیقه', 'etehadyar-core' ),
				'price' => 80000,
			),
		);
	}

	/**
	 * Current rates, with admin overrides applied.
	 *
	 * @return array
	 */
	public static function all() {
		$saved    = get_option( self::OPTION_RATES, array() );
		$defaults = self::defaults();

		if ( ! is_array( $saved ) ) {
			$saved = array();
		}

		foreach ( $defaults as $key => $config ) {
			if ( isset( $saved[ $key ] ) && is_numeric( $saved[ $key ] ) ) {
				$defaults[ $key ]['price'] = max( 0, (int) $saved[ $key ] );
			}
		}

		/**
		 * Filter the platform price list.
		 *
		 * @param array $defaults Rate definitions.
		 */
		return apply_filters( 'etehadyar_pricing_rates', $defaults );
	}

	/**
	 * Unit price for an operation.
	 *
	 * @param string $key Operation key.
	 * @return int Rial, 0 when unknown.
	 */
	public static function price( $key ) {
		$rates = self::all();

		return isset( $rates[ $key ] ) ? (int) $rates[ $key ]['price'] : 0;
	}

	/**
	 * Cost of an operation for a given quantity.
	 *
	 * Quantities are rounded up: a 1.2 minute clip costs two minutes. Charging
	 * a fraction of a unit would be impossible to explain on an invoice.
	 *
	 * @param string $key      Operation key.
	 * @param float  $quantity Units consumed.
	 * @return int Rial.
	 */
	public static function cost( $key, $quantity = 1 ) {
		$quantity = max( 0, (float) $quantity );

		if ( 0.0 === $quantity ) {
			return 0;
		}

		return (int) ceil( $quantity ) * self::price( $key );
	}

	/**
	 * Save admin overrides.
	 *
	 * @param array $rates key => price.
	 * @return bool
	 */
	public static function save( $rates ) {
		$clean    = array();
		$defaults = self::defaults();

		foreach ( (array) $rates as $key => $price ) {
			$key = sanitize_key( $key );

			if ( isset( $defaults[ $key ] ) && is_numeric( $price ) ) {
				$clean[ $key ] = max( 0, (int) $price );
			}
		}

		return update_option( self::OPTION_RATES, $clean, false );
	}
}
