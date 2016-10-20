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
	 * @param $clear_api_calls
	 *
	 * @return false|int
	 */
	public static function clear_cache_by( $clear_api_calls ) {
		global $wpdb;

		array_filter( $clear_api_calls, function( $arr ) use ( $wpdb ) {
			return $wpdb->delete( $wpdb->base_prefix . WP_Rest_Cache::$table, $arr );
		} );
	}

	/**
	 * Deletes all github api calls from the rest cache to operate with afragen/github-updater plugin
	 *
	 * @return int number of deleted cache rows
	 */
	public static function clear_ghu_cache() {
		$clear_api_calls = array(
			array( 'rest_domain' => 'https://api.github.com' ),
		);

		/**
		 * Filters array of arrays containing key/value pairs of column/value pairs
		 * for removal from wp_rest_cache table.
		 *
		 * @param array $clear_columns Array of arrays containing key/value pairs for removal.
		 *                             Default contains GitHub and GitHub Updater values.
		 *
		 * @return array $clear_columns Merged array.
		 */
		$clear_api_calls = apply_filters( 'wp_rest_cache_clear_extra', $clear_api_calls );

		return self::clear_cache_by( $clear_api_calls );
	}

}
