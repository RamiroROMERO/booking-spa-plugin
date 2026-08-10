<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Booking_Rest_Packages_Controller {

	protected $namespace = 'booking-plugin/v1';
	protected $rest_base = 'packages';

	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'write_permissions_check' ),
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

		$table  = $wpdb->prefix . 'booking_packages';
		$status = $request->get_param( 'status' );

		if ( in_array( $status, array( 'active', 'inactive' ), true ) ) {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE status = %s ORDER BY id ASC", $status ) );
		} else {
			$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id ASC" );
		}

		return rest_ensure_response( array_map( array( $this, 'prepare_item' ), $rows ) );
	}

	public function create_item( $request ) {
		global $wpdb;

		$name = trim( (string) $request->get_param( 'name' ) );

		if ( '' === $name ) {
			return new WP_Error( 'booking_rest_invalid_name', __( 'Name is required.', 'booking-plugin' ), array( 'status' => 400 ) );
		}

		$total_sessions = $request->get_param( 'total_sessions' );

		if ( null === $total_sessions || (int) $total_sessions < 1 ) {
			return new WP_Error( 'booking_rest_invalid_total_sessions', __( 'total_sessions must be greater than or equal to 1.', 'booking-plugin' ), array( 'status' => 400 ) );
		}

		$price = $request->get_param( 'price' );

		if ( null !== $price && '' !== $price && (float) $price < 0 ) {
			return new WP_Error( 'booking_rest_invalid_price', __( 'price must be greater than or equal to 0.', 'booking-plugin' ), array( 'status' => 400 ) );
		}

		$services   = $request->get_param( 'services' );
		$services   = null === $services ? array() : $services;
		$validation = $this->validate_services( $services );

		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		$table = $wpdb->prefix . 'booking_packages';
		$now   = current_time( 'mysql', true );

		$wpdb->insert(
			$table,
			array(
				'name'           => $name,
				'total_sessions' => (int) $total_sessions,
				'price'          => null !== $price ? (float) $price : 0,
				'wc_product_id'  => null,
				'status'         => 'active',
				'created_at'     => $now,
				'updated_at'     => $now,
			),
			array( '%s', '%d', '%f', '%d', '%s', '%s', '%s' )
		);

		$package_id = (int) $wpdb->insert_id;

		$this->replace_services( $package_id, $services );

		$row = $this->get_row( $package_id );

		Booking_Plugin_WooCommerce::sync_product_for_package( $row );

		// No reutilizar $wpdb->insert_id aca: sync_product_for_package() hace
		// sus propios INSERT (WooCommerce guardando el producto), que pisan
		// ese valor global -- se reusa el $package_id ya capturado arriba.
		$row = $this->get_row( $package_id );

		$response = rest_ensure_response( $this->prepare_item( $row ) );
		$response->set_status( 201 );

		return $response;
	}

	public function update_item( $request ) {
		global $wpdb;

		$id  = (int) $request['id'];
		$row = $this->get_row( $id );

		if ( ! $row ) {
			return new WP_Error( 'booking_rest_not_found', __( 'Package not found.', 'booking-plugin' ), array( 'status' => 404 ) );
		}

		$table   = $wpdb->prefix . 'booking_packages';
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

		if ( null !== $request->get_param( 'total_sessions' ) ) {
			$total_sessions = (int) $request->get_param( 'total_sessions' );

			if ( $total_sessions < 1 ) {
				return new WP_Error( 'booking_rest_invalid_total_sessions', __( 'total_sessions must be greater than or equal to 1.', 'booking-plugin' ), array( 'status' => 400 ) );
			}

			$data['total_sessions'] = $total_sessions;
			$formats[]              = '%d';
		}

		if ( null !== $request->get_param( 'price' ) ) {
			$price = $request->get_param( 'price' );

			if ( '' !== $price && (float) $price < 0 ) {
				return new WP_Error( 'booking_rest_invalid_price', __( 'price must be greater than or equal to 0.', 'booking-plugin' ), array( 'status' => 400 ) );
			}

			$data['price'] = (float) $price;
			$formats[]     = '%f';
		}

		if ( null !== $request->get_param( 'status' ) ) {
			$status = $request->get_param( 'status' );

			if ( ! in_array( $status, array( 'active', 'inactive' ), true ) ) {
				return new WP_Error( 'booking_rest_invalid_status', __( 'status must be either active or inactive.', 'booking-plugin' ), array( 'status' => 400 ) );
			}

			$data['status'] = $status;
			$formats[]      = '%s';
		}

		if ( $request->has_param( 'services' ) ) {
			$services   = $request->get_param( 'services' );
			$validation = $this->validate_services( $services );

			if ( is_wp_error( $validation ) ) {
				return $validation;
			}
		}

		if ( ! empty( $data ) ) {
			$data['updated_at'] = current_time( 'mysql', true );
			$formats[]           = '%s';

			$wpdb->update( $table, $data, array( 'id' => $id ), $formats, array( '%d' ) );
		}

		if ( isset( $services ) ) {
			$this->replace_services( $id, $services );
		}

		$row = $this->get_row( $id );

		Booking_Plugin_WooCommerce::sync_product_for_package( $row );

		$row = $this->get_row( $id );

		return rest_ensure_response( $this->prepare_item( $row ) );
	}

	public function delete_item( $request ) {
		global $wpdb;

		$id  = (int) $request['id'];
		$row = $this->get_row( $id );

		if ( ! $row ) {
			return new WP_Error( 'booking_rest_not_found', __( 'Package not found.', 'booking-plugin' ), array( 'status' => 404 ) );
		}

		$wpdb->update(
			$wpdb->prefix . 'booking_packages',
			array(
				'status'     => 'inactive',
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'id' => $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		$row = $this->get_row( $id );

		Booking_Plugin_WooCommerce::sync_product_for_package( $row );

		return rest_ensure_response( $this->prepare_item( $this->get_row( $id ) ) );
	}

	protected function validate_services( $services ) {
		global $wpdb;

		if ( ! is_array( $services ) ) {
			return new WP_Error( 'booking_rest_invalid_services', __( 'services must be an array.', 'booking-plugin' ), array( 'status' => 400 ) );
		}

		$table = $wpdb->prefix . 'booking_services';

		foreach ( $services as $service ) {
			if ( ! is_array( $service ) || ! isset( $service['service_id'] ) ) {
				return new WP_Error( 'booking_rest_invalid_services', __( 'Each service requires service_id.', 'booking-plugin' ), array( 'status' => 400 ) );
			}

			$service_id = (int) $service['service_id'];
			$exists     = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE id = %d", $service_id ) );

			if ( ! $exists ) {
				return new WP_Error(
					'booking_rest_invalid_services',
					sprintf( __( 'service_id %d does not exist.', 'booking-plugin' ), $service_id ),
					array( 'status' => 400 )
				);
			}

			$credit_cost = isset( $service['credit_cost'] ) ? (int) $service['credit_cost'] : 1;

			if ( $credit_cost < 1 ) {
				return new WP_Error( 'booking_rest_invalid_services', __( 'credit_cost must be greater than or equal to 1.', 'booking-plugin' ), array( 'status' => 400 ) );
			}
		}

		return true;
	}

	protected function replace_services( $package_id, array $services ) {
		global $wpdb;

		$table = $wpdb->prefix . 'booking_package_services';
		$wpdb->delete( $table, array( 'package_id' => $package_id ), array( '%d' ) );

		$seen = array();

		foreach ( $services as $service ) {
			$service_id = (int) $service['service_id'];

			if ( $service_id <= 0 || isset( $seen[ $service_id ] ) ) {
				continue;
			}

			$seen[ $service_id ] = true;
			$credit_cost         = isset( $service['credit_cost'] ) ? max( 1, (int) $service['credit_cost'] ) : 1;

			$wpdb->insert(
				$table,
				array(
					'package_id'  => $package_id,
					'service_id'  => $service_id,
					'credit_cost' => $credit_cost,
				),
				array( '%d', '%d', '%d' )
			);
		}
	}

	protected function get_row( $id ) {
		global $wpdb;

		$table = $wpdb->prefix . 'booking_packages';

		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) );
	}

	protected function get_services( $package_id ) {
		global $wpdb;

		$table = $wpdb->prefix . 'booking_package_services';
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT service_id, credit_cost FROM {$table} WHERE package_id = %d ORDER BY service_id ASC", $package_id ) );

		return array_map(
			function ( $row ) {
				return array(
					'service_id'  => (int) $row->service_id,
					'credit_cost' => (int) $row->credit_cost,
				);
			},
			$rows
		);
	}

	protected function prepare_item( $row ) {
		return array(
			'id'             => (int) $row->id,
			'name'           => $row->name,
			'total_sessions' => (int) $row->total_sessions,
			'price'          => (float) $row->price,
			'wc_product_id'  => null !== $row->wc_product_id ? (int) $row->wc_product_id : null,
			'status'         => $row->status,
			'services'       => $this->get_services( (int) $row->id ),
			'created_at'     => $row->created_at,
			'updated_at'     => $row->updated_at,
		);
	}
}
