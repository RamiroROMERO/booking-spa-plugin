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

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/days',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_days' ),
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

		$staff_id  = $request->get_param( 'staff_id' );
		$timezone  = $request->get_param( 'timezone' );
		$addon_ids = $this->parse_addon_ids( $request->get_param( 'addon_ids' ) );

		$availability = new Booking_Plugin_Availability();
		$slots        = $availability->get_available_slots(
			(int) $service_id,
			$date,
			$timezone,
			$staff_id ? (int) $staff_id : null,
			$addon_ids
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

	public function get_days( $request ) {
		$service_id = $request->get_param( 'service_id' );

		if ( empty( $service_id ) ) {
			return new WP_Error( 'booking_rest_invalid_service', __( 'service_id is required.', 'booking-plugin' ), array( 'status' => 400 ) );
		}

		$month = $request->get_param( 'month' );

		if ( empty( $month ) || ! preg_match( '/^\d{4}-\d{2}$/', $month ) ) {
			return new WP_Error( 'booking_rest_invalid_date', __( 'month must be in Y-m format.', 'booking-plugin' ), array( 'status' => 400 ) );
		}

		try {
			$month_start = new DateTimeImmutable( $month . '-01' );
		} catch ( Exception $e ) {
			return new WP_Error( 'booking_rest_invalid_date', __( 'month must be in Y-m format.', 'booking-plugin' ), array( 'status' => 400 ) );
		}

		$staff_id = $request->get_param( 'staff_id' );
		$timezone = $request->get_param( 'timezone' );

		$days_in_month   = (int) $month_start->format( 't' );
		$availability    = new Booking_Plugin_Availability();
		$available_dates = array();

		for ( $day = 1; $day <= $days_in_month; $day++ ) {
			$date = $month_start->modify( '+' . ( $day - 1 ) . ' days' )->format( 'Y-m-d' );

			$has_slot = $availability->has_available_slot(
				(int) $service_id,
				$date,
				$timezone,
				$staff_id ? (int) $staff_id : null
			);

			if ( is_wp_error( $has_slot ) ) {
				return $has_slot;
			}

			if ( $has_slot ) {
				$available_dates[] = $date;
			}
		}

		return rest_ensure_response(
			array(
				'service_id'      => (int) $service_id,
				'month'           => $month,
				'available_dates' => $available_dates,
			)
		);
	}
}
