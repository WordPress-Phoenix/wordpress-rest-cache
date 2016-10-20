<?php

/**
 * Class WRC_Filters
 */
class WRC_Filters {

	static function init() {

		static::always_filters();
		if ( is_admin() ) {
			static::admin_filters();
		} else {
			static::frontend_filters();
		}

	}

	/**
	 * Filters registered on every pageload
	 */
	static function always_filters() {
		add_filter( 'oembed_remote_get_args', array( get_called_class(), 'oembed_remote_get_args' ), 99, 1 );
	}

	/**
	 * Admin only filters are registered
	 */
	static function admin_filters() {
		// hook into refresh transients and clear cache at the same time
		add_action( 'ghu_refresh_transients', array( 'WRC_Utility', 'clear_ghu_cache') );
		// disable using transients as it is basically double caching
		add_filter( 'ghu_use_remote_call_transients', '__return_false' );
		// bypass update logic so ghu always reaches out to rest_cache for data
		add_filter('ghu_always_fetch_update', '__return_true');
	}

	/**
	 * Non-Admin, frontend only filters are registered
	 */
	static function frontend_filters() {

	}

	/**
	 * Exclude all GET requests for WP oEmbeds as WordPress core already
	 * has its own caching mechanism in place for those.
	 *
	 * @param $args
	 *
	 * @return array
	 */
	static function oembed_remote_get_args( $args ) {
		$args['wp-rest-cache'] = 'exclude';

		return $args;
	}

} //end class
