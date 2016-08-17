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
				'wp_rest_cache',
				array( get_called_class(), 'options_page' )
			);
		}

		static function options_page() {
			?>
			<div class="wrap">
			<h1>WP REST Cache Utilities</h1>

			<div id="<?php echo static::$utilities_id; ?>">
				<form id="wrc-util-unused" method="POST" action="" class="card">
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

	} // END class

} // END if(!class_exists())