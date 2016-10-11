<?php

/**
 * Class WRC_Utility
 *
 * Utility functions used to help and simplify clearing out cache in different ways
 *
 */
class WRC_Utility {

	public static function clear_cache_by( $column, $value ) {
		global $wpdb;

		return $wpdb->delete( $wpdb->base_prefix . WP_Rest_Cache::$table, array( $column => $value ) );
	}

	/**
	 * Deletes all github api calls from the rest cache to operate with afragen/github-updater plugin
	 *
	 * @return false|int number of deleted cache rows
	 */
	public static function clear_ghu_cache() {
		self::clear_cache_by( 'rest_domain', 'https://api.github.com' );
	}

}