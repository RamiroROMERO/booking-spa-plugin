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

		$has_pending_balance = $request->get_param( 'has_pending_balance' );

		if ( ! empty( $has_pending_balance ) && 'false' !== $has_pending_balance ) {
			$where[] = 'balance_due IS NOT NULL AND balance_collected_at IS NULL';
		}

		$has_deposit = $request->get_param( 'has_deposit' );

		if ( ! empty( $has_deposit ) && 'false' !== $has_deposit ) {
			$where[] = 'balance_due IS NOT NULL';
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

		$addon_ids = $this->parse_addon_ids( $request->get_param( 'addon_ids' ) );
		$addons    = array();

		if ( ! empty( $addon_ids ) ) {
			$addons = $this->get_valid_addons( (int) $service_id, $addon_ids );

			if ( is_wp_error( $addons ) ) {
				return $addons;
			}
		}

		$use_credit_id = $request->get_param( 'use_credit_id' );
		$credit        = null;

		if ( ! empty( $use_credit_id ) ) {
			if ( ! is_user_logged_in() ) {
				return new WP_Error( 'booking_rest_forbidden', __( 'You must be logged in to use a credit.', 'booking-plugin' ), array( 'status' => 403 ) );
			}

			if ( ! empty( $addon_ids ) ) {
				return new WP_Error( 'booking_rest_credit_addons_not_allowed', __( 'A credit cannot be combined with add-ons.', 'booking-plugin' ), array( 'status' => 400 ) );
			}

			$credit = $this->validate_credit( (int) $use_credit_id, (int) $service_id, get_current_user_id() );

			if ( is_wp_error( $credit ) ) {
				return $credit;
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
			$result = $this->attempt_booking( (int) $service_id, (int) $staff_id, $start_datetime, $insert_data, $addon_ids, $addons, $credit );

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

				if ( $credit ) {
					$response_data['paid_with_credit_id'] = (int) $row->paid_with_credit_id;
					$response_data['credits_consumed']    = (int) $row->credits_consumed;
				} else {
					$payment_result = $this->maybe_create_payment_order( $row );

					if ( $payment_result ) {
						if ( ! empty( $payment_result['checkout_url'] ) ) {
							$response_data['checkout_url'] = $payment_result['checkout_url'];
						}

						if ( null !== $payment_result['deposit_amount'] ) {
							$response_data['deposit_amount'] = $payment_result['deposit_amount'];
							$response_data['balance_due']    = $payment_result['balance_due'];

							global $wpdb;

							$wpdb->update(
								$wpdb->prefix . 'booking_appointments',
								array(
									'deposit_amount' => $payment_result['deposit_amount'],
									'balance_due'    => $payment_result['balance_due'],
								),
								array( 'id' => (int) $row->id ),
								array( '%f', '%f' ),
								array( '%d' )
							);
						}
					}
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

	/**
	 * Chequeo rapido (sin lock) de que use_credit_id pertenece al usuario,
	 * cubre el service_id elegido y tiene saldo. El descuento real vuelve a
	 * verificar el saldo bajo FOR UPDATE dentro de attempt_booking() (ver
	 * Risks de SPEC 12) para evitar una condicion de carrera entre dos
	 * reservas simultaneas con el mismo credito.
	 */
	protected function validate_credit( $credit_id, $service_id, $wp_user_id ) {
		global $wpdb;

		$credits_table = $wpdb->prefix . 'booking_user_credits';
		$pivot_table   = $wpdb->prefix . 'booking_package_services';

		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$credits_table} WHERE id = %d", $credit_id ) );

		if ( ! $row || (int) $row->wp_user_id !== $wp_user_id ) {
			return new WP_Error( 'booking_rest_invalid_credit', __( 'use_credit_id does not belong to the current user.', 'booking-plugin' ), array( 'status' => 400 ) );
		}

		$credit_cost = $wpdb->get_var(
			$wpdb->prepare( "SELECT credit_cost FROM {$pivot_table} WHERE package_id = %d AND service_id = %d", (int) $row->package_id, $service_id )
		);

		if ( null === $credit_cost ) {
			return new WP_Error( 'booking_rest_invalid_credit', __( 'This service is not included in the package for this credit.', 'booking-plugin' ), array( 'status' => 400 ) );
		}

		if ( (int) $row->remaining_sessions < (int) $credit_cost ) {
			return new WP_Error( 'booking_rest_insufficient_credit', __( 'Insufficient credit balance.', 'booking-plugin' ), array( 'status' => 409 ) );
		}

		return array(
			'id'          => (int) $row->id,
			'credit_cost' => (int) $credit_cost,
		);
	}

	/**
	 * Bloquea la fila de user_credits (FOR UPDATE) dentro de la transaccion
	 * ya abierta por lock_and_revalidate(), revalida el saldo y lo descuenta.
	 * Debe llamarse solo entre START TRANSACTION y COMMIT/ROLLBACK.
	 */
	protected function lock_and_consume_credit( $credit_id, $credit_cost ) {
		global $wpdb;

		$table = $wpdb->prefix . 'booking_user_credits';

		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d FOR UPDATE", $credit_id ) );

		if ( ! $row || (int) $row->remaining_sessions < $credit_cost ) {
			return false;
		}

		$wpdb->update(
			$table,
			array( 'remaining_sessions' => (int) $row->remaining_sessions - $credit_cost ),
			array( 'id' => $credit_id ),
			array( '%d' ),
			array( '%d' )
		);

		return true;
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

		if ( $request->has_param( 'balance_collected' ) && $request->get_param( 'balance_collected' ) ) {
			if ( ! $is_admin ) {
				return new WP_Error( 'booking_rest_forbidden', __( 'You are not allowed to perform this action.', 'booking-plugin' ), array( 'status' => 403 ) );
			}

			if ( null === $row->balance_due || null !== $row->balance_collected_at ) {
				return new WP_Error( 'booking_rest_balance_not_collectible', __( 'This appointment has no pending balance to collect, or it was already collected.', 'booking-plugin' ), array( 'status' => 409 ) );
			}

			global $wpdb;

			$wpdb->update(
				$wpdb->prefix . 'booking_appointments',
				array(
					'balance_collected_at' => current_time( 'mysql', true ),
					'updated_at'            => current_time( 'mysql', true ),
				),
				array( 'id' => (int) $row->id ),
				array( '%s', '%s' ),
				array( '%d' )
			);

			$row = $this->get_row( (int) $row->id );

			return rest_ensure_response( $this->prepare_item( $row ) );
		}

		$status             = $request->get_param( 'status' );
		$new_start          = $request->get_param( 'start_datetime' );
		$addon_ids_provided = $request->has_param( 'addon_ids' );
		$new_addon_ids      = array();
		$new_addons         = array();

		if ( $addon_ids_provided ) {
			if ( ! $is_admin ) {
				return new WP_Error( 'booking_rest_forbidden', __( 'Only an administrator can edit add-ons.', 'booking-plugin' ), array( 'status' => 403 ) );
			}

			if ( null !== $row->wc_order_id || ! in_array( $row->status, array( 'pending', 'confirmed' ), true ) ) {
				return new WP_Error( 'booking_rest_addons_not_editable', __( 'Add-ons can only be edited on pending or confirmed appointments that do not have a WooCommerce order yet.', 'booking-plugin' ), array( 'status' => 409 ) );
			}

			$new_addon_ids = $this->parse_addon_ids( $request->get_param( 'addon_ids' ) );

			if ( ! empty( $new_addon_ids ) ) {
				$new_addons = $this->get_valid_addons( (int) $row->service_id, $new_addon_ids );

				if ( is_wp_error( $new_addons ) ) {
					return $new_addons;
				}
			}
		}

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

		if ( null !== $new_start || $addon_ids_provided ) {
			if ( ! $is_admin ) {
				$window_error = $this->check_cancellation_window( $row );

				if ( is_wp_error( $window_error ) ) {
					return $window_error;
				}
			}

			$start_for_lock = null !== $new_start ? $new_start : $this->to_iso( $row->start_datetime );
			$lock_addon_ids = $addon_ids_provided ? $new_addon_ids : $this->get_appointment_addon_ids( (int) $row->id );

			$lock = $this->lock_and_revalidate( (int) $row->service_id, (int) $row->staff_id, $start_for_lock, (int) $row->id, $lock_addon_ids );

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

			if ( $addon_ids_provided ) {
				$this->replace_appointment_addons( (int) $row->id, $new_addons );
			}

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
			$this->maybe_refund_credit( $row );

			do_action( 'booking_plugin_appointment_cancelled', $row );
		}

		if ( 'completed' === $row->status && 'completed' !== $previous_status ) {
			Booking_Plugin_Payroll::log_commission_for_appointment( (int) $row->id );
		}

		return rest_ensure_response( $this->prepare_item( $row ) );
	}

	protected function maybe_refund_credit( $row ) {
		if ( empty( $row->paid_with_credit_id ) || empty( $row->credits_consumed ) ) {
			return;
		}

		global $wpdb;

		$table = $wpdb->prefix . 'booking_user_credits';

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET remaining_sessions = remaining_sessions + %d WHERE id = %d",
				(int) $row->credits_consumed,
				(int) $row->paid_with_credit_id
			)
		);
	}

	protected function attempt_booking( $service_id, $staff_id, $start_datetime, array $insert_data, array $addon_ids = array(), array $addons = array(), $credit = null ) {
		global $wpdb;

		$lock = $this->lock_and_revalidate( $service_id, $staff_id, $start_datetime, 0, $addon_ids );

		if ( is_wp_error( $lock ) ) {
			return $lock;
		}

		if ( ! $lock['available'] ) {
			$wpdb->query( 'ROLLBACK' );

			return false;
		}

		if ( $credit ) {
			$consumed = $this->lock_and_consume_credit( (int) $credit['id'], (int) $credit['credit_cost'] );

			if ( ! $consumed ) {
				$wpdb->query( 'ROLLBACK' );

				return new WP_Error( 'booking_rest_insufficient_credit', __( 'Insufficient credit balance.', 'booking-plugin' ), array( 'status' => 409 ) );
			}
		}

		$appointments_table = $wpdb->prefix . 'booking_appointments';
		$access_token        = wp_generate_password( 32, false, false );
		$now                 = current_time( 'mysql', true );

		$extra = array(
			'staff_id'       => $staff_id,
			'start_datetime' => $lock['candidate_start']->format( 'Y-m-d H:i:s' ),
			'end_datetime'   => $lock['candidate_end']->format( 'Y-m-d H:i:s' ),
			'status'         => $credit ? 'confirmed' : 'pending',
			'access_token'   => $access_token,
			'created_at'     => $now,
			'updated_at'     => $now,
		);

		if ( $credit ) {
			$extra['paid_with_credit_id'] = (int) $credit['id'];
			$extra['credits_consumed']    = (int) $credit['credit_cost'];
		}

		$wpdb->insert( $appointments_table, array_merge( $insert_data, $extra ) );

		$new_id = (int) $wpdb->insert_id;

		$this->insert_appointment_addons( $new_id, $addons );

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
	protected function lock_and_revalidate( $service_id, $staff_id, $start_datetime, $exclude_appointment_id = 0, $addon_ids = array() ) {
		global $wpdb;

		$services_table = $wpdb->prefix . 'booking_services';
		$service        = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$services_table} WHERE id = %d AND status = 'active'", $service_id ) );

		if ( ! $service ) {
			return new WP_Error( 'booking_rest_invalid_service', __( 'Service not found or inactive.', 'booking-plugin' ), array( 'status' => 404 ) );
		}

		$availability = new Booking_Plugin_Availability();

		$addons_extra_minutes = $availability->get_addons_extra_minutes( $service_id, $addon_ids );

		if ( is_wp_error( $addons_extra_minutes ) ) {
			return $addons_extra_minutes;
		}

		try {
			$candidate_start = new DateTimeImmutable( $start_datetime, new DateTimeZone( 'UTC' ) );
		} catch ( Exception $e ) {
			return new WP_Error( 'booking_rest_invalid_datetime', __( 'start_datetime must be a valid ISO 8601 datetime.', 'booking-plugin' ), array( 'status' => 400 ) );
		}

		$candidate_end = $candidate_start->modify( '+' . ( (int) $service->duration_minutes + $addons_extra_minutes ) . ' minutes' );

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

		$available = $availability->is_staff_slot_available( $service_id, $staff_id, $start_datetime, $exclude_appointment_id, $addon_ids );

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

	protected function parse_addon_ids( $raw ) {
		if ( empty( $raw ) ) {
			return array();
		}

		$ids = is_array( $raw ) ? $raw : explode( ',', (string) $raw );

		$ids = array_filter(
			array_map( 'intval', $ids ),
			function ( $id ) {
				return $id > 0;
			}
		);

		return array_values( array_unique( $ids ) );
	}

	/**
	 * Valida que cada addon_id pertenezca a $service_id y este active.
	 * Devuelve las filas completas (para el snapshot en appointment_addons)
	 * o un WP_Error 400 si alguno no es valido.
	 */
	protected function get_valid_addons( $service_id, array $addon_ids ) {
		global $wpdb;

		$table        = $wpdb->prefix . 'booking_service_addons';
		$placeholders = implode( ', ', array_fill( 0, count( $addon_ids ), '%d' ) );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE service_id = %d AND status = 'active' AND id IN ({$placeholders})",
				array_merge( array( $service_id ), $addon_ids )
			)
		);

		if ( count( $rows ) !== count( $addon_ids ) ) {
			return new WP_Error( 'booking_rest_invalid_addons', __( 'One or more addon_ids are invalid for this service.', 'booking-plugin' ), array( 'status' => 400 ) );
		}

		return $rows;
	}

	protected function insert_appointment_addons( $appointment_id, array $addons ) {
		if ( empty( $addons ) ) {
			return;
		}

		global $wpdb;

		$table = $wpdb->prefix . 'booking_appointment_addons';

		foreach ( $addons as $addon ) {
			$wpdb->insert(
				$table,
				array(
					'appointment_id'     => $appointment_id,
					'addon_id'           => (int) $addon->id,
					'name'               => $addon->name,
					'price'              => (float) $addon->price,
					'extra_time_minutes' => (int) $addon->extra_time_minutes,
				),
				array( '%d', '%d', '%s', '%f', '%d' )
			);
		}
	}

	protected function replace_appointment_addons( $appointment_id, array $addons ) {
		global $wpdb;

		$wpdb->delete( $wpdb->prefix . 'booking_appointment_addons', array( 'appointment_id' => $appointment_id ), array( '%d' ) );

		$this->insert_appointment_addons( $appointment_id, $addons );
	}

	protected function get_appointment_addon_ids( $appointment_id ) {
		global $wpdb;

		$table = $wpdb->prefix . 'booking_appointment_addons';

		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT addon_id FROM {$table} WHERE appointment_id = %d", $appointment_id ) ) );
	}

	protected function get_appointment_addons( $appointment_id ) {
		global $wpdb;

		$table = $wpdb->prefix . 'booking_appointment_addons';
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT addon_id, name, price, extra_time_minutes FROM {$table} WHERE appointment_id = %d ORDER BY id ASC", $appointment_id ) );

		return array_map(
			function ( $row ) {
				return array(
					'addon_id'           => (int) $row->addon_id,
					'name'               => $row->name,
					'price'              => (float) $row->price,
					'extra_time_minutes' => (int) $row->extra_time_minutes,
				);
			},
			$rows
		);
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
			'paid_with_credit_id' => $row->paid_with_credit_id ? (int) $row->paid_with_credit_id : null,
			'credits_consumed'    => $row->credits_consumed ? (int) $row->credits_consumed : null,
			'deposit_amount'       => null !== $row->deposit_amount ? (float) $row->deposit_amount : null,
			'balance_due'          => null !== $row->balance_due ? (float) $row->balance_due : null,
			'balance_collected_at' => $row->balance_collected_at ? $this->to_iso( $row->balance_collected_at ) : null,
			'addons'         => $this->get_appointment_addons( (int) $row->id ),
			'created_at'     => $this->to_iso( $row->created_at ),
			'updated_at'     => $this->to_iso( $row->updated_at ),
		);

		if ( $row->wc_order_id && Booking_Plugin_WooCommerce::is_active() ) {
			$order = wc_get_order( (int) $row->wc_order_id );

			if ( $order ) {
				$data['payment_status']    = wc_get_order_status_name( $order->get_status() );
				$data['wc_order_edit_url'] = $order->get_edit_order_url();
				$data['refunded_amount']   = (float) $order->get_total_refunded();
			}
		}

		return $data;
	}
}
