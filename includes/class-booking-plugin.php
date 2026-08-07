<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Booking_Plugin {

	public function init() {
		load_plugin_textdomain( 'booking-plugin', false, dirname( plugin_basename( __FILE__ ) ) . '/../languages' );
	}
}
