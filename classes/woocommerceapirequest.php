<?php

/*
 * Allows management of WooCommerce Products between the Source and Target sites
 * @package Sync
 * @author WPSiteSync
 */
class SyncWooCommerceApiRequest extends SyncInput
{

	const ERROR_WOOCOMMERCE_INVALID_PRODUCT = 600;
	const ERROR_NO_WOOCOMMERCE_PRODUCT_SELECTED = 601;
	const ERROR_WOOCOMMERCE_VERSION_MISMATCH = 602;
	const ERROR_WOOCOMMERCE_NOT_ACTIVATED = 603;

	const NOTICE_PRODUCT_MODIFIED = 600;
	const NOTICE_CANNOT_UPLOAD_WOOCOMMERCE = 604;

	private $_post_id;
	private $_api;
	private $_sync_model;
	private $_api_controller;
	private $_push_controller;
	public $media_id;
	public $local_media_name;

	/**
	 * Filters the errors list, adding SyncWooCommerce specific code-to-string values
	 *
	 * @param string $message The error string message to be returned
	 * @param int $code The error code being evaluated
	 * @return string The modified $message string, with WooCommerce specific errors added to it
	 */
	public function filter_error_codes($message, $code)
	{
		switch ($code) {
		case self::WOOCOMMERCE_INVALID_PRODUCT:
			$message = __('Post ID is not a WooCommerce product', 'wpsitesync-woocommerce');
			break;
		case self::ERROR_NO_WOOCOMMERCE_PRODUCT_SELECTED:
			$message = __('No WooCommerce product was selected', 'wpsitesync-woocommerce');
			break;
		case self::ERROR_WOOCOMMERCE_VERSION_MISMATCH:
			$message = __('WooCommerce versions do not match', 'wpsitesync-woocommerce');
			break;
		case self::ERROR_WOOCOMMERCE_NOT_ACTIVATED:
			$message = __('WooCommerce is not activated on Target site', 'wpsitesync-woocommerce');
			break;
		}
		return $message;
	}

	/**
	 * Filters the notices list, adding SyncWooCommerce specific code-to-string values
	 *
	 * @param string $message The notice string message to be returned
	 * @param int $code The notice code being evaluated
	 * @return string The modified $message string, with WooCommerce specific notices added to it
	 */
	public function filter_notice_codes($message, $code)
	{
		switch ($code) {
		case self::NOTICE_PRODUCT_MODIFIED:
			$message = __('WooCommerce Product has been modified on Target site since the last Push. Continue?', 'wpsitesync-woocommerce');
			break;
		case self::NOTICE_CANNOT_UPLOAD_WOOCOMMERCE:
			$message = __('You do not have permission to upload media', 'wpsitesync-woocommerce');
			break;
		}
		return $message;
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
		if ('pushwoocommerce' === $action || 'pullwoocommerce' === $action) {
			$remote_args['headers']['x-woo-commerce-version'] = WC()->version;
			$remote_args['headers']['x-sync-strict'] = SyncOptions::get_int('strict', 0);
		}
		return $remote_args;
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
	 * Callback for filtering the post data before it's sent to the Target. Here we check for additional data needed.
	 * @param array $data The data being Pushed to the Target machine
	 * @param SyncApiRequest $apirequest Instance of the API Request object
	 * @return array The modified data
	 */
	public function filter_push_content($data, $apirequest)
	{
SyncDebug::log(__METHOD__ . '() filtering push content=' . var_export($data, TRUE));
SyncDebug::log(__METHOD__ . '() for post id=' . var_export($data['post_id'], TRUE));
		$this->_sync_model = new SyncModel();
		$this->_api = $apirequest;

		$site_url = site_url();
		$data['source_domain'] = site_url();
		$apirequest->set_source_domain($site_url);

		// get target post id from synced data
		if (NULL !== ($sync_data = $this->_sync_model->get_sync_target_post($data['post_id'], SyncOptions::get('target_site_key'), 'wooproduct'))) {
			$data['target_post_id'] = $sync_data->target_content_id;
SyncDebug::log(__METHOD__ . '() found product target post id=' . var_export($data['target_post_id'], TRUE));
		}

		// get product type
		$product = wc_get_product($data['post_id']);
		$data['product_type'] = $product->get_type();

		// if variable product, add variations
		if ($product->is_type('variable')) {

			remove_filter('spectrom_sync_api_push_content', array($this, 'filter_push_content'));

			// @todo renablle
			// get transient of post ids
			$ids = FALSE; // remove
//			$current_user = wp_get_current_user();
//			$ids = get_transient("spectrom_sync_woo_{$current_user->ID}_{$args['post_id']}");

			if (FALSE === $ids) {
				$ids = $product->get_children();
			}

SyncDebug::log(__METHOD__ . '() remaining variation ids=' . var_export($ids, TRUE));

			foreach ($ids as $key => &$id) {
SyncDebug::log(__METHOD__ . '() adding variation id=' . var_export($id, TRUE));
				$data['product_variations'][] = $this->_api->get_push_data($id, $data);
				unset($ids[$key]);
			}

//			if (empty($ids)) {
//				delete_transient("spectrom_sync_woo_{$current_user->ID}_{$args['post_id']}");
//			} else {
//SyncDebug::log(__METHOD__ . '() new remaining variation ids=' . var_export($ids, TRUE));
//				set_transient("spectrom_sync_woo_{$current_user->ID}_{$args['post_id']}", $ids, 60 * 60 * 1);
//			}
		}

		// process meta values
		foreach ($data['post_meta'] as $meta_key => $meta_value) {

			if (NULL !== $meta_value && !empty($meta_value)) {
				switch ($meta_key) {
				case '_product_image_gallery':
					$this->_get_product_gallery($data['post_id'], $meta_value);
					break;
				case '_upsell_ids':
				case '_crosssell_ids':
					$ids = maybe_unserialize($meta_value[0]);
					foreach ($ids as $associated_id) {
						$data[$meta_key][$associated_id] = $this->_get_associated_products($associated_id);
					}
					break;
				case '_downloadable_files';
					$this->_get_downloadable_files($data['post_id'], $meta_value);
					break;
				case '_min_price_variation_id':
				case '_max_price_variation_id':
				case '_min_regular_price_variation_id':
				case '_max_regular_price_variation_id':
				case '_min_sale_price_variation_id':
				case '_max_sale_price_variation_id':
					$associated_id = $meta_value[0];
					$data[$meta_key][$associated_id] = $this->_get_associated_products($associated_id, 'woovariableproduct');
					break;
				default:
					break;
				}
			}
		}

		// check if any featured images or downloads in variations need to be added to queue
		if (array_key_exists('product_variations', $data)) {
			foreach ($data['product_variations'] as $var) {

				// process variation featured image
				if (0 != $var['thumbnail']) {
SyncDebug::log(__METHOD__ . '() variation has thumbnail id=' . var_export($var['thumbnail'], TRUE));
					$img = wp_get_attachment_image_src($var['thumbnail'], 'full');
					if (FALSE !== $img) {
						$path = str_replace(trailingslashit(site_url()), ABSPATH, $img[0]);
						// @todo change to sendmedia, apirequest
						$this->_api->upload_media($var['post_data']['ID'], $path, NULL, TRUE, $var['thumbnail']);
					}
				}

				foreach ($var['post_meta'] as $meta_key => $meta_value) {
					// process downloadable files
					if ('_downloadable_files' === $meta_key && !empty($meta_value)) {
SyncDebug::log(__METHOD__ . '() found variation downloadable files data=' . var_export($meta_value, TRUE));
						$this->_get_downloadable_files($var['post_data']['ID'], $meta_value);
					}
				}
			}
		}

		$data['attribute_taxonomies'] = wc_get_attribute_taxonomies();

SyncDebug::log(__METHOD__ . '() data=' . var_export($data, TRUE));
		return $data;
	}

	/**
	 * Checks the API request if the action is to pull/push the product
	 *
	 * @param array $args The arguments array sent to SyncApiRequest::api()
	 * @param string $action The API requested
	 * @param array $remote_args Array of arguments sent to SyncRequestApi::api()
	 * @return array The modified $args array, with any additional information added to it
	 * @todo remove?
	 */
	public function api_request($args, $action, $remote_args)
	{
SyncDebug::log(__METHOD__ . '() action=' . $action);
	 	//if ('pullwoocommerce' === $action) {
//SyncDebug::log(__METHOD__ . '() args=' . var_export($args, TRUE));
//
////			if (NULL !== ($sync_data = $this->_sync_model->get_sync_data($this->post_int('post_id', 0), SyncOptions::get('site_key'), 'wooproduct'))) {
////				$args['target_post_id'] = $sync_data->target_content_id;
////			} elseif (NULL !== ($sync_data = $this->_sync_model->get_sync_data($this->post_int('post_id', 0), SyncOptions::get('site_key'), 'woovariableproduct'))) {
////				$args['target_post_id'] = $sync_data->target_content_id;
////			}
//		}

		// return the filter value
		return $args;
	}

	/**
	 * Handles fixup of data on the Target after SyncApiController has finished processing Content.
	 * @param int $target_post_id The post ID being created/updated via API call
	 * @param array $post_data Post data sent via API call
	 * @param SyncApiResponse $response Response instance
	 */
	public function handle_push($target_post_id, $post_data, $response)
	{
SyncDebug::log(__METHOD__ . "({$target_post_id})");

		add_filter('spectrom_sync_upload_media_allowed_mime_type', array(&$this, 'filter_allowed_mime_type'), 10, 2);

SyncDebug::log(__METHOD__ . '() found post_data information: ' . var_export($post_data, TRUE));

		$this->_api = WPSiteSync_WooCommerce::get_instance()->api;
		$this->_sync_model = new SyncModel();
		$this->_api_controller = SyncApiController::get_instance();

		// set source domain
		$this->_api->set_source_domain($this->post_raw('source_domain', ''));
SyncDebug::log(__METHOD__ . '() source domain: ' . var_export($this->post_raw('source_domain', ''), TRUE));

		$product_type = $this->post_raw('product_type', '');
		$response->set('product_type', $product_type);
		$post_meta = $this->post_raw('post_meta', array());

		// sync metadata
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' handling meta data');

		foreach ($post_meta as $meta_key => $meta_value) {

			// loop through meta_value array
			if ('_product_attributes' === $meta_key) {
SyncDebug::log('   processing product attributes: ');
SyncDebug::log(__METHOD__ . '() meta value: ' . var_export($meta_value, TRUE));
				$this->_process_attributes($target_post_id, $meta_value[0]);
			} else {
				foreach ($meta_value as $value) {
					$value = maybe_unserialize(stripslashes($value));
SyncDebug::log('   meta value ' . var_export($value, TRUE));
					switch ($meta_key) {
					case '_upsell_ids':
					case '_crosssell_ids':
						$target_ids = $this->post_raw($meta_key, array());
						$new_meta_ids = array();

						foreach ($value as $meta_source_id) {
							$new_meta_ids = $this->_process_associated_products($target_ids, $meta_key, $meta_source_id, $new_meta_ids);
						}
							update_post_meta($target_post_id, $meta_key, $new_meta_ids);
						break;
					case '_min_price_variation_id':
					case '_max_price_variation_id':
					case '_min_regular_price_variation_id':
					case '_max_regular_price_variation_id':
					case '_min_sale_price_variation_id':
					case '_max_sale_price_variation_id':
						$values = $this->post_raw($meta_key, array());
						$new_id = $this->_process_variation_ids($values, $value);
SyncDebug::log('  updating post_meta for ' . var_export($meta_key, TRUE));
SyncDebug::log('  updating post_meta with  ' . var_export($new_id, TRUE));
SyncDebug::log('  updating post_meta for target id ' . var_export($target_post_id, TRUE));
						update_post_meta($target_post_id, $meta_key, $new_id);
						break;
					default:
						break;
					}
				}
			}
		}

		$product_variations = $this->post_raw('product_variations', array());
		if (!empty($product_variations)) {
SyncDebug::log('adding variations');
			$variations = $this->_process_variations($target_post_id, $product_variations);
			$response->set('variations', $variations);
		}

		// clear transients
		WC_Post_Data::delete_product_query_transients();
	}

	/**
	 * Handles the requests being processed on the Target from SyncApiController
	 *
	 * @param array $return Value to return
	 * @param string $action The API requested
	 * @param SyncApiResponse $response The SyncApiResponse object from a previous API request
	 * @return bool $response The SyncApiResponse object
	 * // @todo change action to push, check if woocommerce item, return error codes still
	 */
	public function api_controller_request($return, $action, SyncApiResponse $response)
	{
SyncDebug::log(__METHOD__ . "() handling '{$action}' action");

//		if (!WPSiteSyncContent::get_instance()->get_license()->check_license('sync_woocommerce', WPSiteSync_WooCommerce::PLUGIN_KEY, WPSiteSync_WooCommerce::PLUGIN_NAME))
//			return TRUE;

		if ('pushwoocommerce' === $action) {

			if ('product' !== $post_data['post_type']) {
				SyncDebug::log(' - checking post type: ' . $post_data['post_type']);
				$response->error_code(self::WOOCOMMERCE_INVALID_PRODUCT);
				return;
			}

			// Check if WooCommerce is installed and activated
			if (!is_plugin_active('woocommerce/woocommerce.php')) {
				$response->error_code(self::ERROR_WOOCOMMERCE_NOT_ACTIVATED);
				return TRUE;
			}

			// Check if WooCommerce versions match when strict mode is enabled
			$headers = apache_request_headers();
			if ((1 === (int) $headers['X-Sync-Strict'] || 1 === SyncOptions::get_int('strict', 0)) && $headers['X-Woo-Commerce-Version'] !== WC()->version) {
				$response->error_code(self::ERROR_WOOCOMMERCE_VERSION_MISMATCH);
				return TRUE;            // return, signaling that the API request was processed
			}


//			if ('product' !== $post_data['post_type']) {
//SyncDebug::log(' - checking post type: ' . $post_data['post_type']);
//				$response->error_code(self::WOOCOMMERCE_INVALID_PRODUCT);
//				return;
//			}


			// check post thumbnail
			$thumbnail = $this->_api_controller->post('thumbnail', '');
			if ('' === $thumbnail) {
				// remove the thumbnail -- it's no longer attached on the Source
				delete_post_thumbnail($target_post_id);
			}


			$return = TRUE; // tell the SyncApiController that the request was handled

		} else if ('pullwoocommerce' === $action) {

			$this->_sync_model = new SyncModel();

			// process pull request
			$post_id = $this->post_int('target_post_id', 0);
SyncDebug::log(__METHOD__ . '() pull post id=' . var_export($post_id, TRUE));

			$pull_data = array();
			$this->_api = WPSiteSync_WooCommerce::get_instance()->api;
SyncDebug::log(__METHOD__ . '() !!! going to filter push_data');
			remove_filter('spectrom_sync_api_push_content', array(WPSiteSync_Pull::get_instance(), 'filter_push_data'), 1);
SyncDebug::log(__METHOD__ . '() going to set pull data=' . var_export($pull_data, TRUE));
			$pull_data = $this->_api->get_push_data($post_id, $pull_data);
SyncDebug::log(__METHOD__ . '() have pull data=' . var_export($pull_data, TRUE));

			// get product type
			$product = wc_get_product($post_id);
			$pull_data['product_type'] = $product->get_type();

			// if a variable product, add variations
			if ($product->is_type('variable')) {
				foreach ($product->get_children() as $id) {
SyncDebug::log(__METHOD__ . '() adding variation id=' . var_export($id, TRUE));
					$pull_data['product_variations'][] = $this->_api->get_push_data($id, $pull_data);
				}
			}

			$pull_data['attribute_taxonomies'] = wc_get_attribute_taxonomies();

			// send post parent and post title for groupings if listed in sync table
			if (0 !== $pull_data['post_data']['post_parent']) {
				$sync_parent_data = $this->_sync_model->get_sync_data($pull_data['post_data']['post_parent'], SyncOptions::get('site_key'), 'wooproduct');
				if (NULL !== $sync_parent_data) {
					$pull_data['grouping_parent'] = array('target_id' => $sync_parent_data->source_content_id);
				}
				$pull_data['grouping_parent']['source_title'] = get_the_title($pull_data['post_data']['post_parent']);
			}

			// process meta values
			foreach ($pull_data['post_meta'] as $meta_key => $meta_value) {

				if (NULL !== $meta_value && !empty($meta_value)) {
					switch ($meta_key) {
					case '_product_image_gallery':
						$this->_get_product_gallery($post_id, $meta_value);
						break;
					case '_upsell_ids':
					case '_crosssell_ids':
						$ids = maybe_unserialize($meta_value[0]);
						foreach ($ids as $associated_id) {
							$pull_data[$meta_key][$associated_id] = $this->_get_associated_products($associated_id, 'wooproduct', 'pull');
						}
						break;
					case '_downloadable_files';
						$this->_get_downloadable_files($post_id, $meta_value);
						break;
					case '_min_price_variation_id':
					case '_max_price_variation_id':
					case '_min_regular_price_variation_id':
					case '_max_regular_price_variation_id':
					case '_min_sale_price_variation_id':
					case '_max_sale_price_variation_id':
						$associated_id = $meta_value[0];
						$pull_data[$meta_key][$associated_id] = $this->_get_associated_products($associated_id, 'woovariableproduct', 'pull');
						break;
					default:
						break;
					}
				}
			}

			// check if any featured images or downloads in variations need to be added to queue
			if (array_key_exists('product_variations', $pull_data)) {
				foreach ($pull_data['product_variations'] as $var) {

					// process variation featured image
					if (0 != $var['thumbnail']) {
						SyncDebug::log(__METHOD__ . '() variation has thumbnail id=' . var_export($var['thumbnail'], TRUE));
						$img = wp_get_attachment_image_src($var['thumbnail'], 'full');
						if (FALSE !== $img) {
							$path = str_replace(trailingslashit(site_url()), ABSPATH, $img[0]);
							$this->_api->upload_media($var['post_data']['ID'], $path, NULL, TRUE, $var['thumbnail']);
						}
					}

					foreach ($var['post_meta'] as $meta_key => $meta_value) {
						// process downloadable files
						if ('_downloadable_files' === $meta_key && !empty($meta_value)) {
							SyncDebug::log(__METHOD__ . '() found variation downloadable files data=' . var_export($meta_value, TRUE));
							$this->_get_downloadable_files($var['post_data']['ID'], $meta_value);
						}
					}
				}
			}
SyncDebug::log(__METHOD__ . '() !!! going to filter push_data');
			add_filter('spectrom_sync_api_push_content', array(WPSiteSync_Pull::get_instance(), 'filter_push_data'), 10, 2);

			SyncDebug::log(__METHOD__ . '() pull_data=' . var_export($pull_data, TRUE));

			$response->set('pull_data', $pull_data); // add all the post information to the ApiResponse object
			$response->set('site_key', SyncOptions::get('site_key'));
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' - response data=' . var_export($response, TRUE));

			$return = TRUE; // tell the SyncApiController that the request was handled
		}

		return $return;
	}

	/**
	 * Handles the request on the Source after API Requests are made and the response is ready to be interpreted
	 *
	 * @param string $action The API name, i.e. 'push' or 'pull'
	 * @param array $remote_args The arguments sent to SyncApiRequest::api()
	 * @param SyncApiResponse $response The response object after the API request has been made
	 */
	public function api_response($action, $remote_args, $response)
	{
SyncDebug::log(__METHOD__ . "('{$action}')");

		if ('pushwoocommerce' === $action) {
SyncDebug::log(__METHOD__ . '() response from API request: ' . var_export($response, TRUE));

			$api_response = NULL;

			if (isset($response->response)) {
SyncDebug::log(__METHOD__ . '() decoding response: ' . var_export($response->response, TRUE));
				$api_response = $response->response;
			} else {
SyncDebug::log(__METHOD__ . '() no response->response element');
			}

SyncDebug::log(__METHOD__ . '() api response body=' . var_export($api_response, TRUE));

			if (0 === $response->get_error_code()) {
				$response->success(TRUE);
			}

		} else if ('pullwoocommerce' === $action) {
SyncDebug::log(__METHOD__ . '() response from API request: ' . var_export($response, TRUE));

			$api_response = NULL;

			if (isset($response->response)) {
SyncDebug::log(__METHOD__ . '() decoding response: ' . var_export($response->response, TRUE));
				$api_response = $response->response;
			} else {
SyncDebug::log(__METHOD__ . '() no response->response element');
			}

SyncDebug::log(__METHOD__ . '() api response body=' . var_export($api_response, TRUE));

			if (NULL !== $api_response) {
				$save_post = $_POST;

				// convert the pull data into an array
				$pull_data = json_decode(json_encode($api_response->data->pull_data), TRUE); // $response->response->data->pull_data;
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' - pull data=' . var_export($pull_data, TRUE));
				$site_key = $api_response->data->site_key; // $pull_data->site_key;
				$target_url = SyncOptions::get('target');
				$pull_data['site_key'] = $site_key;
				$pull_data['pull'] = TRUE;

				$_POST['post_id'] = $_REQUEST['post_id'];
				//$_POST['post_id'] = abs($api_response->data->post_data->ID);
				//$_POST['target_post_id'] = abs($_REQUEST['post_id']);    // used by SyncApiController->push() to identify target post
				$_POST['push_data'] = $pull_data;
				$_POST['action'] = 'pushwoocommerce';
				$_POST['pull_media'] = $pull_data['pull_media'];
SyncDebug::log(__METHOD__ . '() pull media: ' . var_export($_POST['pull_media'], TRUE));

				$args = array(
					'action' => 'pushwoocommerce',
					'parent_action' => 'pullwoocommerce',
					'site_key' => $site_key,
					'source' => $target_url,
					'response' => $response,
					'auth' => 0,
				);

SyncDebug::log(__METHOD__ . '() creating controller with: ' . var_export($args, TRUE));
				$this->_push_controller = new SyncApiController($args);
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' - returned from controller');
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' - response=' . var_export($response, TRUE));

				if (isset($_POST['pull_media'])) {
SyncDebug::log(__METHOD__ . '() - found ' . count($_POST['pull_media']) . ' media items');
					$this->_handle_media(intval($_POST['post_id']), $_POST['pull_media'], $response);
				}

				$_POST = $save_post;

				if (0 === $response->get_error_code()) {
					$response->success(TRUE);
				}
			}
		}
	}

	/**
	 * Performs post processing of the API response. Used as a chance to call the SyncApiController() and simulate a 'push' operation
	 * @param string $action The API action being performed
	 * @param int $post_id The post id that the action is performed on
	 * @param array $data The data returned from the API request
	 * @param SyncApiResponse $response The response object
	 */
	public function api_success($action, $post_id, $data, $response)
	{
SyncDebug::log(__METHOD__ . "('{$action}', {$post_id}, ...)");

		if ('pushwoocommerce' === $action) {
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' - data: ' . var_export($data, TRUE));
			$sync_data = array(
				'site_key' => SyncOptions::get('site_key'), //$response->response->data->site_key,
				'source_content_id' => abs($data['post_id']),
				'target_content_id' => $response->response->data->post_id,
				'target_site_key' => SyncOptions::get('target_site_key'),
				'content_type' => 'wooproduct',
			);

			$model = new SyncModel();
			$model->save_sync_data($sync_data);

			// Save variations to sync table if the product type is variable
			if ('variable' === $response->response->data->product_type) {
SyncDebug::log(__METHOD__ . '(): variable product');
				foreach ($response->response->data->variations as $variation) {
					$variation_data = array(
						'site_key' => SyncOptions::get('site_key'), //$response->response->data->site_key,
						'source_content_id' => $variation->source_id,
						'target_content_id' => $variation->target_id,
						'target_site_key' => SyncOptions::get('target_site_key'),
						'content_type' => 'woovariableproduct',
					);
					$model->save_sync_data($variation_data);
				}
			}
		}
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
	 * Returns a post object for a given post title
	 * @param string $title The post_title value to search for
	 * @return WP_Post|NULL The WP_Post object if the title is found; otherwise NULL.
	 */
	private function _get_product_by_title($title)
	{
		global $wpdb;

		$sql = "SELECT `ID`
				FROM `{$wpdb->posts}`
				WHERE `post_title`=%s
				AND (`post_type`='product' OR `post_type`='product_variation')
				LIMIT 1";
		$res = $wpdb->get_results($wpdb->prepare($sql, $title), OBJECT);
SyncDebug::log(__METHOD__ . '() ' . $wpdb->last_query . ': ' . var_export($res, TRUE));

		if (1 == count($res)) {
			$post_id = $res[0]->ID;
SyncDebug::log('- post id=' . $post_id);
			$post = get_post($post_id, OBJECT);

			return $post;
		}
		return NULL;
	}

	/**
	 * Add attributes to product
	 *
	 * @param int $post_id The target post id
	 * @param array $attributes Product attributes
	 */
	private function _process_attributes($post_id, $attributes)
	{
		$attributes = maybe_unserialize(stripslashes($attributes));
		$product_attributes_data = array();
		$attribute_taxonomies = $this->post_raw('attribute_taxonomies', array());
SyncDebug::log(__METHOD__ . '() attributes: ' . var_export($attributes, TRUE));
SyncDebug::log(__METHOD__ . '() taxonomy attributes: ' . var_export($attribute_taxonomies, TRUE));

		foreach ($attributes as $attribute_key => $attribute) {

SyncDebug::log(__METHOD__ . '() attribute: ' . var_export($attribute, TRUE));

			// check if attribute is a taxonomy
			if (1 === $attribute['is_taxonomy']) {
				global $wpdb;

				$attribute_name = str_replace('pa_', '', $attribute['name']);
				$tax_array = array();

				// get attribute taxonomy key from push_data
				foreach ($attribute_taxonomies as $key => $tax) {
					if ($tax['attribute_name'] === $attribute_name) {
						$tax_array = $attribute_taxonomies[$key];
					}
				}

				// check if attribute taxonomy already exists
				$att_tax = $wpdb->get_row($wpdb->prepare("
					SELECT *
					FROM {$wpdb->prefix}woocommerce_attribute_taxonomies
					WHERE attribute_name = %s
				 ", $attribute_name));

SyncDebug::log(__METHOD__ . '() found attribute taxonomy: ' . var_export($att_tax, TRUE));

				if (NULL === $att_tax || is_wp_error($att_tax)) {
					// add attribute taxonomy if it doesn't exist
					$args = array(
						'attribute_label' => $tax_array['attribute_label'],
						'attribute_name' => $tax_array['attribute_name'],
						'attribute_type' => $tax_array['attribute_type'],
						'attribute_orderby' => $tax_array['attribute_orderby'],
						'attribute_public' => $tax_array['attribute_public'],
					);

					$insert = $wpdb->insert(
						$wpdb->prefix . 'woocommerce_attribute_taxonomies',
						$args,
						array('%s', '%s', '%s', '%s', '%d')
					);

					$id = $wpdb->insert_id;
				} else {
					$id = $att_tax->id;
				}

SyncDebug::log(__METHOD__ . '() attribute taxonomy id: ' . var_export($id, TRUE));
			}

			$product_attributes_data[$attribute_key] = array(
				'name' => $attribute['name'],
				'value' => $attribute['value'],
				'position' => $attribute['position'],
				'is_visible' => $attribute['is_visible'],
				'is_variation' => $attribute['is_variation'],
				'is_taxonomy' => $attribute['is_taxonomy'],
			);
		}

		update_post_meta($post_id, '_product_attributes', $product_attributes_data);

		flush_rewrite_rules();
		delete_transient('wc_attribute_taxonomies');
	}

	/**
	 * Process variations
	 *
	 * @param int $post_id Target site post id
	 * @param array $variations Product variations
	 * @return array $variation_ids New variation ids
	 */
	private function _process_variations($post_id, $variations)
	{
		$variation_data = array();
		$variation_ids = array();
		$post = NULL;

		foreach ($variations as $variation_index => $variation) {
SyncDebug::log('   adding variation id ' . var_export($variation['post_data']['ID'], TRUE));
			$post_data = $variation['post_data'];
			$index = $variation_index + 1;
			$sync_data = NULL;
			$post = NULL;
			$variation_post_id = 0;

			// check sync table for variations
			$sync_data = $this->_sync_model->get_sync_data($post_data['ID'], $this->_api_controller->source_site_key, 'woovariableproduct');
SyncDebug::log('   variation sync_data: ' . var_export($sync_data, TRUE));
			if (NULL !== $sync_data) {
SyncDebug::log(' - found target post #' . $sync_data->target_content_id);
				$post = get_post($sync_data->target_content_id);
				$variation_post_id = $sync_data->target_content_id;
			}

			// add or update variation
			if (NULL !== $post) {
SyncDebug::log(' ' . __LINE__ . ' - check permission for updating post id#' . $post->ID);
				// make sure the user performing API request has permission to perform the action
				if ($this->_api_controller->has_permission('edit_posts', $post->ID)) {
					$variation_post_id = $post->ID;
					$post_data['post_title'] = 'Variation #' . $index . ' of ' . count($variations) . ' for product #' . $post_id;
					$post_data['post_name'] = 'product-' . $post_id . '-variation-' . $index;
					$post_data['post_parent'] = $post_id;
					$post_data['guid'] = home_url() . '/?product_variation=product-' . $post_id . '-variation-' . $index;
					wp_update_post($post_data, TRUE);
				}
			} else {
SyncDebug::log(' - check permission for creating new variation from source id#' . $post_data['ID']);
				if ($this->_api_controller->has_permission('edit_posts')) {
					// copy to new array so ID can be unset
					$new_post_data = $post_data;
					unset($new_post_data['ID']);
					$new_post_data['post_title'] = 'Variation #' . $index . ' of ' . count($variations) . ' for product #' . $post_id;
					$new_post_data['post_name'] = 'product-' . $post_id . '-variation-' . $index;
					$new_post_data['post_parent'] = $post_id;
					$new_post_data['guid'] = home_url() . '/?product_variation=product-' . $post_id . '-variation-' . $index;
					$variation_post_id = wp_insert_post($new_post_data);
				}
			}

			foreach ($variation['post_meta'] as $meta_key => $meta_value) {
				foreach ($meta_value as $value) {
SyncDebug::log(' adding variation meta value ' . var_export($value, TRUE));
					update_post_meta($variation_post_id, $meta_key, maybe_unserialize(stripslashes($value)));
				}
			}

			// save the source and target post information for later reference
			$save_sync = array(
				'site_key' => $this->_api_controller->source_site_key,
				'source_content_id' => $post_data['ID'],
				'target_content_id' => $variation_post_id,
				'content_type' => 'woovariableproduct',
			);
			$this->_sync_model->save_sync_data($save_sync);

			$variation_ids[] = $variation_post_id;
			$variation_data[] = array( 'target_id' => $variation_post_id, 'source_id' => $post_data['ID']);
		}

		// delete variations if not in current sync data
		$args = array(
			'post_type' => 'product_variation',
			'post_status' => array('private', 'publish'),
			'numberposts' => -1,
			'post_parent' => $post_id,
		);
		$existing_variations = new WP_Query($args);
		if ( $existing_variations->have_posts() ) {
			while ($existing_variations->have_posts()) {
				$existing_variations->the_post();
SyncDebug::log(' found existing variation ' . var_export(get_the_ID(), TRUE));
				if (!in_array(get_the_ID(), $variation_ids, TRUE)) {
SyncDebug::log(' deleting variation id ' . var_export(get_the_ID(), TRUE));
					wp_delete_post(get_the_ID());
					$this->_sync_model->remove_sync_data(get_the_ID(), 'woovariableproduct');
				}
			}
		}
		wp_reset_postdata();

		return $variation_data;
	}

	/**
	 * Register new taxonomy for new attributes
	 *
	 * @since 1.0.0
	 * @param string $attribute_name WooCommerce taxonomy attribute
	 */
	private function _register_taxonomy($attribute_name)
	{
		$permalinks = get_option('woocommerce_permalinks');

		$taxonomy_data = array(
			'hierarchical' => TRUE,
			'update_count_callback' => '_update_post_term_count',
			'show_ui' => FALSE,
			'query_var' => TRUE,
			'rewrite' => array(
				'slug' => empty($permalinks['attribute_base']) ? '' : trailingslashit($permalinks['attribute_base']) . sanitize_title($attribute_name),
				'with_front' => FALSE,
				'hierarchical' => TRUE
			),
			'sort' => FALSE,
			'public' => TRUE,
			'show_in_nav_menus' => FALSE,
			'capabilities' => array(
				'manage_terms' => 'manage_product_terms',
				'edit_terms' => 'edit_product_terms',
				'delete_terms' => 'delete_product_terms',
				'assign_terms' => 'assign_product_terms',
			)
		);

		register_taxonomy($attribute_name, array('product'), $taxonomy_data );
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
	 * Change the content type for get_sync_data
	 *
	 * @since 1.0.0
	 * @return string
	 */
	public function change_media_content_type_variable()
	{
		return 'woovariableproduct';
	}

	/**
	 * Callback used to add product gallery to the data being sent with an image upload
	 * @param array $fields An array of data fields being sent with the image in an 'upload_media' API call
	 * @return array The modified media data, with the post id included
	 */
	public function filter_upload_media_fields($fields)
	{
SyncDebug::log(__METHOD__ . " media fields:" . __LINE__ . ' fields= ' . var_export($fields, TRUE));
		$fields['product_gallery'] = 1;
		return $fields;
	}

	/**
	 * Callback used to add downloadable to the data being sent with an image upload
	 * @param array $fields An array of data fields being sent with the image in an 'upload_media' API call
	 * @return array The modified media data, with the post id included
	 */
	public function filter_downloadable_upload_media_fields($fields)
	{
SyncDebug::log(__METHOD__ . " media fields:" . __LINE__ . ' fields= ' . var_export($fields, TRUE));
		$fields['downloadable'] = 1;
		return $fields;
	}

	/**
	 * Callback for 'spectrom_sync_media_processed', called from SyncApiController->upload_media()
	 *
	 * @param int $target_post_id The Post ID of the Content being pushed
	 * @param int $attach_id The attachment's ID
	 * @param int $media_id The media id
 */
	public function media_processed($target_post_id, $attach_id, $media_id)
	{
SyncDebug::log(__METHOD__ . "({$target_post_id}, {$attach_id}, {$media_id}):" . __LINE__ . ' post= ' . var_export($_POST, TRUE));
		$this->_sync_model = new SyncModel();
		$this->_api_controller = SyncApiController::get_instance();

		// if a downloadable product, replace the url with new URL
		$downloadable = $this->get_int('downloadable', 0);
		if (0 === $downloadable && isset($_POST['downloadable']))
			$downloadable = (int)$_POST['downloadable'];

		if (1 === $downloadable) {
			$this->_process_downloadable_files($target_post_id, $attach_id, $media_id);
		}

		// if the media was in a product image gallery, replace old id with new id or add to existing
		$product_gallery = $this->get_int('product_gallery', 0);
		if (0 === $product_gallery && isset($_POST['product_gallery']))
			$product_gallery = (int)$_POST['product_gallery'];

		if (1 === $product_gallery) {
			$this->_process_product_gallery_image($target_post_id, $attach_id, $media_id);
			return;
		}

		// check for variation product if no target post id was found and set as featured image
		if (0 === $target_post_id) {
			$site_key = $this->_api_controller->source_site_key;
			$sync_data = $this->_sync_model->get_sync_data($_POST['post_id'], $site_key, 'woovariableproduct');
			$new_variation_id = $sync_data->target_content_id;
SyncDebug::log(__METHOD__ . " processing variation image:" . __LINE__ . ' new id= ' . var_export($new_variation_id, TRUE));
			if (NULL !== $sync_data && 0 !== $media_id) {
SyncDebug::log(__METHOD__ . '():' . __LINE__ . " update_post_meta($new_variation_id, '_thumbnail_id', {$media_id})");
				update_post_meta($new_variation_id, '_thumbnail_id', $media_id);
			}
		}
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
	 * Process Product Gallery Post Meta
	 *
	 * @since 1.0.0
	 * @param int $post_id The source site post id
	 * @param array $meta_value The post meta value
	 */
	private function _get_product_gallery($post_id, $meta_value)
	{
		//$this->_api = WPSiteSync_WooCommerce::get_instance()->api;
		$ids = explode(',', $meta_value[0]);
		foreach ($ids as $image_id) {
SyncDebug::log(__METHOD__ . '() adding product image id=' . var_export($image_id, TRUE));
			$img = wp_get_attachment_image_src($image_id, 'full', FALSE);
			if (FALSE !== $img) {
				add_filter('spectrom_sync_upload_media_fields', array(&$this, 'filter_upload_media_fields'), 10, 1);
				$this->_api->send_media($img[0], $post_id, 0, $image_id);
				remove_filter('spectrom_sync_upload_media_fields', array(&$this, 'filter_upload_media_fields'));
			}
		}
	}

	/**
	 * Process downloadable files
	 *
	 * @since 1.0.0
	 * @param int $post_id The source site post id
	 * @param array $meta_value The post meta value
	 */
	private function _get_downloadable_files($post_id, $meta_value)
	{
SyncDebug::log(__METHOD__ . '() found downloadable files data=' . var_export($meta_value, TRUE));
		$files = maybe_unserialize($meta_value[0]);
		foreach ($files as $file_key => $file) {
SyncDebug::log(__METHOD__ . '() file=' . var_export($file['file'], TRUE));
			$file_id = attachment_url_to_postid($file['file']);
			add_filter('spectrom_sync_upload_media_fields', array(&$this, 'filter_downloadable_upload_media_fields'), 10, 1);
			$this->_api->send_media($file['file'], $post_id, 0, $file_id);
			remove_filter('spectrom_sync_upload_media_fields', array(&$this, 'filter_downloadable_upload_media_fields'));
		}
	}

	/**
	 * Process associated products
	 *
	 * @since 1.0.0
	 * @param int $associated_id Product ID
	 * @param string $type Product type
	 * @param string $action Pull or Push being processed
	 * @return array $push_data
	 */
	private function _get_associated_products($associated_id, $type = 'wooproduct', $action = 'push')
	{
		$associated = array();
		$this->_sync_model = new SyncModel();

		if ('pull' === $action) {
			$sync_data = $this->_sync_model->get_sync_data($associated_id, SyncOptions::get('site_key'), $type);
			if (NULL !== $sync_data) {
				$associated['target_id'] = $sync_data->source_content_id;
			}
		} else {
			$sync_data = $this->_sync_model->get_sync_target_post($associated_id, SyncOptions::get('target_site_key'), $type);
			if (NULL !== $sync_data) {
				$associated['target_id'] = $sync_data->target_content_id;
			}
		}

		$associated['source_title'] = get_the_title($associated_id);

		return $associated;
	}

	/**
	 * Handle media file transfers during 'pull' operations
	 * @param int $source_post_id The post ID on the Source
	 * @param array $media_items The $_POST['pull_media'] data
	 * @param SyncApiResponse $response The response instance
	 * // @todo remove?
	 */
	private function _handle_media($source_post_id, $media_items, $response)
	{
		// adopted from SyncApiController::upload_media()

		/*		The media data - built in SyncApiRequest->_upload_media()
					'name' => 'value',
					'post_id' => 219,
					'featured' => 0,
					'boundary' => 'zLR%keXstULAd!#89fmZIq2%',
					'img_path' => '/path/to/wp/wp-content/uploads/2016/04',
					'img_name' => 'image-name.jpg',
					'img_url' => 'http://target.com/wp-content/uploads/2016/04/image-name.jpg',
					'attach_id' => 277,
					'attach_desc' => '',
					'attach_title' => 'image-name',
					'attach_caption' => '',
					'attach_name' => 'image-name',
					'attach_alt' => '',
		 */
		// check that user can upload files
		if (!current_user_can('upload_files')) {
			$response->notice_code(self::NOTICE_CANNOT_UPLOAD_WOOCOMMERCE);
		}

		require_once(ABSPATH . 'wp-admin/includes/image.php');
		require_once(ABSPATH . 'wp-admin/includes/file.php');
		require_once(ABSPATH . 'wp-admin/includes/media.php');

		add_filter('wp_handle_upload', array(SyncApiController::get_instance(), 'handle_upload'));

		// TODO: check uploaded file contents to ensure it's an image
		// https://en.wikipedia.org/wiki/List_of_file_signatures

		$upload_dir = wp_upload_dir();
SyncDebug::log(__METHOD__ . '() upload dir=' . var_export($upload_dir, TRUE));
		foreach ($media_items as $media_file) {
			// check if this is the featured image
			$featured = isset($media_file['featured']) ? intval($media_file['featured']) : 0;
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' featured=' . $featured);

			// move remote file to local site
			$path = $upload_dir['basedir'] . '/' . $media_file['img_name']; // tempnam(sys_get_temp_dir(), 'snc');
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' work file=' . $path . ' url=' . $media_file['img_url']);
			file_put_contents($path, file_get_contents($media_file['img_url']));
			$temp_name = tempnam(sys_get_temp_dir(), 'syn');
SyncDebug::log(__METHOD__ . '() temp name=' . $temp_name);
			copy($path, $temp_name);

			// check file type
			$img_type = wp_check_filetype($path);
			$mime_type = $img_type['type'];
			$basename = basename($media_file['img_name'], '.' . $img_type['ext']);
SyncDebug::log(__METHOD__ . '() found image type=' . $img_type['ext'] . '=' . $img_type['type']);
			if ((FALSE === strpos($mime_type, 'image/') && 'pdf' !== $img_type['ext']) ||
			apply_filters('spectrom_sync_upload_media_allowed_mime_type', FALSE, $img_type)
			) {
				$response->error_code(SyncApiRequest::ERROR_INVALID_IMG_TYPE);
				$response->send();
			}

			global $wpdb;
			$sql = "SELECT `ID`
						FROM `{$wpdb->posts}`
						WHERE `post_name`=%s AND `post_type`='attachment'";
			$res = $wpdb->get_col($wpdb->prepare($sql, $basename));
			$attachment_id = 0;
			if (0 != count($res))
				$attachment_id = intval($res[0]);
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' attach id=' . $attachment_id);

			$target_post_id = intval($media_file['post_id']);

			$this->media_id = 0;
			$this->local_media_name = '';

			// set this up for wp_handle_upload() calls
			$overrides = array(
				'test_form' => FALSE,            // really needed because we're not submitting via a form
				'test_size' => FALSE,            // don't worry about the size
				'unique_filename_callback' => array(SyncApiController::get_instance(), 'unique_filename_callback'),
				'action' => 'wp_handle_sideload', // 'wp_handle_upload',
			);

			// check if attachment exists
			if (0 !== $attachment_id) {
				// if it's the featured image, set that
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' checking featured image - source=' . $source_post_id . ' attach=' . $attachment_id);
				if ($featured && 0 !== $source_post_id)
					set_post_thumbnail($source_post_id, $attachment_id);
			} else {
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' found no image - adding to library');
				$time = str_replace('\\', '/', substr($media_file['img_path'], -7));
				$_POST['action'] = 'wp_handle_upload';        // shouldn't have to do this with $overrides['test_form'] = FALSE
				$_POST['action'] = 'wp_handle_sideload';
				// construct the $_FILES element
				$file_info = array(
					'name' => $media_file['img_name'],
					'type' => $img_type['type'],
					'tmp_name' => $temp_name,
					'error' => 0,
					'size' => filesize($path),
				);
				$_FILES['sync_file_upload'] = $file_info;
SyncDebug::log(' files=' . var_export($_FILES, TRUE));
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' sending to wp_handle_upload(): ' . var_export($file_info, TRUE));
				$file = wp_handle_upload($file_info, $overrides, $time);

SyncDebug::log(__METHOD__ . '() returned: ' . var_export($file, TRUE));
				if (!is_array($file) || isset($file['error'])) {

					$has_error = TRUE;
					$response->notice_code(SyncApiRequest::ERROR_FILE_UPLOAD, $ret->get_error_message());
				} else {
					$upload_file = $upload_dir['baseurl'] . '/' . $time . '/' . basename($file['file']);

					$attachment = array(        // create attachment for our post
						'post_title' => $media_file['attach_title'],
						'post_name' => $media_file['attach_name'],
						'post_content' => $media_file['attach_desc'],
						'post_excerpt' => $media_file['attach_caption'],
						'post_status' => 'inherit',
						'post_mime_type' => $file['type'],    // type of attachment
						'post_parent' => $source_post_id,    // post id
						'guid' => $upload_file,
					);
SyncDebug::log(__METHOD__ . '() insert attachment parameters: ' . var_export($attachment, TRUE));
					$attach_id = wp_insert_attachment($attachment, $file['file'], $source_post_id);    // insert post attachment
SyncDebug::log(__METHOD__ . "() wp_insert_attachment([..., '{$file['file']}', {$source_post_id}) returned {$attach_id}");
					$attach = wp_generate_attachment_metadata($attach_id, $file['file']);    // generate metadata for new attacment
SyncDebug::log(__METHOD__ . "() wp_generate_attachment_metadata({$attach_id}, '{$file['file']}') returned " . var_export($attach, TRUE));
					update_post_meta($attach_id, '_wp_attachment_image_alt', $media_file['attach_alt'], TRUE);
					wp_update_attachment_metadata($attach_id, $attach);
					$this->media_id = $attach_id;

					// if it's the featured image, set that
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' featured=' . $featured . ' source=' . $source_post_id . ' attach=' . $attach_id);
					if ($featured && 0 !== $source_post_id) {
SyncDebug::log(__METHOD__ . "() set_post_thumbnail({$source_post_id}, {$attach_id})");
						set_post_thumbnail($source_post_id, $attach_id);
					}
				}
			}

SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' removing work file ' . $path . ' and temp file ' . $temp_name);
			unlink($path);
			if (file_exists($temp_name))
				unlink($temp_name);

			do_action('spectrom_sync_media_processed', $source_post_id, $attachment_id, $this->media_id);
		}
	}

	/**
	 * Process associated products
	 *
	 * @since 1.0.0
	 * @param $target_ids
	 * @param $meta_key
	 * @param $meta_source_id
	 * @param $new_meta_ids
	 * @return array
	 */
	private function _process_associated_products($target_ids, $meta_key, $meta_source_id, $new_meta_ids)
	{
		$new_id = NULL;
		if (array_key_exists('target_id', $target_ids[$meta_key][$meta_source_id])) {
SyncDebug::log(' - found push target post #' . $target_ids[$meta_key][$meta_source_id]['target_id']);
			$meta_post = get_post($target_ids[$meta_key][$meta_source_id]['target_id']);
		}
		// lookup source_id in sync table
		if (NULL === $meta_post) {
			$sync_data = $this->_sync_model->get_sync_data($meta_source_id, $this->_api_controller->source_site_key, 'wooproduct');
			if (NULL !== $sync_data) {
SyncDebug::log(' - found target post #' . $sync_data->target_content_id);
				$new_id = $sync_data->target_content_id;
			} else {
				// if no match, check for matching title
SyncDebug::log(' - still no product found - look up by title');
				$meta_post = $this->_get_product_by_title($target_ids[$meta_key][$meta_source_id]['source_title']);
				if (NULL !== $meta_post) {
					$new_id = $meta_post->ID;
				}
			}
		} else {
			$new_id = $meta_post->ID;
		}
		if (NULL !== $new_id) {
			$new_meta_ids[] = $new_id;
		}
		return $new_meta_ids;
	}

	/**
	 * Process variation ids
	 *
	 * @since 1.0.0
	 * @param $target_post_id
	 * @param $meta_value
	 * @param $source_id
	 * @return int|null
	 */
	private function _process_variation_ids($meta_value, $source_id)
	{
SyncDebug::log(__METHOD__ . '() source id: ' . var_export($source_id, TRUE));
SyncDebug::log(__METHOD__ . '() meta value: ' . var_export($meta_value, TRUE));
		$new_id = NULL;
		if (array_key_exists('target_id', $meta_value[$source_id])) {
SyncDebug::log(' - found target post #' . $smeta_value[$source_id]['target_id']);
			$meta_post = get_post($meta_value[$source_id]['target_id']);
		}
		// lookup source_id in sync table
		if (NULL === $meta_post) {
			$sync_data = $this->_sync_model->get_sync_data($source_id, $this->_api_controller->source_site_key, 'woovariableproduct');
			if (NULL !== $sync_data) {
SyncDebug::log(' - found target post #' . $sync_data->target_content_id);
				$new_id = $sync_data->target_content_id;
				return $new_id;
			} else {
				// if no match, check for matching title
SyncDebug::log(' - still no product found - look up by title');
				$meta_post = $this->_get_product_by_title($meta_value[$source_id]['source_title']);
				if (NULL !== $meta_post) {
					$new_id = $meta_post->ID;
					return $new_id;
				}
				return $new_id;
			}
		} else {
			$new_id = $meta_post->ID;
			return $new_id;
		}
	}

	/**
	 * Process product gallery
	 *
	 * @since 1.0.0
	 * @param $target_post_id
	 * @param $attach_id
	 * @param $media_id
	 * @return void
	 */
	private function _process_product_gallery_image($target_post_id, $attach_id, $media_id)
	{
		$old_attach_id = $this->get_int('attach_id', 0);
		if (0 === $old_attach_id)
			$old_attach_id = abs($_POST['attach_id']);
		$gallery_ids = explode(',', get_post_meta($target_post_id, '_product_image_gallery', TRUE));

SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' post id=' . $target_post_id . ' old_attach_id=' . $old_attach_id . ' attach_id=' . $attach_id . ' gallery_ids=' . var_export($gallery_ids, TRUE));
		if (in_array($old_attach_id, $gallery_ids)) {
			foreach ($gallery_ids as $key => $id) {
				if ($old_attach_id == $id) {
					if (0 === $attach_id) {
						$gallery_ids[$key] = $media_id;
					} else {
						$gallery_ids[$key] = $attach_id;
					}
				}
			}
			$gallery_ids = implode(',', $gallery_ids);
			update_post_meta($target_post_id, '_product_image_gallery', $gallery_ids);
		} else {
			$gallery_ids = implode(',', array_push($gallery_ids, $attach_id));
			update_post_meta($target_post_id, '_product_image_gallery', $gallery_ids);
		}
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
SyncDebug::log(__METHOD__ . "({$source_post_id}, {$target_post_id})");

		$taxonomies = $this->post_raw('attribute_taxonomies', array());

		foreach ($taxonomies as $taxonomy) {
			if (! taxonomy_exists('pa_' . $taxonomy['attribute_name'])) {
				$this->_register_taxonomy('pa_' . $taxonomy['attribute_name']);
			}
		}
	}

	/**
	 * Process downloadable files
	 *
	 * @since 1.0.0
	 * @param $target_post_id
	 * @param $attach_id
	 * @param $media_id
	 */
	private function _process_downloadable_files($target_post_id, $attach_id, $media_id)
	{
		if (0 === $target_post_id) {
			$site_key = $this->_api_controller->source_site_key;
			$sync_data = $this->_sync_model->get_sync_data($_POST['post_id'], $site_key, 'woovariableproduct');
			$target_post_id = $sync_data->target_content_id;
		}
		$old_attach_id = $this->get_int('attach_id', 0);
		if (0 === $old_attach_id)
			$old_attach_id = abs($_POST['attach_id']);
		$downloads = get_post_meta($target_post_id, '_downloadable_files', TRUE);
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' downloadable file target id=' . $target_post_id . ' old_attach_id=' . $old_attach_id . ' attach_id=' . $attach_id . ' downloads=' . var_export($downloads, TRUE));
		foreach ($downloads as $key => $download) {
			if ($download['file'] === $_POST['img_url']) {
				// get new attachment url
				if (0 === $attach_id) {
					$downloads[$key]['file'] = wp_get_attachment_url($media_id);
				} else {
					$downloads[$key]['file'] = wp_get_attachment_url($attach_id);

				}
			}
		}

		// update post meta
SyncDebug::log(__METHOD__ . '():' . __LINE__ . " update_post_meta($target_post_id, '_downloadable_files', {$downloads})");
		update_post_meta($target_post_id, '_downloadable_files', $downloads);
	}
}

// EOF
