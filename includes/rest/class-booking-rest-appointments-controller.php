<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Booking_Rest_Appointments_Controller {

	protected $namespace = 'booking-plugin/v1';
	protected $rest_base = 'appointments';

	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'admin_permissions_check' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => '__return_true',
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/block',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_block' ),
					'permission_callback' => array( $this, 'admin_permissions_check' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/mine',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_mine' ),
					'permission_callback' => array( $this, 'logged_in_permissions_check' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => '__return_true',
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => '__return_true',
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

	public function logged_in_permissions_check( $request ) {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'booking_rest_forbidden', __( 'You must be logged in to perform this action.', 'booking-plugin' ), array( 'status' => 403 ) );
		}

		return true;
	}

	public function get_items( $request ) {
		global $wpdb;

		$table = $wpdb->prefix . 'booking_appointments';

		$page     = max( 1, (int) $request->get_param( 'page' ) ?: 1 );
		$per_page = (int) $request->get_param( 'per_page' ) ?: 10;
		$per_page = min( max( $per_page, 1 ), 100 );
		$offset   = ( $page - 1 ) * $per_page;

		$where = array( '1=1' );
		$args  = array();

		$status = $request->get_param( 'status' );

		if ( ! empty( $status ) ) {
			$where[] = 'status = %s';
			$args[]  = $status;
		}

		$staff_id = $request->get_param( 'staff_id' );

		if ( ! empty( $staff_id ) ) {
			$where[] = 'staff_id = %d';
			$args[]  = (int) $staff_id;
		}

		$date_from = $request->get_param( 'date_from' );

		if ( ! empty( $date_from ) ) {
			$where[] = 'start_datetime >= %s';
			$args[]  = $date_from . ' 00:00:00';
		}

		$date_to = $request->get_param( 'date_to' );

		if ( ! empty( $date_to ) ) {
			$where[] = 'start_datetime <= %s';
			$args[]  = $date_to . ' 23:59:59';
		}

		$where_sql = implode( ' AND ', $where );

		$total = (int) $wpdb->get_var(
			$args ? $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}", $args ) : "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}"
		);

		$query_args   = $args;
		$query_args[] = $per_page;
		$query_args[] = $offset;

		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY start_datetime ASC LIMIT %d OFFSET %d", $query_args )
		);

		$items = array_map( array( $this, 'prepare_item' ), $rows );

		$response = rest_ensure_response( $items );
		$response->header( 'X-WP-Total', $total );
		$response->header( 'X-WP-TotalPages', (int) ceil( $total / $per_page ) );

		return $response;
	}

	public function get_mine( $request ) {
		global $wpdb;

		$table = $wpdb->prefix . 'booking_appointments';

		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d ORDER BY start_datetime DESC", get_current_user_id() )
		);

		return rest_ensure_response( array_map( array( $this, 'prepare_item' ), $rows ) );
	}

	public function get_item( $request ) {
		$row = $this->get_row( (int) $request['id'] );

		if ( ! $row ) {
			return new WP_Error( 'booking_rest_not_found', __( 'Appointment not found.', 'booking-plugin' ), array( 'status' => 404 ) );
		}

		if ( ! $this->resolve_access( $row, $request ) ) {
			return new WP_Error( 'booking_rest_forbidden', __( 'You are not allowed to view this appointment.', 'booking-plugin' ), array( 'status' => 403 ) );
		}

		return rest_ensure_response( $this->prepare_item( $row ) );
	}

	public function create_item( $request ) {
		$service_id = $request->get_param( 'service_id' );

		if ( empty( $service_id ) ) {
			return new WP_Error( 'booking_rest_invalid_service', __( 'service_id is required.', 'booking-plugin' ), array( 'status' => 400 ) );
		}

		$start_datetime = $request->get_param( 'start_datetime' );

		if ( empty( $start_datetime ) ) {
			return new WP_Error( 'booking_rest_invalid_datetime', __( 'start_datetime is required.', 'booking-plugin' ), array( 'status' => 400 ) );
		}

		try {
			new DateTimeImmutable( $start_datetime, new DateTimeZone( 'UTC' ) );
		} catch ( Exception $e ) {
			return new WP_Error( 'booking_rest_invalid_datetime', __( 'start_datetime must be a valid ISO 8601 datetime.', 'booking-plugin' ), array( 'status' => 400 ) );
		}

		$user_id     = null;
		$guest_name  = null;
		$guest_email = null;
		$guest_phone = null;

		if ( is_user_logged_in() ) {
			$user_id = get_current_user_id();
		} else {
			$guest_name  = trim( (string) $request->get_param( 'guest_name' ) );
			$guest_email = trim( (string) $request->get_param( 'guest_email' ) );
			$guest_phone = $request->get_param( 'guest_phone' );

			if ( '' === $guest_name ) {
				return new WP_Error( 'booking_rest_invalid_guest_name', __( 'guest_name is required for guest bookings.', 'booking-plugin' ), array( 'status' => 400 ) );
			}

			if ( '' === $guest_email || ! is_email( $guest_email ) ) {
				return new WP_Error( 'booking_rest_invalid_guest_email', __( 'A valid guest_email is required for guest bookings.', 'booking-plugin' ), array( 'status' => 400 ) );
			}
		}

		$requested_staff_id = $request->get_param( 'staff_id' );
		$requested_staff_id = empty( $requested_staff_id ) ? null : (int) $requested_staff_id;

		$availability = new Booking_Plugin_Availability();

		if ( $requested_staff_id ) {
			$candidate_staff_ids = array( $requested_staff_id );
		} else {
			$candidate_staff_ids = $availability->get_eligible_staff_ids( (int) $service_id );

			if ( is_wp_error( $candidate_staff_ids ) ) {
				return $candidate_staff_ids;
			}
		}

		if ( empty( $candidate_staff_ids ) ) {
			return new WP_Error( 'booking_rest_no_availability', __( 'No staff available for this service.', 'booking-plugin' ), array( 'status' => 409 ) );
		}

		$insert_data = array(
			'service_id'  => (int) $service_id,
			'user_id'     => $user_id,
			'guest_name'  => $guest_name,
			'guest_email' => $guest_email,
			'guest_phone' => $guest_phone,
			'notes'       => $request->get_param( 'notes' ),
		);

		foreach ( $candidate_staff_ids as $staff_id ) {
			$result = $this->attempt_booking( (int) $service_id, (int) $staff_id, $start_datetime, $insert_data );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			if ( $result ) {
				$row = $this->get_row( $result );

				do_action( 'booking_plugin_appointment_created', $row );

				$response_data = array(
					'id'             => (int) $row->id,
					'status'         => $row->status,
					'start_datetime' => $this->to_iso( $row->start_datetime ),
					'end_datetime'   => $this->to_iso( $row->end_datetime ),
					'staff_id'       => (int) $row->staff_id,
					'access_token'   => $row->access_token,
				);

				$checkout_url = $this->maybe_create_payment_order( $row );

				if ( $checkout_url ) {
					$response_data['checkout_url'] = $checkout_url;
				}

				$response = rest_ensure_response( $response_data );
				$response->set_status( 201 );

				return $response;
			}
		}

		return new WP_Error( 'booking_rest_slot_unavailable', __( 'The requested slot is not available.', 'booking-plugin' ), array( 'status' => 409 ) );
	}

	protected function maybe_create_payment_order( $appointment ) {
		if ( empty( $appointment->service_id ) ) {
			return null;
		}

		global $wpdb;

		$services_table   = $wpdb->prefix . 'booking_services';
		$requires_payment = (bool) $wpdb->get_var(
			$wpdb->prepare( "SELECT requires_payment FROM {$services_table} WHERE id = %d", (int) $appointment->service_id )
		);

		if ( ! $requires_payment ) {
			return null;
		}

		if ( ! Booking_Plugin_WooCommerce::is_active() ) {
			// Degradacion consciente (ver Risks de SPEC 10): sin WooCommerce
			// activo, esta cita se trata como si no requiriera pago. Se deja
			// constancia en el log para que el admin lo note (la pagina de
			// Configuracion tambien muestra el estado de WooCommerce).
			error_log(
				sprintf(
					'[booking-plugin] Cita #%d es de un servicio que requiere pago, pero WooCommerce esta inactivo: se omite la generacion del pedido.',
					(int) $appointment->id
				)
			);

			return null;
		}

		return Booking_Plugin_WooCommerce::create_order_for_appointment( $appointment );
	}

	public function create_block( $request ) {
		global $wpdb;

		$staff_id = $request->get_param( 'staff_id' );

		if ( empty( $staff_id ) ) {
			return new WP_Error( 'booking_rest_invalid_staff', __( 'staff_id is required.', 'booking-plugin' ), array( 'status' => 400 ) );
		}

		$staff_table  = $wpdb->prefix . 'booking_staff';
		$staff_exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$staff_table} WHERE id = %d", (int) $staff_id ) );

		if ( ! $staff_exists ) {
			return new WP_Error( 'booking_rest_invalid_staff', __( 'Staff not found.', 'booking-plugin' ), array( 'status' => 400 ) );
		}

		$start = $request->get_param( 'start_datetime' );
		$end   = $request->get_param( 'end_datetime' );

		if ( empty( $start ) || empty( $end ) ) {
			return new WP_Error( 'booking_rest_invalid_datetime', __( 'start_datetime and end_datetime are required.', 'booking-plugin' ), array( 'status' => 400 ) );
		}

		try {
			$start_dt = new DateTimeImmutable( $start, new DateTimeZone( 'UTC' ) );
			$end_dt   = new DateTimeImmutable( $end, new DateTimeZone( 'UTC' ) );
		} catch ( Exception $e ) {
			return new WP_Error( 'booking_rest_invalid_datetime', __( 'start_datetime and end_datetime must be valid ISO 8601 datetimes.', 'booking-plugin' ), array( 'status' => 400 ) );
		}

		if ( $end_dt <= $start_dt ) {
			return new WP_Error( 'booking_rest_invalid_datetime', __( 'end_datetime must be after start_datetime.', 'booking-plugin' ), array( 'status' => 400 ) );
		}

		$appointments_table = $wpdb->prefix . 'booking_appointments';
		$services_table      = $wpdb->prefix . 'booking_services';
		$lock_from            = $start_dt->modify( '-1 day' )->format( 'Y-m-d H:i:s' );
		$lock_to              = $end_dt->modify( '+1 day' )->format( 'Y-m-d H:i:s' );

		$wpdb->query( 'START TRANSACTION' );

		$wpdb->get_results(
			$wpdb->prepare(
				"SELECT id FROM {$appointments_table} WHERE staff_id = %d AND status != 'cancelled' AND start_datetime < %s AND start_datetime >= %s FOR UPDATE",
				(int) $staff_id,
				$lock_to,
				$lock_from
			)
		);

		$collision = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT a.id FROM {$appointments_table} a
				 LEFT JOIN {$services_table} s ON s.id = a.service_id
				 WHERE a.staff_id = %d
				   AND a.status != 'cancelled'
				   AND a.start_datetime < %s
				   AND DATE_ADD( a.end_datetime, INTERVAL COALESCE( s.buffer_minutes, 0 ) MINUTE ) > %s
				 LIMIT 1",
				(int) $staff_id,
				$end_dt->format( 'Y-m-d H:i:s' ),
				$start_dt->format( 'Y-m-d H:i:s' )
			)
		);

		if ( $collision ) {
			$wpdb->query( 'ROLLBACK' );

			return new WP_Error( 'booking_rest_slot_unavailable', __( 'The requested range overlaps with an existing appointment.', 'booking-plugin' ), array( 'status' => 409 ) );
		}

		$now = current_time( 'mysql', true );

		$wpdb->insert(
			$appointments_table,
			array(
				'service_id'     => null,
				'staff_id'       => (int) $staff_id,
				'user_id'        => null,
				'guest_name'     => null,
				'guest_email'    => null,
				'guest_phone'    => null,
				'start_datetime' => $start_dt->format( 'Y-m-d H:i:s' ),
				'end_datetime'   => $end_dt->format( 'Y-m-d H:i:s' ),
				'status'         => 'blocked',
				'notes'          => $request->get_param( 'notes' ),
				'created_at'     => $now,
				'updated_at'     => $now,
			)
		);

		$new_id = (int) $wpdb->insert_id;

		$wpdb->query( 'COMMIT' );

		$row = $this->get_row( $new_id );

		$response = rest_ensure_response( $this->prepare_item( $row ) );
		$response->set_status( 201 );

		return $response;
	}

	public function update_item( $request ) {
		$row = $this->get_row( (int) $request['id'] );

		if ( ! $row ) {
			return new WP_Error( 'booking_rest_not_found', __( 'Appointment not found.', 'booking-plugin' ), array( 'status' => 404 ) );
		}

		$access = $this->resolve_access( $row, $request );

		if ( ! $access ) {
			return new WP_Error( 'booking_rest_forbidden', __( 'You are not allowed to modify this appointment.', 'booking-plugin' ), array( 'status' => 403 ) );
		}

		$is_admin        = ( 'admin' === $access );
		$previous_status = $row->status;

		$status    = $request->get_param( 'status' );
		$new_start = $request->get_param( 'start_datetime' );

		if ( null !== $status ) {
			$allowed_statuses = array( 'confirmed', 'completed', 'no_show', 'cancelled', 'blocked' );

			if ( ! in_array( $status, $allowed_statuses, true ) ) {
				return new WP_Error( 'booking_rest_invalid_status', __( 'Invalid status value.', 'booking-plugin' ), array( 'status' => 400 ) );
			}

			if ( in_array( $status, array( 'confirmed', 'completed', 'no_show', 'blocked' ), true ) && ! $is_admin ) {
				return new WP_Error( 'booking_rest_forbidden', __( 'Only an administrator can set this status.', 'booking-plugin' ), array( 'status' => 403 ) );
			}

			if ( 'cancelled' === $status && ! $is_admin ) {
				$window_error = $this->check_cancellation_window( $row );

				if ( is_wp_error( $window_error ) ) {
					return $window_error;
				}
			}
		}

		global $wpdb;

		$table = $wpdb->prefix . 'booking_appointments';

		if ( null !== $new_start ) {
			if ( ! $is_admin ) {
				$window_error = $this->check_cancellation_window( $row );

				if ( is_wp_error( $window_error ) ) {
					return $window_error;
				}
			}

			$lock = $this->lock_and_revalidate( (int) $row->service_id, (int) $row->staff_id, $new_start, (int) $row->id );

			if ( is_wp_error( $lock ) ) {
				return $lock;
			}

			if ( ! $lock['available'] ) {
				$wpdb->query( 'ROLLBACK' );

				return new WP_Error( 'booking_rest_slot_unavailable', __( 'The requested slot is not available.', 'booking-plugin' ), array( 'status' => 409 ) );
			}

			$data = array(
				'start_datetime' => $lock['candidate_start']->format( 'Y-m-d H:i:s' ),
				'end_datetime'   => $lock['candidate_end']->format( 'Y-m-d H:i:s' ),
				'updated_at'     => current_time( 'mysql', true ),
			);

			if ( null !== $status ) {
				$data['status'] = $status;
			}

			$wpdb->update( $table, $data, array( 'id' => (int) $row->id ) );

			$wpdb->query( 'COMMIT' );
		} elseif ( null !== $status ) {
			$wpdb->update(
				$table,
				array(
					'status'     => $status,
					'updated_at' => current_time( 'mysql', true ),
				),
				array( 'id' => (int) $row->id )
			);
		}

		$row = $this->get_row( (int) $row->id );

		if ( 'cancelled' === $row->status && ! in_array( $previous_status, array( 'cancelled', 'blocked' ), true ) ) {
			do_action( 'booking_plugin_appointment_cancelled', $row );
		}

		return rest_ensure_response( $this->prepare_item( $row ) );
	}

	protected function attempt_booking( $service_id, $staff_id, $start_datetime, array $insert_data ) {
		global $wpdb;

		$lock = $this->lock_and_revalidate( $service_id, $staff_id, $start_datetime );

		if ( is_wp_error( $lock ) ) {
			return $lock;
		}

		if ( ! $lock['available'] ) {
			$wpdb->query( 'ROLLBACK' );

			return false;
		}

		$appointments_table = $wpdb->prefix . 'booking_appointments';
		$access_token        = wp_generate_password( 32, false, false );
		$now                 = current_time( 'mysql', true );

		$wpdb->insert(
			$appointments_table,
			array_merge(
				$insert_data,
				array(
					'staff_id'       => $staff_id,
					'start_datetime' => $lock['candidate_start']->format( 'Y-m-d H:i:s' ),
					'end_datetime'   => $lock['candidate_end']->format( 'Y-m-d H:i:s' ),
					'status'         => 'pending',
					'access_token'   => $access_token,
					'created_at'     => $now,
					'updated_at'     => $now,
				)
			)
		);

		$new_id = (int) $wpdb->insert_id;

		$wpdb->query( 'COMMIT' );

		return $new_id;
	}

	/**
	 * Opens a transaction, locks the staff's appointments in the affected
	 * time range (SELECT ... FOR UPDATE), and revalidates availability.
	 * Leaves the transaction OPEN on success/unavailable so the caller can
	 * write inside the same lock and COMMIT, or ROLLBACK if it decides not
	 * to proceed. Returns a WP_Error (transaction already rolled back, if
	 * one was started) when the input itself is invalid.
	 */
	protected function lock_and_revalidate( $service_id, $staff_id, $start_datetime, $exclude_appointment_id = 0 ) {
		global $wpdb;

		$services_table = $wpdb->prefix . 'booking_services';
		$service        = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$services_table} WHERE id = %d AND status = 'active'", $service_id ) );

		if ( ! $service ) {
			return new WP_Error( 'booking_rest_invalid_service', __( 'Service not found or inactive.', 'booking-plugin' ), array( 'status' => 404 ) );
		}

		try {
			$candidate_start = new DateTimeImmutable( $start_datetime, new DateTimeZone( 'UTC' ) );
		} catch ( Exception $e ) {
			return new WP_Error( 'booking_rest_invalid_datetime', __( 'start_datetime must be a valid ISO 8601 datetime.', 'booking-plugin' ), array( 'status' => 400 ) );
		}

		$candidate_end = $candidate_start->modify( '+' . (int) $service->duration_minutes . ' minutes' );

		$appointments_table = $wpdb->prefix . 'booking_appointments';
		$lock_from           = $candidate_start->modify( '-1 day' )->format( 'Y-m-d H:i:s' );
		$lock_to             = $candidate_end->modify( '+1 day' )->format( 'Y-m-d H:i:s' );
		$exclude_sql         = $exclude_appointment_id ? ' AND id != ' . (int) $exclude_appointment_id : '';

		$wpdb->query( 'START TRANSACTION' );

		$wpdb->get_results(
			$wpdb->prepare(
				"SELECT id FROM {$appointments_table} WHERE staff_id = %d AND status != 'cancelled' AND start_datetime < %s AND start_datetime >= %s{$exclude_sql} FOR UPDATE",
				$staff_id,
				$lock_to,
				$lock_from
			)
		);

		$availability = new Booking_Plugin_Availability();
		$available    = $availability->is_staff_slot_available( $service_id, $staff_id, $start_datetime, $exclude_appointment_id );

		if ( is_wp_error( $available ) ) {
			$wpdb->query( 'ROLLBACK' );

			return $available;
		}

		return array(
			'available'       => $available,
			'candidate_start' => $candidate_start,
			'candidate_end'   => $candidate_end,
		);
	}

	protected function check_cancellation_window( $row ) {
		$settings = Booking_Plugin_Settings::get_settings();
		$now      = new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
		$start    = new DateTimeImmutable( $row->start_datetime, new DateTimeZone( 'UTC' ) );

		$hours_until = ( $start->getTimestamp() - $now->getTimestamp() ) / 3600;

		if ( $hours_until < (int) $settings['min_cancellation_hours'] ) {
			return new WP_Error(
				'booking_rest_cancellation_window_closed',
				__( 'This appointment can no longer be cancelled or rescheduled without administrator permission.', 'booking-plugin' ),
				array( 'status' => 409 )
			);
		}

		return true;
	}

	protected function resolve_access( $row, $request ) {
		if ( current_user_can( 'manage_options' ) ) {
			return 'admin';
		}

		if ( is_user_logged_in() && $row->user_id && (int) $row->user_id === get_current_user_id() ) {
			return 'owner';
		}

		$token = $request->get_param( 'token' );

		if ( $token && $row->access_token && hash_equals( (string) $row->access_token, (string) $token ) ) {
			return 'token';
		}

		return false;
	}

	protected function get_row( $id ) {
		global $wpdb;

		$table = $wpdb->prefix . 'booking_appointments';

		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) );
	}

	protected function to_iso( $mysql_datetime ) {
		return ( new DateTimeImmutable( $mysql_datetime, new DateTimeZone( 'UTC' ) ) )->format( 'Y-m-d\TH:i:s\Z' );
	}

	protected function prepare_item( $row ) {
		$data = array(
			'id'             => (int) $row->id,
			'service_id'     => $row->service_id ? (int) $row->service_id : null,
			'staff_id'       => (int) $row->staff_id,
			'user_id'        => $row->user_id ? (int) $row->user_id : null,
			'guest_name'     => $row->guest_name,
			'guest_email'    => $row->guest_email,
			'guest_phone'    => $row->guest_phone,
			'start_datetime' => $this->to_iso( $row->start_datetime ),
			'end_datetime'   => $this->to_iso( $row->end_datetime ),
			'status'         => $row->status,
			'notes'          => $row->notes,
			'access_token'   => $row->access_token,
			'wc_order_id'    => $row->wc_order_id ? (int) $row->wc_order_id : null,
			'created_at'     => $this->to_iso( $row->created_at ),
			'updated_at'     => $this->to_iso( $row->updated_at ),
		);

		if ( $row->wc_order_id && Booking_Plugin_WooCommerce::is_active() ) {
			$order = wc_get_order( (int) $row->wc_order_id );

			if ( $order ) {
				$data['payment_status']    = wc_get_order_status_name( $order->get_status() );
				$data['wc_order_edit_url'] = $order->get_edit_order_url();
			}
		}

		return $data;
	}
}
