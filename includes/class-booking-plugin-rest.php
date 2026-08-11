<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Booking_Plugin_Rest {

	public static function register_routes() {
		$controllers = array(
			new Booking_Rest_Categories_Controller(),
			new Booking_Rest_Services_Controller(),
			new Booking_Rest_Addons_Controller(),
			new Booking_Rest_Staff_Controller(),
			new Booking_Rest_Settings_Controller(),
			new Booking_Rest_Business_Hours_Controller(),
			new Booking_Rest_Availability_Controller(),
			new Booking_Rest_Appointments_Controller(),
			new Booking_Rest_Email_Templates_Controller(),
			new Booking_Rest_Packages_Controller(),
			new Booking_Rest_User_Credits_Controller(),
			new Booking_Rest_Payroll_Controller(),
		);

		foreach ( $controllers as $controller ) {
			$controller->register_routes();
		}
	}
}
