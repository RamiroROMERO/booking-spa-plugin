<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/trait-booking-rest-slug.php';

class Booking_Rest_Services_Controller {

	use Booking_Rest_Slug_Trait;

	protected $namespace = 'booking-plugin/v1';
	protected $rest_base = 'services';

	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => '__return_true',
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => array( $this, 'write_permissions_check' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => '__return_true',
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => array( $this, 'write_permissions_check' ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_item' ),
					'permission_callback' => array( $this, 'write_permissions_check' ),
				),
			)
		);
	}

	public function write_permissions_check( $request ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'booking_rest_forbidden', __( 'You are not allowed to perform this action.', 'booking-plugin' ), array( 'status' => 403 ) );
		}

		return true;
	}

	public function get_items( $request ) {
		global $wpdb;

		$table = $wpdb->prefix . 'booking_services';

		$page     = max( 1, (int) $request->get_param( 'page' ) ?: 1 );
		$per_page = (int) $request->get_param( 'per_page' ) ?: 10;
		$per_page = min( max( $per_page, 1 ), 100 );
		$offset   = ( $page - 1 ) * $per_page;

		$where  = array();
		$args   = array();

		$can_manage = current_user_can( 'manage_options' );
		$status     = $request->get_param( 'status' );

		if ( $can_manage && in_array( $status, array( 'active', 'inactive' ), true ) ) {
			$where[] = 'status = %s';
			$args[]  = $status;
		} else {
			$where[] = "status = 'active'";
		}

		$category_id = $request->get_param( 'category_id' );

		if ( null !== $category_id && '' !== $category_id ) {
			$where[] = 'category_id = %d';
			$args[]  = (int) $category_id;
		}

		$where_sql = implode( ' AND ', $where );

		$total = (int) $wpdb->get_var(
			$args ? $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}", $args ) : "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}"
		);

		$query_args   = $args;
		$query_args[] = $per_page;
		$query_args[] = $offset;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE {$where_sql} ORDER BY id ASC LIMIT %d OFFSET %d",
				$query_args
			)
		);

		$items = array_map( array( $this, 'prepare_item' ), $rows );

		$response = rest_ensure_response( $items );
		$response->header( 'X-WP-Total', $total );
		$response->header( 'X-WP-TotalPages', (int) ceil( $total / $per_page ) );

		return $response;
	}

	public function get_item( $request ) {
		$row = $this->get_row( (int) $request['id'] );

		if ( ! $row ) {
			return new WP_Error( 'booking_rest_not_found', __( 'Service not found.', 'booking-plugin' ), array( 'status' => 404 ) );
		}

		return rest_ensure_response( $this->prepare_item( $row ) );
	}

	public function create_item( $request ) {
		global $wpdb;

		$name = trim( (string) $request->get_param( 'name' ) );

		if ( '' === $name ) {
			return new WP_Error( 'booking_rest_invalid_name', __( 'Name is required.', 'booking-plugin' ), array( 'status' => 400 ) );
		}

		$validation_error = $this->validate_numeric_fields( $request, true );

		if ( is_wp_error( $validation_error ) ) {
			return $validation_error;
		}

		$deposit_validation_error = $this->validate_deposit_fields( $request, null );

		if ( is_wp_error( $deposit_validation_error ) ) {
			return $deposit_validation_error;
		}

		$category_id = $request->get_param( 'category_id' );

		if ( ! empty( $category_id ) && ! $this->category_exists( (int) $category_id ) ) {
			return new WP_Error( 'booking_rest_invalid_category', __( 'The given category_id does not exist.', 'booking-plugin' ), array( 'status' => 400 ) );
		}

		$table = $wpdb->prefix . 'booking_services';
		$slug  = $this->generate_unique_slug( $table, $name );
		$now   = current_time( 'mysql', true );

		$wpdb->insert(
			$table,
			array(
				'category_id'      => empty( $category_id ) ? null : (int) $category_id,
				'name'             => $name,
				'slug'             => $slug,
				'description'      => $request->get_param( 'description' ),
				'price'            => (float) $request->get_param( 'price' ),
				'duration_minutes' => (int) $request->get_param( 'duration_minutes' ),
				'buffer_minutes'   => null !== $request->get_param( 'buffer_minutes' ) ? (int) $request->get_param( 'buffer_minutes' ) : 0,
				'status'           => 'active',
				'requires_payment' => $request->get_param( 'requires_payment' ) ? 1 : 0,
				'requires_deposit'   => $request->get_param( 'requires_deposit' ) ? 1 : 0,
				'deposit_percentage' => $request->get_param( 'requires_deposit' ) ? (float) $request->get_param( 'deposit_percentage' ) : null,
				'image_id'         => empty( $request->get_param( 'image_id' ) ) ? null : (int) $request->get_param( 'image_id' ),
				'created_at'       => $now,
				'updated_at'       => $now,
			),
			array( '%d', '%s', '%s', '%s', '%f', '%d', '%d', '%s', '%d', '%d', '%f', '%d', '%s', '%s' )
		);

		$service_id = (int) $wpdb->insert_id;

		$row = $this->get_row( $service_id );

		Booking_Plugin_WooCommerce::sync_product_for_service( $row );

		// No reutilizar $wpdb->insert_id aca: sync_product_for_service() hace
		// sus propios INSERT (WooCommerce guardando el producto), que pisan
		// ese valor global -- se reusa el $service_id ya capturado arriba.
		$row = $this->get_row( $service_id );

		$response = rest_ensure_response( $this->prepare_item( $row ) );
		$response->set_status( 201 );

		return $response;
	}

	public function update_item( $request ) {
		global $wpdb;

		$id  = (int) $request['id'];
		$row = $this->get_row( $id );

		if ( ! $row ) {
			return new WP_Error( 'booking_rest_not_found', __( 'Service not found.', 'booking-plugin' ), array( 'status' => 404 ) );
		}

		$validation_error = $this->validate_numeric_fields( $request, false );

		if ( is_wp_error( $validation_error ) ) {
			return $validation_error;
		}

		$deposit_validation_error = $this->validate_deposit_fields( $request, $row );

		if ( is_wp_error( $deposit_validation_error ) ) {
			return $deposit_validation_error;
		}

		$table   = $wpdb->prefix . 'booking_services';
		$data    = array();
		$formats = array();

		if ( null !== $request->get_param( 'name' ) ) {
			$name = trim( (string) $request->get_param( 'name' ) );

			if ( '' === $name ) {
				return new WP_Error( 'booking_rest_invalid_name', __( 'Name is required.', 'booking-plugin' ), array( 'status' => 400 ) );
			}

			$data['name'] = $name;
			$formats[]    = '%s';
			$data['slug'] = $this->generate_unique_slug( $table, $name, $id );
			$formats[]    = '%s';
		}

		if ( $request->has_param( 'category_id' ) ) {
			$category_id = $request->get_param( 'category_id' );

			if ( ! empty( $category_id ) && ! $this->category_exists( (int) $category_id ) ) {
				return new WP_Error( 'booking_rest_invalid_category', __( 'The given category_id does not exist.', 'booking-plugin' ), array( 'status' => 400 ) );
			}

			$data['category_id'] = empty( $category_id ) ? null : (int) $category_id;
			$formats[]            = '%d';
		}

		if ( null !== $request->get_param( 'description' ) ) {
			$data['description'] = $request->get_param( 'description' );
			$formats[]            = '%s';
		}

		if ( null !== $request->get_param( 'price' ) ) {
			$data['price'] = (float) $request->get_param( 'price' );
			$formats[]     = '%f';
		}

		if ( null !== $request->get_param( 'duration_minutes' ) ) {
			$data['duration_minutes'] = (int) $request->get_param( 'duration_minutes' );
			$formats[]                 = '%d';
		}

		if ( null !== $request->get_param( 'buffer_minutes' ) ) {
			$data['buffer_minutes'] = (int) $request->get_param( 'buffer_minutes' );
			$formats[]               = '%d';
		}

		if ( null !== $request->get_param( 'status' ) ) {
			$status = $request->get_param( 'status' );

			if ( ! in_array( $status, array( 'active', 'inactive' ), true ) ) {
				return new WP_Error( 'booking_rest_invalid_status', __( 'status must be either active or inactive.', 'booking-plugin' ), array( 'status' => 400 ) );
			}

			$data['status'] = $status;
			$formats[]       = '%s';
		}

		if ( $request->has_param( 'requires_payment' ) ) {
			$data['requires_payment'] = $request->get_param( 'requires_payment' ) ? 1 : 0;
			$formats[]                 = '%d';
		}

		if ( $request->has_param( 'requires_deposit' ) || $request->has_param( 'deposit_percentage' ) || $request->has_param( 'requires_payment' ) ) {
			$effective_requires_deposit = $request->has_param( 'requires_deposit' )
				? (bool) $request->get_param( 'requires_deposit' )
				: (bool) $row->requires_deposit;

			$data['requires_deposit'] = $effective_requires_deposit ? 1 : 0;
			$formats[]                 = '%d';

			$data['deposit_percentage'] = $effective_requires_deposit
				? (float) ( $request->has_param( 'deposit_percentage' ) ? $request->get_param( 'deposit_percentage' ) : $row->deposit_percentage )
				: null;
			$formats[]                   = '%f';
		}

		if ( $request->has_param( 'image_id' ) ) {
			$image_id = $request->get_param( 'image_id' );

			$data['image_id'] = empty( $image_id ) ? null : (int) $image_id;
			$formats[]         = '%d';
		}

		if ( ! empty( $data ) ) {
			$data['updated_at'] = current_time( 'mysql', true );
			$formats[]           = '%s';

			$wpdb->update( $table, $data, array( 'id' => $id ), $formats, array( '%d' ) );
		}

		$row = $this->get_row( $id );

		Booking_Plugin_WooCommerce::sync_product_for_service( $row );

		$row = $this->get_row( $id );

		return rest_ensure_response( $this->prepare_item( $row ) );
	}

	public function delete_item( $request ) {
		global $wpdb;

		$id  = (int) $request['id'];
		$row = $this->get_row( $id );

		if ( ! $row ) {
			return new WP_Error( 'booking_rest_not_found', __( 'Service not found.', 'booking-plugin' ), array( 'status' => 404 ) );
		}

		$wpdb->update(
			$wpdb->prefix . 'booking_services',
			array(
				'status'     => 'inactive',
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'id' => $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		$row = $this->get_row( $id );

		Booking_Plugin_WooCommerce::sync_product_for_service( $row );

		$row = $this->get_row( $id );

		return rest_ensure_response( $this->prepare_item( $row ) );
	}

	protected function validate_numeric_fields( $request, $required ) {
		$price = $request->get_param( 'price' );

		if ( null !== $price && '' !== $price && (float) $price < 0 ) {
			return new WP_Error( 'booking_rest_invalid_price', __( 'price must be greater than or equal to 0.', 'booking-plugin' ), array( 'status' => 400 ) );
		}

		$duration = $request->get_param( 'duration_minutes' );

		if ( $required && ( null === $duration || '' === $duration ) ) {
			return new WP_Error( 'booking_rest_invalid_duration', __( 'duration_minutes is required.', 'booking-plugin' ), array( 'status' => 400 ) );
		}

		if ( null !== $duration && '' !== $duration && (int) $duration <= 0 ) {
			return new WP_Error( 'booking_rest_invalid_duration', __( 'duration_minutes must be greater than 0.', 'booking-plugin' ), array( 'status' => 400 ) );
		}

		$buffer = $request->get_param( 'buffer_minutes' );

		if ( null !== $buffer && '' !== $buffer && (int) $buffer < 0 ) {
			return new WP_Error( 'booking_rest_invalid_buffer', __( 'buffer_minutes must be greater than or equal to 0.', 'booking-plugin' ), array( 'status' => 400 ) );
		}

		return true;
	}

	protected function validate_deposit_fields( $request, $existing_row = null ) {
		$touches_deposit_fields = $request->has_param( 'requires_deposit' )
			|| $request->has_param( 'deposit_percentage' )
			|| $request->has_param( 'requires_payment' );

		if ( ! $touches_deposit_fields && null !== $existing_row ) {
			return true;
		}

		$requires_payment = $request->has_param( 'requires_payment' )
			? (bool) $request->get_param( 'requires_payment' )
			: ( $existing_row ? (bool) $existing_row->requires_payment : false );

		$requires_deposit = $request->has_param( 'requires_deposit' )
			? (bool) $request->get_param( 'requires_deposit' )
			: ( $existing_row ? (bool) $existing_row->requires_deposit : false );

		if ( $requires_deposit && ! $requires_payment ) {
			return new WP_Error( 'booking_rest_deposit_requires_payment', __( 'requires_deposit can only be true when requires_payment is true.', 'booking-plugin' ), array( 'status' => 400 ) );
		}

		if ( $requires_deposit ) {
			$deposit_percentage = $request->has_param( 'deposit_percentage' )
				? $request->get_param( 'deposit_percentage' )
				: ( $existing_row ? $existing_row->deposit_percentage : null );

			if ( null === $deposit_percentage || '' === $deposit_percentage || ! is_numeric( $deposit_percentage ) || (float) $deposit_percentage < 1 || (float) $deposit_percentage > 99 ) {
				return new WP_Error( 'booking_rest_invalid_deposit_percentage', __( 'deposit_percentage is required and must be between 1 and 99 when requires_deposit is true.', 'booking-plugin' ), array( 'status' => 400 ) );
			}
		}

		return true;
	}

	protected function category_exists( $category_id ) {
		global $wpdb;

		$table = $wpdb->prefix . 'booking_service_categories';

		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE id = %d", $category_id ) );
	}

	protected function get_row( $id ) {
		global $wpdb;

		$table = $wpdb->prefix . 'booking_services';

		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) );
	}

	protected function prepare_item( $row ) {
		$image_url = null;
		if ( null !== $row->image_id ) {
			$resolved_url = wp_get_attachment_image_url( (int) $row->image_id, 'medium' );
			$image_url    = $resolved_url ? $resolved_url : null;
		}

		return array(
			'id'                => (int) $row->id,
			'category_id'       => null !== $row->category_id ? (int) $row->category_id : null,
			'name'              => $row->name,
			'slug'              => $row->slug,
			'description'       => $row->description,
			'price'             => (float) $row->price,
			'duration_minutes'  => (int) $row->duration_minutes,
			'buffer_minutes'    => (int) $row->buffer_minutes,
			'status'            => $row->status,
			'requires_payment'  => (bool) $row->requires_payment,
			'requires_deposit'   => (bool) $row->requires_deposit,
			'deposit_percentage' => null !== $row->deposit_percentage ? (float) $row->deposit_percentage : null,
			'wc_product_id'     => null !== $row->wc_product_id ? (int) $row->wc_product_id : null,
			'image_id'          => null !== $row->image_id ? (int) $row->image_id : null,
			'image_url'         => $image_url,
			'created_at'        => $row->created_at,
			'updated_at'        => $row->updated_at,
		);
	}
}
