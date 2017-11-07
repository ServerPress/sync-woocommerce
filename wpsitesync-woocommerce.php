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
		private $_api_request = NULL;

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

			// Check if WooCommerce is installed and activated
			include_once ABSPATH . 'wp-admin/includes/plugin.php';
			if (is_admin() && is_plugin_inactive('woocommerce/woocommerce.php')) {
				add_action('admin_notices', array($this, 'notice_woocommerce_inactive'));
				return;
			}

			// check for minimum WPSiteSync version
			if (is_admin() && version_compare(WPSiteSyncContent::PLUGIN_VERSION, self::REQUIRED_VERSION) < 0 && current_user_can('activate_plugins')) {
				add_action('admin_notices', array($this, 'notice_minimum_version'));
				return;
			}

			if (is_admin() && SyncOptions::is_auth()) {
				$this->load_class('woocommerceadmin');
				SyncWooCommerceAdmin::get_instance();
			}

			add_action('spectrom_sync_pre_push_content', array($this, 'pre_push_content'), 10, 4);
			add_action('spectrom_sync_push_content', array($this, 'handle_push'), 10, 3);
			add_filter('spectrom_sync_api_push_content', array($this, 'filter_push_content'), 10, 2);
			add_filter('spectrom_sync_upload_media_allowed_mime_type', array($this, 'filter_allowed_mime_type'), 10, 2);
			add_filter('spectrom_sync_upload_media_content_type', array($this, 'change_content_type_product'));
			add_filter('spectrom_sync_push_content_type', array($this, 'change_content_type_product'));
			add_filter('spectrom_sync_api_arguments', array($this, 'api_arguments'), 10, 2);
			add_filter('spectrom_sync_tax_list', array($this, 'product_taxonomies'), 10, 1);
			add_filter('spectrom_sync_allowed_post_types', array($this, 'allowed_post_types'));
			add_action('spectrom_sync_media_processed', array($this, 'media_processed'), 10, 3);

			add_filter('spectrom_sync_error_code_to_text', array($this, 'filter_error_code'), 10, 2);
			add_filter('spectrom_sync_notice_code_to_text', array($this, 'filter_notice_code'), 10, 2);
		}

		/**
		 * Callback for filtering the post data before it's sent to the Target. Here we check for additional data needed.
		 * @param array $data The data being Pushed to the Target machine
		 * @param SyncApiRequest $apirequest Instance of the API Request object
		 * @return array The modified data
		 */
		public function filter_push_content($data, $apirequest)
		{
			$this->_get_api_request();
			$data = $this->_api_request->filter_push_content($data, $apirequest);

			return $data;
		}

		/**
		 * Check that everything is ready for us to process the Content Push operation on the Target
		 * @param array $post_data The post data for the current Push
		 * @param int $source_post_id The post ID on the Source
		 * @param int $target_post_id The post ID on the Target
		 * @param SyncApiResponse $response The API Response instance for the current API operation
		 */
		public function pre_push_content($post_data, $source_post_id, $target_post_id, $response)
		{
SyncDebug::log(__METHOD__ . '() source id=' . $source_post_id);
			$this->_get_api_request();
			$this->_api_request->pre_push_content($post_data, $source_post_id, $target_post_id, $response);
		}

		/**
		 * Handles the processing of Push requests in response to an API call on the Target
		 * @param int $target_post_id The post ID of the Content on the Target
		 * @param array $post_data The array of post content information sent via the API request
		 * @param SyncApiResponse $response The response object used to reply to the API call
		 */
		public function handle_push($target_post_id, $post_data, SyncApiResponse $response)
		{
			$this->_get_api_request();
			$this->_api_request->handle_push($target_post_id, $post_data, $response);
		}

		/**
		 * Callback for 'spectrom_sync_media_processed', called from SyncApiController->upload_media()
		 * @param int $target_post_id The Post ID of the Content being pushed
		 * @param int $attach_id The attachment's ID
		 * @param int $media_id The media id
		 */
		public function media_processed($target_post_id, $attach_id, $media_id)
		{
			$this->_get_api_request();
			$this->_api_request->media_processed($target_post_id, $attach_id, $media_id);
		}

		/**
		 * Filter the allowed mime type in upload_media
		 *
		 * @since 1.0.0
		 * @param $default
		 * @param $img_type
		 * @return string
		 */
		public function filter_allowed_mime_type($default, $img_type)
		{
			$allowed_file_types = apply_filters('woocommerce_downloadable_file_allowed_mime_types', get_allowed_mime_types());
			if (in_array($img_type['type'], $allowed_file_types)) {
				return TRUE;
			}

			return $default;
		}

		/**
		 * Change the content_type for get_sync_data and save_sync_data
		 *
		 * @since 1.0.0
		 * @return string
		 */
		public function change_content_type_product()
		{
			return 'wooproduct';
		}

		/**
		 * Add Product CPT to allowed post types
		 *
		 * @since 1.0.0
		 * @param array $post_types Currently allowed post types
		 * @return array The merged post types
		 */
		public function allowed_post_types($post_types)
		{
			$post_types[] = 'product';
			$post_types[] = 'product_variation';
			return $post_types;
		}

		/**
		 * Adds product taxonomies to the list of available taxonomies for Syncing
		 *
		 * @param array $tax Array of taxonomy information to filter
		 * @return array The taxonomy list, with product taxonomies added to it
		 */
		public function product_taxonomies($tax)
		{
			$att_taxonomies = array();
			$product_taxonomies = array(
				'product_cat' => get_taxonomy('product_cat'),
				'product_tag' => get_taxonomy('product_tag'),
				'product_type' => get_taxonomy('product_type'),
				'product_shipping_class' => get_taxonomy('product_shipping_class'),
			);
			$attributes = wc_get_attribute_taxonomy_names();
			foreach ($attributes as $attribute) {
				$att_taxonomies[$attribute] = get_taxonomy($attribute);
			}
			$tax = array_merge($tax, $product_taxonomies, $att_taxonomies);
			return $tax;
		}

		/**
		 * Adds arguments to api remote args
		 *
		 * @param array $remote_args Array of arguments sent to SyncRequestApi::api()
		 * @param $action The API requested
		 * @return array The returned remote arguments
		 */
		public function api_arguments($remote_args, $action)
		{
			$this->_get_api_request();
			if ('pushwoocommerce' === $action || 'pullwoocommerce' === $action) {
				$remote_args['headers'][SyncWooCommerceApiRequest::HEADER_WOOCOMMERCE_VERSION] = WC()->version;
			}
			return $remote_args;
		}

		/**
		 * Converts numeric error code to message string
		 * @param string $message Error message
		 * @param int $code The error code to convert
		 * @return string Modified message if one of WPSiteSync WooCommerce's error codes
		 */
		public function filter_error_code($message, $code)
		{
			$this->_get_api_request();
			switch ($code) {
			case SyncWooCommerceApiRequest::ERROR_WOOCOMMERCE_INVALID_PRODUCT:
				$message = __('Post ID is not a WooCommerce product', 'wpsitesync-woocommerce');
				break;
			case SyncWooCommerceApiRequest::ERROR_NO_WOOCOMMERCE_PRODUCT_SELECTED:
				$message = __('No WooCommerce product was selected', 'wpsitesync-woocommerce');
				break;
			case SyncWooCommerceApiRequest::ERROR_WOOCOMMERCE_VERSION_MISMATCH:
				$message = __('WooCommerce versions do not match', 'wpsitesync-woocommerce');
				break;
			case SyncWooCommerceApiRequest::ERROR_WOOCOMMERCE_NOT_ACTIVATED:
				$message = __('WooCommerce is not activated on Target site', 'wpsitesync-woocommerce');
				break;
			case SyncWooCommerceApiRequest::ERROR_WOOCOMMERCE_UNIT_MISMATCH:
				$message = __('WooCommerce measurement units are not the same on both sites', 'wpsitesync-woocommerce');
				break;
			}
			return $message;
		}

		/**
		 * Converts numeric error code to message string
		 * @param string $message Error message
		 * @param int $code The error code to convert
		 * @return string Modified message if one of WPSiteSync WooCommerce's error codes
		 */
		public function filter_notice_code($message, $code)
		{
			$this->_get_api_request();
			switch ($code) {
			case SyncWooCommerceApiRequest::NOTICE_PRODUCT_MODIFIED:
				$message = __('WooCommerce Product has been modified on Target site since the last Push. Continue?', 'wpsitesync-woocommerce');
				break;
			case SyncWooCommerceApiRequest::NOTICE_WOOCOMMERCE_MEDIA_PERMISSION:
				$message = __('You do not have permission to upload media', 'wpsitesync-woocommerce');
				break;
			}
			return $message;
		}

		/**
		 * Retrieve a single copy of the SyncWooCommerceApiRequest class
		 * @return SyncWooCommerceApiRequest instance of the class
		 */
		private function _get_api_request()
		{
			if (NULL === $this->_api_request) {
				$this->load_class('woocommerceapirequest');
				$this->_api_request = new SyncWooCommerceApiRequest();
			}
			return $this->_api_request;
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
		 * Display admin notice to activate WooCommerce plugin
		 */
		public function notice_woocommerce_inactive()
		{
			$this->_show_notice(sprintf(__('WPSiteSync for WooCommerce requires WooCommerce to be activated. Please <a href="%1$s">click here</a> to activate.', 'wpsitesync-woocommerce'),
				admin_url('plugins.php')), 'notice-warning');
		}

		/**
		 * Helper method to display notices
		 * @param string $msg Message to display within notice
		 * @param string $class The CSS class used on the <div> wrapping the notice
		 * @param boolean $dismissable TRUE if message is to be dismissable; otherwise FALSE.
		 */
		private function _show_notice($msg, $class = 'notice-success', $dismissable = FALSE)
		{
			// TODO: refactor to use Sync Core function
			echo '<div class="notice ', $class, ' ', ($dismissable ? 'is-dismissible' : ''), '">';
			echo '<p>', $msg, '</p>';
			echo '</div>';
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
