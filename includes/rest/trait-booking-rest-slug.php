<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait Booking_Rest_Slug_Trait {

	protected function generate_unique_slug( $table, $name, $exclude_id = 0 ) {
		$base_slug = sanitize_title( $name );
		$slug      = $base_slug;
		$attempt   = 1;

		while ( $this->slug_exists( $table, $slug, $exclude_id ) ) {
			$attempt++;

			if ( $attempt > 100 ) {
				$slug = $base_slug . '-' . time();
				break;
			}

			$slug = $base_slug . '-' . $attempt;
		}

		return $slug;
	}

	protected function slug_exists( $table, $slug, $exclude_id = 0 ) {
		global $wpdb;

		$sql  = "SELECT id FROM {$table} WHERE slug = %s";
		$args = array( $slug );

		if ( $exclude_id ) {
			$sql   .= ' AND id != %d';
			$args[] = $exclude_id;
		}

		return (bool) $wpdb->get_var( $wpdb->prepare( $sql, $args ) );
	}
}
