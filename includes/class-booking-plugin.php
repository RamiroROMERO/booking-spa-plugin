<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Booking_Plugin {

	public function init() {
		load_plugin_textdomain( 'booking-plugin', false, dirname( plugin_basename( __FILE__ ) ) . '/../languages' );

		if ( get_option( 'booking_plugin_db_version' ) !== BOOKING_PLUGIN_DB_VERSION ) {
			Booking_Plugin_Activator::activate();
		}

		add_action( 'rest_api_init', array( 'Booking_Plugin_Rest', 'register_routes' ) );

		$admin = new Booking_Plugin_Admin();
		add_action( 'admin_menu', array( $admin, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $admin, 'enqueue_assets' ) );
	}
}
