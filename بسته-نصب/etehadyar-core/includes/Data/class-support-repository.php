<?php
/**
 * Tenant-scoped access to support tickets.
 *
 * @package Etehadyar\Data
 */

namespace Etehadyar\Data;

use Etehadyar\Core\Capabilities;
use Etehadyar\Core\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Support ticket repository.
 */
class Support_Repository extends Tenant_Repository {

	/**
	 * {@inheritDoc}
	 */
	protected function table_key() {
		return 'support';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function sortable_columns() {
		return array( 'id', 'created_at', 'status' );
	}

	/**
	 * Never expose the access token hash to any client.
	 *
	 * {@inheritDoc}
	 */
	protected function public_columns() {
		return array(
			'id',
			'ticket_key',
			'name',
			'contact',
			'message',
			'response',
			'status',
			'assigned_user',
			'created_at',
			'user_id',
		);
	}

	/**
	 * {@inheritDoc}
	 */
	protected function shape_row( $row ) {
		foreach ( array( 'id', 'assigned_user', 'user_id' ) as $col ) {
			if ( isset( $row[ $col ] ) ) {
				$row[ $col ] = (int) $row[ $col ];
			}
		}

		unset( $row['access_token_hash'] );

		return $row;
	}

	/**
	 * Staff view: list tickets across all tenants.
	 *
	 * Bypassing the tenant predicate is legitimate for support staff, so it
	 * lives in its own explicitly named method behind a capability check
	 * rather than as a flag on the ordinary listing method.
	 *
	 * @param array $args Query arguments.
	 * @return array|\WP_Error
	 */
	public function list_all_for_staff( $args = array() ) {
		global $wpdb;

		if ( ! current_user_can( Capabilities::MANAGE_SUPPORT ) && ! current_user_can( Capabilities::MANAGE_PLATFORM ) ) {
			return new \WP_Error(
				'etehadyar_forbidden',
				__( 'اجازهٔ مشاهدهٔ صندوق پشتیبانی را ندارید.', 'etehadyar-core' ),
				array( 'status' => 403 )
			);
		}

		if ( ! Schema::table_exists( $this->table() ) ) {
			return array();
		}

		$args = wp_parse_args(
			$args,
			array(
				'per_page' => 20,
				'page'     => 1,
				'status'   => '',
			)
		);

		$per_page = max( 1, min( 100, (int) $args['per_page'] ) );
		$offset   = ( max( 1, (int) $args['page'] ) - 1 ) * $per_page;

		$table  = $this->table();
		$select = $this->select_list();
		$clause = array( '1=1' );
		$values = array();

		if ( $args['status'] ) {
			$clause[] = 'status = %s';
			$values[] = sanitize_key( $args['status'] );
		}

		$values[] = $per_page;
		$values[] = $offset;

		$sql = "SELECT {$select} FROM `{$table}` WHERE " . implode( ' AND ', $clause )
			. ' ORDER BY id DESC LIMIT %d OFFSET %d';

		// phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $values ), ARRAY_A );

		return array_map( array( $this, 'shape_row' ), (array) $rows );
	}
}
