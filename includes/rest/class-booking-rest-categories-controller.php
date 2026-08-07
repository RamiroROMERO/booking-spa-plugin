<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/trait-booking-rest-slug.php';

class Booking_Rest_Categories_Controller {

	use Booking_Rest_Slug_Trait;

	protected $namespace = 'booking-plugin/v1';
	protected $rest_base = 'categories';

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

		$table = $wpdb->prefix . 'booking_service_categories';

		$page     = max( 1, (int) $request->get_param( 'page' ) ?: 1 );
		$per_page = (int) $request->get_param( 'per_page' ) ?: 10;
		$per_page = min( max( $per_page, 1 ), 100 );
		$offset   = ( $page - 1 ) * $per_page;

		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} ORDER BY sort_order ASC, id ASC LIMIT %d OFFSET %d",
				$per_page,
				$offset
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
			return new WP_Error( 'booking_rest_not_found', __( 'Category not found.', 'booking-plugin' ), array( 'status' => 404 ) );
		}

		return rest_ensure_response( $this->prepare_item( $row ) );
	}

	public function create_item( $request ) {
		global $wpdb;

		$name = trim( (string) $request->get_param( 'name' ) );

		if ( '' === $name ) {
			return new WP_Error( 'booking_rest_invalid_name', __( 'Name is required.', 'booking-plugin' ), array( 'status' => 400 ) );
		}

		$table = $wpdb->prefix . 'booking_service_categories';

		if ( $this->name_exists( $table, $name ) ) {
			return new WP_Error( 'booking_rest_duplicate_name', __( 'A category with this name already exists.', 'booking-plugin' ), array( 'status' => 400 ) );
		}

		$slug = $this->generate_unique_slug( $table, $name );

		$wpdb->insert(
			$table,
			array(
				'name'       => $name,
				'slug'       => $slug,
				'sort_order' => (int) $request->get_param( 'sort_order' ),
				'created_at' => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%d', '%s' )
		);

		$row = $this->get_row( (int) $wpdb->insert_id );

		$response = rest_ensure_response( $this->prepare_item( $row ) );
		$response->set_status( 201 );

		return $response;
	}

	public function update_item( $request ) {
		global $wpdb;

		$id  = (int) $request['id'];
		$row = $this->get_row( $id );

		if ( ! $row ) {
			return new WP_Error( 'booking_rest_not_found', __( 'Category not found.', 'booking-plugin' ), array( 'status' => 404 ) );
		}

		$table = $wpdb->prefix . 'booking_service_categories';
		$data  = array();
		$formats = array();

		if ( null !== $request->get_param( 'name' ) ) {
			$name = trim( (string) $request->get_param( 'name' ) );

			if ( '' === $name ) {
				return new WP_Error( 'booking_rest_invalid_name', __( 'Name is required.', 'booking-plugin' ), array( 'status' => 400 ) );
			}

			if ( $this->name_exists( $table, $name, $id ) ) {
				return new WP_Error( 'booking_rest_duplicate_name', __( 'A category with this name already exists.', 'booking-plugin' ), array( 'status' => 400 ) );
			}

			$data['name']    = $name;
			$formats[]       = '%s';
			$data['slug']    = $this->generate_unique_slug( $table, $name, $id );
			$formats[]       = '%s';
		}

		if ( null !== $request->get_param( 'sort_order' ) ) {
			$data['sort_order'] = (int) $request->get_param( 'sort_order' );
			$formats[]           = '%d';
		}

		if ( ! empty( $data ) ) {
			$wpdb->update( $table, $data, array( 'id' => $id ), $formats, array( '%d' ) );
		}

		$row = $this->get_row( $id );

		return rest_ensure_response( $this->prepare_item( $row ) );
	}

	public function delete_item( $request ) {
		global $wpdb;

		$id  = (int) $request['id'];
		$row = $this->get_row( $id );

		if ( ! $row ) {
			return new WP_Error( 'booking_rest_not_found', __( 'Category not found.', 'booking-plugin' ), array( 'status' => 404 ) );
		}

		$services_table = $wpdb->prefix . 'booking_services';
		$has_services   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$services_table} WHERE category_id = %d", $id ) );

		if ( $has_services > 0 ) {
			return new WP_Error( 'booking_rest_category_in_use', __( 'Cannot delete a category with associated services.', 'booking-plugin' ), array( 'status' => 409 ) );
		}

		$wpdb->delete( $wpdb->prefix . 'booking_service_categories', array( 'id' => $id ), array( '%d' ) );

		return rest_ensure_response(
			array(
				'deleted'  => true,
				'previous' => $this->prepare_item( $row ),
			)
		);
	}

	protected function get_row( $id ) {
		global $wpdb;

		$table = $wpdb->prefix . 'booking_service_categories';

		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) );
	}

	protected function name_exists( $table, $name, $exclude_id = 0 ) {
		global $wpdb;

		$sql  = "SELECT id FROM {$table} WHERE name = %s";
		$args = array( $name );

		if ( $exclude_id ) {
			$sql   .= ' AND id != %d';
			$args[] = $exclude_id;
		}

		return (bool) $wpdb->get_var( $wpdb->prepare( $sql, $args ) );
	}

	protected function prepare_item( $row ) {
		return array(
			'id'         => (int) $row->id,
			'name'       => $row->name,
			'slug'       => $row->slug,
			'sort_order' => (int) $row->sort_order,
			'created_at' => $row->created_at,
		);
	}
}
