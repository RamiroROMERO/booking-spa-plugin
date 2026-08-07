<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Booking_Plugin_Shortcode {

	protected $instance_count = 0;

	public function register() {
		add_shortcode( 'booking_widget', array( $this, 'render' ) );
	}

	public function maybe_enqueue_assets() {
		if ( ! is_singular() ) {
			return;
		}

		$post = get_post();

		if ( ! $post || ! has_shortcode( $post->post_content, 'booking_widget' ) ) {
			return;
		}

		$asset_file = BOOKING_PLUGIN_DIR . 'assets/build/frontend.asset.php';

		if ( ! file_exists( $asset_file ) ) {
			return;
		}

		$asset = require $asset_file;

		wp_enqueue_script(
			'booking-plugin-frontend',
			BOOKING_PLUGIN_URL . 'assets/build/frontend.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		$style_path = BOOKING_PLUGIN_DIR . 'assets/build/style-frontend.css';

		if ( file_exists( $style_path ) ) {
			wp_enqueue_style(
				'booking-plugin-frontend',
				BOOKING_PLUGIN_URL . 'assets/build/style-frontend.css',
				array(),
				$asset['version']
			);
			wp_style_add_data( 'booking-plugin-frontend', 'rtl', 'replace' );
		}

		$current_user = null;

		if ( is_user_logged_in() ) {
			$user = wp_get_current_user();

			$current_user = array(
				'name'  => $user->display_name,
				'email' => $user->user_email,
			);
		}

		wp_localize_script(
			'booking-plugin-frontend',
			'BookingPluginFrontend',
			array(
				'apiUrl'      => esc_url_raw( rest_url( 'booking-plugin/v1' ) ),
				'nonce'       => wp_create_nonce( 'wp_rest' ),
				'timezone'    => wp_timezone_string(),
				'currentUser' => $current_user,
			)
		);
	}

	public function render( $atts ) {
		$this->instance_count++;

		$id = 'booking-plugin-widget-' . $this->instance_count;

		return sprintf(
			'<div id="%s" class="booking-plugin-widget-root"></div>',
			esc_attr( $id )
		);
	}
}
