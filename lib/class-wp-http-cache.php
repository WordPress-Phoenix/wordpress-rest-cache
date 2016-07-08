<?php

/**
 * Class WP_Http_Cache
 *
 * Name of class must stay prefixed with WP_Http to work with WordPress transport filters
 *
 * TODO: consider "paginating" the cached updating via cron. Currently one cron executes to loop over the rows that
 * need new calls/updates
 */
class WP_Http_Cache {
	static $table = 'rest_cache'; // the prefix is appended once we have access to the $wpdb global
	static $columns = 'rest_md5,rest_domain,rest_path,rest_response,rest_expires,rest_last_requested,rest_tag,rest_to_update';
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
		// Add a filter to available HTTP transports so that "cache" is the first thing it checks
		add_filter( 'http_api_transports', array( get_called_class(), 'add_cache_transport' ), 2, 3 );
	}

	/**
	 * Create the interval that we need for our cron that checks in on rest data.
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
	 * Set up the initial cron.
	 */
	static function schedule_cron() {
		$is_multisite = is_multisite();
		if ( $is_multisite ) {
			$primary_blog = get_current_site();
			$current_blog = get_current_blog_id();
		}

		/**
		 * If we're on a multisite, only schedule the cron if we're on the primary blog
		 */
		if (
			( ! $is_multisite || ( $is_multisite && $primary_blog->id === $current_blog ) )
			&& ! wp_next_scheduled( 'wp_rest_cache_cron' )
		) {
			wp_schedule_event( time(), '5_minutes', 'wp_rest_cache_cron' );
		}
	}

	/**
	 * Check the cache table for rows that need updated during our cron.
	 */
	static function check_cache_for_updates() {
		/**
		 * Search our custom DB table for where rest_to_update === 1.
		 * For each  one that === 1, we need to trigger a new wp_remote_get using the args.
		 * We need to split each one of these out into its own execution, so we don't time
		 * out PHP by, for example, running ten 7-second calls in a row.
		 */
		global $wpdb;
		$query   = 'SELECT * FROM ' . REST_CACHE_DB_PREFIX . static::$table . ' WHERE rest_to_update = 1';
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
				 * Get a response by going directly to our own request() function.
				 * By doing so, we don't need to worry that we've turned off our
				 * transport filter during crons, as it doesn't come into play here.
				 */
				$response = static::request( $url, $args, true );

				if ( $response ) {
					// manually apply the http_response filters as they won't get applied when the cron runs for some reason
					$response = apply_filters( 'http_response', $response, $args, $url );
				}
			}
		}

	}

	/**
	 * Prepend the transports array with our custom cache transport
	 *
	 * @param $transports
	 * @param $args
	 * @param $url
	 *
	 * @return array
	 */
	static function add_cache_transport( $transports, $args, $url ) {

		/**
		 * We're avoiding catching the following things in the cache:
		 * - If a filename has been set we're likely dealing with a download.
		 * - We also want to exclude anything with the 'wp-rest-cache' arg
		 *   explicitly set to 'exclude'.
		 * - We want to avoid caching things while WP CRON runs. At the moment,
		 *   this is explicitly because a core WP cron runs on all links in a post
		 *   on post publish, and for pingbacks it does a wp_safe_remote_get (which our
		 *   caching would catch -- no bueno).
		 */
		if (
			! empty( $args['filename'] )
			|| ( ! empty( $args['wp-rest-cache'] ) && 'exclude' === $args['wp-rest-cache'] )
			|| ( defined( 'DOING_CRON' ) && true === DOING_CRON )
		) {
			return $transports;
		}

		$method = ! empty( $args['method'] ) ? strtolower( $args['method'] ) : '';

		// if the domain matches one in the exclusions list, skip it
		$check_url  = parse_url( $url );
		$exclusions = apply_filters( 'wp_rest_cache_exclusions', WP_REST_CACHE_EXCLUSIONS );
		// this could end up being an array already depending on how someone filters it, only explode as necessary
		if ( ! is_array( $exclusions ) ) {
			$exclusions = explode( ',', $exclusions );
		}

		if ( 'get' === $method && ! in_array( $check_url['host'], $exclusions ) && empty( $_REQUEST['force-check'] ) ) {
			$transports = array_merge( array( 'cache' ), $transports );
		}

		return $transports;
	}

	/**
	 * Pull the cached data row from our custom table by matching the md5'd URL
	 *
	 * @param $url
	 *
	 * @return array|bool|null|object|void
	 */
	static function get_data( $url ) {
		global $wpdb;
		$data = $wpdb->get_row( 'SELECT * FROM ' . REST_CACHE_DB_PREFIX . static::$table . ' WHERE rest_md5 = "' . md5( $url ) . '" ', ARRAY_A );

		// if the query doesn't return a row from the DB, return false
		if ( null === $data ) {
			return false;
		}

		return $data;
	}

	/**
	 * Save or update cached data in our custom table based on the md5'd URL
	 *
	 * @param $response
	 * @param $args
	 * @param $url
	 *
	 * @return mixed
	 */
	static function store_data( $response, $args, $url ) {
		// don't try to store if we don't have a 200 response
		if ( 200 != wp_remote_retrieve_response_code( $response ) ) {
			return $response;
		}

		// TODO: consider how we'll skip data storage.. maybe if expires is set to zero?
		// if no "expires" argument is set for now, it gets set to default
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
		$wpdb->replace( REST_CACHE_DB_PREFIX . static::$table, $data );

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

		if ( strtotime( $data['rest_expires'] ) < time() && 1 != $data['rest_to_update'] ) {

			// instead of updating rest_expires, update rest_timeout_length
			$data['rest_args']      = maybe_serialize( $args );
			$data['rest_to_update'] = 1;

			global $wpdb;
			$wpdb->replace( REST_CACHE_DB_PREFIX . static::$table, $data );
		}

	}

	/**
	 * Either return a cached result or run an HTTP curl request
	 *
	 * @param      $url
	 * @param      $args
	 * @param bool $force_update
	 *
	 * @return array|mixed|WP_Error
	 */
	static function request( $url, $args, $force_update = false ) {
		// after setting the transport filter appropriately above,
		// we'll end up in here ( WP_Http_Cache->request() ) to make the actual request

		// $force_update is used when running the cron to just bypass the check for previously cached data entirely
		if ( true !== $force_update ) {
			$cached_request = static::maybe_cached_request( $url, $args );
		}

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