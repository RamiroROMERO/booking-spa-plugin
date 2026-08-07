<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Booking_Plugin_Deactivator {

	public static function deactivate() {
		flush_rewrite_rules();
	}
}
