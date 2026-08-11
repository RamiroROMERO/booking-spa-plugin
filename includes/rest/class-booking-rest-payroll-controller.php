<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Booking_Rest_Payroll_Controller {

	protected $namespace = 'booking-plugin/v1';

	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/payroll',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'admin_permissions_check' ),
				),
			)
		);
	}

	public function admin_permissions_check( $request ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'booking_rest_forbidden', __( 'You are not allowed to perform this action.', 'booking-plugin' ), array( 'status' => 403 ) );
		}

		return true;
	}

	public function get_items( $request ) {
		global $wpdb;

		$date_from = $request->get_param( 'date_from' );
		$date_to   = $request->get_param( 'date_to' );

		if ( empty( $date_from ) || empty( $date_to ) ) {
			return new WP_Error( 'booking_rest_missing_date_range', __( 'date_from and date_to are required.', 'booking-plugin' ), array( 'status' => 400 ) );
		}

		$staff_id = $request->get_param( 'staff_id' );

		$summary_view = $wpdb->prefix . 'booking_payroll_daily_summary';
		$staff_table  = $wpdb->prefix . 'booking_staff';

		$where = array( 's.day BETWEEN %s AND %s' );
		$args  = array( $date_from, $date_to );

		if ( ! empty( $staff_id ) ) {
			$where[] = 's.staff_id = %d';
			$args[]  = (int) $staff_id;
		}

		$where_sql = implode( ' AND ', $where );

		$query = "SELECT 
					s.staff_id, 
					st.name AS staff_name,
					SUM(s.total_commission) AS total_commission, 
					SUM(s.appointments_count) AS appointments_count
				 FROM {$summary_view} s
				 LEFT JOIN {$staff_table} st ON st.id = s.staff_id
				 WHERE {$where_sql}
				 GROUP BY s.staff_id, st.name";

		$rows = $wpdb->get_results( $wpdb->prepare( $query, $args ) );

		if ( ! is_array( $rows ) ) {
			$rows = array();
		}

		return rest_ensure_response( array_map( array( $this, 'prepare_item' ), $rows ) );
	}

	protected function prepare_item( $row ) {
		return array(
			'staff_id'           => (int) $row->staff_id,
			'staff_name'         => $row->staff_name ? (string) $row->staff_name : '',
			'total_commission'   => null !== $row->total_commission ? round( (float) $row->total_commission, 2 ) : 0.0,
			'appointments_count' => null !== $row->appointments_count ? (int) $row->appointments_count : 0,
		);
	}
}
