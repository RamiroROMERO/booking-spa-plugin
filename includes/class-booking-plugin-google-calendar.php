<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Booking_Plugin_Google_Calendar {

	const OPTION_NAME = 'booking_plugin_google_calendar';
	const AUTH_URL     = 'https://accounts.google.com/o/oauth2/v2/auth';
	const TOKEN_URL     = 'https://oauth2.googleapis.com/token';
	const API_BASE      = 'https://www.googleapis.com/calendar/v3';
	const SCOPE          = 'https://www.googleapis.com/auth/calendar';
	const STATE_TRANSIENT = 'booking_plugin_google_oauth_state';

	public static function get_defaults() {
		return array(
			'client_id'        => '',
			'client_secret'    => '',
			'access_token'     => '',
			'refresh_token'    => '',
			'token_expires_at' => '',
			'calendar_id'      => '',
			'calendar_summary' => '',
			'account_email'    => '',
			'status'           => 'disconnected',
			'last_error'       => '',
		);
	}

	public static function get_settings() {
		$stored = get_option( self::OPTION_NAME, array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		return wp_parse_args( $stored, self::get_defaults() );
	}

	public static function update_settings( array $settings ) {
		$current = self::get_settings();
		$merged  = wp_parse_args( $settings, $current );

		$status = in_array( $merged['status'], array( 'disconnected', 'connected', 'error' ), true )
			? $merged['status']
			: $current['status'];

		$sanitized = array(
			'client_id'        => sanitize_text_field( $merged['client_id'] ),
			'client_secret'    => sanitize_text_field( $merged['client_secret'] ),
			'access_token'     => sanitize_text_field( $merged['access_token'] ),
			'refresh_token'    => sanitize_text_field( $merged['refresh_token'] ),
			'token_expires_at' => sanitize_text_field( $merged['token_expires_at'] ),
			'calendar_id'      => sanitize_text_field( $merged['calendar_id'] ),
			'calendar_summary' => sanitize_text_field( $merged['calendar_summary'] ),
			'account_email'    => sanitize_text_field( $merged['account_email'] ),
			'status'           => $status,
			'last_error'       => sanitize_text_field( $merged['last_error'] ),
		);

		update_option( self::OPTION_NAME, $sanitized );

		return $sanitized;
	}

	public static function get_redirect_uri() {
		return rest_url( 'booking-plugin/v1/google-calendar/oauth-callback' );
	}

	public static function get_auth_url() {
		$settings = self::get_settings();
		$state    = wp_generate_password( 32, false );

		// El callback de Google es una navegacion normal del navegador (no un
		// fetch de la SPA), asi que no puede llevar el nonce que REST pide
		// para reconocer la sesion por cookie: por eso esa ruta no exige
		// manage_options y en cambio valida este `state` de un solo uso
		// (mitigacion estandar de OAuth2 contra login CSRF).
		set_transient( self::STATE_TRANSIENT, $state, 10 * MINUTE_IN_SECONDS );

		$params = array(
			'client_id'     => $settings['client_id'],
			'redirect_uri'  => self::get_redirect_uri(),
			'response_type' => 'code',
			'scope'         => self::SCOPE,
			'access_type'   => 'offline',
			'prompt'        => 'consent',
			'state'         => $state,
		);

		return self::AUTH_URL . '?' . http_build_query( $params );
	}

	public static function verify_and_consume_state( $state ) {
		$stored = get_transient( self::STATE_TRANSIENT );

		delete_transient( self::STATE_TRANSIENT );

		return $stored && $state && hash_equals( (string) $stored, (string) $state );
	}

	public static function exchange_code( $code ) {
		$settings = self::get_settings();

		$response = wp_remote_post(
			self::TOKEN_URL,
			array(
				'timeout' => 15,
				'body'    => array(
					'code'          => $code,
					'client_id'     => $settings['client_id'],
					'client_secret' => $settings['client_secret'],
					'redirect_uri'  => self::get_redirect_uri(),
					'grant_type'    => 'authorization_code',
				),
			)
		);

		$result = self::handle_token_response( $response, true );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return self::update_settings(
			array(
				'status'     => 'connected',
				'last_error' => '',
			)
		);
	}

	public static function refresh_access_token() {
		$settings = self::get_settings();

		if ( empty( $settings['refresh_token'] ) ) {
			return new WP_Error( 'booking_google_no_refresh_token', __( 'No hay refresh token guardado para esta cuenta.', 'booking-plugin' ) );
		}

		$response = wp_remote_post(
			self::TOKEN_URL,
			array(
				'timeout' => 15,
				'body'    => array(
					'client_id'     => $settings['client_id'],
					'client_secret' => $settings['client_secret'],
					'refresh_token' => $settings['refresh_token'],
					'grant_type'    => 'refresh_token',
				),
			)
		);

		return self::handle_token_response( $response, false );
	}

	public static function get_valid_access_token() {
		$settings = self::get_settings();

		if ( empty( $settings['access_token'] ) ) {
			return new WP_Error( 'booking_google_not_connected', __( 'La cuenta de Google no está conectada.', 'booking-plugin' ) );
		}

		$expires_at = $settings['token_expires_at'] ? strtotime( $settings['token_expires_at'] . ' UTC' ) : 0;

		if ( $expires_at > time() + 60 ) {
			return $settings['access_token'];
		}

		$refreshed = self::refresh_access_token();

		if ( is_wp_error( $refreshed ) ) {
			return $refreshed;
		}

		return $refreshed['access_token'];
	}

	public static function list_calendars() {
		$token = self::get_valid_access_token();

		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$response = wp_remote_get(
			self::API_BASE . '/users/me/calendarList',
			array(
				'timeout' => 15,
				'headers' => array( 'Authorization' => 'Bearer ' . $token ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 ) {
			return self::handle_api_error( $code, $body );
		}

		$calendars = array();

		foreach ( (array) ( isset( $body['items'] ) ? $body['items'] : array() ) as $item ) {
			$is_primary = ! empty( $item['primary'] );

			$calendars[] = array(
				'id'      => $item['id'],
				'summary' => isset( $item['summary'] ) ? $item['summary'] : $item['id'],
				'primary' => $is_primary,
			);

			if ( $is_primary ) {
				self::update_settings( array( 'account_email' => $item['id'] ) );
			}
		}

		return $calendars;
	}

	public static function mark_connection_error( $message ) {
		self::update_settings(
			array(
				'status'     => 'error',
				'last_error' => $message,
			)
		);
	}

	public static function disconnect() {
		return self::update_settings(
			array(
				'access_token'     => '',
				'refresh_token'    => '',
				'token_expires_at' => '',
				'status'           => 'disconnected',
				'last_error'       => '',
			)
		);
	}

	public static function handle_api_error( $code, $body ) {
		$error_message = is_array( $body ) && ! empty( $body['error']['message'] )
			? $body['error']['message']
			: sprintf( 'Google Calendar API error (HTTP %d)', $code );

		if ( 401 === $code || 404 === $code ) {
			self::mark_connection_error( $error_message );
		}

		return new WP_Error( 'booking_google_api_error', $error_message, array( 'status' => $code ) );
	}

	private static function handle_token_response( $response, $store_refresh_token ) {
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 || ! is_array( $body ) || empty( $body['access_token'] ) ) {
			$error = is_array( $body ) && ! empty( $body['error'] ) ? $body['error'] : 'unknown_error';

			if ( 'invalid_grant' === $error ) {
				self::mark_connection_error( $error );
			}

			return new WP_Error( 'booking_google_token_error', $error, array( 'status' => $code ) );
		}

		$update = array(
			'access_token'     => $body['access_token'],
			'token_expires_at' => gmdate( 'Y-m-d H:i:s', time() + ( isset( $body['expires_in'] ) ? (int) $body['expires_in'] : 3600 ) ),
		);

		if ( $store_refresh_token && ! empty( $body['refresh_token'] ) ) {
			$update['refresh_token'] = $body['refresh_token'];
		}

		return self::update_settings( $update );
	}
}
