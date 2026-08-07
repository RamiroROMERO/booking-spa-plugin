<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Booking_Rest_Availability_Controller {

	protected $namespace = 'booking-plugin/v1';
	protected $rest_base = 'availability';

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
			)
		);
	}

	public function get_items( $request ) {
		$service_id = $request->get_param( 'service_id' );

		if ( empty( $service_id ) ) {
			return new WP_Error( 'booking_rest_invalid_service', __( 'service_id is required.', 'booking-plugin' ), array( 'status' => 400 ) );
		}

		$date = $request->get_param( 'date' );

		if ( empty( $date ) ) {
			return new WP_Error( 'booking_rest_invalid_date', __( 'date is required.', 'booking-plugin' ), array( 'status' => 400 ) );
		}

		$staff_id = $request->get_param( 'staff_id' );
		$timezone = $request->get_param( 'timezone' );

		$availability = new Booking_Plugin_Availability();
		$slots        = $availability->get_available_slots(
			(int) $service_id,
			$date,
			$timezone,
			$staff_id ? (int) $staff_id : null
		);

		if ( is_wp_error( $slots ) ) {
			return $slots;
		}

		return rest_ensure_response(
			array(
				'service_id' => (int) $service_id,
				'date'       => $date,
				'slots'      => $slots,
			)
		);
	}
}
