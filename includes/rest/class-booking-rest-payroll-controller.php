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

		register_rest_route(
			$this->namespace,
			'/payroll/mark-paid',
			array(
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'mark_paid' ),
					'permission_callback' => array( $this, 'admin_permissions_check' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/payroll/unmark-paid',
			array(
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'unmark_paid' ),
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
					SUM(s.appointments_count) AS appointments_count,
					SUM(s.paid_count) AS paid_count
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

	public function mark_paid( $request ) {
		return $this->update_paid_status( $request, true );
	}

	public function unmark_paid( $request ) {
		return $this->update_paid_status( $request, false );
	}

	protected function update_paid_status( $request, $mark_as_paid ) {
		global $wpdb;

		$staff_id  = $request->get_param( 'staff_id' );
		$date_from = $request->get_param( 'date_from' );
		$date_to   = $request->get_param( 'date_to' );

		if ( empty( $staff_id ) ) {
			return new WP_Error( 'booking_rest_missing_staff_id', __( 'staff_id is required.', 'booking-plugin' ), array( 'status' => 400 ) );
		}

		if ( empty( $date_from ) || empty( $date_to ) ) {
			return new WP_Error( 'booking_rest_missing_date_range', __( 'date_from and date_to are required.', 'booking-plugin' ), array( 'status' => 400 ) );
		}

		$staff_id = (int) $staff_id;
		$table    = $wpdb->prefix . 'booking_payroll_logs';

		if ( $mark_as_paid ) {
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table} SET paid_at = %s WHERE staff_id = %d AND DATE(created_at) BETWEEN %s AND %s AND paid_at IS NULL",
					current_time( 'mysql', true ),
					$staff_id,
					$date_from,
					$date_to
				)
			);
		} else {
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table} SET paid_at = NULL WHERE staff_id = %d AND DATE(created_at) BETWEEN %s AND %s AND paid_at IS NOT NULL",
					$staff_id,
					$date_from,
					$date_to
				)
			);
		}

		$counts = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) AS appointments_count, SUM(CASE WHEN paid_at IS NOT NULL THEN 1 ELSE 0 END) AS paid_count
				 FROM {$table}
				 WHERE staff_id = %d AND DATE(created_at) BETWEEN %s AND %s",
				$staff_id,
				$date_from,
				$date_to
			)
		);

		return rest_ensure_response(
			array(
				'staff_id'           => $staff_id,
				'appointments_count' => $counts && null !== $counts->appointments_count ? (int) $counts->appointments_count : 0,
				'paid_count'         => $counts && null !== $counts->paid_count ? (int) $counts->paid_count : 0,
			)
		);
	}

	protected function prepare_item( $row ) {
		return array(
			'staff_id'           => (int) $row->staff_id,
			'staff_name'         => $row->staff_name ? (string) $row->staff_name : '',
			'total_commission'   => null !== $row->total_commission ? round( (float) $row->total_commission, 2 ) : 0.0,
			'appointments_count' => null !== $row->appointments_count ? (int) $row->appointments_count : 0,
			'paid_count'         => null !== $row->paid_count ? (int) $row->paid_count : 0,
		);
	}
}
