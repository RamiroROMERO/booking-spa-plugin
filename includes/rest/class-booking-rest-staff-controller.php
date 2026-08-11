<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Booking_Rest_Staff_Controller {

	protected $namespace = 'booking-plugin/v1';
	protected $rest_base = 'staff';

	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => '__return_true',
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => array( $this, 'write_permissions_check' ),
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
					'permission_callback' => array( $this, 'write_permissions_check' ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_item' ),
					'permission_callback' => array( $this, 'write_permissions_check' ),
				),
			)
		);
	}

	public function write_permissions_check( $request ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'booking_rest_forbidden', __( 'You are not allowed to perform this action.', 'booking-plugin' ), array( 'status' => 403 ) );
		}

		return true;
	}

	public function get_items( $request ) {
		global $wpdb;

		$table = $wpdb->prefix . 'booking_staff';

		$page     = max( 1, (int) $request->get_param( 'page' ) ?: 1 );
		$per_page = (int) $request->get_param( 'per_page' ) ?: 10;
		$per_page = min( max( $per_page, 1 ), 100 );
		$offset   = ( $page - 1 ) * $per_page;

		$where = array();
		$args  = array();

		$can_manage = current_user_can( 'manage_options' );
		$status     = $request->get_param( 'status' );

		if ( $can_manage && in_array( $status, array( 'active', 'inactive' ), true ) ) {
			$where[] = 'status = %s';
			$args[]  = $status;
		} else {
			$where[] = "status = 'active'";
		}

		$service_id = $request->get_param( 'service_id' );

		if ( null !== $service_id && '' !== $service_id ) {
			$where[] = "id IN ( SELECT staff_id FROM {$wpdb->prefix}booking_staff_services WHERE service_id = %d )";
			$args[]  = (int) $service_id;
		}

		$where_sql = implode( ' AND ', $where );

		$total = (int) $wpdb->get_var(
			$args ? $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}", $args ) : "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}"
		);

		$query_args   = $args;
		$query_args[] = $per_page;
		$query_args[] = $offset;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE {$where_sql} ORDER BY id ASC LIMIT %d OFFSET %d",
				$query_args
			)
		);

		$items = array_map(
			function ( $row ) use ( $can_manage ) {
				return $this->prepare_item( $row, $can_manage );
			},
			$rows
		);

		$response = rest_ensure_response( $items );
		$response->header( 'X-WP-Total', $total );
		$response->header( 'X-WP-TotalPages', (int) ceil( $total / $per_page ) );

		return $response;
	}

	public function get_item( $request ) {
		$row = $this->get_row( (int) $request['id'] );

		if ( ! $row ) {
			return new WP_Error( 'booking_rest_not_found', __( 'Staff not found.', 'booking-plugin' ), array( 'status' => 404 ) );
		}

		return rest_ensure_response( $this->prepare_item( $row, current_user_can( 'manage_options' ) ) );
	}

	public function create_item( $request ) {
		global $wpdb;

		$name = trim( (string) $request->get_param( 'name' ) );

		if ( '' === $name ) {
			return new WP_Error( 'booking_rest_invalid_name', __( 'Name is required.', 'booking-plugin' ), array( 'status' => 400 ) );
		}

		$email = trim( (string) $request->get_param( 'email' ) );

		if ( '' === $email || ! is_email( $email ) ) {
			return new WP_Error( 'booking_rest_invalid_email', __( 'A valid email is required.', 'booking-plugin' ), array( 'status' => 400 ) );
		}

		$service_ids = $request->get_param( 'service_ids' );
		$service_ids = null === $service_ids ? array() : $service_ids;
		$validation  = $this->validate_service_ids( $service_ids );

		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		$commissions_provided = $request->has_param( 'commissions' );
		$commissions          = $request->get_param( 'commissions' );
		$commissions          = null === $commissions ? array() : $commissions;

		if ( $commissions_provided ) {
			$validation = $this->validate_commissions( $commissions, $service_ids );
			if ( is_wp_error( $validation ) ) {
				return $validation;
			}
		}

		$schedules  = $request->get_param( 'schedules' );
		$schedules  = null === $schedules ? array() : $schedules;
		$validation = $this->validate_schedules( $schedules );

		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		$exceptions = $request->get_param( 'exceptions' );
		$exceptions = null === $exceptions ? array() : $exceptions;
		$validation = $this->validate_exceptions( $exceptions );

		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		$now = current_time( 'mysql', true );

		$wpdb->insert(
			$wpdb->prefix . 'booking_staff',
			array(
				'user_id'    => null,
				'name'       => $name,
				'email'      => $email,
				'phone'      => $request->get_param( 'phone' ),
				'status'     => 'active',
				'created_at' => $now,
				'updated_at' => $now,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		$staff_id = (int) $wpdb->insert_id;

		$this->replace_service_ids( $staff_id, $service_ids, $commissions_provided, $commissions );
		$this->replace_schedules( $staff_id, $schedules );
		$this->replace_exceptions( $staff_id, $exceptions );

		$row = $this->get_row( $staff_id );

		$response = rest_ensure_response( $this->prepare_item( $row, true ) );
		$response->set_status( 201 );

		return $response;
	}

	public function update_item( $request ) {
		global $wpdb;

		$id  = (int) $request['id'];
		$row = $this->get_row( $id );

		if ( ! $row ) {
			return new WP_Error( 'booking_rest_not_found', __( 'Staff not found.', 'booking-plugin' ), array( 'status' => 404 ) );
		}

		$table   = $wpdb->prefix . 'booking_staff';
		$data    = array();
		$formats = array();

		if ( null !== $request->get_param( 'name' ) ) {
			$name = trim( (string) $request->get_param( 'name' ) );

			if ( '' === $name ) {
				return new WP_Error( 'booking_rest_invalid_name', __( 'Name is required.', 'booking-plugin' ), array( 'status' => 400 ) );
			}

			$data['name'] = $name;
			$formats[]    = '%s';
		}

		if ( null !== $request->get_param( 'email' ) ) {
			$email = trim( (string) $request->get_param( 'email' ) );

			if ( '' === $email || ! is_email( $email ) ) {
				return new WP_Error( 'booking_rest_invalid_email', __( 'A valid email is required.', 'booking-plugin' ), array( 'status' => 400 ) );
			}

			$data['email'] = $email;
			$formats[]     = '%s';
		}

		if ( $request->has_param( 'phone' ) ) {
			$data['phone'] = $request->get_param( 'phone' );
			$formats[]     = '%s';
		}

		if ( null !== $request->get_param( 'status' ) ) {
			$status = $request->get_param( 'status' );

			if ( ! in_array( $status, array( 'active', 'inactive' ), true ) ) {
				return new WP_Error( 'booking_rest_invalid_status', __( 'status must be either active or inactive.', 'booking-plugin' ), array( 'status' => 400 ) );
			}

			$data['status'] = $status;
			$formats[]       = '%s';
		}

		$service_ids_provided = $request->has_param( 'service_ids' );
		if ( $service_ids_provided ) {
			$service_ids = $request->get_param( 'service_ids' );
			$validation  = $this->validate_service_ids( $service_ids );

			if ( is_wp_error( $validation ) ) {
				return $validation;
			}
			$final_service_ids = $service_ids;
		} else {
			$final_service_ids = $this->get_service_ids( $id );
		}

		$commissions_provided = $request->has_param( 'commissions' );
		$commissions          = $request->get_param( 'commissions' );
		$commissions          = null === $commissions ? array() : $commissions;

		if ( $commissions_provided ) {
			$validation = $this->validate_commissions( $commissions, $final_service_ids );
			if ( is_wp_error( $validation ) ) {
				return $validation;
			}
		}

		if ( $request->has_param( 'schedules' ) ) {
			$schedules  = $request->get_param( 'schedules' );
			$validation = $this->validate_schedules( $schedules );

			if ( is_wp_error( $validation ) ) {
				return $validation;
			}
		}

		if ( $request->has_param( 'exceptions' ) ) {
			$exceptions = $request->get_param( 'exceptions' );
			$validation = $this->validate_exceptions( $exceptions );

			if ( is_wp_error( $validation ) ) {
				return $validation;
			}
		}

		if ( ! empty( $data ) ) {
			$data['updated_at'] = current_time( 'mysql', true );
			$formats[]            = '%s';

			$wpdb->update( $table, $data, array( 'id' => $id ), $formats, array( '%d' ) );
		}

		if ( $service_ids_provided || $commissions_provided ) {
			$this->replace_service_ids( $id, $final_service_ids, $commissions_provided, $commissions );
		}

		if ( isset( $schedules ) ) {
			$this->replace_schedules( $id, $schedules );
		}

		if ( isset( $exceptions ) ) {
			$this->replace_exceptions( $id, $exceptions );
		}

		$row = $this->get_row( $id );

		return rest_ensure_response( $this->prepare_item( $row, true ) );
	}

	public function delete_item( $request ) {
		global $wpdb;

		$id  = (int) $request['id'];
		$row = $this->get_row( $id );

		if ( ! $row ) {
			return new WP_Error( 'booking_rest_not_found', __( 'Staff not found.', 'booking-plugin' ), array( 'status' => 404 ) );
		}

		$wpdb->update(
			$wpdb->prefix . 'booking_staff',
			array(
				'status'     => 'inactive',
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'id' => $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		$row = $this->get_row( $id );

		return rest_ensure_response( $this->prepare_item( $row, current_user_can( 'manage_options' ) ) );
	}

	protected function validate_service_ids( $service_ids ) {
		global $wpdb;

		if ( ! is_array( $service_ids ) ) {
			return new WP_Error( 'booking_rest_invalid_service_ids', __( 'service_ids must be an array.', 'booking-plugin' ), array( 'status' => 400 ) );
		}

		$table = $wpdb->prefix . 'booking_services';

		foreach ( $service_ids as $service_id ) {
			$exists = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE id = %d", (int) $service_id ) );

			if ( ! $exists ) {
				return new WP_Error(
					'booking_rest_invalid_service_ids',
					sprintf( __( 'service_id %d does not exist.', 'booking-plugin' ), (int) $service_id ),
					array( 'status' => 400 )
				);
			}
		}

		return true;
	}

	protected function validate_commissions( $commissions, array $service_ids ) {
		if ( ! is_array( $commissions ) ) {
			return new WP_Error(
				'booking_rest_invalid_commissions',
				__( 'commissions must be an array.', 'booking-plugin' ),
				array( 'status' => 400 )
			);
		}

		$service_ids_ints = array_map( 'intval', $service_ids );

		foreach ( $commissions as $entry ) {
			if ( ! is_array( $entry ) ) {
				return new WP_Error(
					'booking_rest_invalid_commissions',
					__( 'Cada entrada de comisión debe ser un arreglo.', 'booking-plugin' ),
					array( 'status' => 400 )
				);
			}

			if ( ! isset( $entry['service_id'] ) || ! is_numeric( $entry['service_id'] ) || (int) $entry['service_id'] <= 0 ) {
				return new WP_Error(
					'booking_rest_invalid_commissions',
					__( 'Cada entrada de comisión debe incluir un service_id válido mayor a 0.', 'booking-plugin' ),
					array( 'status' => 400 )
				);
			}

			$service_id = (int) $entry['service_id'];

			if ( ! in_array( $service_id, $service_ids_ints, true ) ) {
				return new WP_Error(
					'booking_rest_invalid_commissions',
					sprintf( __( 'No se puede configurar comisión para el service_id %d porque no está asignado al staff.', 'booking-plugin' ), $service_id ),
					array( 'status' => 400 )
				);
			}

			if ( isset( $entry['commission_type'] ) && null !== $entry['commission_type'] ) {
				if ( ! in_array( $entry['commission_type'], array( 'fixed', 'percentage' ), true ) ) {
					return new WP_Error(
						'booking_rest_invalid_commissions',
						__( 'El tipo de comisión debe ser "fixed" o "percentage".', 'booking-plugin' ),
						array( 'status' => 400 )
					);
				}
			}

			if ( isset( $entry['commission_value'] ) && null !== $entry['commission_value'] ) {
				if ( ! is_numeric( $entry['commission_value'] ) || floatval( $entry['commission_value'] ) < 0 ) {
					return new WP_Error(
						'booking_rest_invalid_commissions',
						__( 'El valor de comisión debe ser un número mayor o igual a 0.', 'booking-plugin' ),
						array( 'status' => 400 )
					);
				}
			}

			$type_set  = isset( $entry['commission_type'] ) && $entry['commission_type'] !== null && $entry['commission_type'] !== '';
			$value_set = isset( $entry['commission_value'] ) && $entry['commission_value'] !== null && $entry['commission_value'] !== '';

			if ( $type_set !== $value_set ) {
				return new WP_Error(
					'booking_rest_invalid_commissions',
					__( 'El tipo de comisión y el valor de comisión deben configurarse juntos o no configurarse.', 'booking-plugin' ),
					array( 'status' => 400 )
				);
			}
		}

		return true;
	}

	protected function validate_schedules( $schedules ) {
		if ( ! is_array( $schedules ) ) {
			return new WP_Error( 'booking_rest_invalid_schedules', __( 'schedules must be an array.', 'booking-plugin' ), array( 'status' => 400 ) );
		}

		foreach ( $schedules as $schedule ) {
			if ( ! is_array( $schedule ) || ! isset( $schedule['day_of_week'], $schedule['start_time'], $schedule['end_time'] ) ) {
				return new WP_Error( 'booking_rest_invalid_schedules', __( 'Each schedule requires day_of_week, start_time and end_time.', 'booking-plugin' ), array( 'status' => 400 ) );
			}

			$day = (int) $schedule['day_of_week'];

			if ( $day < 0 || $day > 6 ) {
				return new WP_Error( 'booking_rest_invalid_schedules', __( 'day_of_week must be between 0 and 6.', 'booking-plugin' ), array( 'status' => 400 ) );
			}
		}

		return true;
	}

	protected function validate_exceptions( $exceptions ) {
		if ( ! is_array( $exceptions ) ) {
			return new WP_Error( 'booking_rest_invalid_exceptions', __( 'exceptions must be an array.', 'booking-plugin' ), array( 'status' => 400 ) );
		}

		foreach ( $exceptions as $exception ) {
			if ( ! is_array( $exception ) || empty( $exception['exception_date'] ) ) {
				return new WP_Error( 'booking_rest_invalid_exceptions', __( 'Each exception requires exception_date.', 'booking-plugin' ), array( 'status' => 400 ) );
			}
		}

		return true;
	}

	protected function replace_service_ids( $staff_id, array $service_ids, $commissions_provided, array $commissions ) {
		global $wpdb;

		$table          = $wpdb->prefix . 'booking_staff_services';
		$commission_map = array();

		if ( $commissions_provided ) {
			// Convert commissions list to a lookup associative array by service_id
			$commissions_by_service = array();
			foreach ( $commissions as $comm ) {
				if ( isset( $comm['service_id'] ) ) {
					$comm_service_id = (int) $comm['service_id'];
					$comm_type       = isset( $comm['commission_type'] ) && $comm['commission_type'] !== '' ? $comm['commission_type'] : null;
					$comm_val        = isset( $comm['commission_value'] ) && $comm['commission_value'] !== '' ? floatval( $comm['commission_value'] ) : null;
					$commissions_by_service[ $comm_service_id ] = array(
						'commission_type'  => $comm_type,
						'commission_value' => $comm_val,
					);
				}
			}

			foreach ( $service_ids as $sid ) {
				$sid = (int) $sid;
				if ( isset( $commissions_by_service[ $sid ] ) ) {
					$commission_map[ $sid ] = $commissions_by_service[ $sid ];
				} else {
					$commission_map[ $sid ] = array(
						'commission_type'  => null,
						'commission_value' => null,
					);
				}
			}
		} else {
			// commissions_provided is false. We must preserve current commissions from DB.
			// READ current commissions BEFORE deleting the rows!
			$current_rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT service_id, commission_type, commission_value FROM {$table} WHERE staff_id = %d",
					$staff_id
				)
			);

			$db_commissions = array();
			if ( $current_rows ) {
				foreach ( $current_rows as $row ) {
					$db_commissions[ (int) $row->service_id ] = array(
						'commission_type'  => $row->commission_type !== null ? (string) $row->commission_type : null,
						'commission_value' => $row->commission_value !== null ? floatval( $row->commission_value ) : null,
					);
				}
			}

			foreach ( $service_ids as $sid ) {
				$sid = (int) $sid;
				if ( isset( $db_commissions[ $sid ] ) ) {
					$commission_map[ $sid ] = $db_commissions[ $sid ];
				} else {
					$commission_map[ $sid ] = array(
						'commission_type'  => null,
						'commission_value' => null,
					);
				}
			}
		}

		// 2. Hacer el DELETE de todas las filas de staff_services para staff_id (igual que hoy)
		$wpdb->delete( $table, array( 'staff_id' => $staff_id ), array( '%d' ) );

		// 3. Por cada service_id unico y valido (mayor a 0) en service_ids, hacer el wpdb->insert()
		// incluyendo ahora tambien commission_type y commission_value tomados de commission_map
		$unique_service_ids = array_unique( array_map( 'intval', $service_ids ) );
		foreach ( $unique_service_ids as $service_id ) {
			if ( $service_id <= 0 ) {
				continue;
			}

			$comm_type = isset( $commission_map[ $service_id ]['commission_type'] ) ? $commission_map[ $service_id ]['commission_type'] : null;
			$comm_val  = isset( $commission_map[ $service_id ]['commission_value'] ) ? $commission_map[ $service_id ]['commission_value'] : null;

			$data = array(
				'staff_id'         => $staff_id,
				'service_id'       => $service_id,
				'commission_type'  => $comm_type,
				'commission_value' => $comm_val,
			);

			$formats = array( '%d', '%d', '%s', '%f' );

			$wpdb->insert( $table, $data, $formats );
		}
	}

	protected function replace_schedules( $staff_id, array $schedules ) {
		global $wpdb;

		$table = $wpdb->prefix . 'booking_staff_schedules';
		$wpdb->delete( $table, array( 'staff_id' => $staff_id ), array( '%d' ) );

		foreach ( $schedules as $schedule ) {
			$wpdb->insert(
				$table,
				array(
					'staff_id'    => $staff_id,
					'day_of_week' => (int) $schedule['day_of_week'],
					'start_time'  => $schedule['start_time'],
					'end_time'    => $schedule['end_time'],
					'break_start' => ! empty( $schedule['break_start'] ) ? $schedule['break_start'] : null,
					'break_end'   => ! empty( $schedule['break_end'] ) ? $schedule['break_end'] : null,
				),
				array( '%d', '%d', '%s', '%s', '%s', '%s' )
			);
		}
	}

	protected function replace_exceptions( $staff_id, array $exceptions ) {
		global $wpdb;

		$table = $wpdb->prefix . 'booking_staff_exceptions';
		$wpdb->delete( $table, array( 'staff_id' => $staff_id ), array( '%d' ) );

		foreach ( $exceptions as $exception ) {
			$wpdb->insert(
				$table,
				array(
					'staff_id'       => $staff_id,
					'exception_date' => $exception['exception_date'],
					'is_day_off'     => array_key_exists( 'is_day_off', $exception ) ? ( $exception['is_day_off'] ? 1 : 0 ) : 1,
					'start_time'     => ! empty( $exception['start_time'] ) ? $exception['start_time'] : null,
					'end_time'       => ! empty( $exception['end_time'] ) ? $exception['end_time'] : null,
					'reason'         => isset( $exception['reason'] ) ? $exception['reason'] : null,
				),
				array( '%d', '%s', '%d', '%s', '%s', '%s' )
			);
		}
	}

	protected function get_row( $id ) {
		global $wpdb;

		$table = $wpdb->prefix . 'booking_staff';

		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) );
	}

	protected function get_service_ids( $staff_id ) {
		global $wpdb;

		$table = $wpdb->prefix . 'booking_staff_services';
		$ids   = $wpdb->get_col( $wpdb->prepare( "SELECT service_id FROM {$table} WHERE staff_id = %d ORDER BY service_id ASC", $staff_id ) );

		return array_map( 'intval', $ids );
	}

	protected function get_commissions( $staff_id ) {
		global $wpdb;

		$table = $wpdb->prefix . 'booking_staff_services';
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT service_id, commission_type, commission_value FROM {$table} WHERE staff_id = %d ORDER BY service_id ASC",
				$staff_id
			)
		);

		return array_map(
			function ( $row ) {
				return array(
					'service_id'       => (int) $row->service_id,
					'commission_type'  => $row->commission_type !== null ? (string) $row->commission_type : null,
					'commission_value' => $row->commission_value !== null ? floatval( $row->commission_value ) : null,
				);
			},
			$rows
		);
	}

	protected function get_schedules( $staff_id ) {
		global $wpdb;

		$table = $wpdb->prefix . 'booking_staff_schedules';
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE staff_id = %d ORDER BY day_of_week ASC, start_time ASC", $staff_id ) );

		return array_map(
			function ( $row ) {
				return array(
					'id'          => (int) $row->id,
					'day_of_week' => (int) $row->day_of_week,
					'start_time'  => $row->start_time,
					'end_time'    => $row->end_time,
					'break_start' => $row->break_start,
					'break_end'   => $row->break_end,
				);
			},
			$rows
		);
	}

	protected function get_exceptions( $staff_id ) {
		global $wpdb;

		$table = $wpdb->prefix . 'booking_staff_exceptions';
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE staff_id = %d ORDER BY exception_date ASC", $staff_id ) );

		return array_map(
			function ( $row ) {
				return array(
					'id'             => (int) $row->id,
					'exception_date' => $row->exception_date,
					'is_day_off'     => (bool) $row->is_day_off,
					'start_time'     => $row->start_time,
					'end_time'       => $row->end_time,
					'reason'         => $row->reason,
				);
			},
			$rows
		);
	}

	protected function prepare_item( $row, $full = false ) {
		$service_ids = $this->get_service_ids( (int) $row->id );

		if ( ! $full ) {
			return array(
				'id'          => (int) $row->id,
				'name'        => $row->name,
				'status'      => $row->status,
				'service_ids' => $service_ids,
			);
		}

		return array(
			'id'          => (int) $row->id,
			'name'        => $row->name,
			'email'       => $row->email,
			'phone'       => $row->phone,
			'status'      => $row->status,
			'service_ids' => $service_ids,
			'commissions' => $this->get_commissions( (int) $row->id ),
			'schedules'   => $this->get_schedules( (int) $row->id ),
			'exceptions'  => $this->get_exceptions( (int) $row->id ),
			'created_at'  => $row->created_at,
			'updated_at'  => $row->updated_at,
		);
	}
}
