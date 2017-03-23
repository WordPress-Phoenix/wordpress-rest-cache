<?php

/**
 * Class WP_Http_Cache
 *
 * While this class used to hook into the Http Transports,
 * as of WordPress 4.6 it now uses pre_http_request and http_requests
 * filter since the transports filter is no longer used.
 *
 */
class WRC_Caching {
	/**
	 * Initialize
	 */
	static function init() {
		// ensure our filters don't run during crons
		if ( ! defined( 'DOING_CRON' ) ) {
			add_filter( 'pre_http_request', array( get_called_class(), 'pre_http_request' ), 9, 3 );
			// If it gets past pre_http_request and to the http response filter,
			// check if we should create/update the data via store_data
			add_action( 'http_api_debug', array( get_called_class(), 'store_data' ), 9, 5 );
//			add_filter( 'http_response', array( get_called_class(), 'store_data' ), 9, 3 );
		}
	}

	/**
	 * Save or update cached data in our custom table based on the md5'd URL
	 * *** Note, there can only be 3 arguments to this function because it's
	 * run on the `http_response` filter.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed  $response
	 * @param string $context
	 * @param string $class
	 * @param array  $args
	 * @param string $url
	 * @param bool   $verify_cacheable IF false, we won't bother to check against `is_cacheable_call()`.
	 *
	 * @return mixed
	 */
	static function store_data( $response, $context, $class, $args, $url, $verify_cacheable = true ) {
		$status_code = (int) wp_remote_retrieve_response_code( $response );
		// 0 isn't helpful... set to 500 if we didn't get an actual status code with the response.
		$status_code = 0 === $status_code ? 500 : $status_code;

		// don't try to store if we don't have a 200 response
		if (
			(
				true == apply_filters( 'wrc_only_cache_200', false )
				&& ( $status_code < 200 || $status_code >= 300 )
			)
			// only check is_cacheable_call if we're not running force update.
			// Force update is usually set during cron, at which point we already know it's a cacheable call
			|| ( $verify_cacheable && false === static::is_cacheable_call( $args, $url ) )
		) {
			return $response;
		}

		// if no cache expiration is set, we'll set the default expiration time
		if ( empty( $args['wp-rest-cache']['expires'] ) ) {
			$args['wp-rest-cache']['expires'] = WP_Rest_Cache::$default_expires;
		}

		$expiration_date = WP_Rest_Cache::get_expiration_date( $args['wp-rest-cache']['expires'], $status_code );

		global $wpdb;

		$parsed_url = WP_Rest_Cache::get_parsed_url( $url );
		$domain     = $parsed_url['domain'];
		$path       = $parsed_url['path'];

		$tag    = ! empty( $args['wp-rest-cache']['tag'] ) ? $args['wp-rest-cache']['tag'] : '';
		$update = ! empty( $args['wp-rest-cache']['update'] ) ? $args['wp-rest-cache']['update'] : 0;
		$md5    = md5( $url );

		$data = array(
			'rest_md5'            => $md5,
			'rest_key'            => $md5 . '+' . substr( sanitize_key( $tag ), 0, 32 ),
			'rest_domain'         => $domain,
			'rest_path'           => $path,
			'rest_expires'        => $expiration_date,
			// current UTC time
			'rest_last_requested' => date( 'Y-m-d', time() ),
			'rest_tag'            => $tag,
			'rest_to_update'      => $update,
			// Always set args to an empty string - they're only stored on "check expired" so the cron has info it needs.
			'rest_args'           => '',
			'rest_status_code'    => $status_code,
		);

		/**
		 * If the status code indicates a bad response, we don't want
		 * to store or overwrite the contents of `rest_response`.
		 */
		if ( $status_code >= 200 && $status_code < 300 ) {
			$data['rest_response'] = maybe_serialize( $response );
			// TODO: needs tidying...
			$wpdb->query(
				$wpdb->prepare(
					"INSERT INTO {$wpdb->rest_cache} "
					. '(`rest_md5`,`rest_key`,`rest_domain`,`rest_path`,`rest_expires`,`rest_last_requested`,`rest_tag`,`rest_to_update`,`rest_args`,`rest_status_code`,`rest_response`)'
					. 'VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%d,%s) ON DUPLICATE KEY UPDATE rest_md5 = %s',
					array(
						$data['rest_md5'],
						$data['rest_key'],
						$data['rest_domain'],
						$data['rest_path'],
						$data['rest_expires'],
						$data['rest_last_requested'],
						$data['rest_tag'],
						$data['rest_to_update'],
						$data['rest_args'],
						$data['rest_status_code'],
						$data['rest_response'],
						$data['rest_md5'],
					)
				)
			);
		} else {
			$wpdb->query(
				$wpdb->prepare(
					"INSERT INTO {$wpdb->rest_cache} "
					. '(`rest_md5`,`rest_key`,`rest_domain`,`rest_path`,`rest_expires`,`rest_last_requested`,`rest_tag`,`rest_to_update`,`rest_args`,`rest_status_code`) '
					. 'VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%d) ON DUPLICATE KEY UPDATE rest_md5 = %s',
					array(
						$data['rest_md5'],
						$data['rest_key'],
						$data['rest_domain'],
						$data['rest_path'],
						$data['rest_expires'],
						$data['rest_last_requested'],
						$data['rest_tag'],
						$data['rest_to_update'],
						$data['rest_args'],
						$data['rest_status_code'],
						$data['rest_md5'],
					)
				)
			);
		}

		// either update or insert
//		$wpdb->update( REST_CACHE_TABLE, $data, array( 'rest_md5' => $data['rest_md5'] ) );


		return $response;
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
		// the first easy to check params are if a filename exists or if the rest cache param is set to "exclude"
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

		// TODO: fix the exclusions functionality...

		if ( 'get' !== $method || in_array( $check_url['host'], $exclusions ) || ! empty( $_REQUEST['force-check'] ) ) {
			return false;
		}

		return true;
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
	static function pre_http_request( $preempt, $args, $url ) {
		/**
		 * Returning false will simply allow the request to continue,
		 * though we'll need to be sure to remove the other http_request
		 * filter so that doesn't get run.
		 */
		if ( ! static::is_cacheable_call( $args, $url ) ) {
			remove_action( 'http_api_debug', array( get_called_class(), 'store_data' ), 9 );

			return false;
		}

		// if we've made it past all of the above checks, continue on with running the HTTP request
		/**
		 * If this is indeed a cacheable request, return the actual data via our
		 * `maybe_return_requested_data` function which will either return 'false'
		 * if the actual request still needs to be made or it will return the
		 * currently stored result.
		 */

		return static::maybe_return_requested_data( $url, $args );
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
	static function maybe_return_requested_data( $url, $args ) {

		$cached_request = static::maybe_cached_request( $url, $args );

		if ( ! empty( $cached_request['rest_response'] ) ) {
			return maybe_unserialize( $cached_request['rest_response'] );
		}

		// false is returned because it tells the `pre_http_request` filter that it needs to move on to the actual http request
		return false;
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

}
