<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Booking_Plugin_Availability {

	public function get_available_slots( $service_id, $date, $timezone = null, $staff_id = null ) {
		$service = $this->get_active_service( $service_id );

		if ( is_wp_error( $service ) ) {
			return $service;
		}

		$request_tz = $this->resolve_timezone( $timezone );

		if ( is_wp_error( $request_tz ) ) {
			return $request_tz;
		}

		$day_start_local = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $date . ' 00:00:00', $request_tz );

		if ( ! $day_start_local ) {
			return new WP_Error( 'booking_rest_invalid_date', __( 'date must be in Y-m-d format.', 'booking-plugin' ), array( 'status' => 400 ) );
		}

		$day_start_utc = $day_start_local->setTimezone( new DateTimeZone( 'UTC' ) );
		$day_end_utc   = $day_start_utc->modify( '+1 day' );

		$staff_ids = $this->resolve_candidate_staff_ids( $service_id, $staff_id );

		if ( is_wp_error( $staff_ids ) ) {
			return $staff_ids;
		}

		$settings            = Booking_Plugin_Settings::get_settings();
		$now_utc             = new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
		$site_tz             = wp_timezone();
		$business_hours_map  = $this->get_business_hours_map();
		$duration_minutes    = (int) $service->duration_minutes;
		$slot_interval       = max( 1, (int) $settings['slot_interval_minutes'] );

		$slots = array();

		foreach ( $staff_ids as $sid ) {
			$schedules_map  = $this->get_staff_schedules_map( $sid );
			$exceptions_map = $this->get_staff_exceptions_map( $sid, $day_start_local );
			$blocked_ranges = $this->get_blocked_ranges( $sid, $day_start_utc, $day_end_utc );

			$cursor = $day_start_utc;

			while ( true ) {
				$candidate_end = $cursor->modify( '+' . $duration_minutes . ' minutes' );

				if ( $candidate_end > $day_end_utc ) {
					break;
				}

				if ( $this->slot_fits( $cursor, $candidate_end, $business_hours_map, $schedules_map, $exceptions_map, $blocked_ranges, $settings, $site_tz, $now_utc ) ) {
					$slots[] = array(
						'staff_id'       => $sid,
						'start_datetime' => $cursor->format( 'Y-m-d\TH:i:s\Z' ),
						'end_datetime'   => $candidate_end->format( 'Y-m-d\TH:i:s\Z' ),
					);
				}

				$cursor = $cursor->modify( '+' . $slot_interval . ' minutes' );
			}
		}

		return $slots;
	}

	public function get_eligible_staff_ids( $service_id ) {
		return $this->resolve_candidate_staff_ids( $service_id, null );
	}

	public function is_staff_slot_available( $service_id, $staff_id, $start_datetime, $exclude_appointment_id = 0 ) {
		$service = $this->get_active_service( $service_id );

		if ( is_wp_error( $service ) ) {
			return $service;
		}

		$staff_ids = $this->resolve_candidate_staff_ids( $service_id, $staff_id );

		if ( is_wp_error( $staff_ids ) ) {
			return $staff_ids;
		}

		if ( empty( $staff_ids ) ) {
			return false;
		}

		try {
			$candidate_start = new DateTimeImmutable( $start_datetime, new DateTimeZone( 'UTC' ) );
		} catch ( Exception $e ) {
			return new WP_Error( 'booking_rest_invalid_datetime', __( 'start_datetime must be a valid ISO 8601 datetime.', 'booking-plugin' ), array( 'status' => 400 ) );
		}

		$candidate_end = $candidate_start->modify( '+' . (int) $service->duration_minutes . ' minutes' );

		$settings = Booking_Plugin_Settings::get_settings();
		$now_utc  = new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
		$site_tz  = wp_timezone();
		$sid      = $staff_ids[0];

		$business_hours_map = $this->get_business_hours_map();
		$schedules_map       = $this->get_staff_schedules_map( $sid );
		$exceptions_map      = $this->get_staff_exceptions_map( $sid, $candidate_start );
		$blocked_ranges      = $this->get_blocked_ranges( $sid, $candidate_start->modify( '-1 day' ), $candidate_end->modify( '+1 day' ), $exclude_appointment_id );

		return $this->slot_fits( $candidate_start, $candidate_end, $business_hours_map, $schedules_map, $exceptions_map, $blocked_ranges, $settings, $site_tz, $now_utc );
	}

	protected function slot_fits( DateTimeImmutable $candidate_start, DateTimeImmutable $candidate_end, array $business_hours_map, array $schedules_map, array $exceptions_map, array $blocked_ranges, array $settings, DateTimeZone $site_tz, DateTimeImmutable $now_utc ) {
		$min_start = $now_utc->modify( '+' . (int) $settings['min_lead_time_hours'] . ' hours' );
		$max_start = $now_utc->modify( '+' . (int) $settings['max_advance_days'] . ' days' );

		if ( $candidate_start < $min_start || $candidate_start > $max_start ) {
			return false;
		}

		$local_start = $candidate_start->setTimezone( $site_tz );
		$dow         = (int) $local_start->format( 'w' );
		$local_date  = $local_start->format( 'Y-m-d' );

		$business = isset( $business_hours_map[ $dow ] ) ? $business_hours_map[ $dow ] : null;

		if ( ! $business || empty( $business['open_time'] ) || empty( $business['close_time'] ) ) {
			return false;
		}

		$business_open  = $this->local_datetime( $local_date, $business['open_time'], $site_tz );
		$business_close = $this->local_datetime( $local_date, $business['close_time'], $site_tz );

		if ( $candidate_start < $business_open || $candidate_end > $business_close ) {
			return false;
		}

		$exception = isset( $exceptions_map[ $local_date ] ) ? $exceptions_map[ $local_date ] : null;

		if ( $exception ) {
			if ( ! empty( $exception->is_day_off ) ) {
				return false;
			}

			if ( empty( $exception->start_time ) || empty( $exception->end_time ) ) {
				return false;
			}

			$window_start = $this->local_datetime( $local_date, $exception->start_time, $site_tz );
			$window_end   = $this->local_datetime( $local_date, $exception->end_time, $site_tz );

			if ( $candidate_start < $window_start || $candidate_end > $window_end ) {
				return false;
			}
		} else {
			$schedules = isset( $schedules_map[ $dow ] ) ? $schedules_map[ $dow ] : array();

			if ( empty( $schedules ) ) {
				return false;
			}

			$fits = false;

			foreach ( $schedules as $schedule ) {
				$sched_start = $this->local_datetime( $local_date, $schedule->start_time, $site_tz );
				$sched_end   = $this->local_datetime( $local_date, $schedule->end_time, $site_tz );

				if ( $candidate_start < $sched_start || $candidate_end > $sched_end ) {
					continue;
				}

				if ( ! empty( $schedule->break_start ) && ! empty( $schedule->break_end ) ) {
					$break_start = $this->local_datetime( $local_date, $schedule->break_start, $site_tz );
					$break_end   = $this->local_datetime( $local_date, $schedule->break_end, $site_tz );

					if ( $candidate_start < $break_end && $candidate_end > $break_start ) {
						continue;
					}
				}

				$fits = true;
				break;
			}

			if ( ! $fits ) {
				return false;
			}
		}

		foreach ( $blocked_ranges as $range ) {
			if ( $candidate_start < $range['end'] && $candidate_end > $range['start'] ) {
				return false;
			}
		}

		return true;
	}

	protected function local_datetime( $date_str, $time_str, DateTimeZone $tz ) {
		$dt = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $date_str . ' ' . $this->normalize_time( $time_str ), $tz );

		return $dt->setTimezone( new DateTimeZone( 'UTC' ) );
	}

	protected function normalize_time( $time_str ) {
		return ( substr_count( $time_str, ':' ) === 1 ) ? $time_str . ':00' : $time_str;
	}

	protected function resolve_timezone( $timezone ) {
		if ( empty( $timezone ) ) {
			return wp_timezone();
		}

		try {
			return new DateTimeZone( $timezone );
		} catch ( Exception $e ) {
			return new WP_Error( 'booking_rest_invalid_timezone', __( 'Invalid timezone.', 'booking-plugin' ), array( 'status' => 400 ) );
		}
	}

	protected function get_active_service( $service_id ) {
		global $wpdb;

		$table = $wpdb->prefix . 'booking_services';
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d AND status = 'active'", (int) $service_id ) );

		if ( ! $row ) {
			return new WP_Error( 'booking_rest_invalid_service', __( 'Service not found or inactive.', 'booking-plugin' ), array( 'status' => 404 ) );
		}

		return $row;
	}

	protected function resolve_candidate_staff_ids( $service_id, $staff_id ) {
		global $wpdb;

		$staff_table = $wpdb->prefix . 'booking_staff';
		$pivot_table = $wpdb->prefix . 'booking_staff_services';

		if ( $staff_id ) {
			$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$staff_table} WHERE id = %d", (int) $staff_id ) );

			if ( ! $exists ) {
				return new WP_Error( 'booking_rest_invalid_staff', __( 'Staff not found.', 'booking-plugin' ), array( 'status' => 400 ) );
			}

			$eligible = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT st.id FROM {$staff_table} st
					 INNER JOIN {$pivot_table} ps ON ps.staff_id = st.id
					 WHERE st.id = %d AND st.status = 'active' AND ps.service_id = %d",
					(int) $staff_id,
					(int) $service_id
				)
			);

			return $eligible ? array( (int) $staff_id ) : array();
		}

		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT st.id FROM {$staff_table} st
				 INNER JOIN {$pivot_table} ps ON ps.staff_id = st.id
				 WHERE st.status = 'active' AND ps.service_id = %d
				 ORDER BY st.id ASC",
				(int) $service_id
			)
		);

		return array_map( 'intval', $rows );
	}

	protected function get_business_hours_map() {
		global $wpdb;

		$table = $wpdb->prefix . 'booking_business_hours';
		$rows  = $wpdb->get_results( "SELECT * FROM {$table}" );

		$map = array();

		foreach ( $rows as $row ) {
			$map[ (int) $row->day_of_week ] = array(
				'open_time'  => $row->open_time,
				'close_time' => $row->close_time,
			);
		}

		return $map;
	}

	protected function get_staff_schedules_map( $staff_id ) {
		global $wpdb;

		$table = $wpdb->prefix . 'booking_staff_schedules';
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE staff_id = %d", $staff_id ) );

		$map = array();

		foreach ( $rows as $row ) {
			$day = (int) $row->day_of_week;

			if ( ! isset( $map[ $day ] ) ) {
				$map[ $day ] = array();
			}

			$map[ $day ][] = $row;
		}

		return $map;
	}

	protected function get_staff_exceptions_map( $staff_id, DateTimeImmutable $reference_local ) {
		global $wpdb;

		$site_tz = wp_timezone();

		$from = $reference_local->setTimezone( $site_tz )->modify( '-1 day' )->format( 'Y-m-d' );
		$to   = $reference_local->setTimezone( $site_tz )->modify( '+1 day' )->format( 'Y-m-d' );

		$table = $wpdb->prefix . 'booking_staff_exceptions';

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE staff_id = %d AND exception_date BETWEEN %s AND %s",
				$staff_id,
				$from,
				$to
			)
		);

		$map = array();

		foreach ( $rows as $row ) {
			$map[ $row->exception_date ] = $row;
		}

		return $map;
	}

	protected function get_blocked_ranges( $staff_id, DateTimeImmutable $from_utc, DateTimeImmutable $to_utc, $exclude_appointment_id = 0 ) {
		global $wpdb;

		$appointments_table = $wpdb->prefix . 'booking_appointments';
		$services_table      = $wpdb->prefix . 'booking_services';

		$from_padded = $from_utc->modify( '-1 day' )->format( 'Y-m-d H:i:s' );
		$to_str      = $to_utc->format( 'Y-m-d H:i:s' );
		$exclude_sql = $exclude_appointment_id ? ' AND a.id != ' . (int) $exclude_appointment_id : '';

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT a.start_datetime, a.end_datetime, s.buffer_minutes
				 FROM {$appointments_table} a
				 INNER JOIN {$services_table} s ON s.id = a.service_id
				 WHERE a.staff_id = %d
				   AND a.status != 'cancelled'
				   AND a.start_datetime < %s
				   AND a.start_datetime >= %s{$exclude_sql}",
				$staff_id,
				$to_str,
				$from_padded
			)
		);

		$ranges = array();

		foreach ( $rows as $row ) {
			$start = new DateTimeImmutable( $row->start_datetime, new DateTimeZone( 'UTC' ) );
			$end   = new DateTimeImmutable( $row->end_datetime, new DateTimeZone( 'UTC' ) );
			$end   = $end->modify( '+' . (int) $row->buffer_minutes . ' minutes' );

			$ranges[] = array(
				'start' => $start,
				'end'   => $end,
			);
		}

		return $ranges;
	}
}
