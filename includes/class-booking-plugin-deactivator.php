<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Booking_Plugin_Deactivator {

	public static function deactivate() {
		Booking_Plugin_Reminder_Cron::unschedule();
		Booking_Plugin_Payment_Sweep_Cron::unschedule();

		flush_rewrite_rules();
	}
}
