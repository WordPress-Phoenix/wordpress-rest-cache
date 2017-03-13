<?php

/**
 * Class WRC_DB
 *
 * Utility functions used to help and simplify db queries
 *
 */
class WRC_DB {

	public static function clear_cache_containing( $column, $value ) {
		global $wpdb;

		$delete_query = 'DELETE FROM ' . static::table_name();
		$delete_query .= ' WHERE ' . static::table_name() . '.' . sanitize_key( $column );
		$delete_query .= ' LIKE "%%%s%%" LIMIT 1000;';

		return $wpdb->query( $wpdb->prepare( $delete_query, $value ) );
	}

	/**
	 * Helper function to calculate table name
	 *
	 * @return string
	 */
	public static function table_name() {
		global $wpdb;

		return $wpdb->base_prefix . WP_Rest_Cache::$table;
	}

	/**
	 * Deletes all github api calls from the rest cache to operate with afragen/github-updater plugin
	 *
	 * @return int number of deleted cache rows
	 */
	public static function clear_ghu_cache() {
		return self::clear_cache_by( 'rest_domain', 'https://api.github.com' );
	}

	/**
	 * Utility to easily delete cache by exact column value
	 *
	 * @param $column
	 * @param $value
	 *
	 * @return false|int
	 */
	public static function clear_cache_by( $column, $value ) {
		global $wpdb;

		return $wpdb->delete( static::table_name(), array( $column => $value ) );
	}

	public static function get_rest_tags( $search = '' ) {
		global $wpdb;
		$col   = 'rest_tag';
		$query = 'SELECT DISTINCT ' . $col . ' FROM ' . static::table_name();
		$query .= ' WHERE ' . static::table_name() . '.' . $col . ' LIKE "%%%s%%" LIMIT 1000';
		$results = $wpdb->get_col( $wpdb->prepare( $query, array( $search ) ) );

		return $results;
	}

	/**
	 * Query the Rest Cache DB table
	 *
	 * @param int $limit
	 *
	 * @return array|bool
	 */
	static function check_old_requests( $limit = 50 ) {

		global $wpdb;

		$days_ago = (int) $_REQUEST['wrc-days-ago'];
		$days_ago = date( 'Y-m-d', strtotime( $days_ago . ' days ago' ) );

		$sql = '
			SELECT COUNT(*) count, rest_domain 
			FROM   ' . REST_CACHE_TABLE . ' 
			WHERE rest_last_requested < "' . $days_ago . '" 
			GROUP BY rest_domain 
			ORDER BY count DESC LIMIT ' . (int) $limit . ';
			';

		return $wpdb->get_results( $sql, ARRAY_A );

		return false;
	}

	/**
	 * Return an array of rows from the rest table based on domain and path
	 *
	 * @param int $limit
	 * @param     $domain
	 * @param     $path
	 *
	 * @return array|bool|null|object
	 */
	static function query_rest_table( $limit = 10, $domain, $path ) {
		global $wpdb;

		$sql = '
			SELECT rest_md5, rest_domain, rest_path 
			FROM   ' . REST_CACHE_TABLE . ' ';
		if ( ! empty( $domain ) ) {
			$sql .= 'WHERE rest_domain = "' . esc_url( $domain ) . '" ';
		}
		if ( ! empty( $domain ) && ! empty( $path ) ) {
			$sql .= 'AND rest_path = "' . sanitize_text_field( $path ) . '" ';
		} elseif ( ! empty( $path ) ) {
			$sql .= 'WHERE  rest_path = "' . sanitize_text_field( $path ) . '" ';
		}

		$sql .= 'ORDER BY rest_path DESC LIMIT ' . intval( $limit ) . ';
			';

		return $wpdb->get_results( $sql, ARRAY_A );

		return false;
	}

}
