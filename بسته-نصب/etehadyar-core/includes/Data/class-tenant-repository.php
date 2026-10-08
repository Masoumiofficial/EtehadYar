<?php
/**
 * Base repository that forces every query to be tenant-scoped.
 *
 * Subclasses declare which table they own; they cannot build a query that
 * omits the tenant predicate, because the WHERE clause is assembled here.
 * This is the structural guarantee behind data isolation — it does not rely
 * on each developer remembering to add `AND user_id = x`.
 *
 * @package Etehadyar\Data
 */

namespace Etehadyar\Data;

use Etehadyar\Core\Audit;
use Etehadyar\Core\Schema;
use Etehadyar\Core\Tenant_Context;

defined( 'ABSPATH' ) || exit;

/**
 * Abstract tenant-scoped repository.
 */
abstract class Tenant_Repository {

	/**
	 * Logical table key from Schema.
	 *
	 * @return string
	 */
	abstract protected function table_key();

	/**
	 * Columns that may be used for ordering.
	 *
	 * @return string[]
	 */
	protected function sortable_columns() {
		return array( 'id', 'created_at' );
	}

	/**
	 * Columns safe to return to a member.
	 *
	 * An empty array means "all columns".
	 *
	 * @return string[]
	 */
	protected function public_columns() {
		return array();
	}

	/**
	 * Fully qualified table name.
	 *
	 * @return string
	 */
	public function table() {
		return Schema::table( $this->table_key() );
	}

	/**
	 * Whether the underlying table is usable.
	 *
	 * @return bool
	 */
	public function is_available() {
		$table = $this->table();

		return Schema::table_exists( $table ) && Schema::has_column( $table, 'user_id' );
	}

	/**
	 * Resolve and authorise the tenant for an operation.
	 *
	 * @param int|null $tenant_id Requested tenant, or null for current.
	 * @return int|\WP_Error
	 */
	protected function guard( $tenant_id = null ) {
		if ( ! $this->is_available() ) {
			return new \WP_Error(
				'etehadyar_table_missing',
				__( 'جدول دادهٔ مورد نیاز آماده نیست. لطفاً به‌روزرسانی پایگاه داده را اجرا کنید.', 'etehadyar-core' ),
				array( 'status' => 503 )
			);
		}

		return Tenant_Context::resolve( $tenant_id );
	}

	/**
	 * Build the SELECT column list.
	 *
	 * @return string
	 */
	protected function select_list() {
		$columns = $this->public_columns();

		if ( ! $columns ) {
			return '*';
		}

		$safe = array();

		foreach ( $columns as $column ) {
			if ( preg_match( '/^[a-z0-9_]+$/i', $column ) ) {
				$safe[] = '`' . $column . '`';
			}
		}

		return $safe ? implode( ', ', $safe ) : '*';
	}

	/**
	 * Count rows owned by a tenant.
	 *
	 * @param int|null $tenant_id Tenant ID.
	 * @param array    $where     Extra equality filters.
	 * @return int|\WP_Error
	 */
	public function count( $tenant_id = null, $where = array() ) {
		global $wpdb;

		$tenant = $this->guard( $tenant_id );

		if ( is_wp_error( $tenant ) ) {
			return $tenant;
		}

		$table  = $this->table();
		$clause = array( 'user_id = %d' );
		$values = array( $tenant );

		foreach ( $this->normalise_filters( $where ) as $column => $value ) {
			$clause[] = "`{$column}` = %s";
			$values[] = $value;
		}

		$sql = "SELECT COUNT(*) FROM `{$table}` WHERE " . implode( ' AND ', $clause );

		// phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $values ) );
	}

	/**
	 * List rows owned by a tenant.
	 *
	 * @param int|null $tenant_id Tenant ID.
	 * @param array    $args      Query arguments.
	 * @return array|\WP_Error
	 */
	public function list_for( $tenant_id = null, $args = array() ) {
		global $wpdb;

		$tenant = $this->guard( $tenant_id );

		if ( is_wp_error( $tenant ) ) {
			return $tenant;
		}

		$args = wp_parse_args(
			$args,
			array(
				'per_page' => 20,
				'page'     => 1,
				'orderby'  => 'id',
				'order'    => 'DESC',
				'where'    => array(),
			)
		);

		$per_page = max( 1, min( 100, (int) $args['per_page'] ) );
		$page     = max( 1, (int) $args['page'] );
		$offset   = ( $page - 1 ) * $per_page;

		$orderby = in_array( $args['orderby'], $this->sortable_columns(), true ) ? $args['orderby'] : 'id';
		$order   = 'ASC' === strtoupper( $args['order'] ) ? 'ASC' : 'DESC';

		$table  = $this->table();
		$select = $this->select_list();
		$clause = array( 'user_id = %d' );
		$values = array( $tenant );

		foreach ( $this->normalise_filters( $args['where'] ) as $column => $value ) {
			$clause[] = "`{$column}` = %s";
			$values[] = $value;
		}

		$values[] = $per_page;
		$values[] = $offset;

		$sql = "SELECT {$select} FROM `{$table}` WHERE " . implode( ' AND ', $clause )
			. " ORDER BY `{$orderby}` {$order} LIMIT %d OFFSET %d";

		// phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $values ), ARRAY_A );

		return array_map( array( $this, 'shape_row' ), (array) $rows );
	}

	/**
	 * Fetch a single row, enforcing ownership.
	 *
	 * Ownership is part of the WHERE clause rather than a post-fetch check, so
	 * a mismatched ID simply returns nothing instead of briefly loading another
	 * tenant's record into memory.
	 *
	 * @param int      $id        Row ID.
	 * @param int|null $tenant_id Tenant ID.
	 * @return array|null|\WP_Error
	 */
	public function find( $id, $tenant_id = null ) {
		global $wpdb;

		$tenant = $this->guard( $tenant_id );

		if ( is_wp_error( $tenant ) ) {
			return $tenant;
		}

		$id = (int) $id;

		if ( ! $id ) {
			return null;
		}

		$table  = $this->table();
		$select = $this->select_list();

		$sql = "SELECT {$select} FROM `{$table}` WHERE id = %d AND user_id = %d LIMIT 1";

		// phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row( $wpdb->prepare( $sql, $id, $tenant ), ARRAY_A );

		if ( ! $row ) {
			Audit::log(
				'data.access_denied',
				array(
					'user_id'     => $tenant,
					'object_type' => $this->table_key(),
					'object_id'   => $id,
					'severity'    => 'warning',
				)
			);

			return null;
		}

		return $this->shape_row( $row );
	}

	/**
	 * Delete a row, enforcing ownership.
	 *
	 * @param int      $id        Row ID.
	 * @param int|null $tenant_id Tenant ID.
	 * @return bool|\WP_Error
	 */
	public function delete( $id, $tenant_id = null ) {
		global $wpdb;

		$tenant = $this->guard( $tenant_id );

		if ( is_wp_error( $tenant ) ) {
			return $tenant;
		}

		$table = $this->table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$deleted = $wpdb->delete(
			$table,
			array(
				'id'      => (int) $id,
				'user_id' => $tenant,
			),
			array( '%d', '%d' )
		);

		return (bool) $deleted;
	}

	/**
	 * Insert a row, stamping ownership.
	 *
	 * @param array    $data      Column data.
	 * @param int|null $tenant_id Tenant ID.
	 * @return int|\WP_Error Insert ID.
	 */
	public function insert( $data, $tenant_id = null ) {
		global $wpdb;

		$tenant = $this->guard( $tenant_id );

		if ( is_wp_error( $tenant ) ) {
			return $tenant;
		}

		$data            = (array) $data;
		$data['user_id'] = $tenant;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ok = $wpdb->insert( $this->table(), $data );

		if ( ! $ok ) {
			return new \WP_Error(
				'etehadyar_insert_failed',
				__( 'ثبت اطلاعات انجام نشد.', 'etehadyar-core' ),
				array( 'status' => 500 )
			);
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Normalise extra filters to a safe column => scalar map.
	 *
	 * @param array $where Raw filters.
	 * @return array
	 */
	protected function normalise_filters( $where ) {
		$clean = array();

		foreach ( (array) $where as $column => $value ) {
			if ( ! is_string( $column ) || ! preg_match( '/^[a-z0-9_]+$/i', $column ) ) {
				continue;
			}

			if ( ! Schema::has_column( $this->table(), $column ) ) {
				continue;
			}

			if ( is_scalar( $value ) ) {
				$clean[ $column ] = (string) $value;
			}
		}

		return $clean;
	}

	/**
	 * Hook for subclasses to cast/decorate a row before returning it.
	 *
	 * @param array $row Raw row.
	 * @return array
	 */
	protected function shape_row( $row ) {
		return $row;
	}
}
