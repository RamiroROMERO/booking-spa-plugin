<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Booking_Plugin_Notifications {

	const CLIENT_PANEL_PAGE_OPTION = 'booking_plugin_client_panel_page_id';

	public function register_hooks() {
		add_action( 'booking_plugin_appointment_created', array( $this, 'on_appointment_created' ) );
		add_action( 'booking_plugin_appointment_cancelled', array( $this, 'on_appointment_cancelled' ) );
	}

	public function on_appointment_created( $appointment ) {
		if ( 'blocked' === $appointment->status ) {
			return;
		}

		$this->send_client_confirmation( $appointment );
		$this->send_admin_new_booking( $appointment );
	}

	public function on_appointment_cancelled( $appointment ) {
		if ( 'blocked' === $appointment->status ) {
			return;
		}

		$this->send_client_cancellation( $appointment );
		$this->send_admin_cancellation( $appointment );
	}

	public function send_client_confirmation( $appointment ) {
		$context = $this->build_context( $appointment );

		return $this->send( $context['client_email'], 'client_confirmation', $context, (int) $appointment->id );
	}

	public function send_client_reminder( $appointment ) {
		$context = $this->build_context( $appointment );

		return $this->send( $context['client_email'], 'client_reminder', $context, (int) $appointment->id );
	}

	public function send_client_cancellation( $appointment ) {
		$context = $this->build_context( $appointment );

		return $this->send( $context['client_email'], 'client_cancellation', $context, (int) $appointment->id );
	}

	public function send_admin_new_booking( $appointment ) {
		$context  = $this->build_context( $appointment );
		$settings = Booking_Plugin_Settings::get_settings();

		return $this->send( $settings['notification_email'], 'admin_new_booking', $context, (int) $appointment->id );
	}

	public function send_admin_cancellation( $appointment ) {
		$context  = $this->build_context( $appointment );
		$settings = Booking_Plugin_Settings::get_settings();

		return $this->send( $settings['notification_email'], 'admin_cancellation', $context, (int) $appointment->id );
	}

	public function get_html_content_type() {
		return 'text/html';
	}

	protected function build_context( $appointment ) {
		global $wpdb;

		$client_name  = '';
		$client_email = '';
		$client_phone = '';

		if ( $appointment->user_id ) {
			$user = get_userdata( (int) $appointment->user_id );

			if ( $user ) {
				$client_name  = $user->display_name;
				$client_email = $user->user_email;
			}
		} else {
			$client_name  = (string) $appointment->guest_name;
			$client_email = (string) $appointment->guest_email;
			$client_phone = (string) $appointment->guest_phone;
		}

		$service_name = '';

		if ( $appointment->service_id ) {
			$services_table = $wpdb->prefix . 'booking_services';
			$service_name   = (string) $wpdb->get_var(
				$wpdb->prepare( "SELECT name FROM {$services_table} WHERE id = %d", (int) $appointment->service_id )
			);
		}

		$staff_name = '';

		if ( $appointment->staff_id ) {
			$staff_table = $wpdb->prefix . 'booking_staff';
			$staff_name  = (string) $wpdb->get_var(
				$wpdb->prepare( "SELECT name FROM {$staff_table} WHERE id = %d", (int) $appointment->staff_id )
			);
		}

		$start_utc = new DateTimeImmutable( $appointment->start_datetime, new DateTimeZone( 'UTC' ) );

		return array(
			'client_name'   => $client_name,
			'client_email'  => $client_email,
			'client_phone'  => $client_phone,
			'service_name'  => $service_name,
			'staff_name'    => $staff_name,
			'date'          => wp_date( get_option( 'date_format' ), $start_utc->getTimestamp() ),
			'time'          => wp_date( get_option( 'time_format' ), $start_utc->getTimestamp() ),
			'business_name' => get_bloginfo( 'name' ),
			'manage_url'    => $this->get_manage_url( $appointment ),
		);
	}

	protected function get_manage_url( $appointment ) {
		$page_id  = $this->resolve_client_panel_page_id();
		$base_url = $page_id ? get_permalink( $page_id ) : false;

		if ( ! $base_url ) {
			$base_url = home_url( '/' );
		}

		$url = add_query_arg(
			array(
				'appointment' => (int) $appointment->id,
				'token'       => $appointment->access_token,
			),
			$base_url
		);

		return apply_filters( 'booking_plugin_manage_url', $url, $appointment );
	}

	protected function resolve_client_panel_page_id() {
		$cached_id = (int) get_option( self::CLIENT_PANEL_PAGE_OPTION );

		if ( $cached_id ) {
			$page = get_post( $cached_id );

			if ( $page && 'publish' === $page->post_status && has_shortcode( $page->post_content, 'booking_client_panel' ) ) {
				return $cached_id;
			}
		}

		$pages = get_pages( array( 'post_status' => 'publish' ) );

		foreach ( $pages as $page ) {
			if ( has_shortcode( $page->post_content, 'booking_client_panel' ) ) {
				update_option( self::CLIENT_PANEL_PAGE_OPTION, $page->ID );

				return (int) $page->ID;
			}
		}

		return 0;
	}

	protected function render( $key, array $context ) {
		$template = Booking_Plugin_Email_Templates::get_template( $key );

		if ( ! $template ) {
			return null;
		}

		$replacements = array();

		foreach ( $context as $placeholder => $value ) {
			$replacements[ '{{' . $placeholder . '}}' ] = $value;
		}

		return array(
			'subject' => strtr( $template['subject'], $replacements ),
			'body'    => wpautop( strtr( $template['body'], $replacements ) ),
		);
	}

	protected function send( $to, $key, array $context, $appointment_id ) {
		if ( empty( $to ) || ! is_email( $to ) ) {
			error_log( sprintf( '[booking-plugin] No se pudo enviar el correo "%s" para la cita #%d: destinatario inválido.', $key, $appointment_id ) );

			return false;
		}

		$rendered = $this->render( $key, $context );

		if ( ! $rendered ) {
			return false;
		}

		add_filter( 'wp_mail_content_type', array( $this, 'get_html_content_type' ) );

		$sent = wp_mail( $to, $rendered['subject'], $rendered['body'] );

		remove_filter( 'wp_mail_content_type', array( $this, 'get_html_content_type' ) );

		if ( ! $sent ) {
			error_log( sprintf( '[booking-plugin] wp_mail() falló al enviar "%s" a %s para la cita #%d.', $key, $to, $appointment_id ) );
		}

		return $sent;
	}
}
