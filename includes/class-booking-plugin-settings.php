<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Booking_Plugin_Settings {

	const OPTION_NAME = 'booking_plugin_settings';

	public static function get_defaults() {
		return array(
			'min_lead_time_hours'       => 1,
			'max_advance_days'          => 60,
			'min_cancellation_hours'    => 2,
			'slot_interval_minutes'     => 15,
			'notification_email'        => get_option( 'admin_email' ),
			'payment_window_hours'      => 2,
			'auto_refund_enabled'       => false,
			'widget_accent_color'       => '',
			'widget_accent_hover_color' => '',
			'widget_border_color'       => '',
			'widget_text_muted_color'   => '',
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

		$notification_email = sanitize_email( $merged['notification_email'] );

		if ( '' === $notification_email || ! is_email( $notification_email ) ) {
			$notification_email = get_option( 'admin_email' );
		}

		$sanitized = array(
			'min_lead_time_hours'       => max( 0, (int) $merged['min_lead_time_hours'] ),
			'max_advance_days'          => max( 0, (int) $merged['max_advance_days'] ),
			'min_cancellation_hours'    => max( 0, (int) $merged['min_cancellation_hours'] ),
			'slot_interval_minutes'     => max( 1, (int) $merged['slot_interval_minutes'] ),
			'notification_email'        => $notification_email,
			'payment_window_hours'      => max( 1, (int) $merged['payment_window_hours'] ),
			'auto_refund_enabled'       => (bool) $merged['auto_refund_enabled'],
			'widget_accent_color'       => self::sanitize_color_field( $merged['widget_accent_color'], $current['widget_accent_color'] ),
			'widget_accent_hover_color' => self::sanitize_color_field( $merged['widget_accent_hover_color'], $current['widget_accent_hover_color'] ),
			'widget_border_color'       => self::sanitize_color_field( $merged['widget_border_color'], $current['widget_border_color'] ),
			'widget_text_muted_color'   => self::sanitize_color_field( $merged['widget_text_muted_color'], $current['widget_text_muted_color'] ),
		);

		update_option( self::OPTION_NAME, $sanitized );

		return $sanitized;
	}

	private static function sanitize_color_field( $value, $fallback ) {
		$sanitized = sanitize_hex_color( is_string( $value ) ? $value : '' );

		return null === $sanitized ? $fallback : $sanitized;
	}
}
