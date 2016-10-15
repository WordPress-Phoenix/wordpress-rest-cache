<?php
/**
 * Plugin Name: WP REST Cache
 * Plugin URI: https://github.com/WordPress-Phoenix/wordpress-rest-cache
 * Description: A solution to caching REST data calls without relying on transients or wp_options tables. Note: for multisite "Network Activate", table may need manually created before activation.
 * Author: scarstens
 * Version: 1.0.1
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
		static $columns = 'rest_md5,rest_domain,rest_path,rest_response,rest_expires,rest_last_requested,rest_tag,rest_to_update';
		static $default_expires = 600; // defaults to 10 minutes, this is always in seconds

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

			// initialize not by adding an action but by running init function that adds its own actions/filters
			// nesting actions/filters within the init action was causing the transport filter to run too late in some cases
			$this->init();

			add_action( 'before_ghu_delete_all_transients', array( 'WRC_Utility', 'clear_ghu_cache') );

			// init for use with logged in users, see this::authenticated_init for more details
			add_action( 'init', array( $this, 'authenticated_init' ) );

			// uncomment the following to setup custom widget registration
			//add_action( 'widgets_init', array( $this, 'register_custom_widget' ) );

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
			if ( ! defined( 'NO_WP_REST_CACHE' ) ) {
				if ( class_exists( 'WRC_Caching' ) ) {
					WRC_Caching::init();
				}

				if ( class_exists( 'WRC_Cron' ) ) {
					WRC_Cron::init();
				}
			}


			if ( class_exists( 'WRC_Filters' ) ) {
				WRC_Filters::init();
			}

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
				require_once( $this->installed_dir . '/admin/class-wrc-admin.php' );
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

			$sql = "CREATE TABLE " . REST_CACHE_TABLE . " (
  `rest_last_requested` date NOT NULL,
  `rest_expires` datetime DEFAULT NULL,
  `rest_domain` varchar(1055) COLLATE utf8_unicode_ci NOT NULL DEFAULT '',
  `rest_path` varchar(1055) COLLATE utf8_unicode_ci NOT NULL DEFAULT '',
  `rest_response` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `rest_tag` varchar(1055) COLLATE utf8_unicode_ci NOT NULL DEFAULT '',
  `rest_to_update` tinyint(1) DEFAULT '0',
  `rest_md5` char(32) COLLATE utf8_unicode_ci NOT NULL DEFAULT '',
  `rest_args` longtext COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`rest_md5`),
  KEY `rest_domain` (`rest_domain`(191),`rest_path`(191)),
  KEY `rest_expires` (`rest_expires`),
  KEY `rest_last_requested` (`rest_last_requested`),
  KEY `rest_tag` (`rest_tag`(191)),
  KEY `rest_to_update` (`rest_to_update`)
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
			require_once( 'class-wrc-autoloader.php');
		}

		protected function defines_and_globals() {
			global $wpdb;
			define( 'REST_CACHE_DB_PREFIX', $wpdb->base_prefix );
			define( 'REST_CACHE_TABLE', REST_CACHE_DB_PREFIX . static::$table );
		}

		protected function configure_defaults() {
			$this->installed_dir  = dirname( __FILE__ );
			$this->installed_url  = plugins_url( '/', __FILE__ );
		}

		/**
		 * This function is used to make it quick and easy to programatically do things only on your development
		 * domains. Typical usage would be to change debugging options or configure sandbox connections to APIs.
		 */
		public static function is_dev() {
			// catches dev.mydomain.com, mydomain.dev, wpengine staging domains and mydomain.staging
			return (bool) ( stristr( WP_NETWORKURL, '.dev' ) || stristr( WP_NETWORKURL, '.wpengine' ) || stristr( WP_NETWORKURL, 'dev.' ) || stristr( WP_NETWORKURL, '.staging' ) );
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
	global $WP_Rest_Cache;
	$WP_Rest_Cache = new WP_Rest_Cache();
}
