<?php
/**
 * Tenant-scoped access to the AI job queue.
 *
 * @package Etehadyar\Data
 */

namespace Etehadyar\Data;

defined( 'ABSPATH' ) || exit;

/**
 * Job queue repository.
 */
class Jobs_Repository extends Tenant_Repository {

	/**
	 * {@inheritDoc}
	 */
	protected function table_key() {
		return 'jobs';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function sortable_columns() {
		return array( 'id', 'created_at', 'finished_at', 'priority', 'status' );
	}

	/**
	 * Columns exposed to a member.
	 *
	 * `payload` is deliberately withheld: it can contain the raw prompt plus
	 * internal routing data, and the dashboard never needs it.
	 *
	 * {@inheritDoc}
	 */
	protected function public_columns() {
		return array(
			'id',
			'job_type',
			'status',
			'priority',
			'attempts',
			'provider',
			'model',
			'elapsed',
			'error_text',
			'created_at',
			'started_at',
			'finished_at',
			'user_id',
		);
	}

	/**
	 * Cast numeric columns and translate the status label.
	 *
	 * {@inheritDoc}
	 */
	protected function shape_row( $row ) {
		foreach ( array( 'id', 'priority', 'attempts', 'user_id' ) as $int_col ) {
			if ( isset( $row[ $int_col ] ) ) {
				$row[ $int_col ] = (int) $row[ $int_col ];
			}
		}

		if ( isset( $row['elapsed'] ) ) {
			$row['elapsed'] = (float) $row['elapsed'];
		}

		if ( isset( $row['status'] ) ) {
			$row['status_label'] = self::status_label( $row['status'] );
		}

		return $row;
	}

	/**
	 * Human-readable status.
	 *
	 * @param string $status Raw status.
	 * @return string
	 */
	public static function status_label( $status ) {
		$labels = array(
			'pending'    => __( 'در انتظار', 'etehadyar-core' ),
			'processing' => __( 'در حال پردازش', 'etehadyar-core' ),
			'completed'  => __( 'تکمیل‌شده', 'etehadyar-core' ),
			'failed'     => __( 'ناموفق', 'etehadyar-core' ),
		);

		return $labels[ $status ] ?? $status;
	}

	/**
	 * Count a tenant's jobs that are still occupying a worker slot.
	 *
	 * Drives per-plan concurrency limits in phase 3.
	 *
	 * @param int|null $tenant_id Tenant ID.
	 * @param string   $type      Optional job type filter.
	 * @return int|\WP_Error
	 */
	public function count_active( $tenant_id = null, $type = '' ) {
		global $wpdb;

		$tenant = $this->guard( $tenant_id );

		if ( is_wp_error( $tenant ) ) {
			return $tenant;
		}

		$table  = $this->table();
		$values = array( $tenant );
		$sql    = "SELECT COUNT(*) FROM `{$table}` WHERE user_id = %d AND status IN ('pending','processing')";

		if ( $type ) {
			$sql     .= ' AND job_type = %s';
			$values[] = sanitize_key( $type );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $values ) );
	}

	/**
	 * Status breakdown for a tenant's dashboard.
	 *
	 * @param int|null $tenant_id Tenant ID.
	 * @return array|\WP_Error
	 */
	public function summary( $tenant_id = null ) {
		global $wpdb;

		$tenant = $this->guard( $tenant_id );

		if ( is_wp_error( $tenant ) ) {
			return $tenant;
		}

		$out = array(
			'total'      => 0,
			'pending'    => 0,
			'processing' => 0,
			'completed'  => 0,
			'failed'     => 0,
		);

		$table = $this->table();

		// phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT status, COUNT(*) AS total FROM `{$table}` WHERE user_id = %d GROUP BY status", $tenant ),
			ARRAY_A
		);

		foreach ( (array) $rows as $row ) {
			if ( isset( $out[ $row['status'] ] ) ) {
				$out[ $row['status'] ] = (int) $row['total'];
			}

			$out['total'] += (int) $row['total'];
		}

		return $out;
	}
}
