<?php

/**
 * Class WRC_Utility
 *
 * Utility functions used to help and simplify clearing out cache in different ways
 *
 */
class WRC_Utility {

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

		return $wpdb->delete( $wpdb->base_prefix . WP_Rest_Cache::$table, array( $column => $value ) );
	}

	/**
	 * Deletes all github api calls from the rest cache to operate with afragen/github-updater plugin
	 *
	 * @return int number of deleted cache rows
	 */
	public static function clear_ghu_cache() {
		return self::clear_cache_by( 'rest_tag', 'github-updater' );
	}

}
