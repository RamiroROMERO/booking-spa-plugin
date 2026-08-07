<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Booking_Plugin_Reminder_Cron {

	const HOOK     = 'booking_plugin_reminder_sweep';
	const SCHEDULE = 'booking_plugin_every_30_min';

	public function register() {
		add_action( self::HOOK, array( $this, 'run_sweep' ) );
	}

	/**
	 * Registered unconditionally at file scope (see booking-plugin.php), not
	 * from init(): wp_schedule_event() validates the interval name against
	 * this filter, and activation (real or via the DB-version bump in
	 * Booking_Plugin::init()) can call schedule() before init() would
	 * otherwise get around to wiring this up.
	 */
	public static function register_schedule( $schedules ) {
		$schedules[ self::SCHEDULE ] = array(
			'interval' => 30 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every 30 minutes (Booking Plugin)', 'booking-plugin' ),
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
		global $wpdb;

		$table = $wpdb->prefix . 'booking_appointments';
		$now   = new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );

		$window_start = $now->modify( '+23 hours' )->format( 'Y-m-d H:i:s' );
		$window_end   = $now->modify( '+25 hours' )->format( 'Y-m-d H:i:s' );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table}
				 WHERE start_datetime >= %s
				   AND start_datetime <= %s
				   AND status IN ('pending', 'confirmed')
				   AND reminder_sent_at IS NULL",
				$window_start,
				$window_end
			)
		);

		if ( ! $rows ) {
			return;
		}

		$notifications = new Booking_Plugin_Notifications();

		foreach ( $rows as $row ) {
			$notifications->send_client_reminder( $row );

			$wpdb->update(
				$table,
				array( 'reminder_sent_at' => current_time( 'mysql', true ) ),
				array( 'id' => (int) $row->id )
			);
		}
	}
}
