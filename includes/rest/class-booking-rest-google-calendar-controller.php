<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Booking_Rest_Google_Calendar_Controller {

	protected $namespace = 'booking-plugin/v1';
	protected $rest_base = 'google-calendar';

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
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/credentials',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'save_credentials' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/auth-url',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_auth_url' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/oauth-callback',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'oauth_callback' ),
					// Google redirige el navegador directo a esta URL (navegacion,
					// no fetch de la SPA): no puede llevar el X-WP-Nonce que REST
					// exige para reconocer la sesion por cookie, asi que en vez de
					// manage_options la seguridad la da el `state` de un solo uso
					// (ver Booking_Plugin_Google_Calendar::verify_and_consume_state()).
					'permission_callback' => '__return_true',
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/calendars',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_calendars' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/calendar',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'save_calendar' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/disconnect',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'disconnect' ),
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
		return rest_ensure_response( $this->prepare_settings_response( Booking_Plugin_Google_Calendar::get_settings() ) );
	}

	public function save_credentials( $request ) {
		$client_id     = trim( (string) $request->get_param( 'client_id' ) );
		$client_secret = trim( (string) $request->get_param( 'client_secret' ) );

		if ( '' === $client_id || '' === $client_secret ) {
			return new WP_Error( 'booking_rest_invalid_google_credentials', __( 'client_id and client_secret are required.', 'booking-plugin' ), array( 'status' => 400 ) );
		}

		$settings = Booking_Plugin_Google_Calendar::update_settings(
			array(
				'client_id'     => $client_id,
				'client_secret' => $client_secret,
			)
		);

		return rest_ensure_response( $this->prepare_settings_response( $settings ) );
	}

	public function get_auth_url( $request ) {
		$settings = Booking_Plugin_Google_Calendar::get_settings();

		if ( empty( $settings['client_id'] ) || empty( $settings['client_secret'] ) ) {
			return new WP_Error( 'booking_rest_google_missing_credentials', __( 'Save a Client ID and Client Secret before connecting.', 'booking-plugin' ), array( 'status' => 400 ) );
		}

		return rest_ensure_response( array( 'auth_url' => Booking_Plugin_Google_Calendar::get_auth_url() ) );
	}

	/**
	 * Google redirige el navegador del admin directo a esta ruta (no es un
	 * fetch de SettingsPage.js), asi que en vez de devolver JSON se redirige
	 * de vuelta a la pantalla de Configuracion con un flag en la query string.
	 */
	public function oauth_callback( $request ) {
		if ( ! Booking_Plugin_Google_Calendar::verify_and_consume_state( $request->get_param( 'state' ) ) ) {
			wp_safe_redirect( $this->settings_redirect_url( 'google_calendar_error' ) );
			exit;
		}

		$error = $request->get_param( 'error' );

		if ( $error ) {
			Booking_Plugin_Google_Calendar::update_settings(
				array(
					'status'     => 'error',
					'last_error' => sanitize_text_field( $error ),
				)
			);

			wp_safe_redirect( $this->settings_redirect_url( 'google_calendar_error' ) );
			exit;
		}

		$code = $request->get_param( 'code' );

		if ( empty( $code ) ) {
			wp_safe_redirect( $this->settings_redirect_url( 'google_calendar_error' ) );
			exit;
		}

		$result = Booking_Plugin_Google_Calendar::exchange_code( $code );

		if ( is_wp_error( $result ) ) {
			wp_safe_redirect( $this->settings_redirect_url( 'google_calendar_error' ) );
			exit;
		}

		wp_safe_redirect( $this->settings_redirect_url( 'google_calendar_connected' ) );
		exit;
	}

	public function get_calendars( $request ) {
		$calendars = Booking_Plugin_Google_Calendar::list_calendars();

		if ( is_wp_error( $calendars ) ) {
			return $calendars;
		}

		return rest_ensure_response( $calendars );
	}

	public function save_calendar( $request ) {
		$calendar_id = trim( (string) $request->get_param( 'calendar_id' ) );

		if ( '' === $calendar_id ) {
			return new WP_Error( 'booking_rest_invalid_calendar', __( 'calendar_id is required.', 'booking-plugin' ), array( 'status' => 400 ) );
		}

		$calendar_summary = trim( (string) $request->get_param( 'calendar_summary' ) );

		$settings = Booking_Plugin_Google_Calendar::update_settings(
			array(
				'calendar_id'      => $calendar_id,
				'calendar_summary' => $calendar_summary,
			)
		);

		$this->backfill_future_appointments();

		return rest_ensure_response( $this->prepare_settings_response( $settings ) );
	}

	/**
	 * Sincroniza hacia adelante, de forma secuencial (ver Risks de la spec),
	 * las citas/bloqueos futuros que todavia no tienen evento en Google al
	 * momento de elegir el calendario. No toca citas pasadas.
	 */
	protected function backfill_future_appointments() {
		global $wpdb;

		$table = $wpdb->prefix . 'booking_appointments';
		$now   = current_time( 'mysql', true );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table}
				 WHERE start_datetime >= %s
				   AND status IN ('confirmed', 'pending', 'blocked')
				   AND ( google_event_id IS NULL OR google_event_id = '' )",
				$now
			)
		);

		if ( ! $rows ) {
			return;
		}

		foreach ( $rows as $row ) {
			Booking_Plugin_Google_Calendar_Sync::sync_appointment( $row );
		}
	}

	public function disconnect( $request ) {
		$settings = Booking_Plugin_Google_Calendar::disconnect();

		return rest_ensure_response( $this->prepare_settings_response( $settings ) );
	}

	protected function prepare_settings_response( array $settings ) {
		return array(
			'status'             => $settings['status'],
			'account_email'      => $settings['account_email'],
			'calendar_id'        => $settings['calendar_id'],
			'calendar_summary'   => $settings['calendar_summary'],
			'last_error'         => $settings['last_error'],
			'client_id'          => $settings['client_id'],
			'has_client_secret'  => ! empty( $settings['client_secret'] ),
		);
	}

	protected function settings_redirect_url( $flag ) {
		return add_query_arg( $flag, '1', admin_url( 'admin.php?page=' . Booking_Plugin_Admin::SLUG_SETTINGS ) );
	}
}
