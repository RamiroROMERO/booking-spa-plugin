<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Booking_Plugin_Client_Panel_Shortcode {

	public function register() {
		add_shortcode( 'booking_client_panel', array( $this, 'render' ) );
	}

	public function maybe_enqueue_assets() {
		if ( ! is_singular() ) {
			return;
		}

		$post = get_post();

		if ( ! $post || ! has_shortcode( $post->post_content, 'booking_client_panel' ) ) {
			return;
		}

		$mode = $this->resolve_mode();

		if ( 'login' === $mode ) {
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

		wp_set_script_translations( 'booking-plugin-frontend', 'booking-plugin', BOOKING_PLUGIN_DIR . 'languages' );

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

		$guest_context = null;

		if ( 'guest' === $mode ) {
			$guest_context = array(
				'appointmentId' => absint( $_GET['appointment'] ),
				'token'         => sanitize_text_field( wp_unslash( $_GET['token'] ) ),
			);
		}

		$settings = Booking_Plugin_Settings::get_settings();

		wp_localize_script(
			'booking-plugin-frontend',
			'BookingPluginFrontend',
			array(
				'apiUrl'               => esc_url_raw( rest_url( 'booking-plugin/v1' ) ),
				'nonce'                => wp_create_nonce( 'wp_rest' ),
				'timezone'             => wp_timezone_string(),
				'currentUser'          => $current_user,
				'mode'                 => $mode,
				'guestContext'         => $guest_context,
				'minCancellationHours' => (int) $settings['min_cancellation_hours'],
			)
		);
	}

	public function render( $atts ) {
		$mode = $this->resolve_mode();

		if ( 'login' === $mode ) {
			// Sin 'redirect' explicito: wp_login_form() ya usa por defecto la
			// URL actual (REQUEST_URI), que es lo que "sin salir de la
			// pagina" pide la spec.
			return sprintf(
				'<div class="booking-plugin-client-panel-login">%s</div>',
				wp_login_form( array( 'echo' => false ) )
			);
		}

		return '<div id="booking-plugin-client-panel-root" class="booking-plugin-client-panel-root"></div>';
	}

	/**
	 * El modo se resuelve por URL, no por atts del shortcode: el enlace de
	 * SPEC 06 que trae el access_token del invitado apunta a esta misma
	 * pagina con ?token=&appointment=, sin que el sitio necesite un shortcode
	 * distinto para ese caso.
	 */
	protected function resolve_mode() {
		if ( ! empty( $_GET['token'] ) && ! empty( $_GET['appointment'] ) ) {
			return 'guest';
		}

		if ( is_user_logged_in() ) {
			return 'member';
		}

		return 'login';
	}
}
