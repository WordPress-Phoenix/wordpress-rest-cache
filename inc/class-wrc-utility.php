<?php

/**
 * Class WRC_Utility
 *
 * Utility functions used to help and simplify clearing out cache in different ways
 *
 */
class WRC_Utility {

	/**
	 * Deletes all github api calls from the rest cache to operate with afragen/github-updater plugin
	 *
	 * @return false|int number of deleted cache rows
	 */
	public static function clear_ghu_cache() {
		global $wpdb;
		return $wpdb->delete( 'wp_rest_cache', array( 'rest_domain' => 'https://api.github.com' ) );
	}
	
}
