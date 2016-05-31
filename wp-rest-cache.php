<?php
/**
 * Plugin Name: WP REST Cache
 * Plugin URI: https://github.com/WordPress-Phoenix/wordpress-rest-cache
 * Description: A solution to caching REST data calls without relying on transients or wp_options tables
 * Author: scarstens
 * Version: 0.2.0
 * Author URI: http://github.com/scarstens
 * License: GPL V2
 * Text Domain: rest_cache
 *
 * GitHub Plugin URI: https://github.com/WordPress-Phoenix/wordpress-rest-cache
 * GitHub Branch: master
 *
 * @package WP_Rest_Cache
 * @category plugin
 * @author
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
	$default_exclusions = 'downloads.wordpress.org';
	define( 'WP_REST_CACHE_EXCLUSIONS', $default_exclusions );
}

if ( ! class_exists( 'WP_Rest_Cache' ) ) {
	class WP_Rest_Cache {
		public $installed_dir;

		/**
		 * Construct the plugin object
		 *
		 * @since   0.1
		 */
		public function __construct() {
			$this->installed_dir = plugin_dir_path( __FILE__ );

			// hook can be used by mu plugins to modify plugin behavior after plugin is setup
			do_action( get_called_class() . '_preface', $this );

			//simplify getting site options with custom prefix with multisite compatibility
			if ( ! function_exists( 'get_custom_option' ) ) {
				// builds  the function in global scope
				function get_custom_option( $s = '', $network_option = false ) {
					if ( $network_option ) {
						return get_site_option( REST_SITEOPTION_PREFIX . $s );
					} else {
						return get_option( REST_SITEOPTION_PREFIX . $s );
					}
				}
			}

			// Always load libraries first
			$this->load_libary();

			// configure and setup the plugin class variables
			$this->configure_defaults();

			// define globals used by the plugin including bloginfo
			$this->defines_and_globals();

			// Load /includes/ folder php files
			$this->load_classes();

			// initialize
			add_action( 'init', array( $this, 'init' ) );

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
			
			if ( class_exists( 'WP_Http_Cache' ) ) {
				WP_Http_Cache::init();
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
				//Uncomment below if you have created an admin folder for admin only plugin partials
				//Change the name below to a custom name that matches your plugin to avoid class collision
				//require_once( $this->installed_dir . '/admin/Main_Admin.class.php' );
				//$this->admin = new Main_Admin( $this );
				//$this->admin->init();
			}
		}

		/**
		 * Activate the plugin
		 *
		 * @since   0.1
		 * @return  void
		 */
		public static function activate() {

			/*
			 * Create any site options defaults for the plugins, handle deprecated values on upgrades, etc
			 */

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
		 * @TODO: Move to using spl_autoload_register
		 *
		 * @since   0.1
		 * @return  void
		 */
		protected function load_classes() {
			// TODO: update the below section to use an autoloader so we can include properly named classes (should be class-the-name.php)
			// load all files with the pattern *.class.php from the includes directory
//			foreach ( glob( dirname( __FILE__ ) . '/includes/*.class.php' ) as $class ) {
//				require_once $class;
//				$this->modules->count ++;
//			}
		}

		/**
		 * Load all files from /lib/ that match extensions like filename.class.php
		 * @TODO: Move to using spl_autoload_register
		 *
		 * @since   0.1
		 * @return  void
		 */
		protected function load_libary() {
			// TODO: set up autoloader for all files in /lib, we're individually requiring at the moment
			require_once( $this->installed_dir . '/lib/class-wp-http-cache.php' );
		}

		protected function defines_and_globals() {
			
		}

		protected function configure_defaults() {
			// Setup plugins global params
			define( 'REST_SITEOPTION_PREFIX', 'rest_cache' );
			$this->modules        = new stdClass();
			$this->modules->count = 0;
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