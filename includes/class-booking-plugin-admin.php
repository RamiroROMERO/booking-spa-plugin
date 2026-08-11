<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Booking_Plugin_Admin {

	const SLUG_CALENDAR      = 'booking-plugin';
	const SLUG_SERVICES      = 'booking-plugin-services';
	const SLUG_STAFF         = 'booking-plugin-staff';
	const SLUG_SETTINGS      = 'booking-plugin-settings';
	const SLUG_NOTIFICATIONS = 'booking-plugin-notifications';
	const SLUG_PACKAGES      = 'booking-plugin-packages';
	const SLUG_PAYROLL       = 'booking-plugin-payroll';

	protected $hook_suffixes = array();

	public function register_menu() {
		add_menu_page(
			__( 'Reservas', 'booking-plugin' ),
			__( 'Reservas', 'booking-plugin' ),
			'manage_options',
			self::SLUG_CALENDAR,
			array( $this, 'render_page' ),
			'dashicons-calendar-alt'
		);

		$this->hook_suffixes[ self::SLUG_CALENDAR ] = add_submenu_page(
			self::SLUG_CALENDAR,
			__( 'Calendario', 'booking-plugin' ),
			__( 'Calendario', 'booking-plugin' ),
			'manage_options',
			self::SLUG_CALENDAR,
			array( $this, 'render_page' )
		);

		$this->hook_suffixes[ self::SLUG_SERVICES ] = add_submenu_page(
			self::SLUG_CALENDAR,
			__( 'Servicios', 'booking-plugin' ),
			__( 'Servicios', 'booking-plugin' ),
			'manage_options',
			self::SLUG_SERVICES,
			array( $this, 'render_page' )
		);

		$this->hook_suffixes[ self::SLUG_STAFF ] = add_submenu_page(
			self::SLUG_CALENDAR,
			__( 'Staff', 'booking-plugin' ),
			__( 'Staff', 'booking-plugin' ),
			'manage_options',
			self::SLUG_STAFF,
			array( $this, 'render_page' )
		);

		$this->hook_suffixes[ self::SLUG_SETTINGS ] = add_submenu_page(
			self::SLUG_CALENDAR,
			__( 'Configuración', 'booking-plugin' ),
			__( 'Configuración', 'booking-plugin' ),
			'manage_options',
			self::SLUG_SETTINGS,
			array( $this, 'render_page' )
		);

		$this->hook_suffixes[ self::SLUG_NOTIFICATIONS ] = add_submenu_page(
			self::SLUG_CALENDAR,
			__( 'Notificaciones', 'booking-plugin' ),
			__( 'Notificaciones', 'booking-plugin' ),
			'manage_options',
			self::SLUG_NOTIFICATIONS,
			array( $this, 'render_page' )
		);

		$this->hook_suffixes[ self::SLUG_PACKAGES ] = add_submenu_page(
			self::SLUG_CALENDAR,
			__( 'Paquetes', 'booking-plugin' ),
			__( 'Paquetes', 'booking-plugin' ),
			'manage_options',
			self::SLUG_PACKAGES,
			array( $this, 'render_page' )
		);

		$this->hook_suffixes[ self::SLUG_PAYROLL ] = add_submenu_page(
			self::SLUG_CALENDAR,
			__( 'Nómina', 'booking-plugin' ),
			__( 'Nómina', 'booking-plugin' ),
			'manage_options',
			self::SLUG_PAYROLL,
			array( $this, 'render_page' )
		);
	}

	public function render_page() {
		echo '<div id="booking-plugin-admin-root"></div>';
	}

	public function enqueue_assets( $hook ) {
		$slug = array_search( $hook, $this->hook_suffixes, true );

		if ( false === $slug ) {
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

		$sections = array(
			self::SLUG_CALENDAR      => 'calendar',
			self::SLUG_SERVICES      => 'services',
			self::SLUG_STAFF         => 'staff',
			self::SLUG_SETTINGS      => 'settings',
			self::SLUG_NOTIFICATIONS => 'notifications',
			self::SLUG_PACKAGES      => 'packages',
			self::SLUG_PAYROLL       => 'payroll',
		);

		wp_localize_script(
			'booking-plugin-admin',
			'BookingPluginAdmin',
			array(
				'nonce'    => wp_create_nonce( 'wp_rest' ),
				'apiUrl'   => esc_url_raw( rest_url( 'booking-plugin/v1' ) ),
				'timezone' => wp_timezone_string(),
				'section'  => $sections[ $slug ],
			)
		);
	}
}
