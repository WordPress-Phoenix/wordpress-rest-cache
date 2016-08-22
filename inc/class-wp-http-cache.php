<?php

/**
 * Class WP_Http_Cache
 *
 * While this class used to hook into the Http Transports,
 * as of WordPress 4.6 it now uses pre_http_request and http_requests
 * filter since the transports filter is no longer used.
 *
 * TODO: consider "paginating" the cached updating via cron. Currently one cron executes to loop over the rows that need new calls/updates
 */
class WP_Http_Cache {
	static $default_expires = 600; // defaults to 10 minutes, this is always in seconds

	/**
	 * Initialize
	 */
	static function init() {
		if ( defined( 'NO_WP_REST_CACHE' ) ) {
			return false;
		}
		add_action( 'wp', array( get_called_class(), 'schedule_cron' ) );
		add_action( 'wp_rest_cache_cron', array( get_called_class(), 'check_cache_for_updates' ) );
		add_filter( 'cron_schedules', array( get_called_class(), 'add_schedule_interval' ) );

		add_filter( 'pre_http_request', array( get_called_class(), 'filter_pre_http_request' ), 2, 3 );

		// If it gets past pre_http_request and to the http response filter,
		// check if we should create/update the data via store_data
		add_filter( 'http_response', array( get_called_class(), 'store_data' ), 1, 3 );
	}

	/**
	 * Create the interval that we need for our cron that checks in on rest data.
	 *
	 * @since 0.1.0
	 *
	 * @param $schedules
	 *
	 * @return mixed
	 */
	public static function add_schedule_interval( $schedules ) {

		$schedules['5_minutes'] = array(
			'interval' => 300, // 5 minutes in seconds
			'display'  => 'Once every 5 minutes'
		);

		return $schedules;
	}

	/**
	 * Set up the initial cron
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	static function schedule_cron() {
		$is_multisite = is_multisite();
		if ( $is_multisite ) {
			$primary_blog = get_current_site();
			$current_blog = get_current_blog_id();
		} else {
			$primary_blog = 1;
			$current_blog = 1;
		}

		/**
		 * If we're on a multisite, only schedule the cron if we're on the primary blog
		 */
		if (
			( ! $is_multisite || ( $is_multisite && $primary_blog->id === $current_blog ) )
			&& ! wp_next_scheduled( 'wp_rest_cache_cron' )
		) {
			wp_schedule_event( time(), '5_minutes', 'wp_rest_cache_cron' );
			do_action( 'wrc_after_schedule_cron', $primary_blog, $current_blog );
		}
	}

	/**
	 * Check the cache table for rows that need updated during our cron.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	static function check_cache_for_updates() {
		/**
		 * Search our custom DB table for where rest_to_update === 1.
		 * For each  one that === 1, we need to trigger a new wp_remote_get using the args.
		 * We need to split each one of these out into its own execution, so we don't time
		 * out PHP by, for example, running ten 7-second calls in a row.
		 */
		global $wpdb;
		$query   = 'SELECT * FROM ' . REST_CACHE_TABLE . ' WHERE rest_to_update = 1';
		$results = $wpdb->get_results( $query, ARRAY_A );

		if ( is_array( $results ) && ! empty ( $results ) ) {
			foreach ( $results as $row ) {
				// run maybe_unserialize on rest_args and check to see if the update arg is set and set to false if it is
				$args = maybe_unserialize( $row['rest_args'] );
				$url  = $row['rest_domain'] . $row['rest_path'];
				if ( ! empty( $args['wp-rest-cache']['update'] ) ) {
					$args['wp-rest-cache']['update'] = 0;
				}

				/**
				 * Make the call as a wp_safe_remote_get - the response will be saved when we run
				 * `apply_filters( 'http_response', $response, $args, $url )` below
				 */
				$response = wp_safe_remote_get( $url, $args );

				//

				if ( $response ) {
					// manually apply the http_response filters as they won't get applied when the cron runs for some reason
					$response = apply_filters( 'http_response', $response, $args, $url );
				}
			}
		}

		return;
	}

	/**
	 * Pull the cached data row from our custom table by matching the md5'd URL
	 *
	 * @since 0.1.0
	 *
	 * @param $url
	 *
	 * @return array|bool|null|object|void
	 */
	static function get_data( $url ) {
		global $wpdb;
		$data = $wpdb->get_row( 'SELECT * FROM ' . REST_CACHE_TABLE . ' WHERE rest_md5 = "' . md5( $url ) . '" ', ARRAY_A );

		// if the query doesn't return a row from the DB, return false
		if ( null === $data ) {
			return false;
		}

		return $data;
	}

	/**
	 * Save or update cached data in our custom table based on the md5'd URL
	 *
	 * @since 0.1.0
	 *
	 * @param $response
	 * @param $args
	 * @param $url
	 *
	 * @return mixed
	 */
	static function store_data( $response, $args, $url ) {

		// don't try to store if we don't have a 200 response,
		// and also skip if this isn't a cacheable call
		if (
			200 != wp_remote_retrieve_response_code( $response )
			|| false === static::is_cacheable_call( $args, $url )
		) {
			return $response;
		}

		// if no cache expiration is set, we'll set the default expiration time
		if ( empty( $args['wp-rest-cache']['expires'] ) ) {
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
			'rest_last_requested' => date( 'Y-m-d', time() ),
			// current UTC time
			'rest_tag'            => $tag,
			'rest_to_update'      => $update,
			'rest_args'           => '',
			// always set args to an empty as we store them on "check expired" so the cron has info it needs
		);

		// either update or insert
		$wpdb->replace( REST_CACHE_TABLE, $data );

		return $response;
	}

	/**
	 * Check to see if we've already got this call stored and if it's expired
	 *
	 * @since 0.1.0
	 *
	 * @param $url
	 * @param $args
	 *
	 * @return array|bool|null|object|void
	 */
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
	 * @param array $data The full result from get_data, passed in via maybe_cached_request
	 * @param array $args Args passed into the initial request
	 *
	 * @since 0.1.0
	 */
	protected static function check_for_expired_result( $data, $args ) {
		/**
		 * TODO: get guaranteed UTC time here, Seth and Justin had to do the same
		 */

		if ( strtotime( $data['rest_expires'] ) < time() && 1 != $data['rest_to_update'] ) {

			// instead of updating rest_expires, update rest_timeout_length
			$data['rest_args']      = maybe_serialize( $args );
			$data['rest_to_update'] = 1;

			global $wpdb;
			$wpdb->replace( REST_CACHE_TABLE, $data );
		}

	}

	/**
	 * Either return a cached result or run an HTTP curl request
	 *
	 * @since 0.1.0
	 *
	 * @param      $url
	 * @param      $args
	 *
	 * @return array|mixed|WP_Error
	 */
	static function request( $url, $args ) {

		$cached_request = static::maybe_cached_request( $url, $args );

		if ( ! empty( $cached_request['rest_response'] ) ) {
			return maybe_unserialize( $cached_request['rest_response'] );
		}

		return false;

	}

	/**
	 * Utilize the pre_http_request filter as the filter used
	 * previously (http_api_transports) is useless as of WP version 4.6
	 *
	 * @since 0.9.0
	 *
	 * @param $preempt
	 * @param $args
	 * @param $url
	 *
	 * @return bool
	 */
	static function filter_pre_http_request( $preempt, $args, $url ) {

		/**
		 * Returning false will simply allow the request to continue,
		 * though we'll need to be sure to remove the other http_request
		 * filter so that doesn't get run.
		 */
		if ( ! static::is_cacheable_call( $args, $url ) ) {
			remove_filter( 'http_response', array( get_called_class(), 'store_data' ), 1 );
			return false;
		}

		// if we've made it past all of the above checks, continue on with running the HTTP request
		/**
		 * If this is indeed a cacheable request, return our request function which
		 * will either return 'false' if the actual request still needs to be made or
		 * it will return the cached result.
		 */
		return static::request( $url, $args );
	}

	/**
	 * Verifies that a remote call is cacheable based on query args and URL
	 *
	 * @since 0.9.0
	 *
	 * @param $args
	 * @param $url
	 *
	 * @return bool
	 */
	static function is_cacheable_call( $args, $url ) {
		if (
			! empty( $args['filename'] )
			|| ( ! empty( $args['wp-rest-cache'] ) && 'exclude' === $args['wp-rest-cache'] )
		) {
			return false;
		}

		$method = ! empty( $args['method'] ) ? strtolower( $args['method'] ) : '';

		// if the domain matches one in the exclusions list, skip it
		$check_url  = parse_url( $url );
		$exclusions = apply_filters( 'wp_rest_cache_exclusions', WP_REST_CACHE_EXCLUSIONS );
		// this could end up being an array already depending on how someone filters it, only explode as necessary
		if ( ! is_array( $exclusions ) ) {
			$exclusions = explode( ',', $exclusions );
		}

		if ( 'get' !== $method || in_array( $check_url['host'], $exclusions ) || ! empty( $_REQUEST['force-check'] ) ) {
			return false;
		}

		return true;
	}

}