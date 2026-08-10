<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Booking_Rest_Addons_Controller {

	protected $namespace = 'booking-plugin/v1';

	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/services/(?P<service_id>[\d]+)/addons',
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
			'/addons/(?P<id>[\d]+)',
			array(
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

		$service_id = (int) $request['service_id'];

		if ( ! $this->service_exists( $service_id ) ) {
			return new WP_Error( 'booking_rest_not_found', __( 'Service not found.', 'booking-plugin' ), array( 'status' => 404 ) );
		}

		$table = $wpdb->prefix . 'booking_service_addons';

		$can_manage = current_user_can( 'manage_options' );
		$status     = $request->get_param( 'status' );

		if ( $can_manage && 'all' === $status ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare( "SELECT * FROM {$table} WHERE service_id = %d ORDER BY id ASC", $service_id )
			);
		} else {
			$rows = $wpdb->get_results(
				$wpdb->prepare( "SELECT * FROM {$table} WHERE service_id = %d AND status = 'active' ORDER BY id ASC", $service_id )
			);
		}

		return rest_ensure_response( array_map( array( $this, 'prepare_item' ), $rows ) );
	}

	public function create_item( $request ) {
		global $wpdb;

		$service_id = (int) $request['service_id'];

		if ( ! $this->service_exists( $service_id ) ) {
			return new WP_Error( 'booking_rest_not_found', __( 'Service not found.', 'booking-plugin' ), array( 'status' => 404 ) );
		}

		$name = trim( (string) $request->get_param( 'name' ) );

		if ( '' === $name ) {
			return new WP_Error( 'booking_rest_invalid_name', __( 'Name is required.', 'booking-plugin' ), array( 'status' => 400 ) );
		}

		$validation_error = $this->validate_numeric_fields( $request );

		if ( is_wp_error( $validation_error ) ) {
			return $validation_error;
		}

		$table = $wpdb->prefix . 'booking_service_addons';
		$now   = current_time( 'mysql', true );

		$wpdb->insert(
			$table,
			array(
				'service_id'         => $service_id,
				'name'               => $name,
				'price'              => (float) $request->get_param( 'price' ),
				'extra_time_minutes' => null !== $request->get_param( 'extra_time_minutes' ) ? (int) $request->get_param( 'extra_time_minutes' ) : 0,
				'status'             => 'active',
				'created_at'         => $now,
				'updated_at'         => $now,
			),
			array( '%d', '%s', '%f', '%d', '%s', '%s', '%s' )
		);

		$row = $this->get_row( (int) $wpdb->insert_id );

		$response = rest_ensure_response( $this->prepare_item( $row ) );
		$response->set_status( 201 );

		return $response;
	}

	public function update_item( $request ) {
		global $wpdb;

		$id  = (int) $request['id'];
		$row = $this->get_row( $id );

		if ( ! $row ) {
			return new WP_Error( 'booking_rest_not_found', __( 'Add-on not found.', 'booking-plugin' ), array( 'status' => 404 ) );
		}

		$validation_error = $this->validate_numeric_fields( $request );

		if ( is_wp_error( $validation_error ) ) {
			return $validation_error;
		}

		$table   = $wpdb->prefix . 'booking_service_addons';
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

		if ( null !== $request->get_param( 'price' ) ) {
			$data['price'] = (float) $request->get_param( 'price' );
			$formats[]     = '%f';
		}

		if ( null !== $request->get_param( 'extra_time_minutes' ) ) {
			$data['extra_time_minutes'] = (int) $request->get_param( 'extra_time_minutes' );
			$formats[]                   = '%d';
		}

		if ( null !== $request->get_param( 'status' ) ) {
			$status = $request->get_param( 'status' );

			if ( ! in_array( $status, array( 'active', 'inactive' ), true ) ) {
				return new WP_Error( 'booking_rest_invalid_status', __( 'status must be either active or inactive.', 'booking-plugin' ), array( 'status' => 400 ) );
			}

			$data['status'] = $status;
			$formats[]       = '%s';
		}

		if ( ! empty( $data ) ) {
			$data['updated_at'] = current_time( 'mysql', true );
			$formats[]           = '%s';

			$wpdb->update( $table, $data, array( 'id' => $id ), $formats, array( '%d' ) );
		}

		return rest_ensure_response( $this->prepare_item( $this->get_row( $id ) ) );
	}

	public function delete_item( $request ) {
		global $wpdb;

		$id  = (int) $request['id'];
		$row = $this->get_row( $id );

		if ( ! $row ) {
			return new WP_Error( 'booking_rest_not_found', __( 'Add-on not found.', 'booking-plugin' ), array( 'status' => 404 ) );
		}

		$wpdb->update(
			$wpdb->prefix . 'booking_service_addons',
			array(
				'status'     => 'inactive',
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'id' => $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		return rest_ensure_response( $this->prepare_item( $this->get_row( $id ) ) );
	}

	protected function validate_numeric_fields( $request ) {
		$price = $request->get_param( 'price' );

		if ( null !== $price && '' !== $price && (float) $price < 0 ) {
			return new WP_Error( 'booking_rest_invalid_price', __( 'price must be greater than or equal to 0.', 'booking-plugin' ), array( 'status' => 400 ) );
		}

		$extra_time_minutes = $request->get_param( 'extra_time_minutes' );

		if ( null !== $extra_time_minutes && '' !== $extra_time_minutes && (int) $extra_time_minutes < 0 ) {
			return new WP_Error( 'booking_rest_invalid_extra_time', __( 'extra_time_minutes must be greater than or equal to 0.', 'booking-plugin' ), array( 'status' => 400 ) );
		}

		return true;
	}

	protected function service_exists( $service_id ) {
		global $wpdb;

		$table = $wpdb->prefix . 'booking_services';

		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE id = %d", $service_id ) );
	}

	protected function get_row( $id ) {
		global $wpdb;

		$table = $wpdb->prefix . 'booking_service_addons';

		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) );
	}

	protected function prepare_item( $row ) {
		return array(
			'id'                  => (int) $row->id,
			'service_id'          => (int) $row->service_id,
			'name'                => $row->name,
			'price'               => (float) $row->price,
			'extra_time_minutes'  => (int) $row->extra_time_minutes,
			'status'              => $row->status,
			'created_at'          => $row->created_at,
			'updated_at'          => $row->updated_at,
		);
	}
}
