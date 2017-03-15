<?php

/**
 * Class WP_Http_Cache
 *
 * While this class used to hook into the Http Transports,
 * as of WordPress 4.6 it now uses pre_http_request and http_requests
 * filter since the transports filter is no longer used.
 *
 * TODO: consider "paginating" the cached updating via cron. Currently one cron executes to loop over the rows that
 * need new calls/updates
 */
class WRC_Cron {

	/**
	 * Initialize
	 */
	static function init() {
		// set up any Cron needs, this should be able to run independently of the front-end processes
		add_action( 'wp', array( get_called_class(), 'schedule_cron' ) );
		add_action( 'wp_rest_cache_cron', array( get_called_class(), 'check_cache_for_updates' ) );
		add_filter( 'cron_schedules', array( get_called_class(), 'add_schedule_interval' ) );
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

				if ( $response ) {
					// run self:: store_data
					self::store_data( $response, $args, $url, true );
				}
			}
		}

		return;
	}

	/**
	 * Save or update cached data in our custom table based on the md5'd URL
	 *
	 * TODO: get rid of the redundancy between this version of store_data and the version in WRC_Caching class
	 *
	 * @since 0.1.0
	 *
	 * @param      $response
	 * @param      $args
	 * @param      $url
	 *
	 * @return mixed
	 */
	static function store_data( $response, $args, $url ) {
		$status_code = wp_remote_retrieve_response_code( $response );

		// don't try to store if we don't have a 200 response
		if ( 200 != $status_code ) {
			return $response;
		}

		// if no cache expiration is set, we'll set the default expiration time
		if ( empty( $args['wp-rest-cache']['expires'] ) ) {
			$args['wp-rest-cache']['expires'] = WP_Rest_Cache::$default_expires;
		}

		$expiration_date = WP_Rest_Cache::get_expiration_date( $args['wp-rest-cache']['expires'], $status_code );

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
		$md5 = md5( $url );

		$data = array(
			'rest_md5'            => $md5,
			'key'                 => $md5 . '+' . substr( sanitize_key( $tag ), 0, 32 ),
			'rest_domain'         => $domain,
			'rest_path'           => $path,
			'rest_response'       => maybe_serialize( $response ),
			'rest_expires'        => $expiration_date,
			'rest_last_requested' => date( 'Y-m-d', time() ),
			// current UTC time
			'rest_tag'            => $tag,
			'rest_to_update'      => $update,
			'rest_args'           => '',
			'status_code'         => $status_code,
		);

		// either update or insert
		$wpdb->replace( REST_CACHE_TABLE, $data );

		return $response;
	}

}