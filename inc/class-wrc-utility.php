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
	 * @param $clear_cache
	 *
	 * @return false|int
	 */
	public static function clear_cache_by( $clear_cache ) {
		global $wpdb;

		array_filter( $clear_cache, function( $args ) use ( $wpdb ) {
			return $wpdb->delete( $wpdb->base_prefix . WP_Rest_Cache::$table, $args );
		} );
	}

	/**
	 * Deletes all github api calls from the rest cache to operate with afragen/github-updater plugin
	 *
	 * @return int number of deleted cache rows
	 */
	public static function clear_ghu_cache() {
		$clear_cache = array(
			array( 'rest_domain' => 'https://api.github.com' ),
			array( 'rest_args' => 'GitHub Updater' ),
		);

		/**
		 * Filters array of arrays containing key/value pairs of column/value pairs
		 * for removal from wp_rest_cache table.
		 *
		 * @param array $clear_cache Array of arrays containing key/value pairs for removal.
		 *                           Default contains GitHub and GitHub Updater values.
		 *
		 * @return array $clear_cache Merged array.
		 */
		$clear_cache = apply_filters( 'wp_rest_cache_clear_extra', $clear_cache );

		return self::clear_cache_by( $clear_cache );
	}

}
