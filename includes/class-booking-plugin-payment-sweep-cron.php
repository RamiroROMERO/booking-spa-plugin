<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Booking_Plugin_Payment_Sweep_Cron {

	const HOOK     = 'booking_plugin_payment_sweep';
	const SCHEDULE = 'booking_plugin_every_15_min';

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
			'display'  => __( 'Every 15 minutes (Booking Plugin)', 'booking-plugin' ),
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
		if ( ! Booking_Plugin_WooCommerce::is_active() ) {
			return;
		}

		global $wpdb;

		$table    = $wpdb->prefix . 'booking_appointments';
		$settings = Booking_Plugin_Settings::get_settings();

		$cutoff = ( new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ) )
			->modify( '-' . (int) $settings['payment_window_hours'] . ' hours' )
			->format( 'Y-m-d H:i:s' );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table}
				 WHERE status = 'pending'
				   AND wc_order_id IS NOT NULL
				   AND created_at <= %s",
				$cutoff
			)
		);

		if ( ! $rows ) {
			return;
		}

		foreach ( $rows as $row ) {
			$this->maybe_cancel_unpaid( $row );
		}
	}

	protected function maybe_cancel_unpaid( $appointment ) {
		$order = wc_get_order( (int) $appointment->wc_order_id );

		if ( ! $order ) {
			return;
		}

		// Revalida el estado real del pedido antes de actuar (ver Risks de
		// SPEC 10): el hook de WooCommerce podria no haber sincronizado el
		// pago todavia, y no queremos cancelar una cita que si se pago.
		if ( $order->is_paid() ) {
			return;
		}

		global $wpdb;

		$table = $wpdb->prefix . 'booking_appointments';

		$wpdb->update(
			$table,
			array(
				'status'     => 'cancelled',
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'id' => (int) $appointment->id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		$updated = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $appointment->id ) );

		do_action( 'booking_plugin_appointment_cancelled', $updated );
	}
}
