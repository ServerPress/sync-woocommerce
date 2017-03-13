<?php
/*
Plugin Name: WPSiteSync for WooCommerce
Plugin URI: http://wpsitesync.com
Description: Extension for WPSiteSync for Content that provides the ability to Sync WooCommerce Products within the WordPress admin.
Author: WPSiteSync
Author URI: http://wpsitesync.com
Version: 1.0
Text Domain: wpsitesync-woocommerce

The PHP code portions are distributed under the GPL license. If not otherwise stated, all
images, manuals, cascading stylesheets and included JavaScript are NOT GPL.
*/

if (!class_exists('WPSiteSync_WooCommerce')) {
	/*
	 * @package WPSiteSync_WooCommerce
	 * @author WPSiteSync
	 */

	class WPSiteSync_WooCommerce
	{
		private static $_instance = NULL;
		public $api;

		const PLUGIN_NAME = 'WPSiteSync for WooCommerce';
		const PLUGIN_VERSION = '1.0';
		const PLUGIN_KEY = 'c51144fe92984ecb07d30e447c39c27a';
		// TODO: this needs to be updated to 1.3.3 before releasing
		const REQUIRED_VERSION = '1.3.2';									// minimum version of WPSiteSync required for this add-on to initialize

		private function __construct()
		{
			add_action('spectrom_sync_init', array($this, 'init'));
			if (is_admin())
				add_action('wp_loaded', array($this, 'wp_loaded'));
		}

		/**
		 * Retrieve singleton class instance
		 *
		 * @since 1.0.0
		 * @static
		 * @return null|WPSiteSync_WooCommerce
		 */
		public static function get_instance()
		{
			if (NULL === self::$_instance)
				self::$_instance = new self();
			return self::$_instance;
		}

		/**
		 * Callback for Sync initialization action
		 *
		 * @since 1.0.0
		 * @return void
		 */
		public function init()
		{
			add_filter('spectrom_sync_active_extensions', array($this, 'filter_active_extensions'), 10, 2);

//			if (!WPSiteSyncContent::get_instance()->get_license()->check_license('sync_woocommerce', self::PLUGIN_KEY, self::PLUGIN_NAME))
//				return;
			// TODO: check if WooCommerce is activated and do not initialize. display admin notice to activate

			// check for minimum WPSiteSync version
			if (is_admin() && version_compare(WPSiteSyncContent::PLUGIN_VERSION, self::REQUIRED_VERSION) < 0 && current_user_can('activate_plugins')) {
				add_action('admin_notices', array($this, 'notice_minimum_version'));
				return;
			}

			// TODO: this and SyncWooCommerceApiRequest forces the loading of these classes on every page load.
			// TODO: need to setup local method callback for several of these and when called, these can load additional classes and call those methods.
			// TODO: see Beaver Builder add-on for examples (Note: this isn't critical but would be slightly more performant)
			$this->api = new SyncApiRequest();

			if (is_admin() && SyncOptions::is_auth()) {
				$this->load_class('woocommerceadmin');
				SyncWooCommerceAdmin::get_instance();
			}

			$api = $this->load_class('woocommerceapirequest', TRUE);

			add_action('spectrom_sync_pre_push_content', array($api, 'pre_push_content'), 10, 4);
			add_action('spectrom_sync_push_content', array($api, 'handle_push'), 10, 3);
			add_filter('spectrom_sync_api_push_content', array($api, 'filter_push_content'), 10, 2);
			add_filter('spectrom_sync_upload_media_allowed_mime_type', array($api, 'filter_allowed_mime_type'), 10, 2);
			add_filter('spectrom_sync_upload_media_content_type', array($api, 'change_content_type_product'));
			add_filter('spectrom_sync_push_content_type', array($api, 'change_content_type_product'));
			add_filter('spectrom_sync_api_arguments', array($api, 'api_arguments'), 10, 2);
			add_filter('spectrom_sync_tax_list', array($api, 'product_taxonomies'), 10, 1);
			add_filter('spectrom_sync_allowed_post_types', array($api, 'allowed_post_types'));
			add_action('spectrom_sync_media_processed', array($api, 'media_processed'), 10, 3);

			add_filter('spectrom_sync_error_code_to_text', array($api, 'filter_error_codes'), 10, 2);
			add_filter('spectrom_sync_notice_code_to_text', array($api, 'filter_notice_codes'), 10, 2);
		}

		/**
		 * Loads a specified class file name and optionally creates an instance of it
		 *
		 * @since 1.0.0
		 * @param $name Name of class to load
		 * @param bool $create TRUE to create an instance of the loaded class
		 * @return bool|object Created instance of $create is TRUE; otherwise FALSE
		 */
		public function load_class($name, $create = FALSE)
		{
			if (file_exists($file = dirname(__FILE__) . '/classes/' . strtolower($name) . '.php')) {
				require_once($file);
				if ($create) {
					$instance = 'Sync' . $name;
					return new $instance();
				}
			}
			return FALSE;
		}

		/**
		 * Return reference to asset, relative to the base plugin's /assets/ directory
		 *
		 * @since 1.0.0
		 * @param string $ref asset name to reference
		 * @static
		 * @return string href to fully qualified location of referenced asset
		 */
		public static function get_asset($ref)
		{
			$ret = plugin_dir_url(__FILE__) . 'assets/' . $ref;
			return $ret;
		}

		/**
		 * Callback for the 'wp_loaded' action. Used to display admin notice if WPSiteSync for Content is not activated
		 */
		public function wp_loaded()
		{
			if (!class_exists('WPSiteSyncContent', FALSE) && current_user_can('activate_plugins')) {
				if (is_admin())
					add_action('admin_notices', array($this, 'notice_requires_wpss'));
				return;
			}
		}

		/**
		 * Display admin notice to install/activate WPSiteSync for Content
		 */
		public function notice_requires_wpss()
		{
			$this->_show_notice(sprintf(__('WPSiteSync for WooCommerce requires the main <em>WPSiteSync for Content</em> plugin to be installed and activated. Please <a href="%1$s">click here</a> to install or <a href="%2$s">click here</a> to activate.', 'wpsitesync-woocommerce'),
				admin_url('plugin-install.php?tab=search&s=wpsitesync'),
				admin_url('plugins.php')), 'notice-warning');
		}

		/**
		 * Display admin notice to upgrade WPSiteSync for Content plugin
		 */
		public function notice_minimum_version()
		{
			$this->_show_notice(sprintf(__('WPSiteSync for WooCommerce requires version %1$s or greater of <em>WPSiteSync for Content</em> to be installed. Please <a href="2%s">click here</a> to update.', 'wpsitesync-woocommerce'),
				self::REQUIRED_VERSION,
				admin_url('plugins.php')), 'notice-warning');
		}

		/**
		 * Adds the WPSiteSync WooCommerce add-on to the list of known WPSiteSync extensions
		 *
		 * @param array $extensions The list of extensions
		 * @param boolean TRUE to force adding the extension; otherwise FALSE
		 * @return array Modified list of extensions
		 */
		public function filter_active_extensions($extensions, $set = FALSE)
		{
			if ($set || WPSiteSyncContent::get_instance()->get_license()->check_license('sync_woocommerce', self::PLUGIN_KEY, self::PLUGIN_NAME))
				$extensions['sync_woocommerce'] = array(
					'name' => self::PLUGIN_NAME,
					'version' => self::PLUGIN_VERSION,
					'file' => __FILE__,
				);
			return $extensions;
		}
	}
}

// Initialize the extension
WPSiteSync_WooCommerce::get_instance();

// EOF
