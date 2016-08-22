<?php
/**
 * Builds an admin UI for handling administrative utilities
 */

//avoid direct calls to this file, because now WP core and framework has been used
if ( ! function_exists( 'add_filter' ) ) {
	header( 'Status: 403 Forbidden' );
	header( 'HTTP/1.1 403 Forbidden' );
	exit();
}

if ( ! class_exists( 'WRC_Admin' ) ) {
	class WRC_Admin {
		static protected $is_multisite = false;
		static $utilities_id = 'wrc-utilities';
		static $admin_page_slug = 'wp_rest_cache';

		/**
		 * Init function
		 *
		 * @since 0.8.0
		 */
		public static function init() {
			self::$is_multisite = is_multisite();

			/**
			 * Check to make sure we're in the admin, since this init is
			 * only called after checking for `is_user_logged_in()`
			 */
			if ( self::$is_multisite && is_network_admin() ) {
				add_action( 'network_admin_menu', array( get_called_class(), 'add_menu_page' ) );
			} elseif ( is_admin() ) {
				add_action( 'admin_menu', array( get_called_class(), 'add_menu_page' ) );
			}
		} // end init()

		static function add_menu_page() {
			if ( self::$is_multisite ) {
				$parent = 'settings.php';
			} else {
				$parent = 'tools.php';
			}

			add_submenu_page(
				$parent,
				__( 'WP REST Cache', 'rest_cache' ),
				'WP REST Cache',
				'create_users',
				self::$admin_page_slug,
				array( get_called_class(), 'options_page' )
			);
		}

		static function options_page() {

			if ( ! empty( $_REQUEST['wrc-entry-delete'] ) ) {
				$deleted = static::delete_by_md5( $_REQUEST['wrc-entry-delete'] );

				if ( false !== $deleted ) {
					echo '<div class="updated"><p>The following entry was deleted:';
					echo '<pre>';
					var_export( $deleted );
					echo '</pre>';
					echo '</p></div>';

				} else {
					echo '<div class="error"><p>Oops, looks like there was an issue deleting that entry.</p></div>';
				}

			}

			?>
			<div class="wrap">
			<h1>WP REST Cache Utilities</h1>

			<div id="<?php echo static::$utilities_id; ?>">
				<form id="wrc-util-unused" method="POST" action="" class="card" style="max-width: 100%;">
					<p><strong>Check a subset of results based on last request date:</strong></p>
					<?php wp_nonce_field( static::$utilities_id, 'wrc-util-unused' ); ?>
					<label for="wrc-unused-num">Max # of rows to return: </label>
					<br><input type="number" value="50"
					           name="wrc-unused-num"
					           id="wrc-unused-num"/>
					<br><label for="wrc-days-ago">Check for results older than # of days: </label>
					<br><input type="number" value="30"
					           name="wrc-days-ago"
					           id="wrc-days-ago"/>
					<p class="description">Limit is querying the DB, it is recommended to keep the max rows number as
						small as possible.</p>
					<input type="submit" id="wrc-unused-submit" value="Run" class="button-primary">
					<br>
					<div class="results">

						<?php
						if (
							! empty( $_REQUEST['wrc-util-unused'] )
							&& wp_verify_nonce( $_REQUEST['wrc-util-unused'], static::$utilities_id )
							&& ! empty( $_REQUEST['wrc-unused-num'] )
						) {
							$old_items = static::check_old_requests( $_REQUEST['wrc-unused-num'] );


							echo '<hr><table style="width: 100%; text-align: left;"><thead>';
							echo '<tr><th>Count</th><th>REST Call Domain</th></tr>';
							echo '</thead><tbody>';

							if ( ! empty( $old_items ) ) {
								foreach ( $old_items as $old_item ) {
									echo '<tr>';
									echo '<td>' . $old_item['count'] . '</td>';
									echo '<td>' . $old_item['rest_domain'] . '</td>';
									echo '</tr>';
								}
							} else {
								echo '<tr>';
								echo '<td colspan="2"><p><em>';
								echo 'There are no items with a last request date of more than ' . (int) $_REQUEST['wrc-days-ago'] . ' day(s) ago';
								echo '</em></p></td>';
								echo '</tr>';
							}

							echo '</tbody></table>';

						}
						?>

					</div>
				</form>

				<form id="wrc-util-search" method="POST" action="" class="card" style="max-width: 100%;">
					<p><strong>Search for a specific request:</strong></p>
					<?php wp_nonce_field( static::$utilities_id, 'wrc-util-search' ); ?>
					<label for="wrc-limit-num">Max # of rows to return: </label>
					<br><input type="number" value="10"
					           name="wrc-limit-num"
					           id="wrc-limit-num"/>
					<br><label for="wrc-search-rest-domain">REST Call Domain (ex: <em>https://api.fansided.com</em>):
					</label>
					<br><input type="text" style="width: 100%;"
					           value="<?php echo isset( $_REQUEST['wrc-search-rest-domain'] ) ? $_REQUEST['wrc-search-rest-domain'] : 'https://api.fansided.com'; ?>"
					           name="wrc-search-rest-domain"
					           id="wrc-search-rest-domain"/>
					<br><label for="wrc-search-rest-path">REST Call Path (ex: <em>/v2/topics/</em>): </label>
					<br><input type="text" style="width: 100%;"
					           value="<?php echo isset( $_REQUEST['wrc-search-rest-path'] ) ? $_REQUEST['wrc-search-rest-path'] : '/v2/topics/'; ?>"
					           name="wrc-search-rest-path"
					           id="wrc-search-rest-path"/>
					<p><em>Both the Domain and Path fields are searched on an EQUALS basis to help deal with DB search
							performance.</em></p>
					<input type="submit" id="wrc-util-search-submit" value="Run" class="button-primary">
					<br>
					<div class="results">

						<?php
						if (
							! empty( $_REQUEST['wrc-util-search'] )
							&& wp_verify_nonce( $_REQUEST['wrc-util-search'], static::$utilities_id )
							&& ! empty( $_REQUEST['wrc-limit-num'] )
							&& ( ! empty( $_REQUEST['wrc-search-rest-domain'] ) || ! empty( $_REQUEST['wrc-search-rest-path'] ) )
						) {
							$searched_domain = ! empty( $_REQUEST['wrc-search-rest-domain'] ) ? $_REQUEST['wrc-search-rest-domain'] : '';
							$searched_path   = ! empty( $_REQUEST['wrc-search-rest-path'] ) ? $_REQUEST['wrc-search-rest-path'] : '';
							$returned_items  = static::query_rest_table( $_REQUEST['wrc-limit-num'], $searched_domain, $searched_path );

							if ( is_multisite() ) {
								$admin_url = network_admin_url( 'settings.php?page=' . self::$admin_page_slug );
							} else {
								$admin_url = admin_url( 'tools.php?page=' . self::$admin_page_slug );
							}

							echo '<hr><table style="width: 100%; text-align: left;"><thead>';
							echo '<tr><th>REST Call Domain</th><th>REST Call Path</th><th></th><th>md5</th></tr>';
							echo '</thead><tbody>';

							if ( ! empty( $returned_items ) ) {
								foreach ( $returned_items as $item ) {
									echo '<tr>';
									echo '<td>' . $item['rest_domain'] . '</td>';
									echo '<td>' . $item['rest_path'] . '</td>';
									echo '<td><form method="post" action="" id="delete_' . $item['rest_md5'] . '"><button type="submit" name="wrc-entry-delete" value="' . $item['rest_md5'] . '">Delete</button></form></td>';
									echo '<td>' . $item['rest_md5'] . '</td>';
									echo '</tr>';
								}
							} else {
								echo '<tr>';
								echo '<td colspan="4"><p><em>';
								echo 'There are no items that match your search for <em>' . $searched_domain . $searched_path . '</em>';
								echo '</em></p></td>';
								echo '</tr>';
							}

							echo '</tbody></table>';

						}
						?>

					</div>
				</form>
				<div style="margin-top: 40px;">
					<?php
					if ( ! empty( $_REQUEST['wrc-phpinfo'] ) ) {
						phpinfo();
					}
					?>
				</div>
			</div>
			<?php
		}

		/**
		 * Query the Rest Cache DB table
		 *
		 * @param int $limit
		 *
		 * @return array|bool
		 */
		static function check_old_requests( $limit = 50 ) {
			if (
				wp_verify_nonce( $_REQUEST['wrc-util-unused'], static::$utilities_id )
				&& ! empty( $_REQUEST['wrc-unused-num'] )
				&& ! empty( $_REQUEST['wrc-days-ago'] )
			) {
				global $wpdb;

				$days_ago = (int) $_REQUEST['wrc-days-ago'];
				$days_ago = date( 'Y-m-d', strtotime( $days_ago . ' days ago' ) );

				$sql = '
			SELECT COUNT(*) count, rest_domain 
			FROM   ' . REST_CACHE_TABLE . ' 
			WHERE rest_last_requested < "' . $days_ago . '" 
			GROUP BY rest_domain 
			ORDER BY count DESC LIMIT ' . (int) $limit . ';
			';

				return $wpdb->get_results( $sql, ARRAY_A );
			}

			return false;
		}

		/**
		 * Return an array of rows from the rest table based on domain and path
		 *
		 * @param int $limit
		 * @param     $domain
		 * @param     $path
		 *
		 * @return array|bool|null|object
		 */
		static function query_rest_table( $limit = 10, $domain, $path ) {
			if (
				wp_verify_nonce( $_REQUEST['wrc-util-search'], static::$utilities_id )
				&& ( ! empty( $domain ) || ! empty( $path ) ) // verify that at least one of the required items is not empty
			) {
				global $wpdb;

				$sql = '
			SELECT rest_md5, rest_domain, rest_path 
			FROM   ' . REST_CACHE_TABLE . ' ';
				if ( ! empty( $domain ) ) {
					$sql .= 'WHERE rest_domain = "' . esc_url( $domain ) . '" ';
				}
				if ( ! empty( $domain ) && ! empty( $path ) ) {
					$sql .= 'AND rest_path = "' . sanitize_text_field( $path ) . '" ';
				} elseif ( ! empty( $path ) ) {
					$sql .= 'WHERE  rest_path = "' . sanitize_text_field( $path ) . '" ';
				}

				$sql .= 'ORDER BY rest_path DESC LIMIT ' . intval( $limit ) . ';
			';

				return $wpdb->get_results( $sql, ARRAY_A );
			}

			return false;
		}

		static function delete_by_md5( $md5 ) {
			global $wpdb;

			$select = '
			SELECT rest_md5, rest_domain, rest_path
			FROM   ' . REST_CACHE_TABLE . '
			WHERE rest_md5 = "' . esc_attr( $md5 ) . '" 
			LIMIT 1;
			';

			$select_result = $wpdb->get_results( $select, ARRAY_A );

			if ( ! empty( $select_result ) ) {
				$delete = '
				DELETE
				FROM   ' . REST_CACHE_TABLE . '
				WHERE rest_md5 = "' . esc_attr( $md5 ) . '" 
				LIMIT 1;
				';

				$delete_it = $wpdb->get_results( $delete, ARRAY_A );

				return $select_result[0];
			}

			return false;
		}

	} // END class

} // END if(!class_exists())