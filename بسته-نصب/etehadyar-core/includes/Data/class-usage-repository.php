<?php
/**
 * Tenant-scoped access to AI usage records.
 *
 * This table is the metering backbone: phase 3 billing reads consumption from
 * here, so per-tenant accuracy matters more than anywhere else in the system.
 *
 * @package Etehadyar\Data
 */

namespace Etehadyar\Data;

defined( 'ABSPATH' ) || exit;

/**
 * Usage repository.
 */
class Usage_Repository extends Tenant_Repository {

	/**
	 * {@inheritDoc}
	 */
	protected function table_key() {
		return 'usage';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function sortable_columns() {
		return array( 'id', 'created_at', 'total_tokens', 'estimated_cost' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function shape_row( $row ) {
		foreach ( array( 'id', 'prompt_tokens', 'completion_tokens', 'total_tokens', 'job_id', 'user_id' ) as $col ) {
			if ( isset( $row[ $col ] ) ) {
				$row[ $col ] = (int) $row[ $col ];
			}
		}

		if ( isset( $row['estimated_cost'] ) ) {
			$row['estimated_cost'] = (float) $row['estimated_cost'];
		}

		return $row;
	}

	/**
	 * Aggregate consumption for a tenant.
	 *
	 * @param int|null $tenant_id Tenant ID.
	 * @param int      $days      Window in days; 0 means all time.
	 * @return array|\WP_Error
	 */
	public function summary( $tenant_id = null, $days = 0 ) {
		global $wpdb;

		$tenant = $this->guard( $tenant_id );

		if ( is_wp_error( $tenant ) ) {
			return $tenant;
		}

		$table  = $this->table();
		$values = array( $tenant );
		$sql    = "SELECT COUNT(*) AS requests, COALESCE(SUM(total_tokens),0) AS tokens,
			COALESCE(SUM(estimated_cost),0) AS cost
			FROM `{$table}` WHERE user_id = %d";

		$days = (int) $days;

		if ( $days > 0 ) {
			$sql     .= ' AND created_at >= %s';
			$values[] = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row( $wpdb->prepare( $sql, $values ), ARRAY_A );

		return array(
			'requests' => (int) ( $row['requests'] ?? 0 ),
			'tokens'   => (int) ( $row['tokens'] ?? 0 ),
			'cost'     => round( (float) ( $row['cost'] ?? 0 ), 6 ),
			'days'     => $days,
		);
	}

	/**
	 * Daily consumption series for charts.
	 *
	 * @param int|null $tenant_id Tenant ID.
	 * @param int      $days      Number of days.
	 * @return array|\WP_Error
	 */
	public function daily( $tenant_id = null, $days = 14 ) {
		global $wpdb;

		$tenant = $this->guard( $tenant_id );

		if ( is_wp_error( $tenant ) ) {
			return $tenant;
		}

		$days = max( 1, min( 90, (int) $days ) );
		$out  = array();

		for ( $i = $days - 1; $i >= 0; $i-- ) {
			$date         = gmdate( 'Y-m-d', time() - ( $i * DAY_IN_SECONDS ) );
			$out[ $date ] = array(
				'date'     => $date,
				'requests' => 0,
				'tokens'   => 0,
				'cost'     => 0.0,
			);
		}

		$table = $this->table();
		$from  = gmdate( 'Y-m-d 00:00:00', time() - ( ( $days - 1 ) * DAY_IN_SECONDS ) );

		$sql = "SELECT DATE(created_at) AS day, COUNT(*) AS requests,
			COALESCE(SUM(total_tokens),0) AS tokens, COALESCE(SUM(estimated_cost),0) AS cost
			FROM `{$table}` WHERE user_id = %d AND created_at >= %s
			GROUP BY DATE(created_at) ORDER BY day ASC";

		// phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $tenant, $from ), ARRAY_A );

		foreach ( (array) $rows as $row ) {
			if ( isset( $out[ $row['day'] ] ) ) {
				$out[ $row['day'] ]['requests'] = (int) $row['requests'];
				$out[ $row['day'] ]['tokens']   = (int) $row['tokens'];
				$out[ $row['day'] ]['cost']     = round( (float) $row['cost'], 6 );
			}
		}

		return array_values( $out );
	}

	/**
	 * Record a usage entry for a tenant.
	 *
	 * @param array    $data      Usage columns.
	 * @param int|null $tenant_id Tenant ID.
	 * @return int|\WP_Error
	 */
	public function record( $data, $tenant_id = null ) {
		$prompt     = max( 0, (int) ( $data['prompt_tokens'] ?? 0 ) );
		$completion = max( 0, (int) ( $data['completion_tokens'] ?? 0 ) );

		return $this->insert(
			array(
				'provider'          => sanitize_key( $data['provider'] ?? '' ),
				'model'             => sanitize_text_field( $data['model'] ?? '' ),
				'prompt_tokens'     => $prompt,
				'completion_tokens' => $completion,
				'total_tokens'      => $prompt + $completion,
				'estimated_cost'    => round( (float) ( $data['estimated_cost'] ?? 0 ), 8 ),
				'job_id'            => (int) ( $data['job_id'] ?? 0 ),
			),
			$tenant_id
		);
	}
}
