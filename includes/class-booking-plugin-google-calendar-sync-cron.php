<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Booking_Plugin_Google_Calendar_Sync_Cron {

	const HOOK     = 'booking_plugin_google_calendar_sync_sweep';
	const SCHEDULE = 'booking_plugin_google_calendar_every_15_min';

	public function register() {
		add_action( self::HOOK, array( $this, 'run_sweep' ) );
	}

	/**
	 * Registrado incondicionalmente a nivel de archivo (ver booking-plugin.php),
	 * no desde init(): mismo motivo que Booking_Plugin_Reminder_Cron::register_schedule().
	 */
	public static function register_schedule( $schedules ) {
		$schedules[ self::SCHEDULE ] = array(
			'interval' => 15 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every 15 minutes (Booking Plugin - Google Calendar Sync)', 'booking-plugin' ),
		);

		return $schedules;
	}

	public static function schedule() {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time(), self::SCHEDULE, self::HOOK );
		}
	}

	public static function unschedule() {
		wp_clear_scheduled_hook( self::HOOK );
	}

	public function run_sweep() {
		$account = Booking_Plugin_Google_Calendar::get_settings();

		if ( 'connected' !== $account['status'] ) {
			return;
		}

		global $wpdb;

		$table = $wpdb->prefix . 'booking_appointments';

		$rows = $wpdb->get_results(
			"SELECT * FROM {$table} WHERE google_sync_status IN ('pending', 'failed')"
		);

		if ( ! $rows ) {
			return;
		}

		foreach ( $rows as $row ) {
			Booking_Plugin_Google_Calendar_Sync::sync_appointment( $row );
		}
	}
}
