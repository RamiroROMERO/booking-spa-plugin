<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Booking_Plugin_Email_Templates {

	const OPTION_NAME = 'booking_plugin_email_templates';

	public static function get_keys() {
		return array(
			'client_confirmation',
			'client_reminder',
			'client_cancellation',
			'admin_new_booking',
			'admin_cancellation',
		);
	}

	public static function get_defaults() {
		return array(
			'client_confirmation' => array(
				'subject' => __( 'Tu reserva en {{business_name}} fue recibida', 'booking-plugin' ),
				'body'    => __(
					"Hola {{client_name}},\n\nTu reserva quedó registrada con estos datos:\n\nServicio: {{service_name}}\nCon: {{staff_name}}\nFecha: {{date}}\nHora: {{time}}\n\nPodés gestionar tu reserva desde este enlace: {{manage_url}}\n\nGracias por elegir {{business_name}}.",
					'booking-plugin'
				),
			),
			'client_reminder'     => array(
				'subject' => __( 'Recordatorio: tu cita en {{business_name}} es mañana', 'booking-plugin' ),
				'body'    => __(
					"Hola {{client_name}},\n\nTe recordamos tu cita:\n\nServicio: {{service_name}}\nCon: {{staff_name}}\nFecha: {{date}}\nHora: {{time}}\n\nPodés gestionar tu reserva desde este enlace: {{manage_url}}\n\nTe esperamos en {{business_name}}.",
					'booking-plugin'
				),
			),
			'client_cancellation' => array(
				'subject' => __( 'Tu reserva en {{business_name}} fue cancelada', 'booking-plugin' ),
				'body'    => __(
					"Hola {{client_name}},\n\nTu reserva fue cancelada:\n\nServicio: {{service_name}}\nCon: {{staff_name}}\nFecha: {{date}}\nHora: {{time}}\n\nSi querés reservar nuevamente, podés hacerlo desde: {{manage_url}}\n\nGracias por tu comprensión.",
					'booking-plugin'
				),
			),
			'admin_new_booking'   => array(
				'subject' => __( 'Nueva reserva: {{client_name}}', 'booking-plugin' ),
				'body'    => __(
					"Se registró una nueva reserva:\n\nCliente: {{client_name}}\nEmail: {{client_email}}\nTeléfono: {{client_phone}}\nServicio: {{service_name}}\nCon: {{staff_name}}\nFecha: {{date}}\nHora: {{time}}",
					'booking-plugin'
				),
			),
			'admin_cancellation'  => array(
				'subject' => __( 'Reserva cancelada: {{client_name}}', 'booking-plugin' ),
				'body'    => __(
					"Se canceló una reserva:\n\nCliente: {{client_name}}\nEmail: {{client_email}}\nTeléfono: {{client_phone}}\nServicio: {{service_name}}\nCon: {{staff_name}}\nFecha: {{date}}\nHora: {{time}}",
					'booking-plugin'
				),
			),
		);
	}

	public static function get_all_templates() {
		$defaults = self::get_defaults();
		$stored   = get_option( self::OPTION_NAME, array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		$templates = array();

		foreach ( $defaults as $key => $default ) {
			$override = isset( $stored[ $key ] ) && is_array( $stored[ $key ] ) ? $stored[ $key ] : array();

			$subject = isset( $override['subject'] ) && '' !== $override['subject'] ? $override['subject'] : $default['subject'];
			$body    = isset( $override['body'] ) && '' !== $override['body'] ? $override['body'] : $default['body'];

			$templates[ $key ] = array(
				'subject'       => $subject,
				'body'          => $body,
				// Comparado contra el default, no solo "hay override guardado":
				// save_templates() siempre persiste las 5 plantillas juntas (PUT
				// exige el payload completo), así que editar una sola termina
				// re-guardando el texto default sin cambios para las otras 4 --
				// si is_customized solo mirara "hay un override no vacío", esas
				// 4 quedarían marcadas como "Personalizada" por error.
				'is_customized' => $subject !== $default['subject'] || $body !== $default['body'],
			);
		}

		return $templates;
	}

	public static function get_template( $key ) {
		$templates = self::get_all_templates();

		return isset( $templates[ $key ] ) ? $templates[ $key ] : null;
	}

	public static function is_customized( $key ) {
		$template = self::get_template( $key );

		return $template ? (bool) $template['is_customized'] : false;
	}

	public static function save_templates( array $templates ) {
		$valid_keys = self::get_keys();
		$sanitized  = array();

		foreach ( $templates as $key => $template ) {
			if ( ! in_array( $key, $valid_keys, true ) ) {
				return new WP_Error( 'booking_rest_invalid_template', __( 'Unknown template key.', 'booking-plugin' ), array( 'status' => 400 ) );
			}

			$subject = isset( $template['subject'] ) ? trim( sanitize_text_field( $template['subject'] ) ) : '';
			$body    = isset( $template['body'] ) ? trim( wp_kses_post( $template['body'] ) ) : '';

			if ( '' === $subject || '' === $body ) {
				return new WP_Error( 'booking_rest_invalid_template', __( 'subject and body must not be empty.', 'booking-plugin' ), array( 'status' => 400 ) );
			}

			$sanitized[ $key ] = array(
				'subject' => $subject,
				'body'    => $body,
			);
		}

		foreach ( $valid_keys as $key ) {
			if ( ! isset( $sanitized[ $key ] ) ) {
				return new WP_Error( 'booking_rest_invalid_template', __( 'All 5 templates must be provided.', 'booking-plugin' ), array( 'status' => 400 ) );
			}
		}

		update_option( self::OPTION_NAME, $sanitized );

		return self::get_all_templates();
	}

	public static function restore_default( $key ) {
		$stored = get_option( self::OPTION_NAME, array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		unset( $stored[ $key ] );

		update_option( self::OPTION_NAME, $stored );

		return self::get_all_templates();
	}
}
