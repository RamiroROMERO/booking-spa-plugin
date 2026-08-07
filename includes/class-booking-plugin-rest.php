<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Booking_Plugin_Rest {

	public static function register_routes() {
		$controllers = array(
			new Booking_Rest_Categories_Controller(),
			new Booking_Rest_Services_Controller(),
			new Booking_Rest_Staff_Controller(),
		);

		foreach ( $controllers as $controller ) {
			$controller->register_routes();
		}
	}
}
