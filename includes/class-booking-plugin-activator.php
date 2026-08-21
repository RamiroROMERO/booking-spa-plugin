<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Booking_Plugin_Activator {

	public static function activate() {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		foreach ( Booking_Plugin_DB_Schema::get_sql() as $sql ) {
			dbDelta( $sql );
		}

		Booking_Plugin_DB_Schema::maybe_create_views();

		update_option( 'booking_plugin_db_version', BOOKING_PLUGIN_DB_VERSION );

		Booking_Plugin_Reminder_Cron::schedule();
		Booking_Plugin_Payment_Sweep_Cron::schedule();
		Booking_Plugin_Google_Calendar_Sync_Cron::schedule();
	}
}
