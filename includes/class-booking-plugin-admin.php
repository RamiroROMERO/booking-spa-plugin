<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Booking_Plugin_Admin {

	protected $hook_suffix;

	public function register_menu() {
		$this->hook_suffix = add_menu_page(
			__( 'Reservas', 'booking-plugin' ),
			__( 'Reservas', 'booking-plugin' ),
			'manage_options',
			'booking-plugin',
			array( $this, 'render_page' ),
			'dashicons-calendar-alt'
		);
	}

	public function render_page() {
		echo '<div id="booking-plugin-admin-root"></div>';
	}

	public function enqueue_assets( $hook ) {
		if ( $hook !== $this->hook_suffix ) {
			return;
		}

		$asset_file = BOOKING_PLUGIN_DIR . 'assets/build/admin.asset.php';

		if ( ! file_exists( $asset_file ) ) {
			return;
		}

		$asset = require $asset_file;

		wp_enqueue_script(
			'booking-plugin-admin',
			BOOKING_PLUGIN_URL . 'assets/build/admin.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		$style_path = BOOKING_PLUGIN_DIR . 'assets/build/style-admin.css';

		if ( file_exists( $style_path ) ) {
			wp_enqueue_style(
				'booking-plugin-admin',
				BOOKING_PLUGIN_URL . 'assets/build/style-admin.css',
				array( 'wp-components' ),
				$asset['version']
			);
			wp_style_add_data( 'booking-plugin-admin', 'rtl', 'replace' );
		}

		wp_localize_script(
			'booking-plugin-admin',
			'BookingPluginAdmin',
			array(
				'nonce'    => wp_create_nonce( 'wp_rest' ),
				'apiUrl'   => esc_url_raw( rest_url( 'booking-plugin/v1' ) ),
				'timezone' => wp_timezone_string(),
			)
		);
	}
}
