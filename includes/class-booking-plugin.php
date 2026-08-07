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

		$notifications = new Booking_Plugin_Notifications();
		$notifications->register_hooks();

		$reminder_cron = new Booking_Plugin_Reminder_Cron();
		$reminder_cron->register();

		$admin = new Booking_Plugin_Admin();
		add_action( 'admin_menu', array( $admin, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $admin, 'enqueue_assets' ) );

		$shortcode = new Booking_Plugin_Shortcode();
		$shortcode->register();
		add_action( 'wp_enqueue_scripts', array( $shortcode, 'maybe_enqueue_assets' ) );

		$client_panel_shortcode = new Booking_Plugin_Client_Panel_Shortcode();
		$client_panel_shortcode->register();
		add_action( 'wp_enqueue_scripts', array( $client_panel_shortcode, 'maybe_enqueue_assets' ) );
	}
}
