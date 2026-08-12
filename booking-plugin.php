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
define( 'BOOKING_PLUGIN_DB_VERSION', '1.9.0' );

require_once BOOKING_PLUGIN_DIR . 'includes/class-booking-plugin.php';
require_once BOOKING_PLUGIN_DIR . 'includes/class-booking-plugin-db-schema.php';
require_once BOOKING_PLUGIN_DIR . 'includes/emails/class-booking-plugin-email-templates.php';
require_once BOOKING_PLUGIN_DIR . 'includes/class-booking-plugin-activator.php';
require_once BOOKING_PLUGIN_DIR . 'includes/class-booking-plugin-deactivator.php';
require_once BOOKING_PLUGIN_DIR . 'includes/rest/class-booking-rest-categories-controller.php';
require_once BOOKING_PLUGIN_DIR . 'includes/rest/class-booking-rest-services-controller.php';
require_once BOOKING_PLUGIN_DIR . 'includes/rest/class-booking-rest-addons-controller.php';
require_once BOOKING_PLUGIN_DIR . 'includes/rest/class-booking-rest-staff-controller.php';
require_once BOOKING_PLUGIN_DIR . 'includes/class-booking-plugin-settings.php';
require_once BOOKING_PLUGIN_DIR . 'includes/class-booking-plugin-woocommerce.php';
require_once BOOKING_PLUGIN_DIR . 'includes/class-booking-plugin-payroll.php';
require_once BOOKING_PLUGIN_DIR . 'includes/class-booking-plugin-availability.php';
require_once BOOKING_PLUGIN_DIR . 'includes/rest/class-booking-rest-settings-controller.php';
require_once BOOKING_PLUGIN_DIR . 'includes/rest/class-booking-rest-business-hours-controller.php';
require_once BOOKING_PLUGIN_DIR . 'includes/rest/class-booking-rest-availability-controller.php';
require_once BOOKING_PLUGIN_DIR . 'includes/rest/class-booking-rest-appointments-controller.php';
require_once BOOKING_PLUGIN_DIR . 'includes/rest/class-booking-rest-email-templates-controller.php';
require_once BOOKING_PLUGIN_DIR . 'includes/rest/class-booking-rest-packages-controller.php';
require_once BOOKING_PLUGIN_DIR . 'includes/rest/class-booking-rest-user-credits-controller.php';
require_once BOOKING_PLUGIN_DIR . 'includes/rest/class-booking-rest-payroll-controller.php';
require_once BOOKING_PLUGIN_DIR . 'includes/class-booking-plugin-rest.php';
require_once BOOKING_PLUGIN_DIR . 'includes/class-booking-plugin-notifications.php';
require_once BOOKING_PLUGIN_DIR . 'includes/class-booking-plugin-reminder-cron.php';
require_once BOOKING_PLUGIN_DIR . 'includes/class-booking-plugin-payment-sweep-cron.php';

add_filter( 'cron_schedules', array( 'Booking_Plugin_Reminder_Cron', 'register_schedule' ) );
add_filter( 'cron_schedules', array( 'Booking_Plugin_Payment_Sweep_Cron', 'register_schedule' ) );
require_once BOOKING_PLUGIN_DIR . 'includes/class-booking-plugin-admin.php';
require_once BOOKING_PLUGIN_DIR . 'includes/class-booking-plugin-shortcode.php';
require_once BOOKING_PLUGIN_DIR . 'includes/class-booking-plugin-client-panel-shortcode.php';

register_activation_hook( __FILE__, array( 'Booking_Plugin_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Booking_Plugin_Deactivator', 'deactivate' ) );

function booking_plugin_run() {
	$plugin = new Booking_Plugin();
	$plugin->init();
}
add_action( 'plugins_loaded', 'booking_plugin_run' );
