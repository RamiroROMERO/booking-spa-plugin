<?php
/**
 * Plugin Name:       Booking Plugin
 * Plugin URI:        https://machmedia.com/
 * Description:       Booking functionality for WordPress.
 * Version:           0.1.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            MachMedia
 * Author URI:        https://machmedia.com/
 * License:            GPL v2 or later
 * License URI:        https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:        booking-plugin
 * Domain Path:        /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

define( 'BOOKING_PLUGIN_VERSION', '0.1.0' );
define( 'BOOKING_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'BOOKING_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once BOOKING_PLUGIN_DIR . 'includes/class-booking-plugin.php';

function booking_plugin_run() {
	$plugin = new Booking_Plugin();
	$plugin->init();
}
add_action( 'plugins_loaded', 'booking_plugin_run' );
