<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Booking_Plugin_Google_Calendar_Sync {

	public static function sync_appointment( $row ) {
		if ( ! is_object( $row ) || empty( $row->id ) ) {
			return;
		}

		$account = Booking_Plugin_Google_Calendar::get_settings();

		if ( 'connected' !== $account['status'] ) {
			return;
		}

		if ( in_array( $row->status, array( 'completed', 'no_show' ), true ) ) {
			return;
		}

		if ( 'cancelled' === $row->status ) {
			self::sync_cancelled( $account, $row );
			return;
		}

		if ( ! in_array( $row->status, array( 'confirmed', 'pending', 'blocked' ), true ) ) {
			return;
		}

		self::sync_upsert( $account, $row );
	}

	private static function sync_cancelled( $account, $row ) {
		global $wpdb;
		$table = $wpdb->prefix . 'booking_appointments';

		if ( empty( $row->google_event_id ) ) {
			return;
		}

		$result = self::request( $account, 'DELETE', self::event_path( $account, $row->google_event_id ), null );

		if ( is_wp_error( $result ) ) {
			$wpdb->update( $table, array( 'google_sync_status' => 'pending' ), array( 'id' => $row->id ) );
			return;
		}

		$wpdb->update(
			$table,
			array(
				'google_event_id'    => null,
				'google_sync_status' => 'not_synced',
				'google_synced_at'   => null,
			),
			array( 'id' => $row->id )
		);
	}

	private static function sync_upsert( $account, $row ) {
		global $wpdb;
		$table = $wpdb->prefix . 'booking_appointments';

		$event = self::build_event_payload( $row );

		if ( ! empty( $row->google_event_id ) ) {
			$result = self::request( $account, 'PATCH', self::event_path( $account, $row->google_event_id ), $event );
		} else {
			$result = self::request( $account, 'POST', self::events_path( $account ), $event );
		}

		if ( is_wp_error( $result ) ) {
			$wpdb->update( $table, array( 'google_sync_status' => 'pending' ), array( 'id' => $row->id ) );
			return;
		}

		$update = array(
			'google_sync_status' => 'synced',
			'google_synced_at'   => current_time( 'mysql', true ),
		);

		if ( empty( $row->google_event_id ) && ! empty( $result['id'] ) ) {
			$update['google_event_id'] = $result['id'];
		}

		$wpdb->update( $table, $update, array( 'id' => $row->id ) );
	}

	private static function events_path( $account ) {
		return '/calendars/' . rawurlencode( $account['calendar_id'] ) . '/events';
	}

	private static function event_path( $account, $event_id ) {
		return self::events_path( $account ) . '/' . rawurlencode( $event_id );
	}

	private static function request( $account, $method, $path, $body ) {
		$token = Booking_Plugin_Google_Calendar::get_valid_access_token();

		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$args = array(
			'method'  => $method,
			'timeout' => 15,
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'application/json',
			),
		);

		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}

		$response = wp_remote_request( Booking_Plugin_Google_Calendar::API_BASE . $path, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code      = wp_remote_retrieve_response_code( $response );
		$raw_body  = wp_remote_retrieve_body( $response );
		$parsed    = $raw_body ? json_decode( $raw_body, true ) : array();

		if ( $code < 200 || $code >= 300 ) {
			return Booking_Plugin_Google_Calendar::handle_api_error( $code, $parsed );
		}

		return is_array( $parsed ) ? $parsed : array();
	}

	private static function build_event_payload( $row ) {
		if ( 'blocked' === $row->status ) {
			$title = ! empty( $row->notes ) ? 'Bloqueado - ' . $row->notes : 'Bloqueado';
		} else {
			$title = self::resolve_service_name( $row->service_id ) . ' - ' . self::resolve_client_name( $row );
		}

		$description_lines   = array();
		$description_lines[] = 'Staff: ' . self::resolve_staff_name( $row->staff_id );
		$description_lines[] = 'Estado: ' . $row->status;

		if ( ! empty( $row->notes ) ) {
			$description_lines[] = 'Notas: ' . $row->notes;
		}

		return array(
			'summary'     => $title,
			'description' => implode( "\n", $description_lines ),
			'start'       => self::format_event_datetime( $row->start_datetime ),
			'end'         => self::format_event_datetime( $row->end_datetime ),
		);
	}

	private static function resolve_service_name( $service_id ) {
		if ( empty( $service_id ) ) {
			return __( 'Reserva', 'booking-plugin' );
		}

		global $wpdb;
		$name = $wpdb->get_var( $wpdb->prepare( "SELECT name FROM {$wpdb->prefix}booking_services WHERE id = %d", $service_id ) );

		return $name ? $name : __( 'Reserva', 'booking-plugin' );
	}

	private static function resolve_staff_name( $staff_id ) {
		global $wpdb;
		$name = $wpdb->get_var( $wpdb->prepare( "SELECT name FROM {$wpdb->prefix}booking_staff WHERE id = %d", $staff_id ) );

		return $name ? $name : __( 'Sin asignar', 'booking-plugin' );
	}

	// Misma logica que client_name en Booking_Rest_Appointments_Controller::prepare_item()
	// (SPEC 23), pero resuelta a mano porque $row aca no viene del LEFT JOIN de get_items()
	// y agrega el fallback "Reserva" que pide esta spec para el titulo del evento.
	private static function resolve_client_name( $row ) {
		if ( ! empty( $row->guest_name ) ) {
			return $row->guest_name;
		}

		if ( ! empty( $row->user_id ) ) {
			$user = get_userdata( $row->user_id );

			if ( $user && $user->display_name ) {
				return $user->display_name;
			}
		}

		return __( 'Reserva', 'booking-plugin' );
	}

	private static function format_event_datetime( $mysql_datetime_utc ) {
		$utc       = new DateTimeImmutable( $mysql_datetime_utc, new DateTimeZone( 'UTC' ) );
		$tz_string = wp_timezone_string();

		try {
			$site_tz = new DateTimeZone( $tz_string );
		} catch ( Exception $e ) {
			$site_tz = new DateTimeZone( 'UTC' );
		}

		$local = $utc->setTimezone( $site_tz );

		$event = array( 'dateTime' => $local->format( DateTime::RFC3339 ) );

		if ( false !== strpos( $tz_string, '/' ) ) {
			$event['timeZone'] = $tz_string;
		}

		return $event;
	}
}
