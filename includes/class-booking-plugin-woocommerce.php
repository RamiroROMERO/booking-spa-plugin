<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Booking_Plugin_WooCommerce {

	public static function is_active() {
		return class_exists( 'WooCommerce' );
	}

	public static function sync_product_for_service( $service ) {
		if ( ! self::is_active() ) {
			return;
		}

		$should_have_product = ! empty( $service->requires_payment ) && 'active' === $service->status;

		if ( ! $should_have_product ) {
			if ( ! empty( $service->wc_product_id ) ) {
				self::unpublish_product( (int) $service->wc_product_id );
			}

			return;
		}

		$product = ! empty( $service->wc_product_id ) ? wc_get_product( (int) $service->wc_product_id ) : false;

		if ( ! $product ) {
			$product = new WC_Product_Simple();
		}

		// Prefijo "[Reserva]" para que un admin no se confunda si lo ve
		// listado entre sus productos reales de WooCommerce (ver Risks de SPEC 10).
		$product->set_name( sprintf( '[Reserva] %s', $service->name ) );
		$product->set_regular_price( (string) $service->price );
		$product->set_price( (string) $service->price );
		$product->set_catalog_visibility( 'hidden' );
		$product->set_status( 'publish' );
		$product->set_virtual( true );

		$product_id = $product->save();

		if ( (int) $service->wc_product_id !== (int) $product_id ) {
			self::save_wc_product_id( (int) $service->id, (int) $product_id );
		}
	}

	public static function sync_product_for_package( $package ) {
		if ( ! self::is_active() ) {
			return;
		}

		$should_have_product = 'active' === $package->status;

		if ( ! $should_have_product ) {
			if ( ! empty( $package->wc_product_id ) ) {
				self::unpublish_product( (int) $package->wc_product_id );
			}

			return;
		}

		$product = ! empty( $package->wc_product_id ) ? wc_get_product( (int) $package->wc_product_id ) : false;

		if ( ! $product ) {
			$product = new WC_Product_Simple();
		}

		// Mismo prefijo "[Reserva]" que los productos de servicios (ver SPEC 10).
		$product->set_name( sprintf( '[Reserva] %s', $package->name ) );
		$product->set_regular_price( (string) $package->price );
		$product->set_price( (string) $package->price );
		$product->set_catalog_visibility( 'hidden' );
		$product->set_status( 'publish' );
		$product->set_virtual( true );

		$product_id = $product->save();

		if ( (int) $package->wc_product_id !== (int) $product_id ) {
			self::save_wc_product_id_for_package( (int) $package->id, (int) $product_id );
		}
	}

	protected static function unpublish_product( $product_id ) {
		$product = wc_get_product( $product_id );

		if ( $product && 'draft' !== $product->get_status() ) {
			$product->set_status( 'draft' );
			$product->save();
		}
	}

	protected static function save_wc_product_id( $service_id, $product_id ) {
		global $wpdb;

		$wpdb->update(
			$wpdb->prefix . 'booking_services',
			array( 'wc_product_id' => $product_id ),
			array( 'id' => $service_id ),
			array( '%d' ),
			array( '%d' )
		);
	}

	protected static function save_wc_product_id_for_package( $package_id, $product_id ) {
		global $wpdb;

		$wpdb->update(
			$wpdb->prefix . 'booking_packages',
			array( 'wc_product_id' => $product_id ),
			array( 'id' => $package_id ),
			array( '%d' ),
			array( '%d' )
		);
	}

	public static function create_order_for_appointment( $appointment ) {
		if ( ! self::is_active() ) {
			return null;
		}

		global $wpdb;

		$services_table = $wpdb->prefix . 'booking_services';
		$service        = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$services_table} WHERE id = %d", (int) $appointment->service_id )
		);

		if ( ! $service || empty( $service->wc_product_id ) ) {
			return null;
		}

		$product = wc_get_product( (int) $service->wc_product_id );

		if ( ! $product ) {
			return null;
		}

		$addons = self::get_appointment_addons( (int) $appointment->id );

		$addons_total = 0.0;

		foreach ( $addons as $addon ) {
			$addons_total += (float) $addon->price;
		}

		// Una unica linea con precio = servicio + suma de add-ons (ver SPEC 11);
		// no se crea una linea por add-on porque eso requeriria un producto WC
		// propio por cada uno, sin beneficio confirmado.
		$total = (float) $service->price + $addons_total;

		$deposit_amount = null;
		$balance_due    = null;
		$order_amount   = $total;

		// Si el servicio requiere deposito, el pedido de WooCommerce se genera
		// solo por ese monto -- el saldo restante lo cobra el admin manualmente
		// (ver SPEC 15). deposit_amount/balance_due son snapshot: se calculan
		// una unica vez aca y el controlador de citas los persiste tal cual.
		if ( ! empty( $service->requires_deposit ) ) {
			$deposit_amount = round( $total * ( (float) $service->deposit_percentage / 100 ), 2 );
			$balance_due    = round( $total - $deposit_amount, 2 );
			$order_amount   = $deposit_amount;
		}

		// "pending" (no "on-hold"): es el unico estado inicial que WooCommerce
		// considera pagable (needs_payment() solo es true para pending/failed/
		// checkout-draft). Crear directo en on-hold deja el pedido huerfano
		// -- la pagina de pago nunca ofrece pasarelas. Tras el pago real, cada
		// pasarela hace su propia transicion estandar de WooCommerce
		// (automaticas -> processing/completed; manuales como cheque/
		// transferencia -> on-hold, que es como se llega al "on-hold" que
		// originalmente pedia la spec, solo que despues del pago en vez de antes).
		$order = wc_create_order( array( 'status' => 'pending' ) );

		$item_id = $order->add_product(
			$product,
			1,
			array(
				'subtotal' => $order_amount,
				'total'    => $order_amount,
			)
		);

		self::apply_addon_metadata( $order, $item_id, $addons );

		list( $email, $name ) = self::resolve_customer( $appointment );

		if ( $appointment->user_id ) {
			$order->set_customer_id( (int) $appointment->user_id );
		}

		if ( $email ) {
			$order->set_billing_email( $email );
		}

		if ( $name ) {
			$order->set_billing_first_name( $name );
		}

		$order->calculate_totals();
		$order->save();

		$wpdb->update(
			$wpdb->prefix . 'booking_appointments',
			array( 'wc_order_id' => $order->get_id() ),
			array( 'id' => (int) $appointment->id ),
			array( '%d' ),
			array( '%d' )
		);

		return array(
			'checkout_url'   => $order->get_checkout_payment_url(),
			'deposit_amount' => $deposit_amount,
			'balance_due'    => $balance_due,
		);
	}

	protected static function get_appointment_addons( $appointment_id ) {
		global $wpdb;

		$table = $wpdb->prefix . 'booking_appointment_addons';

		return $wpdb->get_results( $wpdb->prepare( "SELECT name, price FROM {$table} WHERE appointment_id = %d ORDER BY id ASC", $appointment_id ) );
	}

	// Un meta por add-on (clave = nombre, valor = precio formateado) para que
	// salga en el recibo del pedido -- ver SPEC 11.
	protected static function apply_addon_metadata( $order, $item_id, array $addons ) {
		if ( ! $item_id || empty( $addons ) ) {
			return;
		}

		$item = $order->get_item( $item_id );

		if ( ! $item ) {
			return;
		}

		foreach ( $addons as $addon ) {
			$item->add_meta_data( $addon->name, get_woocommerce_currency_symbol() . number_format( (float) $addon->price, 2 ), true );
		}

		$item->save();
	}

	protected static function resolve_customer( $appointment ) {
		if ( $appointment->user_id ) {
			$user = get_userdata( (int) $appointment->user_id );

			if ( $user ) {
				return array( $user->user_email, $user->display_name );
			}
		}

		return array( $appointment->guest_email, $appointment->guest_name );
	}

	public function register_hooks() {
		if ( ! self::is_active() ) {
			return;
		}

		add_action( 'woocommerce_order_status_changed', array( $this, 'handle_order_status_changed' ), 10, 4 );
		add_action( 'woocommerce_order_status_completed', array( $this, 'handle_order_completed_for_packages' ), 10, 2 );
		add_action( 'booking_plugin_appointment_cancelled', array( $this, 'handle_appointment_cancelled_for_refund' ) );
	}

	// Otorga créditos al completar un pedido que incluye un producto de
	// paquete (SPEC 12). Se usa "completed" (no "processing"/is_paid())
	// porque un paquete no reserva ningún horario -- no hay motivo para
	// adelantar el crédito antes de que el pedido se de por finalizado.
	public function handle_order_completed_for_packages( $order_id, $order ) {
		if ( ! $order ) {
			return;
		}

		$customer_id = (int) $order->get_customer_id();

		if ( ! $customer_id ) {
			return;
		}

		global $wpdb;

		$packages_table = $wpdb->prefix . 'booking_packages';
		$credits_table  = $wpdb->prefix . 'booking_user_credits';

		foreach ( $order->get_items() as $item ) {
			$product_id = $item->get_product_id();
			$package    = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$packages_table} WHERE wc_product_id = %d", $product_id ) );

			if ( ! $package ) {
				continue;
			}

			$already_credited = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$credits_table} WHERE woo_order_id = %d AND package_id = %d",
					$order_id,
					$package->id
				)
			);

			if ( $already_credited ) {
				continue;
			}

			$wpdb->insert(
				$credits_table,
				array(
					'wp_user_id'         => $customer_id,
					'package_id'         => (int) $package->id,
					'total_sessions'     => (int) $package->total_sessions,
					'remaining_sessions' => (int) $package->total_sessions,
					'source'             => 'woocommerce',
					'woo_order_id'       => $order_id,
					'granted_by'         => null,
					'note'               => null,
					'created_at'         => current_time( 'mysql', true ),
				),
				array( '%d', '%d', '%d', '%d', '%s', '%d', '%d', '%s', '%s' )
			);
		}
	}

	public function handle_order_status_changed( $order_id, $old_status, $new_status, $order ) {
		$appointment = $this->get_appointment_for_order( (int) $order_id );

		if ( ! $appointment ) {
			return;
		}

		if ( $order->is_paid() ) {
			$this->transition_appointment( $appointment, 'confirmed' );

			return;
		}

		if ( in_array( $new_status, array( 'cancelled', 'refunded' ), true ) ) {
			$this->transition_appointment( $appointment, 'cancelled' );
		}
	}

	public function handle_appointment_cancelled_for_refund( $appointment ) {
		$settings = Booking_Plugin_Settings::get_settings();
		if ( empty( $settings['auto_refund_enabled'] ) || ! self::is_active() ) {
			return;
		}

		if ( empty( $appointment->wc_order_id ) ) {
			return;
		}

		$order = wc_get_order( (int) $appointment->wc_order_id );
		if ( ! $order || ! $order->is_paid() ) {
			return;
		}

		$remaining = $order->get_remaining_refund_amount();
		if ( $remaining <= 0 ) {
			return;
		}

		$result = wc_create_refund(
			array(
				'order_id'       => $order->get_id(),
				'amount'         => $remaining,
				'reason'         => sprintf( 'Cita cancelada #%d', (int) $appointment->id ),
				'refund_payment' => true,
			)
		);

		if ( is_wp_error( $result ) ) {
			error_log( $result->get_error_message() );
		}
	}

	protected function get_appointment_for_order( $order_id ) {
		global $wpdb;

		$table = $wpdb->prefix . 'booking_appointments';

		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE wc_order_id = %d", $order_id ) );
	}

	// No transiciona citas que ya llegaron a un estado terminal/manual
	// (cancelled/blocked/completed/no_show) -- evita que un webhook de WC
	// fuera de orden reabra o pise una decision ya tomada por el admin.
	protected function transition_appointment( $appointment, $new_status ) {
		if ( $appointment->status === $new_status ) {
			return;
		}

		if ( in_array( $appointment->status, array( 'cancelled', 'blocked', 'completed', 'no_show' ), true ) ) {
			return;
		}

		global $wpdb;

		$table = $wpdb->prefix . 'booking_appointments';

		$wpdb->update(
			$table,
			array(
				'status'     => $new_status,
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'id' => (int) $appointment->id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		if ( 'cancelled' === $new_status ) {
			$updated = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $appointment->id ) );

			do_action( 'booking_plugin_appointment_cancelled', $updated );
		}
	}
}
