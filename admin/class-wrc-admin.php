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
		static $utilities_id = 'wrc-utilities';
		static $admin_page_slug = 'wp_rest_cache';

		/**
		 * Admin init prepares admin settings page
		 *
		 * @since 0.8.0
		*/
		public static function init() {
			/**
			 * Check to make sure we're in the admin, since this init is
			 * only called after checking for `is_user_logged_in()`
			 */
			if ( is_multisite() && is_network_admin() ) {
				add_action( 'network_admin_menu', array( get_called_class(), 'add_menu_page' ) );
			} elseif ( is_admin() ) {
				add_action( 'admin_menu', array( get_called_class(), 'add_menu_page' ) );
			}

			/**
			 * Used for auto complete text fields
			 */
			add_action( 'current_screen', function() {
			    $current_screen = get_current_screen();
			    if ( stristr( $current_screen->id, static::$admin_page_slug ) ) {
			        wp_enqueue_script( 'suggest' );
			    }
			} );
		}

		/**
		 * Callback to insert WRC into wp-admin navigation menu
		 *
		 */
		static function add_menu_page() {
			if ( is_multisite() ) {
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

		/**
		 * Handles processing admin form submissions on admin settings pages
		 *
		 */
		static function process_request() {

			if ( ! empty( $_REQUEST['wrc-entry-delete'] ) ) {
				$deleted = WRC_DB::clear_cache_by( 'rest_md5', $_REQUEST['wrc-entry-delete'] );

				if ( false !== $deleted ) {
					$msg  = 'The following entry was deleted:<br /><pre>' . var_export( $deleted, true ) . '</pre>';
					return WRC_Admin_Utility::get_action_message( 'update', $msg );
				} else {
					$msg = 'Oops, looks like there was an issue deleting that entry.';
					return WRC_Admin_Utility::get_action_message( 'update', $msg );
				}
				return $msg;
			}

			// Validate action submitted
			if ( empty( $_REQUEST['wrc-action'] ) ) {
				return '';
			}

			// If action submitted validate nonce
			if ( ! wp_verify_nonce( $_REQUEST[ static::$utilities_id ], $_REQUEST['wrc-action'] ) ) {
				return WRC_Admin_Utility::get_action_message( 'error', 'Error: failed nonce validation.' );
			}

			// Assess action since validation passed
			global $wpdb;
			$action = $_REQUEST['wrc-action'];
			switch ( $action ) {
				case 'clear-cache-by-tag':
					$seek_by = $_REQUEST['wrc-tag'];
					$results = WRC_DB::clear_cache_containing( 'rest_tag', $_REQUEST['wrc-tag'] );
					if ( $results ) {
						$extra = '';
						if ( intval( $results ) > 999 ) {
							$extra = ' This cache clear was limited to 1000 rows. Please continue running until this 
							message goes away in order to completely purge this cache.';
						}
						return WRC_Admin_Utility::get_action_message( 'updated', "$results cache items cleared for tag <i>$seek_by</i>.$extra" );
					} else {
						$extra = '<span style="display: none;">Data: ' . var_export( $results, true ) . ' ~ ' . $wpdb->last_query . '</span>';
						return WRC_Admin_Utility::get_action_message( 'error', "<i>$action</i> returned no results or was empty on <i>$seek_by</i>.$extra" );
					}
					break;
				default:
					break;
			}
		}

		/**
		 * Print settings page HTML
		 *
		 */
		static function options_page() {
			if ( ! empty( $_POST ) ) {
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

		/**
		 * Print HTML for the clear by tag form
		 *
		 */
		static function build_cache_clear_form() {
			$action = 'clear-cache-by-tag';
			$rest_tag = '';
			if ( ! empty( $_REQUEST['wrc-tag'] ) ) {
				$rest_tag = $_REQUEST['wrc-tag'];
			}
			?>
			<form id="wrc-cache-clear-form" method="POST" action="" class="card" style="max-width: 100%;">
			<p><strong>Clear CACHE by tag:</strong></p>
			<?php wp_nonce_field( $action, static::$utilities_id ); ?>
			<input type="hidden" name="wrc-action" value="<?php echo $action ?>" />
			<label for="wrc-tag">Clear REST Cache rows with rest_tag containing: </label>
			<br /><input type="text" name="wrc-tag" id="wrc-tag" value="<?php echo $rest_tag; ?>" />
			<p class="description">Delete is limited to delete only 1000 rows at a time, you must keep running the
			utility until less then 0 rows are deleted to ensure all cache for that tag is cleared.</p>
			<br /><input type="button" id="wrc-submit" value="Run" class="button-primary" onclick="jQuery(this).next().show();" />
			<input type="submit" id="wrc-submit-confirm" value="Confirm Action" class="button-primary" style="display: none;"/>
			<script>
			jQuery(function($) {
				jQuery('#wrc-tag').suggest(ajaxurl+"?action=wrc-ajax-run&route=rest_tags", {delay: 500, minchars: 1, multiple:false, multipleSep: ","});
				jQuery('#wrc-cache-clear-form').on('keyup keypress', function(e) {
				  var keyCode = e.keyCode || e.which;
				  if (keyCode === 13) {
				    e.preventDefault();
				    return false;
				  }
				});
			});
			</script>
			</form>
			<?php
		}

		/**
		 * Print HTML for the recent cache query
		 *
		 */
		static function build_query_one_form() {
			$num_rows = 50;
			$days_ago = 30;
			if ( ! empty( $_REQUEST['wrc-unused-num'] ) ) {
				$num_rows = $_REQUEST['wrc-unused-num'];
			}
			if ( ! empty( $_REQUEST['wrc-days-ago'] ) ) {
				$days_ago = $_REQUEST['wrc-days-ago'];
			}
			?>
			<div class="card" style="max-width: 100%;">
			<form id="wrc-util-unused" method="POST" action="">
					<p><strong>Check a subset of results based on last request date:</strong></p>
					<?php wp_nonce_field( static::$utilities_id, 'wrc-util-unused' ); ?>
				<label for="wrc-unused-num">Max # of rows to return: </label>
				<br><input type="number" value="<?php echo $num_rows; ?>"
				           name="wrc-unused-num"
				           id="wrc-unused-num"/>
				<br><label for="wrc-days-ago">Check for results older than # of days: </label>
				<br><input type="number" value="<?php echo $days_ago; ?>"
				           name="wrc-days-ago"
				           id="wrc-days-ago"/>
				<p class="description">Limit is querying the DB, it is recommended to keep the max rows number as
					small as possible.</p>
				<input type="submit" id="wrc-unused-submit" value="Run" class="button-primary">
				</form>
				<br>
				<div class="results">
					<?php
					if (
						! empty( $_REQUEST['wrc-util-unused'] )
						&& wp_verify_nonce( $_REQUEST['wrc-util-unused'], static::$utilities_id )
						&& ! empty( $_REQUEST['wrc-unused-num'] )
					) {
						$old_items = WRC_DB::check_old_requests( $_REQUEST['wrc-unused-num'] );
						echo '<hr>';
						$csv = '"REST Call Domain","md5"' . PHP_EOL;
						if ( ! empty( $old_items ) ) {
							foreach ( $old_items as $old_item ) {
								$csv .= $old_item['count'] . ',';
								$csv .= $old_item['rest_domain'] . PHP_EOL;
							}
							echo WRC_Admin_Utility::csv_to_table( $csv );
						} else {
							echo '<p>There are no items with a last request date of more than ' . (int) $_REQUEST['wrc-days-ago'] . ' day(s) ago</p>';
						}
					}
					?>
				</div>
				</div>
				<?php
		}

		/**
		 * Print HTML for the find and delete form
		 *
		 */
		static function build_query_two_form() {
			$num_rows = 10;
			$search_domain = 'https://api.github.com';
			$search_path = '/wordpress-phoenix/';
			if ( ! empty( $_REQUEST['wrc-limit-num'] ) ) {
				$num_rows = $_REQUEST['wrc-limit-num'];
			}
			if ( ! empty( $_REQUEST['wrc-search-rest-domain'] ) ) {
				$search_domain = esc_url( $_REQUEST['wrc-search-rest-domain'] );
			}
			if ( ! empty( $_REQUEST['wrc-search-rest-path'] ) ) {
				$search_path = esc_attr( $_REQUEST['wrc-search-rest-path'] );
			}
			?>
			<div class="card" style="max-width: 100%;">
					<form id="wrc-util-search" method="POST" action="">
					<p><strong>Search for a specific request:</strong></p>
					<?php wp_nonce_field( static::$utilities_id, 'wrc-util-search' ); ?>
					<label for="wrc-limit-num">Max # of rows to return: </label>
					<br><input type="number" value="<?php echo $num_rows; ?>"
					           name="wrc-limit-num"
					           id="wrc-limit-num"/>
					<br><label for="wrc-search-rest-domain">REST Call Domain (ex: <em>https://api.github.com</em>):
					</label>
					<br><input type="text" style="width: 100%;"
					           value="<?php echo $search_domain; ?>"
					           name="wrc-search-rest-domain"
					           id="wrc-search-rest-domain"/>
					<br><label for="wrc-search-rest-path">REST Call Path (ex: <em>/wordpress-phoenix/</em>): </label>
					<br><input type="text" style="width: 100%;"
					           value="<?php echo $search_path; ?>"
					           name="wrc-search-rest-path"
					           id="wrc-search-rest-path"/>
					<p><em>Both the Domain and Path fields are searched on an EQUALS basis to help deal with DB search
							performance.</em></p>
					<input type="submit" id="wrc-util-search-submit" value="Run" class="button-primary">
					</form>
					<br />
					<div class="results">
						<?php
						if (
							! empty( $_REQUEST['wrc-util-search'] )
							&& wp_verify_nonce( $_REQUEST['wrc-util-search'], static::$utilities_id )
							&& ! empty( $_REQUEST['wrc-limit-num'] )
							&& ( ! empty( $_REQUEST['wrc-search-rest-domain'] ) || ! empty( $_REQUEST['wrc-search-rest-path'] ) )
						) {
							$searched_domain = ! empty( $_REQUEST['wrc-search-rest-domain'] ) ? $search_domain : '';
							$searched_path   = ! empty( $_REQUEST['wrc-search-rest-path'] ) ? $search_path : '';
							$returned_items  = WRC_DB::query_rest_table( $_REQUEST['wrc-limit-num'], $searched_domain, $searched_path );

							echo '<hr>';
							$csv = '"REST Call Domain","REST Call Path","md5","action"' . PHP_EOL;

							if ( ! empty( $returned_items ) ) {
								foreach ( $returned_items as $item ) {
									$csv .= $item['rest_domain'] . ',';
									$csv .= $item['rest_path'] . ',';
									$csv .= $item['rest_md5'] . ',';
									$csv .= '{{action_delete__' . $item['rest_md5'] . '}}' . PHP_EOL;
								}
								echo WRC_Admin_Utility::csv_to_table( $csv );
							} else {
								echo 'There are no items that match your search for <em>' . $searched_domain . $searched_path . '</em>';
							}
						}
						?>
					</div>
				</div>
			<?php
		}

	} // END class
} // END if(!class_exists())
