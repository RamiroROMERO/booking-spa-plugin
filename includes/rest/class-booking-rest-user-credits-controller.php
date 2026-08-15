<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Booking_Rest_User_Credits_Controller {

	protected $namespace = 'booking-plugin/v1';

	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/users/(?P<wp_user_id>[\d]+)/credits',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'admin_permissions_check' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => array( $this, 'admin_permissions_check' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/credits/mine',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_mine' ),
					'permission_callback' => array( $this, 'logged_in_permissions_check' ),
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

		$wp_user_id = (int) $request['wp_user_id'];

		// SPEC 22: con service_id, devuelve solo los creditos aplicables a ese
		// servicio (mismo JOIN/forma que get_mine(), pero para el wp_user_id
		// de la ruta en vez de get_current_user_id() — uso admin, no propio).
		$service_id = (int) $request->get_param( 'service_id' );

		if ( $service_id > 0 ) {
			$credits_table  = $wpdb->prefix . 'booking_user_credits';
			$packages_table = $wpdb->prefix . 'booking_packages';
			$services_pivot = $wpdb->prefix . 'booking_package_services';

			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT uc.*, p.name AS package_name, ps.credit_cost AS credit_cost
					 FROM {$credits_table} uc
					 INNER JOIN {$services_pivot} ps ON ps.package_id = uc.package_id AND ps.service_id = %d
					 LEFT JOIN {$packages_table} p ON p.id = uc.package_id
					 WHERE uc.wp_user_id = %d AND uc.remaining_sessions > 0
					 ORDER BY uc.id ASC",
					$service_id,
					$wp_user_id
				)
			);

			$items = array_map(
				function ( $row ) {
					return array(
						'id'                 => (int) $row->id,
						'package_id'         => (int) $row->package_id,
						'package_name'       => $row->package_name,
						'total_sessions'     => (int) $row->total_sessions,
						'remaining_sessions' => (int) $row->remaining_sessions,
						'credit_cost'        => (int) $row->credit_cost,
					);
				},
				$rows
			);

			return rest_ensure_response( $items );
		}

		$credits_table  = $wpdb->prefix . 'booking_user_credits';
		$packages_table = $wpdb->prefix . 'booking_packages';

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT uc.*, p.name AS package_name
				 FROM {$credits_table} uc
				 LEFT JOIN {$packages_table} p ON p.id = uc.package_id
				 WHERE uc.wp_user_id = %d
				 ORDER BY uc.id DESC",
				$wp_user_id
			)
		);

		return rest_ensure_response( array_map( array( $this, 'prepare_item' ), $rows ) );
	}

	public function create_item( $request ) {
		global $wpdb;

		$wp_user_id = (int) $request['wp_user_id'];

		if ( ! get_userdata( $wp_user_id ) ) {
			return new WP_Error( 'booking_rest_not_found', __( 'User not found.', 'booking-plugin' ), array( 'status' => 404 ) );
		}

		$package_id = (int) $request->get_param( 'package_id' );

		$packages_table = $wpdb->prefix . 'booking_packages';
		$package        = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$packages_table} WHERE id = %d", $package_id ) );

		if ( ! $package ) {
			return new WP_Error( 'booking_rest_invalid_package', __( 'package_id does not exist.', 'booking-plugin' ), array( 'status' => 400 ) );
		}

		$note = $request->get_param( 'note' );
		$now  = current_time( 'mysql', true );

		$credits_table = $wpdb->prefix . 'booking_user_credits';

		$wpdb->insert(
			$credits_table,
			array(
				'wp_user_id'         => $wp_user_id,
				'package_id'         => $package_id,
				'total_sessions'     => (int) $package->total_sessions,
				'remaining_sessions' => (int) $package->total_sessions,
				'source'             => 'manual',
				'woo_order_id'       => null,
				'granted_by'         => get_current_user_id(),
				'note'               => ! empty( $note ) ? $note : null,
				'created_at'         => $now,
			),
			array( '%d', '%d', '%d', '%d', '%s', '%d', '%d', '%s', '%s' )
		);

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT uc.*, p.name AS package_name
				 FROM {$credits_table} uc
				 LEFT JOIN {$packages_table} p ON p.id = uc.package_id
				 WHERE uc.id = %d",
				(int) $wpdb->insert_id
			)
		);

		$response = rest_ensure_response( $this->prepare_item( $row ) );
		$response->set_status( 201 );

		return $response;
	}

	public function get_mine( $request ) {
		global $wpdb;

		$service_id = (int) $request->get_param( 'service_id' );

		if ( $service_id <= 0 ) {
			return new WP_Error( 'booking_rest_invalid_service_id', __( 'service_id is required.', 'booking-plugin' ), array( 'status' => 400 ) );
		}

		$credits_table  = $wpdb->prefix . 'booking_user_credits';
		$packages_table = $wpdb->prefix . 'booking_packages';
		$services_pivot = $wpdb->prefix . 'booking_package_services';

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT uc.*, p.name AS package_name, ps.credit_cost AS credit_cost
				 FROM {$credits_table} uc
				 INNER JOIN {$services_pivot} ps ON ps.package_id = uc.package_id AND ps.service_id = %d
				 LEFT JOIN {$packages_table} p ON p.id = uc.package_id
				 WHERE uc.wp_user_id = %d AND uc.remaining_sessions > 0
				 ORDER BY uc.id ASC",
				$service_id,
				get_current_user_id()
			)
		);

		$items = array_map(
			function ( $row ) {
				return array(
					'id'                 => (int) $row->id,
					'package_id'         => (int) $row->package_id,
					'package_name'       => $row->package_name,
					'total_sessions'     => (int) $row->total_sessions,
					'remaining_sessions' => (int) $row->remaining_sessions,
					'credit_cost'        => (int) $row->credit_cost,
				);
			},
			$rows
		);

		return rest_ensure_response( $items );
	}

	protected function prepare_item( $row ) {
		return array(
			'id'                 => (int) $row->id,
			'wp_user_id'         => (int) $row->wp_user_id,
			'package_id'         => (int) $row->package_id,
			'package_name'       => $row->package_name,
			'total_sessions'     => (int) $row->total_sessions,
			'remaining_sessions' => (int) $row->remaining_sessions,
			'source'             => $row->source,
			'woo_order_id'       => null !== $row->woo_order_id ? (int) $row->woo_order_id : null,
			'granted_by'         => null !== $row->granted_by ? (int) $row->granted_by : null,
			'note'               => $row->note,
			'created_at'         => $row->created_at,
		);
	}
}
