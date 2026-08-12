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
			requires_payment TINYINT(1) NOT NULL DEFAULT 0,
			wc_product_id BIGINT UNSIGNED NULL,
			image_id BIGINT UNSIGNED NULL,
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

		// commission_type: 'fixed' | 'percentage' | NULL (no configurado).
		$sql[] = "CREATE TABLE {$prefix}booking_staff_services (
			staff_id BIGINT UNSIGNED NOT NULL,
			service_id BIGINT UNSIGNED NOT NULL,
			commission_type VARCHAR(20) NULL,
			commission_value DECIMAL(10,2) NULL,
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
			service_id BIGINT UNSIGNED NULL,
			staff_id BIGINT UNSIGNED NOT NULL,
			user_id BIGINT UNSIGNED NULL,
			guest_name VARCHAR(191) NULL,
			guest_email VARCHAR(191) NULL,
			guest_phone VARCHAR(30) NULL,
			start_datetime DATETIME NOT NULL,
			end_datetime DATETIME NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			notes TEXT NULL,
			access_token VARCHAR(64) NULL,
			reminder_sent_at DATETIME NULL,
			wc_order_id BIGINT UNSIGNED NULL,
			paid_with_credit_id BIGINT UNSIGNED NULL,
			credits_consumed SMALLINT UNSIGNED NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			KEY staff_start (staff_id, start_datetime),
			KEY service_id (service_id),
			KEY user_id (user_id),
			KEY status (status),
			UNIQUE KEY access_token (access_token)
		) $charset_collate;";

		$sql[] = "CREATE TABLE {$prefix}booking_service_addons (
			id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
			service_id BIGINT UNSIGNED NOT NULL,
			name VARCHAR(191) NOT NULL,
			price DECIMAL(10,2) NOT NULL DEFAULT 0,
			extra_time_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			status VARCHAR(20) NOT NULL DEFAULT 'active',
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			KEY service_id (service_id),
			KEY status (status)
		) $charset_collate;";

		$sql[] = "CREATE TABLE {$prefix}booking_appointment_addons (
			id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
			appointment_id BIGINT UNSIGNED NOT NULL,
			addon_id BIGINT UNSIGNED NOT NULL,
			name VARCHAR(191) NOT NULL,
			price DECIMAL(10,2) NOT NULL,
			extra_time_minutes SMALLINT UNSIGNED NOT NULL,
			UNIQUE KEY appointment_addon (appointment_id, addon_id),
			KEY appointment_id (appointment_id),
			KEY addon_id (addon_id)
		) $charset_collate;";

		$sql[] = "CREATE TABLE {$prefix}booking_packages (
			id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
			name VARCHAR(191) NOT NULL,
			total_sessions SMALLINT UNSIGNED NOT NULL,
			price DECIMAL(10,2) NOT NULL DEFAULT 0,
			wc_product_id BIGINT UNSIGNED NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'active',
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			KEY status (status)
		) $charset_collate;";

		$sql[] = "CREATE TABLE {$prefix}booking_package_services (
			package_id BIGINT UNSIGNED NOT NULL,
			service_id BIGINT UNSIGNED NOT NULL,
			credit_cost SMALLINT UNSIGNED NOT NULL DEFAULT 1,
			PRIMARY KEY  (package_id, service_id)
		) $charset_collate;";

		$sql[] = "CREATE TABLE {$prefix}booking_user_credits (
			id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
			wp_user_id BIGINT UNSIGNED NOT NULL,
			package_id BIGINT UNSIGNED NOT NULL,
			total_sessions SMALLINT UNSIGNED NOT NULL,
			remaining_sessions SMALLINT UNSIGNED NOT NULL,
			source VARCHAR(20) NOT NULL DEFAULT 'woocommerce',
			woo_order_id BIGINT UNSIGNED NULL,
			granted_by BIGINT UNSIGNED NULL,
			note VARCHAR(191) NULL,
			created_at DATETIME NOT NULL,
			KEY wp_user_id (wp_user_id),
			KEY package_id (package_id)
		) $charset_collate;";

		$sql[] = "CREATE TABLE {$prefix}booking_payroll_logs (
			id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
			appointment_id        BIGINT UNSIGNED NOT NULL,
			staff_id              BIGINT UNSIGNED NOT NULL,
			total_service_amount  DECIMAL(10,2) NOT NULL,
			commission_type       VARCHAR(20) NOT NULL,
			commission_value      DECIMAL(10,2) NOT NULL,
			commission_earned     DECIMAL(10,2) NOT NULL,
			created_at            DATETIME NOT NULL,
			UNIQUE KEY appointment_id (appointment_id),
			KEY staff_id (staff_id),
			KEY created_at (created_at)
		) $charset_collate;";

		return $sql;
	}

	public static function maybe_create_views() {
		global $wpdb;

		$wpdb->query( "CREATE OR REPLACE VIEW {$wpdb->prefix}booking_payroll_daily_summary AS
			SELECT
				staff_id,
				DATE(created_at)            AS day,
				COUNT(*)                    AS appointments_count,
				SUM(total_service_amount)   AS total_service_amount,
				SUM(commission_earned)      AS total_commission
			FROM {$wpdb->prefix}booking_payroll_logs
			GROUP BY staff_id, DATE(created_at);" );
	}
}
