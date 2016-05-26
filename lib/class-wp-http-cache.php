<?php

/**
 * Class WP_Http_Cache
 *
 * Name of class must stay prefixed with WP_Http to work with WordPress transport filters
 */
class WP_Http_Cache {
	static $table = 'wp_rest_cache';
	static $columns = 'rest_md5,rest_domain,rest_path,rest_response,rest_expires,rest_last_requested,rest_tag,rest_to_update';
	static $default_expires = 600; // defaults to 10 minutes, this is always in seconds

	static function init() {
		// Add a filter to available HTTP transports so that "cache" is the first thing it checks
		add_filter( 'http_api_transports', array( get_called_class(), 'add_cache_transport' ), 2, 3 );
	}

	static function add_cache_transport( $transports, $args, $url ) {
		$method = ! empty( $args['method'] ) ? strtolower( $args['method'] ) : '';

		if ( 'get' === $method && empty( $_REQUEST['force-check'] ) ) {
			$transports = array_merge( array( 'cache' ), $transports );
		}

		return $transports;
	}

	static function get_data( $url ) {
		global $wpdb;

		$data = $wpdb->get_row( 'SELECT * FROM ' . static::$table . ' WHERE rest_md5 = "' . md5( $url ) . '" ', ARRAY_A );

		// if the query doesn't return a row from the DB, return false
		if ( null === $data ) {
			return false;
		}

		return $data;
	}

	static function store_data( $response, $args, $url ) {

		// don't try to store if we don't have a 200 response
		if ( 200 != wp_remote_retrieve_response_code( $response ) ) {
			return $response;
		}

		// if no "expires" argument is set, we need to skip data storage
		if ( empty( $args['wp-rest-cache']['expires'] ) ) {
//			return $response;
			$args['wp-rest-cache']['expires'] = static::$default_expires;
		}

		global $wpdb;

		// if you're on PHP < 5.4.7 make sure you're not leaving the scheme out, as it'll screw up parse_url
		$parsed_url = parse_url( $url );
		$scheme     = isset( $parsed_url['scheme'] ) ? $parsed_url['scheme'] . '://' : '';
		$host       = isset( $parsed_url['host'] ) ? $parsed_url['host'] : '';
		$port       = isset( $parsed_url['port'] ) ? ':' . $parsed_url['port'] : '';
		$user       = isset( $parsed_url['user'] ) ? $parsed_url['user'] : '';
		$pass       = isset( $parsed_url['pass'] ) ? ':' . $parsed_url['pass'] : '';
		$pass       = ( $user || $pass ) ? $pass . '@' : '';
		$path       = isset( $parsed_url['path'] ) ? $parsed_url['path'] : '';
		$query      = isset( $parsed_url['query'] ) ? '?' . $parsed_url['query'] : '';
		$fragment   = isset( $parsed_url['fragment'] ) ? '#' . $parsed_url['fragment'] : '';

		// a domain could potentially not have a scheme, in which case we need to skip appending the colon
		$domain = $scheme . $user . $pass . $host . $port;
		$path   = $path . $query . $fragment;

		$tag    = ! empty( $args['wp-rest-cache']['tag'] ) ? $args['wp-rest-cache']['tag'] : '';
		$update = ! empty( $args['wp-rest-cache']['update'] ) ? $args['wp-rest-cache']['update'] : 0;

		$data = array(
			'rest_md5'            => md5( $url ),
			'rest_domain'         => $domain,
			'rest_path'           => $path,
			'rest_response'       => maybe_serialize( $response ),
			'rest_expires'        => date( 'Y-m-d H:i:s', time() + $args['wp-rest-cache']['expires'] ),
			'rest_last_requested' => date( 'Y-m-d', time() ), // current UTC time
			'rest_tag'            => $tag,
			'rest_to_update'      => $update,
			'rest_args'           => '', // always set args to an empty as we store them on "check expired" so the cron has info it needs
		);

		// either update or insert
		$wpdb->replace( static::$table, $data );

		return $response;
	}

	/**
	 * Class used to tell core that this is a valid HTTP transport module
	 *
	 * @return bool
	 */
	static function test() {
		return true;
	}

	public static function maybe_cached_request( $url, $args ) {
		$data = static::get_data( $url );

		if ( ! empty( $data ) ) {
			// check to see if this request is expired
			static::check_for_expired_result( $data, $args );

			return $data;
		}

		return false;
	}

	/**
	 * Compares the current time in the row returned from static::get_data()
	 * We're also documenting the "rest_last_requested" info here
	 *
	 * @param $data The full result from get_data, passed in via maybe_cached_request
	 * @param $args Args passed into the initial request
	 *
	 * @since 1.0
	 */
	protected static function check_for_expired_result( $data, $args ) {
		/**
		 * TODO: get guaranteed UTC time here, Seth and Justin had to do the same
		 */
		if ( strtotime( $data['rest_expires'] ) < time() && 1 !== $data['rest_to_update'] ) {

			// instead of updating rest_expires, update rest_timeout_length
			$data['rest_args'] = maybe_serialize( $args );
			$data['rest_to_update'] = 1;

			global $wpdb;
			$wpdb->replace( static::$table, $data );
		}

	}

	public function request( $url, $args ) {
		// after setting the transport filter appropriately above,
		// we'll end up in here ( WP_Http_Cache->request() ) to make the actual request
		$cached_request = static::maybe_cached_request( $url, $args );

		// to return an uncached result and update the result in the DB, add `?WP_Http_Cache=replace` to the request
		if ( ! empty( $cached_request['rest_response'] ) && empty( $_REQUEST['WP_Http_Cache'] ) ) {
			return maybe_unserialize( $cached_request['rest_response'] );
		} else {
			// If it gets to the http response filter, check if we should create/update the data
			add_filter( 'http_response', array( get_called_class(), 'store_data' ), 10, 3 );

			$wp_request = new WP_Http_Curl();
			$response   = $wp_request->request( $url, $args );

			return $response;
		}

	}

}