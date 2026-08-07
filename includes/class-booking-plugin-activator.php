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

		update_option( 'booking_plugin_db_version', BOOKING_PLUGIN_DB_VERSION );

		Booking_Plugin_Reminder_Cron::schedule();
	}
}
