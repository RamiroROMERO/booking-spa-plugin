<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Booking_Rest_Email_Templates_Controller {

	protected $namespace = 'booking-plugin/v1';
	protected $rest_base = 'email-templates';

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

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/test-send',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'test_send' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<key>[a-z_]+)/restore-default',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'restore_default' ),
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
		return rest_ensure_response( Booking_Plugin_Email_Templates::get_all_templates() );
	}

	public function update_item( $request ) {
		$params = $request->get_json_params();

		if ( ! is_array( $params ) ) {
			$params = array();
		}

		$result = Booking_Plugin_Email_Templates::save_templates( $params );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	public function test_send( $request ) {
		$params = $request->get_json_params();

		if ( ! is_array( $params ) ) {
			$params = array();
		}

		$template_key = isset( $params['template_key'] ) ? sanitize_key( $params['template_key'] ) : '';

		if ( ! in_array( $template_key, Booking_Plugin_Email_Templates::get_keys(), true ) ) {
			return new WP_Error( 'booking_rest_invalid_template', __( 'Unknown template key.', 'booking-plugin' ), array( 'status' => 400 ) );
		}

		$subject = isset( $params['subject'] ) ? trim( sanitize_text_field( $params['subject'] ) ) : '';
		$body    = isset( $params['body'] ) ? trim( wp_kses_post( $params['body'] ) ) : '';

		if ( '' === $subject || '' === $body ) {
			return new WP_Error( 'booking_rest_invalid_template', __( 'subject and body must not be empty.', 'booking-plugin' ), array( 'status' => 400 ) );
		}

		$current_user = wp_get_current_user();

		$notifications = new Booking_Plugin_Notifications();
		$result        = $notifications->send_test( $current_user->user_email, $subject, $body );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( array( 'sent' => true ) );
	}

	public function restore_default( $request ) {
		$key = sanitize_key( $request['key'] );

		if ( ! in_array( $key, Booking_Plugin_Email_Templates::get_keys(), true ) ) {
			return new WP_Error( 'booking_rest_invalid_template', __( 'Unknown template key.', 'booking-plugin' ), array( 'status' => 400 ) );
		}

		return rest_ensure_response( Booking_Plugin_Email_Templates::restore_default( $key ) );
	}
}
