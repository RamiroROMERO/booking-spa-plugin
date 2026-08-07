<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Booking_Rest_Business_Hours_Controller {

	protected $namespace = 'booking-plugin/v1';
	protected $rest_base = 'business-hours';

	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
			)
		);
	}

	public function permissions_check( $request ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'booking_rest_forbidden', __( 'You are not allowed to perform this action.', 'booking-plugin' ), array( 'status' => 403 ) );
		}

		return true;
	}

	public function get_item( $request ) {
		return rest_ensure_response( array( 'days' => $this->get_days() ) );
	}

	public function update_item( $request ) {
		$params = $request->get_json_params();
		$days   = isset( $params['days'] ) ? $params['days'] : null;

		$validation = $this->validate_days( $days );

		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		$this->replace_days( $days );

		return rest_ensure_response( array( 'days' => $this->get_days() ) );
	}

	protected function validate_days( $days ) {
		if ( ! is_array( $days ) || count( $days ) !== 7 ) {
			return new WP_Error( 'booking_rest_invalid_days', __( 'days must be an array with exactly 7 entries (day_of_week 0-6).', 'booking-plugin' ), array( 'status' => 400 ) );
		}

		$seen = array();

		foreach ( $days as $day ) {
			if ( ! is_array( $day ) || ! array_key_exists( 'day_of_week', $day ) ) {
				return new WP_Error( 'booking_rest_invalid_days', __( 'Each entry requires day_of_week.', 'booking-plugin' ), array( 'status' => 400 ) );
			}

			$day_of_week = (int) $day['day_of_week'];

			if ( $day_of_week < 0 || $day_of_week > 6 ) {
				return new WP_Error( 'booking_rest_invalid_days', __( 'day_of_week must be between 0 and 6.', 'booking-plugin' ), array( 'status' => 400 ) );
			}

			if ( isset( $seen[ $day_of_week ] ) ) {
				return new WP_Error( 'booking_rest_invalid_days', __( 'day_of_week values must not repeat.', 'booking-plugin' ), array( 'status' => 400 ) );
			}

			$seen[ $day_of_week ] = true;

			$open_time  = ! empty( $day['open_time'] ) ? $day['open_time'] : null;
			$close_time = ! empty( $day['close_time'] ) ? $day['close_time'] : null;

			if ( ( null === $open_time ) !== ( null === $close_time ) ) {
				return new WP_Error( 'booking_rest_invalid_days', __( 'open_time and close_time must both be set, or both be empty (closed day).', 'booking-plugin' ), array( 'status' => 400 ) );
			}
		}

		if ( count( $seen ) !== 7 ) {
			return new WP_Error( 'booking_rest_invalid_days', __( 'days must cover every day_of_week from 0 to 6 exactly once.', 'booking-plugin' ), array( 'status' => 400 ) );
		}

		return true;
	}

	protected function replace_days( array $days ) {
		global $wpdb;

		$table = $wpdb->prefix . 'booking_business_hours';

		$wpdb->query( "TRUNCATE TABLE {$table}" );

		foreach ( $days as $day ) {
			$open_time  = ! empty( $day['open_time'] ) ? $day['open_time'] : null;
			$close_time = ! empty( $day['close_time'] ) ? $day['close_time'] : null;

			$wpdb->insert(
				$table,
				array(
					'day_of_week' => (int) $day['day_of_week'],
					'open_time'   => $open_time,
					'close_time'  => $close_time,
				),
				array( '%d', '%s', '%s' )
			);
		}
	}

	protected function get_days() {
		global $wpdb;

		$table = $wpdb->prefix . 'booking_business_hours';
		$rows  = $wpdb->get_results( "SELECT * FROM {$table}" );

		$by_day = array();

		foreach ( $rows as $row ) {
			$by_day[ (int) $row->day_of_week ] = array(
				'day_of_week' => (int) $row->day_of_week,
				'open_time'   => $row->open_time,
				'close_time'  => $row->close_time,
			);
		}

		$days = array();

		for ( $day_of_week = 0; $day_of_week <= 6; $day_of_week++ ) {
			$days[] = isset( $by_day[ $day_of_week ] )
				? $by_day[ $day_of_week ]
				: array(
					'day_of_week' => $day_of_week,
					'open_time'   => null,
					'close_time'  => null,
				);
		}

		return $days;
	}
}
