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

		static function process_request(){
			$updated_div = '<div class="updated">';
			$error_div = '<div class="error">';

			if ( ! empty( $_REQUEST['wrc-entry-delete'] ) ) {
				$deleted = WRC_Utility::clear_cache_by( 'rest_md5', $_REQUEST['wrc-entry-delete'] );

				if ( false !== $deleted ) {
					$msg  = 'The following entry was deleted:<br /><pre>' . var_export( $deleted, true ) . '</pre>';
					return static::get_action_message( 'update', $msg );
				} else {
					$msg = 'Oops, looks like there was an issue deleting that entry.';
					return static::get_action_message( 'update', $msg );
				}
				return $msg;
			}

			// Validate action submitted
			if( empty( $_REQUEST['wrc-action'] ) ) {
				return '';
			}

			// If action submitted validate nonce
			if( ! wp_verify_nonce( $_REQUEST[static::$utilities_id], $_REQUEST['wrc-action'] ) ) {
				return static::get_action_message('error', 'Error: failed nonce validation.');
			}

			// Assess action since validation passed
			switch ( $_REQUEST['wrc-action'] ) {
				case 'clear-ghu-cache':
					die('works, about to delete ghu cache');
					$results = WRC_Utility::clear_ghu_cache();
					if( $results ) {
						return static::get_action_message( 'updated', 'GHU Rest Cache Cleared.' );
					} else {
						return static::get_action_message( 'updated', 'Action returned an error.' );
					}
					break;
				default:
					break;
			}
		}

		static function options_page() {
			if ( ! empty( $_REQUEST) ) {
				echo static::process_request();
			}
			?>
			<div class="wrap">
			<h1>WP REST Cache Utilities</h1>
			<div id="<?php echo static::$utilities_id; ?>">
				<?php static::build_query_one_form(); ?>
				<?php static::build_query_two_form(); ?>
				<?php static::build_cache_clear_form(); ?>

			</div>
			<?php
		}
		static function build_cache_clear_form(){
			$action = 'clear-ghu-cache';
			?>
			<form id="wrc-cache-clear-form" method="POST" action="" class="card" style="max-width: 100%;">
			<p><strong>Delete GHU API Cache:</strong></p>
			<?php wp_nonce_field( $action, static::$utilities_id ); ?>
			<input type="hidden" name="wrc-action" value="clear-ghu-cache" />
			<input type="submit" id="wrc-submit" value="Run" class="button-primary">
			</form>
			<?php
		}

		static function get_action_message( $type, $content ) {
			$msg  = '<div class="'.$type.'">';
			$msg .= '<p>' . $content . '</p>';
			$msg .= '</div>';

			return apply_filters('wrc_admin_build_page_action_message', $msg, $type, $content);
		}


		static function build_query_one_form(){
			?>
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
						echo '<hr>';
						$csv = '"REST Call Domain","md5"' . PHP_EOL;
						if ( ! empty( $old_items ) ) {
							foreach ( $old_items as $old_item ) {
								$csv .= $old_item['count'] . ',';
								$csv .= $old_item['rest_domain'] . PHP_EOL;
							}
							echo static::csvToTable($csv);
						} else {
							echo '<p>There are no items with a last request date of more than ' . (int) $_REQUEST['wrc-days-ago'] . ' day(s) ago</p>';
						}
					}
					?>
				</div>
				</form>
				<?php
		}


		static function build_query_two_form(){
			?>
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
					<br />
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
							echo '<hr>';
							$csv = '"REST Call Domain","REST Call Path","md5","action"' . PHP_EOL;

							if ( ! empty( $returned_items ) ) {
								foreach ( $returned_items as $item ) {
									$csv .= $item['rest_domain'] . ',';
									$csv .= $item['rest_path'] . ',';
									$csv .= $item['rest_md5'] . ',';
									$csv .= '{{action_delete__' . $item['rest_md5'] . '}}' . PHP_EOL;
								}
								echo static::csvToTable($csv);
							} else {
								echo 'There are no items that match your search for <em>' . $searched_domain . $searched_path . '</em>';
							}
						}
						?>

					</div>
				</form>
				<br />
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


		/**
		 * Fancy table printer
		 *
         * @param $csv_content
         *
		 * @return string
        */
		public static function csvToTable( $csv_content ) {
			if( empty($csv_content) || ! stristr($csv_content, PHP_EOL ) ) {
				return $csv_content;
			}
			$table  = '<table style="width: 100%; text-align: left;">';
			// convert csv into array
			$rows  = str_getcsv( $csv_content, "\n" );

			// pull first row off and build table header
			$table .= '<thead><tr>';
			$header_row = array_shift( $rows );
			$cells = str_getcsv( $header_row );
			foreach ( $cells as &$cell ) {
				$table .= "<th>$cell</th>";
			}
			$table .= '</thead>';

			//build table body data
			$table .= '<tbody>';
			foreach ( $rows as &$row ) {
				$table .= "<tr>";
				$cells = str_getcsv( $row );
				foreach ( $cells as &$cell ) {
					if( substr( $cell, 0, 2 ) == '{{' ) {
						$cell = static::do_cell_shortcode($cell);
					}
					$table .= "<td>$cell</td>";
				}
				$table .= "</tr>";
			}
			$table .= "</tbody></table>";

			return $table;
		}

		public static function do_cell_shortcode( $shortcode ){
			$shortcode = trim( $shortcode, '{}' );
			$data = explode('__', $shortcode);
			$action = array_shift($data);
			switch ( $action ) {
				case 'action_delete':
					if( empty($data[0]) ) {
						return '';
					}
					$out  = '<form method="post" action="" id="delete_' . $data[0] . '">';
					$out .= '<button type="submit" name="wrc-entry-delete" value="' . $data[0] . '">Delete</button>';
					$out .= '</form>';
					return $out;
			}
			return '<b>' . var_export($data, true) . '</b>';
		}
	} // END class
} // END if(!class_exists())