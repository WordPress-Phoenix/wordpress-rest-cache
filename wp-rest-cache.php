<?php
/**
 * Plugin Name: WP REST Cache
 * Plugin URI: https://github.com/WordPress-Phoenix/wordpress-rest-cache
 * Description: A solution to caching REST data calls without relying on transients or wp_options tables. Note: for multisite "Network Activate", table may need manually created before activation.
 * Author: scarstens, mlteal
 * Version: 1.3.1
 * Author URI: http://github.com/scarstens
 * License: GPL V2
 * Text Domain: rest_cache
 *
 * GitHub Plugin URI: https://github.com/WordPress-Phoenix/wordpress-rest-cache
 * GitHub Branch: master
 *
 * @package WP_Rest_Cache
 * @category plugin
 * @author mlteal, scarstens
 * @internal Plugin derived from https://github.com/scarstens/worpress-plugin-boilerplate-redux
 */

//avoid direct calls to this file, because now WP core and framework has been used
if ( ! function_exists( 'add_filter' ) ) {
	header( 'Status: 403 Forbidden' );
	header( 'HTTP/1.1 403 Forbidden' );
	exit();
}

/**
 * a comma separated list entries for domains that should be entirely excluded from caching
 */
if ( ! defined( 'WP_REST_CACHE_EXCLUSIONS' ) ) {
	$default_exclusions = 'downloads.wordpress.org,api.wordpress.org';
	define( 'WP_REST_CACHE_EXCLUSIONS', $default_exclusions );
}

if ( ! class_exists( 'WP_Rest_Cache' ) ) {
	class WP_Rest_Cache {
		public $installed_dir;
		static $table = 'rest_cache'; // the prefix is appended once we have access to the $wpdb global
		static $table_version_key = 'wrc_table_version';
		static $columns = 'rest_md5,rest_domain,rest_path,rest_response,rest_expires,rest_last_requested,rest_tag,rest_to_update';
		static $default_expires = array(
			// Default status codes  to 10 minutes, this is always in seconds.
			'default' => 10 * MINUTE_IN_SECONDS,
			'400'     => 5 * MINUTE_IN_SECONDS,
			'401'     => 5 * MINUTE_IN_SECONDS,
			'404'     => 5 * MINUTE_IN_SECONDS,
			'410'     => 8 * WEEK_IN_SECONDS,
			'500'     => 5 * MINUTE_IN_SECONDS,
		);

		/**
		 * Construct the plugin object
		 *
		 * @since   0.1
		 */
		public function __construct() {
			$this->installed_dir = plugin_dir_path( __FILE__ );

			// hook can be used by mu plugins to modify plugin behavior after plugin is setup
			do_action( get_called_class() . '_preface', $this );

			// configure and setup the plugin class variables
			$this->configure_defaults();

			// define globals used by the plugin including bloginfo
			$this->defines_and_globals();

			// Loads the /inc/ autoloader
			$this->load_classes();

			if ( ! defined( 'NO_WP_REST_CACHE' ) ) {
				if ( class_exists( 'WRC_Caching' ) ) {
					WRC_Caching::init();
				}
			}
			// initialize plugin during init
			add_action( 'init', array( $this, 'init' ), 5 );
			// init for use with logged in users, see this::authenticated_init for more details
			add_action( 'init', array( $this, 'authenticated_init' ) );

			// hook can be used by mu plugins to modify plugin behavior after plugin is setup
			do_action( get_called_class() . '_setup', $this );

		} // END public function __construct

		/**
		 * Initialize the plugin - for public (front end)
		 *
		 * @since   0.1
		 * @return  void
		 */
		public function init() {

			do_action( get_called_class() . '_before_init' );
			self::maybe_upgrade_table();

			if ( class_exists( 'WRC_Cron' ) ) {
				WRC_Cron::init();
			}

			if ( class_exists( 'WRC_Filters' ) ) {
				WRC_Filters::init();
			}

			add_action( 'wp_ajax_wrc-ajax-run', array( 'WRC_Ajax', 'run' ) );

			do_action( get_called_class() . '_after_init' );
		}

		/**
		 * Initialize the plugin - for admin (back end)
		 * You would expected this to be handled on action admin_init, but it does not properly handle
		 * the use case for all logged in user actions. Always keep is_user_logged_in() wrapper within
		 * this function for proper usage.
		 *
		 * @since   0.1
		 * @return  void
		 */
		public function authenticated_init() {
			if ( is_user_logged_in() ) {
				require_once( 'admin/class-wrc-admin.php' );
				require_once( 'admin/class-wrc-admin-utility.php' );
				WRC_Admin::init();
			}
		}

		/**
		 * Activate the plugin
		 *
		 * @since   0.1
		 * @return  void
		 */
		public static function activate() {

			// create our table if it doesn't already exist

			$sql = 'CREATE TABLE ' . REST_CACHE_TABLE . " (
			`rest_md5` char(32) COLLATE utf8_unicode_ci NOT NULL DEFAULT '',
            `rest_key` varchar(65) COLLATE utf8_unicode_ci NOT NULL,
            `rest_domain` varchar(1055) COLLATE utf8_unicode_ci NOT NULL DEFAULT '',
            `rest_path` varchar(1055) COLLATE utf8_unicode_ci NOT NULL DEFAULT '',
            `rest_response` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
            `rest_expires` datetime DEFAULT NULL,
            `rest_last_requested` date NOT NULL,
            `rest_tag` varchar(1055) COLLATE utf8_unicode_ci NOT NULL DEFAULT '',
            `rest_to_update` tinyint(1) DEFAULT '0',
            `rest_args` longtext COLLATE utf8mb4_unicode_ci,
            `rest_status_code` varchar(3) COLLATE utf8_unicode_ci NOT NULL DEFAULT '200',
  PRIMARY KEY (`rest_md5`),
  KEY `rest_domain` (`rest_domain`(191),`rest_path`(191)),
  KEY `rest_expires` (`rest_expires`),
  KEY `rest_last_requested` (`rest_last_requested`),
  KEY `rest_tag` (`rest_tag`(191)),
  KEY `rest_to_update` (`rest_to_update`),
  KEY `rest_status_code` (`rest_status_code`),
  KEY `rest_key` (`rest_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

			require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
			dbDelta( $sql );

		} // END public static function activate

		/**
		 * Deactivate the plugin
		 *
		 * @since   0.1
		 * @return  void
		 */
		public static function deactivate() {

			/*
			 * Do not delete site options on deactivate. Usually only things in here will be related to
			 * cache clearing like updating permalinks since some may no longer exist
			 */

		} // END public static function deactivate

		/**
		 * Loads PHP files in the includes folder
		 *
		 * @since   0.1
		 * @return  void
		 */
		protected function load_classes() {
			// this class self-instantiates from within the file
			require_once( 'class-wrc-autoloader.php' );
		}

		protected function defines_and_globals() {
			global $wpdb;
			define( 'REST_CACHE_DB_PREFIX', $wpdb->base_prefix );
			define( 'REST_CACHE_TABLE', REST_CACHE_DB_PREFIX . static::$table );

			// Register the REST Cache table with the wpdb object.
			if ( ! isset( $wpdb->rest_cache ) ) {
				$wpdb->rest_cache = REST_CACHE_TABLE;
			}
		}

		protected function configure_defaults() {
			$this->installed_dir = dirname( __FILE__ );
			$this->installed_url = plugins_url( '/', __FILE__ );
		}

		/**
		 * Correctly returns a date based on the defaults set up and/or
		 * the response status code.
		 *
		 * @since 1.2.0
		 *
		 * @param string|array $expires_values
		 * @param string|int   $status_code
		 *
		 * @return false|string
		 */
		static function get_expiration_date( $expires_values, $status_code ) {
			if ( ! is_array( $expires_values ) ) {
				$default_expires_values            = WP_Rest_Cache::$default_expires;
				$default_expires_values['default'] = $expires_values;
				$expires_values                    = $default_expires_values;
			}

			if ( ! empty( $expires_values[ $status_code ] ) ) {
				$time = $expires_values[ $status_code ];
			} elseif ( ! empty( $expires_values['default'] ) ) {
				$time = $expires_values['default'];
			} else {
				$time = WP_Rest_Cache::$default_expires['default'];
			}

			return date( 'Y-m-d H:i:s', time() + (int) $time );
		}

		/**
		 * This function is used to make it quick and easy to programatically do things only on your development
		 * domains. Typical usage would be to change debugging options or configure sandbox connections to APIs.
		 */
		public static function is_dev() {
			// catches dev.mydomain.com, mydomain.dev, wpengine staging domains and mydomain.staging
			return (bool) ( stristr( WP_NETWORKURL, '.dev' ) || stristr( WP_NETWORKURL, '.wpengine' ) || stristr( WP_NETWORKURL, 'dev.' ) || stristr( WP_NETWORKURL, '.staging' ) );
		}

		/**
		 * @since 1.2.0
		 */
		protected static function maybe_upgrade_table() {
			$table_version = get_site_option( self::$table_version_key );
			// Table version is incremented by 1 on each table update.
			if ( ! $table_version || 2 == (int) $table_version  ) {
				// Version 2 adds a `rest_status_code` column to the table.
				global $wpdb;
				// Check to see if the columns already exist before attempting to add them in
				$query1 = $wpdb->query( "SELECT * FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA='{$wpdb->dbname}' AND TABLE_NAME = '{$wpdb->rest_cache}' AND COLUMN_NAME='rest_status_code';" );
				if ( 1 != $query1 ) {
					$query1 = $wpdb->query( "ALTER TABLE `{$wpdb->rest_cache}` ADD `rest_status_code` VARCHAR(3) COLLATE utf8_unicode_ci NOT NULL DEFAULT '200' AFTER `rest_args`;" );
				}

				$query2 = $wpdb->query( "SELECT * FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA='{$wpdb->dbname}' AND TABLE_NAME = '{$wpdb->rest_cache}' AND COLUMN_NAME='rest_key';" );
				if ( 1 != $query2 ) {
					$query2 = $wpdb->query( "ALTER TABLE `{$wpdb->rest_cache}` ADD `rest_key` VARCHAR(65) COLLATE utf8_unicode_ci NOT NULL AFTER `rest_md5`;" );
				}

				if ( $query1 && $query2 ) {
					update_site_option( self::$table_version_key, '3' );
				} else {
					new WP_Error( 'wrc_error', 'There was an error updating the WP REST Cache table.', array(
						$query1,
						$query2,
					) );
				}
			}

			if ( 2 == (int) $table_version ) {
				// Version 3 adds a `status_code` column to the table.
				global $wpdb;

				// Only attempt to drop the columns if they're currently in the table.
				// The check handles edge cases where the site option might not have properly updated.
				$query1 = $wpdb->query( "SELECT * FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA='{$wpdb->dbname}' AND TABLE_NAME = '{$wpdb->rest_cache}' AND COLUMN_NAME='status_code';" );
				if ( 1 == $query1 ) {
					$query1 = $wpdb->query( "ALTER TABLE `{$wpdb->rest_cache}` DROP COLUMN `status_code`;" );
				}

				$query2 = $wpdb->query( "SELECT * FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA='{$wpdb->dbname}' AND TABLE_NAME = '{$wpdb->rest_cache}' AND COLUMN_NAME='key';" );
				if ( 1 == $query2 ) {
					$query2 = $wpdb->query( "ALTER TABLE `{$wpdb->rest_cache}` DROP COLUMN `key`" );
				}

				if ( $query1 && $query2 ) {
					update_site_option( self::$table_version_key, '3' );
				} else {
					new WP_Error( 'wrc_error', 'There was an error updating the WP REST Cache table.', array(
						$query1,
						$query2,
					) );
				}
			}
		}
	} // END class
} // END if(!class_exists())

/**
 * Build and initialize the plugin
 */
if ( class_exists( 'WP_Rest_Cache' ) ) {
	// Installation and un-installation hooks
	register_activation_hook( __FILE__, array( 'WP_Rest_Cache', 'activate' ) );
	register_deactivation_hook( __FILE__, array( 'WP_Rest_Cache', 'deactivate' ) );

	// instantiate the plugin class, which should never be instantiated more then once
	global $wp_rest_cache;
	$wp_rest_cache = new WP_Rest_Cache();
}
