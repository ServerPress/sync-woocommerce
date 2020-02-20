<?php
/*
Plugin Name: WPSiteSync for WooCommerce
Plugin URI: https://wpsitesync.com/downloads/wpsitesync-woocommerce-products/
Description: Extension for WPSiteSync for Content that provides the ability to Sync WooCommerce Products within the WordPress admin.
Author: WPSiteSync
Author URI: https://wpsitesync.com
Version: 0.9.5
Text Domain: wpsitesync-woocommerce

The PHP code portions are distributed under the GPL license. If not otherwise stated, all
images, manuals, cascading stylesheets and included JavaScript are NOT GPL.
*/

if (!class_exists('WPSiteSync_WooCommerce', FALSE)) {
	/*
	 * @package WPSiteSync_WooCommerce
	 * @author WPSiteSync
	 */

	class WPSiteSync_WooCommerce
	{
		private static $_instance = NULL;

		const PLUGIN_NAME = 'WPSiteSync for WooCommerce';
		const PLUGIN_VERSION = '0.9.5';
		const PLUGIN_KEY = 'c51144fe92984ecb07d30e447c39c27a';
		const REQUIRED_VERSION = '1.5.4';								// minimum version of WPSiteSync required for this add-on to initialize

		private $_api_request = NULL;										// instance of SyncWooCommerceApiRequest
		private $_source_api = NULL;										// instance of SyncWooCommerceSourceApi
		private $_target_api = NULL;										// instance of SyncWooCommerceTargetApi

		private function __construct()
		{
			add_action('spectrom_sync_init', array($this, 'init'));
			if (is_admin())
				add_action('wp_loaded', array($this, 'wp_loaded'));
		}

		/**
		 * Retrieve singleton class instance
		 * @return WPSiteSync_WooCommerce instance
		 */
		public static function get_instance()
		{
			if (NULL === self::$_instance)
				self::$_instance = new self();
			return self::$_instance;
		}

		/**
		 * Callback for Sync initialization action
		 */
		public function init()
		{
			add_filter('spectrom_sync_active_extensions', array($this, 'filter_active_extensions'), 10, 2);

//			if (!WPSiteSyncContent::get_instance()->get_license()->check_license('sync_woocommerce', self::PLUGIN_KEY, self::PLUGIN_NAME))
//				return;

			// Check if WooCommerce is installed and activated
//			include_once ABSPATH . 'wp-admin/includes/plugin.php';
			if (!class_exists('WooCommerce', FALSE)) {
				// still need to hook this in order to return 'wc not installed' error message
				add_action('spectrom_sync_pre_push_content', array($this, 'pre_push_content'), 10, 4);
				add_action('spectrom_sync_push_content', array($this, 'handle_push'), 10, 3);
			}
			if (is_admin() && !class_exists('WooCommerce', FALSE) /* is_plugin_inactive('woocommerce/woocommerce.php') */ ) {
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

			// the following disable the Pull button. Once Pull is implemented, these will be removed
			add_filter('spectrom_sync_show_pull', array($this, 'show_pull'), 90, 1);
			add_filter('spectrom_sync_show_disabled_pull', array($this, 'show_disabled_pull'), 90, 1);
			add_action('current_screen', array($this, 'disable_pull'), 90);

			add_action('spectrom_sync_pre_push_content', array($this, 'pre_push_content'), 10, 4);
			add_action('spectrom_sync_push_content', array($this, 'handle_push'), 10, 3);
			add_filter('spectrom_sync_api_push_content', array($this, 'filter_push_content'), 10, 2);
			add_filter('spectrom_sync_api_response', array($this, 'filter_api_response'), 10, 3);
			add_action('spectrom_sync_parse_gutenberg_block', array($this, 'parse_gutenberg_block'), 10, 6);
			add_filter('spectrom_sync_process_gutenberg_block', array($this, 'process_gutenberg_block'), 10, 7);
			add_filter('spectrom_sync_shortcode_list', array($this, 'filter_shortcode_list'));
			add_action('spectrom_sync_parse_shortcode', array($this, 'check_shortcode_content'), 10, 3);
			add_filter('spectrom_sync_upload_media_allowed_mime_type', array($this, 'filter_allowed_mime_type'), 10, 2);
			add_filter('spectrom_sync_api_arguments', array($this, 'api_arguments'), 10, 2);
			add_filter('spectrom_sync_tax_list', array($this, 'product_taxonomies'), 10, 1);
			add_filter('spectrom_sync_allowed_post_types', array($this, 'filter_allowed_post_types'));
			add_action('spectrom_sync_media_processed', array($this, 'media_processed'), 10, 3);

			add_filter('spectrom_sync_error_code_to_text', array($this, 'filter_error_code'), 10, 3);
			add_filter('spectrom_sync_notice_code_to_text', array($this, 'filter_notice_code'), 10, 2);
		}

		public function show_pull($show)
		{
			$screen = get_current_screen();
			if ('post' === $screen->base && 'product' === $screen->post_type) {
				$show = FALSE;
			}
			return $show;
		}
		public function disable_pull()
		{
			if (class_exists('WPSiteSync_Pull', FALSE) && $this->show_disabled_pull(FALSE))
				remove_action('spectrom_sync_metabox_after_button', array(SyncPullAdmin::get_instance(), 'add_pull_to_metabox'), 10, 1);
		}
		public function show_disabled_pull($show)
		{
			$screen = get_current_screen();
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' screen=' . var_export($screen, TRUE));
			if (class_exists('WPSiteSync_Pull', FALSE) && ('post' === $screen->base && 'product' === $screen->post_type)) {
// add_action('spectrom_sync_metabox_after_button', array($this, 'add_pull_to_metabox'), 10, 1);
//				remove_action('spectrom_sync_metabox_after_button', array(SyncPullAdmin::get_instance(), 'add_pull_to_metabox'), 10, 1);
				$show = TRUE;
			}
			return $show;
		}

		/**
		 * Callback for filtering the post data before it's sent to the Target. Here we check for additional data needed.
		 * @param array $data The data being Pushed to the Target machine
		 * @param SyncApiRequest $apirequest Instance of the API Request object
		 * @return array The modified data
		 */
		public function filter_push_content($data, $apirequest)
		{
			$this->_get_source_api();
			$data = $this->_source_api->filter_push_content($data, $apirequest);

			return $data;
		}

		/**
		 * Filters the API response. During a Push with Variations will return the numbe of variations to process so the update can continue
		 * @param SyncApiResponse $response The response instance
		 * @param string $action The API action, i.e. "push"
		 * @param array $data The data that was sent via the API aciton
		 * @return SyncApiResponse the modified API response instance
		 */
		public function filter_api_response($response, $action, $data)
		{
			$this->_get_source_api();
			return $this->_source_api->filter_api_response($response, $action, $data);
		}

		/**
		 * Handle notifications of Gutenberg Block names during content parsing on the Source site
		 * @param string $block_name A String containing the Block Name, such as 'wp:cover'
		 * @param string $json A string containing the JSON data found in the Gutenberg Block Marker
		 * @param int $source_post_id The post ID being parsed on the Source site
		 * @param array $data The data array being assembled for the Push API call
		 * @param int $pos The position within the $data['post_content'] where the Block Marker is found
		 * @param SyncApiRequest The instance making the API request
		 */
		public function parse_gutenberg_block($block_name, $json, $source_post_id, $data, $pos, $apirequest)
		{
			$this->_get_source_api();
			$data = $this->_source_api->parse_gutenberg_block($block_name, $json, $source_post_id, $data, $pos, $apirequest);
			return $data;
		}

		/**
		 * Processes the Gutenberg content on the Target site, adjusting Block Content as necessary
		 * @param string $content The content for the entire post
		 * @param string $block_name A string containing the Block Name, such as 'wp:cover'
		 * @param string $json A string containing the JSON data found in the Gutenberg Block Marker
		 * @param int $target_post_id The post ID being processed on the Target site
		 * @param int $start The starting offset within $content for the current Block Marker JSON
		 * @param int $end The ending offset within the $content for the current Block Marker JSON
		 * @param int $pos The starting offset within the $content where the Block Marker `<!-- wp:{block_name}` is found
		 * @return string The $content modified as necessary so that it works on the Target site
		 */
		public function process_gutenberg_block($content, $block_name, $json, $target_post_id, $start, $end, $pos)
		{
			$this->_get_target_api();
			$data = $this->_target_api->process_gutenberg_block($content, $block_name, $json, $target_post_id, $start, $end, $pos);
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
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' source id=' . $source_post_id);
			$this->_get_target_api();
			$this->_target_api->pre_push_content($post_data, $source_post_id, $target_post_id, $response);
		}

		/**
		 * Handles the processing of Push requests in response to an API call on the Target
		 * @param int $target_post_id The post ID of the Content on the Target
		 * @param array $post_data The array of post content information sent via the API request
		 * @param SyncApiResponse $response The response object used to reply to the API call
		 */
		public function handle_push($target_post_id, $post_data, SyncApiResponse $response)
		{
SyncDebug::log(__METHOD__.'():' . __LINE__);
			$this->_get_target_api();
			$this->_target_api->handle_push($target_post_id, $post_data, $response);
		}

		/**
		 * Callback for 'spectrom_sync_media_processed', called from SyncApiController->upload_media()
		 * @param int $target_post_id The Post ID of the Content being pushed
		 * @param int $attach_id The attachment's ID
		 * @param int $media_id The media id
		 */
		public function media_processed($target_post_id, $attach_id, $media_id)
		{
			$this->_get_target_api();
			$this->_target_api->media_processed($target_post_id, $attach_id, $media_id);
		}

		/**
		 * Filter the allowed mime type in upload_media
		 * @param boolean $default TRUE to indicate mime type is allowed; otherwise FALSE
		 * @param string $img_type The image type
		 * @return boolean Returns TRUE if the mime type is known; otherwise allows further filters to respond
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
		 * Add Product CPT to allowed post types
		 * @param array $post_types Currently allowed post types
		 * @return array The merged post types
		 */
		public function filter_allowed_post_types($post_types)
		{
			$post_types[] = 'product';
//			$post_types[] = 'product_variation';		// srs #9
			return $post_types;
		}

		/**
		 * Filters the known shortcodes, adding WooCommerce specific shortcodes to the list
		 * @param attay $shortcodes The list of shortcodes to process during Push operations
		 * @return array Modified list of shortcodes
		 */
		public function filter_shortcode_list($shortcodes)
		{
			// https://docs.woocommerce.com/document/woocommerce-shortcodes/
			// https://www.tytonmedia.com/blog/woocommerce-shortcodes-list/
			$shortcodes['product'] = 'ids:pl|id:p';
			$shortcodes['products'] = 'ids:pl|category:s|tag:t';
			$shortcodes['product_page'] = 'id:p';
			$shortcodes['product_category'] = 'category:t|ids:p|parent:t';
			$shortcodes['product_categories'] = 'ids:tl|parent:t';
			$shortcodes['add_to_cart'] = 'id:p';
			$shortcodes['add_to_cart_url'] = 'id:p';
			$shortcodes['recent_products'] = 'category:t';
			$shortcodes['sale_products'] = 'category:s';
			$shortcodes['best_selling_products'] = 'category:i';
			$shortcodes['top_rated_products'] = 'category:t';
			$shortcodes['featured_products'] = 'category:t';
//			$shortcodes['product_attribute'] = '';
//			$shortcodes['related_products'] = '';
//			$shortcodes['shop_messages'] = '';
//			$shortcodes['woocommerce_order_tracking'] = '';
//			$shortcodes['woocommerce_cart'] = '';
//			$shortcodes['woocommerce_checkout'] = '';
//			$shortcodes['woocommerce_my_account'] = '';

			return $shortcodes;
		}

		/**
		 * Checks the content of shortcodes, looking for Product references that have not yet
		 * been Pushed and taxonomy information that needs to be added to the Push content.
		 * @param string $shortcode The name of the shortcode being processed by SyncApiRequest::_process_shortcodes()
		 * @param SyncShortcodeEntry $sce An instance that contains information about the shortcode being processed, including attributes and values
		 * @param SyncApiResponse $apiresponse An instance that can be used to force errors if Products are referenced and not yet Pushed.
		 */
		public function check_shortcode_content($shortcode, $sce, $apiresponse)
		{
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' checking shortcode ' . $shortcode);
			$this->_get_source_api();
			$this->_source_api->check_shortcode_content($shortcode, $sce, $apiresponse);
		}

		/**
		 * Adds product taxonomies to the list of available taxonomies for Syncing
		 * @param array $tax Array of taxonomy information to filter
		 * @return array The taxonomy list, with product taxonomies added to it
		 */
		public function product_taxonomies($tax)
		{
			// called via 'spectrom_sync_tax_list' filter from SyncModel::get_all_taxonomies()
			if (class_exists('WooCommerce', FALSE)) {
SyncDebug::log(__METHOD__.'():' . __LINE__, TRUE);
				$att_taxonomies = array();
				$product_taxonomies = array(
					'product_cat' => get_taxonomy('product_cat'),
					'product_tag' => get_taxonomy('product_tag'),
					'product_type' => get_taxonomy('product_type'),
					'product_shipping_class' => get_taxonomy('product_shipping_class'),
					'product_visibility' => get_taxonomy('product_visibility'),
				);
				$attributes = wc_get_attribute_taxonomy_names();
				foreach ($attributes as $attribute) {
					$att_taxonomies[$attribute] = get_taxonomy($attribute);
				}
				$tax = array_merge($tax, $product_taxonomies, $att_taxonomies);
			}
			return $tax;
		}

		/**
		 * Adds arguments to api remote args
		 * @param array $remote_args Array of arguments sent to SyncRequestApi::api()
		 * @param $action The API requested
		 * @return array The modified remote arguments
		 */
		public function api_arguments($remote_args, $action)
		{
			$this->_get_api_request();
			if ('push' === $action || 'pull' === $action) {
				// this adds the version info on all Push/Pull actions.
				// TODO: add only for 'product' === post_type
				$remote_args['headers'][SyncWooCommerceApiRequest::HEADER_WOOCOMMERCE_VERSION] = WC()->version;
			}
			return $remote_args;
		}

		/**
		 * Converts numeric error code to message string
		 * @param string $message Error message
		 * @param int $code The error code to convert
		 * @param mixed $data Additional data related to the error code
		 * @return string Modified message if one of WPSiteSync WooCommerce's error codes
		 */
		public function filter_error_code($message, $code, $data = NULL)
		{
			// TODO: move to SyncWooCommerceApiRequest class
			$this->_get_api_request();
			switch ($code) {
			case SyncWooCommerceApiRequest::ERROR_WOOCOMMERCE_INVALID_PRODUCT:
				$message = __('Post ID is not a WooCommerce product.', 'wpsitesync-woocommerce');
				break;
			case SyncWooCommerceApiRequest::ERROR_NO_WOOCOMMERCE_PRODUCT_SELECTED:
				$message = __('No WooCommerce product was selected.', 'wpsitesync-woocommerce');
				break;
			case SyncWooCommerceApiRequest::ERROR_WOOCOMMERCE_VERSION_MISMATCH:
				$message = __('The WooCommerce versions on the Source and Target sites do not match.', 'wpsitesync-woocommerce');
				break;
			case SyncWooCommerceApiRequest::ERROR_WOOCOMMERCE_NOT_ACTIVATED:
				$message = __('WooCommerce is not activated on Target site.', 'wpsitesync-woocommerce');
				break;
			case SyncWooCommerceApiRequest::ERROR_WOOCOMMERCE_UNIT_MISMATCH:
				$message = __('WooCommerce measurement units are not the same on both sites.', 'wpsitesync-woocommerce');
				break;
			case SyncWooCommerceApiRequest::ERROR_WOOCOMMERCE_DEPENDENT_PRODUCT_NOT_PUSHED:
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' getting product info for #' . $data);
				$prod = wc_get_product($data);
				if (FALSE === $prod)
					$prodname = ' #' . $data;
				else
					$prodname = '#' . $data . ': ' . $prod->get_title();
				$message = sprintf(__('There is a dependent product, "%1$s", that has not yet been Pushed to the Target site. Please Push this first.', 'wpsitesync-woocommerce'),
					$prodname);
				break;
			case SyncWooCommerceApiRequest::ERROR_WOOCOMMERCE_TARGET_VARIATION_MISSING:
				$message = sprintf(__('Source Variation ID #%1$d cannot be found on Target site.', 'wpsitesync-woocommerce'), $data);
				break;
			case SyncWooCommerceApiRequest::ERROR_WOOCOMMERCE_CURRENCY_MISMATCH:
				$message = __('The Currency settings on the Source site are different than the Target site.', 'wpsitesync-woocommerce');
				break;
			case SyncWooCommerceApiRequest::ERROR_WOOCOMMERCE_NOT_CALC_TAXES:
				$message = __('Target site not calculating taxes, but Tax Class set in Source Product.', 'wpsitesync-woocommerce');
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
			// TODO: move to SyncWooCommerceApiRequest class
			$this->_get_api_request();
			switch ($code) {
			case SyncWooCommerceApiRequest::NOTICE_PRODUCT_MODIFIED:
				$message = __('WooCommerce Product has been modified on Target site since the last Push. Continue?', 'wpsitesync-woocommerce');
				break;
			case SyncWooCommerceApiRequest::NOTICE_WOOCOMMERCE_MEDIA_PERMISSION:
				$message = __('You do not have permission to upload media', 'wpsitesync-woocommerce');
				break;
			case SyncWooCommerceApiRequest::NOTICE_PARTIAL_VARIATION_UPDATE:
				$message = __('Partial Variation update...continuing Push operation.', 'wpsitesync-woocommerce');
				break;
			case SyncWooCommerceApiRequest::NOTICE_CALC_TAXES_DIFFERENT:
				$message = __('The "Calculate Taxes" setting is different on the Source and Target sites.', 'wpsitesync-woocommerce');
				break;
			}
			return $message;
		}

		/**
		 * Retrieve a single instance of the SyncWooCommerceApiRequest class
		 * @return SyncWooCommerceApiRequest The instance of SyncWooCommerceApiRequest
		 */
		private function _get_api_request()
		{
			if (NULL === $this->_api_request) {
				$this->_api_request = $this->load_class('woocommerceapirequest', TRUE);
			}
			return $this->_api_request;
		}

		/**
		 * Retrieves a single instance of the SyncWooCommerceSourceApi class
		 * @return SyncWooCommerceSourceApi The instance of SyncWooCommerceSourceApi
		 */
		private function _get_source_api()
		{
			$this->_get_api_request();
			if (NULL === $this->_source_api) {
				$this->_source_api = $this->load_class('woocommercesourceapi', TRUE);
			}
			return $this->_source_api;
		}

		/**
		 * Retrieves a single instance of the SyncWooCommerceTargetApi class
		 * @return SyncWooCommerceTargetApi The instance of SyncWooCommerceTargetApi
		 */
		private function _get_target_api()
		{
			$this->_get_api_request();
			if (NULL === $this->_target_api) {
				$this->_target_api = $this->load_class('woocommercetargetapi', TRUE);
			}
			return $this->_target_api;
		}

		/**
		 * Loads a specified class file name and optionally creates an instance of it
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
		 * @param string $ref asset name to reference
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
			// TODO: add check for WC installed
			// TODO: see beaver builder add-on for messaging
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
