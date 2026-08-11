<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Booking_Plugin_Payroll {

	public static function log_commission_for_appointment( $appointment_id ) {
		global $wpdb;

		// 1. Obtener la fila de la cita
		$appointments_table = $wpdb->prefix . 'booking_appointments';
		$appointment = $wpdb->get_row( $wpdb->prepare(
			"SELECT service_id, staff_id FROM {$appointments_table} WHERE id = %d",
			$appointment_id
		) );

		if ( ! $appointment ) {
			return;
		}

		$service_id = (int) $appointment->service_id;
		$staff_id   = (int) $appointment->staff_id;

		// 2. Calcular total_service_amount
		$services_table = $wpdb->prefix . 'booking_services';
		$service_price  = $wpdb->get_var( $wpdb->prepare(
			"SELECT price FROM {$services_table} WHERE id = %d",
			$service_id
		) );

		$service_price = $service_price !== null ? (float) $service_price : 0.0;

		$addons_table = $wpdb->prefix . 'booking_appointment_addons';
		$addons_sum   = $wpdb->get_var( $wpdb->prepare(
			"SELECT SUM(price) FROM {$addons_table} WHERE appointment_id = %d",
			$appointment_id
		) );

		$addons_sum = $addons_sum !== null ? (float) $addons_sum : 0.0;

		$total_service_amount = $service_price + $addons_sum;

		// 3. Buscar la configuración de la comisión
		$staff_services_table = $wpdb->prefix . 'booking_staff_services';
		$commission = $wpdb->get_row( $wpdb->prepare(
			"SELECT commission_type, commission_value FROM {$staff_services_table} WHERE staff_id = %d AND service_id = %d",
			$staff_id,
			$service_id
		) );

		if ( ! $commission || empty( $commission->commission_type ) || $commission->commission_value === null ) {
			error_log( sprintf(
				'[booking-plugin] No se generó payroll_log para la cita #%d: el staff #%d no tiene comisión configurada para el servicio #%d.',
				$appointment_id,
				$staff_id,
				$service_id
			) );
			return;
		}

		$commission_type   = $commission->commission_type;
		$commission_value  = (float) $commission->commission_value;
		$commission_earned = 0.0;

		if ( 'fixed' === $commission_type ) {
			$commission_earned = $commission_value;
		} elseif ( 'percentage' === $commission_type ) {
			$commission_earned = ( $total_service_amount * $commission_value ) / 100.0;
		} else {
			return;
		}

		// 4. Insertar fila en booking_payroll_logs (UNIQUE KEY en appointment_id protege de duplicados)
		$payroll_logs_table = $wpdb->prefix . 'booking_payroll_logs';
		$wpdb->insert(
			$payroll_logs_table,
			array(
				'appointment_id'       => $appointment_id,
				'staff_id'             => $staff_id,
				'total_service_amount' => $total_service_amount,
				'commission_type'      => $commission_type,
				'commission_value'     => $commission_value,
				'commission_earned'    => $commission_earned,
				'created_at'           => current_time( 'mysql', true ),
			),
			array(
				'%d',
				'%d',
				'%f',
				'%s',
				'%f',
				'%f',
				'%s',
			)
		);
	}
}
