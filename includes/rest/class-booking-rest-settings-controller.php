<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Booking_Rest_Settings_Controller {

	protected $namespace = 'booking-plugin/v1';
	protected $rest_base = 'settings';

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
		$settings                       = Booking_Plugin_Settings::get_settings();
		$settings['woocommerce_active'] = Booking_Plugin_WooCommerce::is_active();

		return rest_ensure_response( $settings );
	}

	public function update_item( $request ) {
		$params = $request->get_json_params();

		if ( ! is_array( $params ) ) {
			$params = array();
		}

		return rest_ensure_response( Booking_Plugin_Settings::update_settings( $params ) );
	}
}
