<?php

class SyncWooCommerceSourceApi extends SyncInput
{
	public $variations = 0;													// number of variations associated with this product
	private $_api;															// reference to SyncApiRequest
	private $_response = NULL;												// reference to SyncApiResponse
	private $_sync_model = NULL;											// reference to SyncModel
	private $_processing_variations = FALSE;								// set to TRUE when processing variable products
	private $_thumb_id = NULL;												// thumbnail id used to pass to gutenberg_attachment_block()

	// used for tracking product and taxonomy IDs referenced within shortcodes
	private $_product_shortcode_ids = array();								// list of product IDs referenced in shortcodes
	private $_category_shortcode_ids = array();								// list of category IDs referenced in shortcodes
	private $_tag_shortcode_ids = array();									// list of tag IDs referenced in shortcodes

	private $_block_names = NULL;											// array of block names (keys) from $gutenberg_props


	public function __construct()
	{
		$this->_block_names = array_keys(SyncWooCommerceApiRequest::$gutenberg_props);
		$this->_sync_model = new SyncModel();
	}

	/**
	 * Callback for filtering the post data before it's sent to the Target. Here we check for additional data needed.
	 * @param array $data The data being Pushed to the Target machine
	 * @param SyncApiRequest $apirequest Instance of the API Request object
	 * @return array The modified data
	 */
	public function filter_push_content($data, $apirequest)
	{
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' data=' . var_export($data, TRUE));

		if ($this->_processing_variations) {
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' processing variations...skipping');
			return $data;
		}

//		if (!WPSiteSyncContent::get_instance()->get_license()->check_license('sync_woocommerce', WPSiteSyncContent_WooCommerce::PLUGIN_KEY, WPSiteSyncContent_WooCommerce::PLUGIN_NAME))
//			return $data;

		$post_id = 0;
		$action = 'push';
		// TODO: use SyncApiController->get_parent_action()
		if (isset($data['post_id']))					// present on Push operations
			$post_id = abs($data['post_id']);
		else if (isset($data['post_data']['ID'])) {		// present on Pull operations
			$post_id = abs($data['post_data']['ID']);
			$action = 'pull';
		}

		// check the post_type. If not a product, no further processing required
		$post_type = NULL;
		if (isset($data['post_data']['post_type']))
			$post_type = $data['post_data']['post_type'];
		if (NULL === $post_type)
			$post_type = get_post_type($post_id);
		if ('product' !== $post_type)
			return $data;

SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' filtering push content=' . var_export($data, TRUE));
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' for post id=' . var_export($post_id, TRUE));
		// meeded by other private methods so save this in class instance
		$this->_api = $apirequest;
		$this->_response = $this->_api->get_response();

		$site_url = site_url();
		$data['source_domain'] = site_url();
		$apirequest->set_source_domain($site_url);

		// get target post id from synced data
		if (NULL !== ($sync_data = $this->_sync_model->get_sync_target_post($post_id, SyncOptions::get('target_site_key'), 'post'))) {
			$data['target_post_id'] = $sync_data->target_content_id;
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' found product target post id=' . var_export($data['target_post_id'], TRUE));
		}

		// get product type
		$product = wc_get_product($post_id);
		$data['product_type'] = $product->get_type();
		$this->start_time = microtime();

		// if variable product, add variations
		if ($product->is_type('variable')) {
			$this->_processing_variations = TRUE;
			// NOTE: filter is hooked to WPSiteSync_WooCommerce, not $this
//			remove_filter('spectrom_sync_api_push_content', array($this, 'filter_push_content'));

			$ids = $product->get_children();
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' ids=' . var_export($ids, TRUE));
			$this->variations = count($ids);

			// use the SyncWooCommerceApiRequest::OFFSET_INCREMENT to get a 'chunk' of the array of variations
			$offset = $this->post_int('offset', 0);
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' offset=' . $offset . ' incr=' .  SyncWooCommerceApiRequest::OFFSET_INCREMENT . ' count=' . $this->variations);
			if ($offset + SyncWooCommerceApiRequest::OFFSET_INCREMENT >= count($ids)) {
				// last batch of variations. include list of IDs so Target can remove deleted variations srs#15.c.ii.4
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' constructing variation_list for deletion on Target');
//				$all_ids = array();
//				foreach ($ids as $var) {
//					$all_ids[] = abs($var['ID']);
//				}
				$data['variation_list'] = $ids;
			}
			$slice = array_slice($ids, $offset, SyncWooCommerceApiRequest::OFFSET_INCREMENT);
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' found ' . $this->variations . '; offset=' . $offset . ' incr=' . SyncWooCommerceApiRequest::OFFSET_INCREMENT . ' slice:' . var_export($slice, TRUE));
			$ids = $slice;

			// get information on each of the variations using SyncApiRequest::get_push_data()
//			add_filter('spectrom_sync_allowed_post_types', array($this, 'filter_allowed_post_types'), 10, 1);
			foreach ($ids as $key => $id) {
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' adding variation id=' . var_export($id, TRUE));
				// the SyncApiRequest->_post_data is reset inside get_push_data()
//				$this->_api->clear_post_data();
				// TODO: do these have their own dependencies, taxonomies, etc?
//				$temp_data = array();
//				$data['product_variations'][] = $this->_api->get_push_data($id, $temp_data);
				$var_data = get_post($id, ARRAY_A);
				$meta_data = get_post_meta($id);
				// TODO: push images
				$data['product_variations'][] = array('post_data' => $var_data, 'post_meta' => $meta_data);
			}
//			$this->_api->set_post_data($data);
			$this->_processing_variations = FALSE;
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' variation data=' . var_export($data['product_variations'], TRUE));
		}

		// process meta values
		foreach ($data['post_meta'] as $meta_key => $meta_value) {
			if (NULL !== $meta_value && !empty($meta_value)) {
				switch ($meta_key) {
				case '_product_image_gallery':
					$this->_get_product_gallery($post_id, $meta_value);
					break;

				case '_children':
				case '_upsell_ids':
				case '_crosssell_ids':
					$ids = maybe_unserialize($meta_value[0]);
					foreach ($ids as $associated_id) {
						$data[$meta_key][$associated_id] = $this->_get_associated_products($associated_id, $action);
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
					$data[$meta_key][$associated_id] = $this->_get_associated_products($associated_id, $action);
					break;

				case '_product_attributes':
					$attributes = maybe_unserialize($meta_value);
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' checking product attributes ' . var_export($attributes, TRUE));
#					$taxonomies = $attributes[0];
#					foreach ($taxonomies as $tax_name => $tax_data) {
#						$taxname = $tax_data['name'];
#						if ('pa_' === substr($taxname, 0, 3)) {
#							$terms = get_the_terms($post_id, $taxname);
#							foreach ($terms as $tax_term) {
#								$data['product_attributes'][] = $tax_term;
#							}
#						}
#					}

//SyncDebug::log(__METHOD__.'():' . __LINE__ . ' offset=' . $offset);
					// include a list of Product Attributes #12
#					$pa_terms = get_the_terms($product_id, $tax);
#					if (0 === $offset) {
						// only provide list on first Push in the case of Variable Products
						$pa_taxonomies = wc_get_attribute_taxonomies();
						$data['product_attribute_taxonomies'] = $pa_taxonomies;
#					}
					break;
				}
			}
		}

		// check if any featured images or downloads in variations need to be added to queue
		if (array_key_exists('product_variations', $data)) {
			foreach ($data['product_variations'] as $var) {
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' product variation: ' . var_export($var, TRUE));
				// process variation featured image
				$thumb_id = 0;
				if (isset($var['thumbnail']))
					$thumb_id = abs($var['thumbnail']);
				if (0 !== $thumb_id) {
					// TODO: use upload_media_by_id()
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' variation has thumbnail id=' . var_export($var['thumbnail'], TRUE));
					$img = wp_get_attachment_image_src($var['thumbnail'], 'full');
					if (FALSE !== $img) {
						$path = str_replace(trailingslashit(site_url()), ABSPATH, $img[0]);
						$this->_api->upload_media($var['post_data']['ID'], $path, NULL, TRUE, $var['thumbnail']);
					}
				}

				foreach ($var['post_meta'] as $meta_key => $meta_value) {
					// process downloadable files
					if ('_downloadable_files' === $meta_key && !empty($meta_value)) {
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' found variation downloadable files data=' . var_export($meta_value, TRUE));
						$this->_get_downloadable_files($var['post_data']['ID'], $meta_value);
					}
				} // foreach
			} // foreach ['product_variations']
		} // array_key_exists('product_variations')

		$data['attribute_taxonomies'] = wc_get_attribute_taxonomies();

		$data['woo_settings'] = array(
			'weight_unit' => get_option('woocommerce_weight_unit', 'kg'),
			'dimension_unit' => get_option('woocommerce_dimension_unit', 'cm'),
		);

		// TODO: check contents of WPSiteSync_WooCommerce->$_category_shortcode_ids and _tag_shortcode_ids

SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' data=' . var_export($data, TRUE));
		return $data;
	}

	/**
	 * Add the 'product_variation' post type to allowed post type list
	 * @param array $post_types The current allowed post types
	 * @return array Allowed post type list with 'product_variation' added
	 */
	public function filter_allowed_post_types($post_types)
	{
		// TODO: likely not needed since a product_variation will not be pushed by itself
		$post_types[] = 'product_variation';
		return $post_types;
	}

	/**
	 * Callback for the 'spectrom_sync_api_response' action called after API result is returned from Target
	 * @param SyncApiResponse $response Instance of the response object that will be returned to the browser
	 * @param string $action The API action code
	 * @param array $data The data sent to the Target via the API call
	 */
	public function filter_api_response($response, $action, $data)
	{
		// the $variations property is set when processing a variable product
		if (0 !== $this->variations) {
			$offset = $this->post_int('offset', 0);
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' checking progress, offset=' . $offset . ' incr=' . SyncWooCommerceApiRequest::OFFSET_INCREMENT . ' count=' . $this->variations);
			if ($offset + SyncWooCommerceApiRequest::OFFSET_INCREMENT >= $this->variations) {
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' completed with all variations');
			} else {
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' continuing push process...');
				$pcnt = floor((($offset + SyncWooCommerceApiRequest::OFFSET_INCREMENT) * 100) / $this->variations);
				$elapsed = microtime() - $this->start_time;

				$response->notice_code(SyncWooCommerceApiRequest::NOTICE_PARTIAL_VARIATION_UPDATE);
				$response->set('variations', $this->variations);
				$response->set('offset_increment', SyncWooCommerceApiRequest::OFFSET_INCREMENT);
				$response->set('percent', $pcnt);
				$response->set('elapsed', $elapsed);
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' var=' . $this->variations . ' incr=' . SyncWooCommerceApiRequest::OFFSET_INCREMENT . ' pcnt=' . $pcnt . ' elapsed=' . $elapsed);
			}
		}
	}

	/**
	 * Checks the content of shortcodes, looking for Product references that have not yet
	 * been Pushed and taxonomy information that needs to be added to the Push content.
	 * @param string $shortcode The name of the shortcode being processed by SyncApiRequest::_process_shortcodes()
	 * @param SyncShortcodeEntry $sce An instance that contains information about the shortcode being processed, including attributes and values
	 * @param SyncApiResponse $apiresponse An instance that can be used to force errors if Products are referenced and not yet Pushed.
	 */
	public function check_shortcode_content($shortcode, SyncShortcodeEntry $sce, SyncApiResponse $apiresponse)
	{
		// check shortcode content to ensure everything's already been pushed srs#10
		$products = array();
		$categories = array();
		$tags = array();

		switch ($shortcode) {
		case 'product':
			if ($sce->has_attribute('ids'))
				$products[] = $sce->get_attribute('ids');
			if ($sce->has_attribute('id'))
				$products[] = $sce->get_attribute('id');
			break;
		case 'product_page':
		case 'add_to_cart':
		case 'add_to_cart_url':
			if ($sce->has_attribute('id'))
				$products[] = $sce->get_attribute('id');
			break;
		case 'product_category':			// ids, category, parent
		case 'products':					// ids, category, tag
			if ($sce->has_attribute('ids'))
				$products[] = $sce->get_attribute('ids');
			if ($sce->has_attribute('category'))
				$categories[] = $sce->get_attribute('category');
			if ($sce->has_attribute('parent'))
				$categories[] = $sce->get_attribute('parent');
			if ($sce->has_attribute('tag'))
				$tags[] = $sce->get_attribute('tag');
			break;
		case 'product_categories':
			if ($sce->has_attribute('ids'))
				$categories[] = $sce->get_attribute('ids');
			break;
		case 'recent_products':
			if ($sce->has_attribute('category'))
				$categories[] = $sce->get_attribute('category');
			break;
		case 'recent_products':
		case 'sale_products':
		case 'best_selling_products':
		case 'top_rated_products':
		case 'featured_products':
			if ($sce->has_attribute('category'))
				$categories[] = $sce->get_attribute('category');
			break;
		}


		// check all product IDs referenced
		foreach ($products as $id_list) {
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' checking products: ' . var_export($products, TRUE));
			$id_list = explode(',', $id_list);
			foreach ($id_list as $prod_id) {
				$prod_id = abs($prod_id);
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' checking product id#' . $prod_id);
				if (!in_array($prod_id, $this->_product_shortcode_ids)) {
					$this->_product_shortcode_ids[] = $prod_id;
					$sync_data = $this->_sync_model->get_sync_target_post($prod_id, SyncOptions::get('target_site_key'));
					if (NULL === $sync_data) {
						$apiresponse->error_code(SyncWooCommerceApiRequest::ERROR_WOOCOMMERCE_DEPENDENT_PRODUCT_NOT_PUSHED, $prod_id);
						break;
					}
				}
			}
		}
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' checked products: ' . implode(';', $this->_product_shortcode_ids));

		// check all categories referenced
		// these don't do anything except add to the _category_short_ids and _tag_shortcode_ids array
		// which are using during the '' filter to add final taxonomy information to the post_data before the Push API call
		foreach ($categories as $id_list) {
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' checking categories: ' . var_export($categories, TRUE));
			$id_list = explode(',', $id_list);
			foreach ($id_list as $cat_id) {
				$cat_id = abs($cat_id);
				if (!in_array($cat_id, $this->_category_shortcode_ids)) {
					// build this list so these taxonomies can be added to the Push data
					$this->_category_shortcode_ids[] = $cat_id;
				}
			}
		}
		foreach ($tags as $id_list) {
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' checking tags: ' . var_export($tags, TRUE));
			$id_list = explode(',', $id_list);
			foreach ($id_list as $tag_id) {
				$tag_id = abs($tag_id);
				if (!in_array($tag_id, $this->_tag_shortcode_ids)) {
					// build this list so these taxonomies can be added to the Push data
					$this->_tag_shortcode_ids[] = $tag_id;
				}
			}
		}
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
//SyncDebug::log(__METHOD__.'():' . __LINE__ . ' block name=' . $block_name);
		// the block name is found within our list of known block types to update
		$obj = json_decode($json);
//if ('wp:uagb/info-box' === $block_name) SyncDebug::log(__METHOD__.'():' . __LINE__ . ' found json block data for "' . $block_name . '" : ' . var_export($obj, TRUE));

		if (!empty($json) && NULL !== $obj) {
			// this block has a JSON object embedded within it
			$props = explode('|', SyncWooCommerceApiRequest::$gutenberg_props[$block_name]);
//SyncDebug::log(__METHOD__.'():' . __LINE__ . ' props=' . var_export($props, TRUE));
			foreach ($props as $property) {
				// for each property listed in the $gutenberg_props array, look to see if it refers to an image ID
				$ref_ids = array();
				$gbentry = new SyncGutenbergEntry($property); // $apirequest->parse_property($property);
//					$this->_parse_property($property);
//						$prop_name = $this->_prop_name;

				if ($gbentry->prop_array) {								// property denotes an array reference
					if (isset($obj->{$gbentry->prop_list[0]})) {			// make sure property exists
//SyncDebug::log(__METHOD__.'():' . __LINE__ . ' checking array: "' . $gbentry->prop_list[0] . '"');
						$idx = 0;
						foreach ($obj->{$gbentry->prop_list[0]} as $entry) {
							$ref_id = abs($gbentry->get_val($entry, $idx));
//SyncDebug::log(__METHOD__.'():' . __LINE__ . ' source ref=' . var_export($ref_id, TRUE));
							if (0 !== $ref_id)
								$ref_ids[] = $ref_id;
							++$idx;
						}
					}
				} else {												// not an array reference, look up single property
					$ref_id = abs($gbentry->get_val($obj));
//SyncDebug::log(__METHOD__.'():' . __LINE__ . ' source ref=' . var_export($ref_id, TRUE));
					if (0 !== $ref_id)
						$ref_ids[] = $ref_id;
				}
//SyncDebug::log(__METHOD__.'():' . __LINE__ . ' found property "' . $prop_name . '" referencing ids ' . implode(',', $ref_ids));

				switch ($gbentry->prop_type) {
				case SyncGutenbergEntry::PROPTYPE_IMAGE:
					// get the thumbnail id if we haven't already
					if (NULL === $this->_thumb_id)			// if the thumb id hasn't already been determined, get it here
						$this->_thumb_id = abs(get_post_thumbnail_id($source_post_id));

					// now go through the list. it's a list since Ultimate Addons uses arrays for some of it's block data
					foreach ($ref_ids as $ref_id) {
						if (0 !== $ref_id) {
							// the property has a non-zero value, it's an image reference
							if (FALSE === $apirequest->gutenberg_attachment_block($ref_id, $source_post_id, $this->_thumb_id, $block_name)) {
								// TODO: error recovery
							}
						} // 0 !== $ref_id
					}
					break;
				case SyncGutenbergEntry::PROPTYPE_LINK:
					break;
				case SyncGutenbergEntry::PROPTYPE_POST:
					$apirequest->trigger_push_complete();
					break;
				case SyncGutenbergEntry::PROPTYPE_USER:
					break;
				case SyncGutenbergEntry::PROPTYPE_TAX:
					break;
				}
			} // foreach
		} // !empty($json)
//SyncDebug::log(__METHOD__.'():' . __LINE__ . ' exiting parse_gutenberg_block()');
	}

	/**
	 * Process downloadable files
	 * @param int $post_id The source site post id
	 * @param array $meta_value The post meta value
	 */
	private function _get_downloadable_files($post_id, $meta_value)
	{
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' found downloadable files data=' . var_export($meta_value, TRUE));
		$files = maybe_unserialize($meta_value[0]);
		foreach ($files as $file_key => $file) {
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' file=' . var_export($file['file'], TRUE));
			$file_id = attachment_url_to_postid($file['file']);
			add_filter('spectrom_sync_upload_media_fields', array($this, 'filter_downloadable_upload_media_fields'), 10, 1);
			$this->_api->send_media($file['file'], $post_id, 0, $file_id);
			remove_filter('spectrom_sync_upload_media_fields', array($this, 'filter_downloadable_upload_media_fields'));
		}
	}

	/**
	 * Process Product Gallery Post Meta
	 * @param int $post_id The source site post id
	 * @param array $meta_value The post meta value
	 */
	private function _get_product_gallery($post_id, $meta_value)
	{
		$ids = explode(',', $meta_value[0]);
		foreach ($ids as $image_id) {
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' adding product image id=' . var_export($image_id, TRUE));
			$img = wp_get_attachment_image_src($image_id, 'full', FALSE);
			if (FALSE !== $img) {
				add_filter('spectrom_sync_upload_media_fields', array($this, 'filter_upload_media_fields'), 10, 1);
				// TODO: use send_media_by_id()
				$this->_api->send_media($img[0], $post_id, 0, $image_id);
				remove_filter('spectrom_sync_upload_media_fields', array($this, 'filter_upload_media_fields'));
			}
		}
	}

	/**
	 * Process associated products
	 * @param int $associated_id Product ID
	 * @param string $action Pull or Push being processed
	 * @return array $push_data
	 */
	// TODO: remove $action parameter; use SyncApiController->get_parent_action()
	private function _get_associated_products($associated_id, $action = 'push')
	{
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' associated id: ' . var_export($associated_id, TRUE));
		$associated = array();

		if ('pull' === $action) {
			$sync_data = $this->_sync_model->get_sync_data($associated_id, SyncOptions::get('target_site_key'));
			if (NULL !== $sync_data) {
				$associated['target_id'] = $sync_data->source_content_id;
			} else {
				$this->_response->error_code(SyncWooCommerceApiRequest::ERROR_WOOCOMMERCE_DEPENDENT_PRODUCT_NOT_PUSHED, $associated_id);
			}
		} else {
			$sync_data = $this->_sync_model->get_sync_target_post($associated_id, SyncOptions::get('target_site_key'));
			if (NULL !== $sync_data) {
				$associated['target_id'] = $sync_data->target_content_id;
			} else {
				$this->_response->error_code(SyncWooCommerceApiRequest::ERROR_WOOCOMMERCE_DEPENDENT_PRODUCT_NOT_PUSHED, $associated_id);
			}
		}

		$associated['source_title'] = get_the_title($associated_id);

		return $associated;
	}

	/**
	 * Callback used to add downloadable to the data being sent with an image upload
	 * @param array $fields An array of data fields being sent with the image in an 'upload_media' API call
	 * @return array The modified media data, with the post id included
	 */
	public function filter_downloadable_upload_media_fields($fields)
	{
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' media fields= ' . var_export($fields, TRUE));
		$fields['downloadable'] = 1;
		return $fields;
	}

	/**
	 * Callback used to add product gallery to the data being sent with an image upload
	 * @param array $fields An array of data fields being sent with the image in an 'upload_media' API call
	 * @return array The modified media data, with the post id included
	 */
	public function filter_upload_media_fields($fields)
	{
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' media fields= ' . var_export($fields, TRUE));
		$fields['product_gallery'] = 1;
		return $fields;
	}
}

// EOF
