<?php

/**
 * Class WP_Http_Cache
 *
 * Name of class must stay prefixed with WP_Http to work with WordPress transport filters
 */
class WP_Http_Cache {

	static function init(){

		# Add a filter to available HTTP transports so that "cache" is the first thing it checks
		add_filter( 'http_api_transports', array( get_called_class(), "add_cache_transport" ), 2, 3 );

		# If it gets to the http response filter, check if we should create/update the data
		add_filter( 'http_response', array( get_called_class(), "store_data" ));
	}

	static function add_cache_transport($transports, $args, $url ){
		$new_transports = array_merge( array("Cache"), $transports );
		print_r( $new_transports ); exit;
		return $new_transports;
	}

	static function store_data($response){
		echo "<h2>Oh man, lookout, we are storing rest data!!</h2>";
		echo "<pre>";
		print_r(wp_remote_retrieve_body( $response ));
		echo "</pre>";
		return $response;
	}

	/**
	 * Class used to tell core that this is a valid HTTP transport module
	 *
	 * @return bool
	 */
	static function test(){
		return true;
	}

}