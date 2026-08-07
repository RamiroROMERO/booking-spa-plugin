<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Booking_Plugin_DB_Schema {

	public static function get_sql() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();
		$prefix           = $wpdb->prefix;

		$sql = array();

		$sql[] = "CREATE TABLE {$prefix}booking_service_categories (
			id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
			name VARCHAR(191) NOT NULL,
			slug VARCHAR(191) NOT NULL,
			sort_order INT UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			UNIQUE KEY slug (slug)
		) $charset_collate;";

		$sql[] = "CREATE TABLE {$prefix}booking_services (
			id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
			category_id BIGINT UNSIGNED NULL,
			name VARCHAR(191) NOT NULL,
			slug VARCHAR(191) NOT NULL,
			description TEXT NULL,
			price DECIMAL(10,2) NOT NULL DEFAULT 0,
			duration_minutes SMALLINT UNSIGNED NOT NULL,
			buffer_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			status VARCHAR(20) NOT NULL DEFAULT 'active',
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			UNIQUE KEY slug (slug),
			KEY category_id (category_id),
			KEY status (status)
		) $charset_collate;";

		$sql[] = "CREATE TABLE {$prefix}booking_staff (
			id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
			user_id BIGINT UNSIGNED NULL,
			name VARCHAR(191) NOT NULL,
			email VARCHAR(191) NOT NULL,
			phone VARCHAR(30) NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'active',
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			KEY user_id (user_id),
			KEY status (status)
		) $charset_collate;";

		$sql[] = "CREATE TABLE {$prefix}booking_staff_services (
			staff_id BIGINT UNSIGNED NOT NULL,
			service_id BIGINT UNSIGNED NOT NULL,
			PRIMARY KEY  (staff_id, service_id)
		) $charset_collate;";

		$sql[] = "CREATE TABLE {$prefix}booking_staff_schedules (
			id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
			staff_id BIGINT UNSIGNED NOT NULL,
			day_of_week TINYINT UNSIGNED NOT NULL,
			start_time TIME NOT NULL,
			end_time TIME NOT NULL,
			break_start TIME NULL,
			break_end TIME NULL,
			KEY staff_id_day (staff_id, day_of_week)
		) $charset_collate;";

		$sql[] = "CREATE TABLE {$prefix}booking_staff_exceptions (
			id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
			staff_id BIGINT UNSIGNED NOT NULL,
			exception_date DATE NOT NULL,
			is_day_off TINYINT(1) NOT NULL DEFAULT 1,
			start_time TIME NULL,
			end_time TIME NULL,
			reason VARCHAR(191) NULL,
			UNIQUE KEY staff_date (staff_id, exception_date)
		) $charset_collate;";

		$sql[] = "CREATE TABLE {$prefix}booking_business_hours (
			id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
			day_of_week TINYINT UNSIGNED NOT NULL,
			open_time TIME NULL,
			close_time TIME NULL,
			UNIQUE KEY day_of_week (day_of_week)
		) $charset_collate;";

		$sql[] = "CREATE TABLE {$prefix}booking_appointments (
			id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
			service_id BIGINT UNSIGNED NOT NULL,
			staff_id BIGINT UNSIGNED NOT NULL,
			user_id BIGINT UNSIGNED NULL,
			guest_name VARCHAR(191) NULL,
			guest_email VARCHAR(191) NULL,
			guest_phone VARCHAR(30) NULL,
			start_datetime DATETIME NOT NULL,
			end_datetime DATETIME NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			notes TEXT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			KEY staff_start (staff_id, start_datetime),
			KEY service_id (service_id),
			KEY user_id (user_id),
			KEY status (status)
		) $charset_collate;";

		return $sql;
	}
}
