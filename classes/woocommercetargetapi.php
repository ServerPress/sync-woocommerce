<?php

class SyncWooCommerceTargetApi extends SyncInput
{
	private $_api;															// reference to SyncApiRequest
	private $_sync_model;													// reference to SyncModel
	private $_api_controller;												// reference to SyncApiController
	private $_response = NULL;												// used to set response value

	private $_block_names = NULL;											// array of block names (keys) from $gutenberg_props

	const TIME_THRESHHOLD = 15000;											// process for 15 seconds at a time

	public function __construct()
	{
		$this->_block_names = array_keys(SyncWooCommerceApiRequest::$gutenberg_props);
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
SyncDebug::log(__METHOD__ . "({$source_post_id}, {$target_post_id}):" . __LINE__);
		// check for WC existence and post_type='product'
		if ('product' !== $post_data['post_type']) {
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' post type="' . $post_data['post_type'] . '" ... skipping');
			return TRUE;
		}
		if (!class_exists('WooCommerce', FALSE) || !function_exists('WC')) {
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' WC does not exist');
			$response->error_code(SyncWooCommerceApiRequest::ERROR_WOOCOMMERCE_NOT_ACTIVATED);
			return TRUE;
		}

		if (!WPSiteSyncContent::get_instance()->get_license()->check_license('sync_woocommerce', WPSiteSync_WooCommerce::PLUGIN_KEY, WPSiteSync_WooCommerce::PLUGIN_NAME))
			return $data;

		// check if currency settings match #20
		$currency = $this->post('currency', '');
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' source currency=' . $currency . ' target currency=' . get_woocommerce_currency());
		if (get_woocommerce_currency() !== $currency) {
			$response->error_code(SyncWooCommerceApiRequest::ERROR_WOOCOMMERCE_CURRENCY_MISMATCH);
			return TRUE;				// return, signaling that the API request was processed
		}

		// check for strict mode and version mismatch
		if (1 === SyncOptions::get_int('strict', 0)) {
			if (SyncApiController::get_instance()->get_header(SyncWooCommerceApiRequest::HEADER_WOOCOMMERCE_VERSION) !== $GLOBALS['woocommerce']->version) {
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' strict mode and versions do not match');
				$response->error_code(SyncWooCommerceApiRequest::ERROR_WOOCOMMERCE_VERSION_MISMATCH);
				return TRUE;
			}

			// check for overwriting product tax status when calc taxes is disabled on Target #19
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' calc taxes=' . get_option('woocommerce_calc_taxes'));
			if ('yes' !== get_option('woocommerce_calc_taxes')) {
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' tax_class=' . var_export($_POST['post_meta']['_tax_class'], TRUE));
				if (isset($_POST['post_meta']) && isset($_POST['post_meta']['_tax_class'])) {
					$tax_class = implode('.', $_POST['post_meta']['_tax_class']);
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' $tax_class="' . $tax_class . '"');
					if (!empty($_POST['post_meta']['_tax_class']) && !empty($tax_class)) {
						$response->notice_code(SyncWooCommerceApiRequest::NOTICE_WOOCOMMERCE_NOT_CALC_TAXES);
					}
				}
			}
		}

		// check if calc taxes status is different and display warning #19
		if (get_option('woocommerce_calc_taxes') !== $this->post('calctaxes') &&
			!$response->has_notices()) {
			$response->notice_code(SyncWooCommerceApiRequest::NOTICE_CALC_TAXES_DIFFERENT);
		}

		$taxonomies = $this->post_raw('attribute_taxonomies', array());

		foreach ($taxonomies as $taxonomy) {
			if (! taxonomy_exists('pa_' . $taxonomy['attribute_name'])) {
				$this->_register_taxonomy('pa_' . $taxonomy['attribute_name']);
			}
		}
	}

	/**
	 * Register new taxonomy for new attributes
	 * @param string $attribute_name WooCommerce taxonomy attribute
	 */
	// TODO: verify that we need to register the taxonomy
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

		register_taxonomy($attribute_name, array('product'), $taxonomy_data);
	}

	/**
	 * Handles fixup of data on the Target after SyncApiController has finished processing Content.
	 * @param int $target_post_id The post ID being created/updated via API call
	 * @param array $post_data Post data sent via API call
	 * @param SyncApiResponse $response Response instance
	 */
	public function handle_push($target_post_id, $post_data, $response)
	{
SyncDebug::log(__METHOD__ . "({$target_post_id}):" . __LINE__);

		if (!class_exists('WooCommerce', FALSE) || !function_exists('WC')) {
			$response->error_code(SyncWooCommerceApiRequest::ERROR_WOOCOMMERCE_NOT_ACTIVATED);
			return TRUE;
		}

		if ('product' !== $post_data['post_type'])
			return;										// don't need to do anything if it's not a 'product' post type
		if (!WPSiteSyncContent::get_instance()->get_license()->check_license('sync_woocommerce', WPSiteSync_WooCommerce::PLUGIN_KEY, WPSiteSync_WooCommerce::PLUGIN_NAME))
			return;

		// check if WooCommerce versions match when strict mode is enabled
		if (1 === SyncOptions::get_int('strict', 0) && SyncApiController::get_instance()->get_header(SyncWooCommerceApiRequest::HEADER_WOOCOMMERCE_VERSION) !== WC()->version) {
			$response->error_code(SyncWooCommerceApiRequest::ERROR_WOOCOMMERCE_VERSION_MISMATCH);
			return TRUE;			// return, signaling that the API request was processed
		}

		// Check if WooCommerce dimension units match
		$units = $this->post('woo_settings', array());
		if (get_option('woocommerce_dimension_unit', 'cm') !== $units['dimension_unit'] || get_option('woocommerce_weight_unit', 'kg') !== $units['weight_unit']) {
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' source weight: ' . var_export(get_option('woocommerce_dimension_unit', 'cm'), TRUE));
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' target weight: ' . var_export($units['dimension_unit'], TRUE));

			$response->error_code(SyncWooCommerceApiRequest::ERROR_WOOCOMMERCE_UNIT_MISMATCH);
			return TRUE;				// return, signaling that the API request was processed
		}

		add_filter('spectrom_sync_upload_media_allowed_mime_type', array(WPSiteSync_WooCommerce::get_instance(), 'filter_allowed_mime_type'), 10, 2);

SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' found post_data information: ' . var_export($post_data, TRUE));

		$this->_api = new SyncApiRequest();
		$this->_sync_model = new SyncModel();
		$this->_api_controller = SyncApiController::get_instance();
		$this->_response = $response;

		// set source domain- needed for handling media operations
		$this->_api->set_source_domain($this->post_raw('source_domain', ''));
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' source domain: ' . var_export($this->post_raw('source_domain', ''), TRUE));

		$product_type = $this->post_raw('product_type', '');
		$response->set('product_type', $product_type);
		$post_meta = $this->post_raw('post_meta', array());
global $wpdb;
$sql = "select * from `{$wpdb->prefix}term_relationships` where `object_id` = {$target_post_id}";
$res = $wpdb->get_results($sql);
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' checking taxonomy- before ' . var_export($res, TRUE));
wp_set_object_terms($target_post_id, $product_type, 'product_type', TRUE);
$res = $wpdb->get_results($sql);
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' checking taxonomy- after ' . var_export($res, TRUE));

		// sync metadata
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' handling meta data');

		foreach ($post_meta as $meta_key => $meta_value) {
			// loop through meta_value array
			if ('_product_attributes' === $meta_key) {
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' processing product attributes: ');
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' meta value: ' . var_export($meta_value, TRUE));
				$this->_process_attributes($target_post_id, $meta_value[0]);
			} else {
				foreach ($meta_value as $value) {
					$value = maybe_unserialize(stripslashes($value));
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' meta value ' . var_export($value, TRUE));
					switch ($meta_key) {
					case '_children':
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
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' updating post_meta for #' . $target_post_id . ' key="' . $meta_key . '" value=' . var_export($new_id));
						update_post_meta($target_post_id, $meta_key, $new_id);
						break;
					}
				}
			}
		}

$res = $wpdb->get_results($sql);
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' checking taxonomy- end ' . var_export($res, TRUE));

		$product_variations = $this->post_raw('product_variations', array());
		if (!empty($product_variations)) {
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' processing variations');
			$variations = $this->_process_variations($target_post_id, $product_variations);
			$response->set('variations', $variations);				// set variations return data
		}

		// is there anything to delete? srs#15.c.ii.4
		// TODO: move into _process_variations()
		if (isset($_POST['variation_list'])) {
			// handle deletion of any variations removed on Source
			$product = wc_get_product($target_post_id);
			// a list of current product variations for the parent Product ID
			$current_variations = $product->get_children();
			$variation_ids = array_keys($current_variations);
			// convert the list of Source Variation post IDs to Target post IDs
			$source_variations = $this->post_raw('variation_list', array());
			$target_variations = array();
			foreach ($source_variations as $source_var_id) {
				$source_var_id = abs($source_var_id);
				$sync_data = $this->_sync_model->get_sync_data($source_var_id, $this->_api_controller->source_site_key);
				if (NULL === $sync_data) {
					$response->error_code(SyncWooCommerceApiRequest::ERROR_WOOCOMMERCE_TARGET_VARIATION_MISSING, $source_var_id);
				} else {
					$target_variations[] = abs($sync_data->target_content_id);
				}
			}
			// the difference in these lists are the variations that are to be deleted
			$delete_list = array_diff($target_variations, $variation_ids);
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' deleting variation ids: ' . implode(',' ,$delete_list));
			foreach ($delete_list as $delete_id) {
				wp_delete_post($delete_id);
				// TODO: remove any images
				$this->_sync_model->remove_sync_data($delete_id, 'post');
			}
		}

$res = $wpdb->get_results($sql);
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' checking taxonomy- end ' . var_export($res, TRUE));

		// if handling Simple or first Variable Product, check for attribute taxonomies #12
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' checking for attribute taxonomies');
		if (isset($_POST['product_attribute_taxonomies']) && function_exists('wc_create_attribute')) {
			$target_attributes = wc_get_attribute_taxonomies();
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' existing attributes: ' . var_export($target_attributes, TRUE));
			$source_attributes = $this->post_raw('product_attribute_taxonomies', array());

			// save taxonomy information. this is ugly but it allows the wc_create_attribute() call to work
			global $wp_taxonomies;
			$save_wp_taxonomies = $wp_taxonomies;

			foreach ($source_attributes as $source_attr) {
				$found = NULL;
				foreach ($target_attributes as $target_attr) {
//SyncDebug::log(__METHOD__.'():' . __LINE__ . ' searching attributes source=' . $source_attr['name'] . ' target=' . $target_attr->name);
//SyncDebug::log(__METHOD__.'():' . __LINE__ . ' searching attributes source=' . var_export($source_attr, TRUE) . ' target=' . var_export($target_attr, TRUE));
					if ($source_attr['attribute_name'] == $target_attr->attribute_name) {		// `name` is the only indexed column
						$found = $target_attr;
						$attr_id = abs($target_attr->attribute_id);
//SyncDebug::log(__METHOD__.'():' . __LINE__ . ' found match id=' . $attr_id);
						break;
					}
				}

				// setup attributes prior to helper function call
				$attr_args = array(
					'name' => $source_attr['attribute_name'],
					'label' => $source_attr['attribute_label'],
					'type' => $source_attr['attribute_type'],
					'orderby' => $source_attr['attribute_orderby'],
					'public' => $source_attr['attribute_public'],
				);

				// remove tax name so wc_update_attribute() will work
				$attr_tax_name = wc_attribute_taxonomy_name( $attr_args['name'] );
				unset($wp_taxonomies[$attr_tax_name]);
				$tax_exists = taxonomy_exists( $attr_tax_name );
//SyncDebug::log(__METHOD__.'():' . __LINE__ . ' attr tax name=' . var_export($attr_tax_name, TRUE) . ' tax_exists=' . var_export($tax_exists, TRUE));

				// helper functions clear attribute transients so no need to do this ourselves
				if (NULL === $found) {
					// Product Attribute not found - create it
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' creating attribute ' . var_export($attr_args, TRUE));
					$res = wc_create_attribute($attr_args);
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' res=' . var_export($res, TRUE));
				} else {
					// update the existing Product Attribute data
					$target_attr = $found;					// rename for clarity
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' updating attribute #' . $target_attr->attribute_id . '/' . $attr_id . '=' . var_export($attr_args, TRUE));

					$res = wc_update_attribute($attr_id, $attr_args);
//SyncDebug::log(__METHOD__.'():' . __LINE__ . ' res=' . var_export($res, TRUE));
// if ( ( 0 === $id && taxonomy_exists( wc_attribute_taxonomy_name( $slug ) ) ) ||
//	( isset( $args['old_slug'] ) && $args['old_slug'] !== $slug && taxonomy_exists( wc_attribute_taxonomy_name( $slug ) ) ) ) {
				}
			}
			// restore taxonomy array
			$wp_taxonomies = $save_wp_taxonomies;

			// wc_create_attribute()
			// wc_delete_attribute()
		}

$res = $wpdb->get_results($sql);
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' checking taxonomy- end ' . var_export($res, TRUE));

		// clear transients and other objects srs#15.d
		wc_delete_product_transients($target_post_id);

		// check for updating the lookup tables https://woocommerce.wordpress.com/2019/04/01/performance-improvements-in-3-6/
		if (version_compare($GLOBALS['woocommerce']->version, '3.6', '>=')) {
/*			if (class_exists('WC_Data_Store_WP', FALSE)) {
				require_once(__DIR__ . DIRECTORY_SEPARATOR . 'woocommercedatastore.php');
				$wcds = new SyncWooCommerceDataStore();
				$wcds->update_table($target_post_id);		// calls WC_Data_Store_WP::update_lookup_table($target_post_id);
			} */

$res = $wpdb->get_results($sql);
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' checking taxonomy- end ' . var_export($res, TRUE));

			// possible product types: 'simple', 'grouped', 'external', 'variable', 'virtual', 'downloadable'
			$factory = new WC_Product_Factory();
			$product = $factory->get_product($target_post_id);
			$type = $product->get_type();

$res = $wpdb->get_results($sql);
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' checking taxonomy- end ' . var_export($res, TRUE));

			// use the product type specific data store classes to force updates of lookup tables
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' updating lookup table for product #' . $target_post_id . ' type "' . $type . '"');
			switch ($type) {
			case 'simple':
			case 'external':
				$ds = new WC_Product_Data_Store_CPT();
				$ds->update($product);
				$ds->update_product_stock($target_post_id);
				$ds->update_product_sales($target_post_id);
				break;
			case 'grouped':
				$ds = new WC_Product_Grouped_Data_Store_CPT();
				$ds->sync_price($product);
//				$ds->update_post_meta($product, TRUE);
//				$ds->handle_updated_props($product);
				break;
			case 'variable':
				$ds = new WC_Product_Variable_Data_Store_CPT();
				$ds->sync_price($product);
				$ds->sync_managed_variation_stock_status($product);
//				$ds->update_post_meta($product, TRUE);
				break;
			default:
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' product type "' . $type . '" is not recognized');
			}
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' lookup table update complete');

$res = $wpdb->get_results($sql);
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' checking taxonomy- end ' . var_export($res, TRUE));
		} // version_compare('3.6')
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
SyncDebug::log(__METHOD__.'():' . __LINE__);
		// look for known block names
		if (in_array($block_name, $this->_block_names)) {
			// check to see if it's one of the form block names; skip those

			$obj = json_decode($json);
			if (!empty($json) && NULL !== $obj) {
				$updated = FALSE;
				if (NULL === $this->_sync_model) {
					// create instance if not already set
					$this->_api_controller = SyncApiController::get_instance();
					$this->_sync_model = new SyncModel();
				}

				// only need to process block names that are in our known list
				if (isset(SyncWooCommerceApiRequest::$gutenberg_props[$block_name])) {
					$props = explode('|', SyncWooCommerceApiRequest::$gutenberg_props[$block_name]);
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' props=' . var_export($props, TRUE));
					foreach ($props as $property) {
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' examining property "' . $property . '"');
						// check for each property name found within the block's data
						$gb_entry = new SyncGutenbergEntry($property);	// $this->_parse_property($property);
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' gb entry ' . var_export($gb_entry, TRUE));
//						$prop_name = $this->_prop_name;

SyncDebug::log(__METHOD__.'():' . __LINE__ . ' json=' . $json);
						if ($gb_entry->prop_array) {							// property denotes an array reference
							$max = 1;
							if ($gb_entry->prop_array) {
								$max = $gb_entry->array_size($obj);
							}
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' array size=' . $max);

/*							$prop_arr = array();
							if (isset($obj->{$gb_entry->prop_name})) {
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' prop_name=' . $gb_entry->prop_name);
								$prop_arr = $gb_entry->get_val($obj, $idx);
							} else {
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' prop array=' . implode('.', $gb_entry->prop_list));
								if (isset($obj->{$gb_entry->prop_list[0]}))		// make sure property exists
									$prop_arr = $obj->{$gb_entry->prop_list[0]};
							} */

							for ($idx = 0; $idx < $max; $idx++) {
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' idx=' . $idx);
								$source_ref_id = $gb_entry->get_val($obj, $idx);
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' source ref=' . var_export($source_ref_id, TRUE));
								if (0 !== $source_ref_id) {
									// get the Target's post ID from the Source's post ID
									$target_ref_id = $gb_entry->get_target_ref($source_ref_id);
									if (FALSE !== $target_ref_id) {
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' updating Source ID ' . $source_ref_id . ' to Target ID ' . $target_ref_id);
										$gb_entry->set_val($obj, $target_ref_id, $idx);
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' obj=' . var_export($obj, TRUE));
										$updated = TRUE;
									}
								}
							} // foreach
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' done with list');
						} else {												// scaler reference
							$source_ref_id = $gb_entry->get_val($obj);
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' source ref=' . var_export($source_ref_id, TRUE));
							if (0 !== $source_ref_id) {
								// get the Target's post ID from the Source's post ID
								$target_ref_id = $gb_entry->get_target_ref($source_ref_id);
								if (FALSE !== $target_ref_id) {
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' updating Source ID ' . $source_ref_id . ' to Target ID ' . $target_ref_id);
									$gb_entry->set_val($obj, $target_ref_id);
									$updated = TRUE;
								}
							}
						}
					} // foreach

					if ($updated) {
						// one or more properties were updated with their Target post ID
						// values. update the content with the new JSON data
						$new_obj_data = json_encode($obj, JSON_UNESCAPED_SLASHES);
						$content = substr($content, 0, $start) . $new_obj_data . substr($content, $end + 1);
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' original: ' . $json . PHP_EOL . ' updated: ' . $new_obj_data);
					}

					// check for any block specific updates
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' block content update check for ' . $block_name);
					switch ($block_name) {
					case 'wp:woocommerce/reviews-by-product':
						$gb_entry = new SyncGutenbergEntry('productId:p');
						// need to update data-product-id="{product ID}" attribute references within generated block content
						$product_id = abs($gb_entry->get_val($obj));
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' prod id ' . $product_id);
						if (0 !== $product_id && FALSE !== strpos($content, 'data-product-id=')) {
//							$sync_data = $this->_sync_model->get_sync_data($product_id, $this->_api_controller->source_site_key);
							$sync_data = $this->_sync_model->get_source_from_target($product_id, $this->_api_controller->source_site_key);
							if (NULL !== $sync_data) {
								$source_attribute = ' data-product-id="' . $sync_data->source_content_id . '"';
								$target_attribute = ' data-product-id="' . $product_id . '"';
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' updating [' . $source_attribute . '] to [' . $target_attribute . ']');
								$content = str_replace($source_attribute, $target_attribute, $content);
							}
						}
						break;
					}
				} // isset(SyncWooCommerceApiRequest::$gutenberg_props[$block_name])
			} // !empty($json)
		} // in_array($block_name, SyncWooCommerceApiRequest::$gutenberg_props)
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' returning');
		return $content;
	}

	/**
	 * Add attributes to product
	 * @param int $post_id The target post id
	 * @param array $attributes Product attributes
	 */
	private function _process_attributes($post_id, $attributes)
	{
		$attributes = maybe_unserialize(stripslashes($attributes));
		$product_attributes_data = array();
		$attribute_taxonomies = $this->post_raw('attribute_taxonomies', array());
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' attributes: ' . var_export($attributes, TRUE));
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' taxonomy attributes: ' . var_export($attribute_taxonomies, TRUE));

		foreach ($attributes as $attribute_key => $attribute) {
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' attribute: ' . var_export($attribute, TRUE));

			// check if attribute is a taxonomy
			if (1 === $attribute['is_taxonomy']) {
				WPSiteSync_WooCommerce::get_instance()->load_class('woocommercemodel');
				$woo_model = new SyncWooCommerceModel();

				$attribute_name = str_replace('pa_', '', $attribute['name']);
				$tax_array = array();

				// get attribute taxonomy key from push_data
				foreach ($attribute_taxonomies as $key => $tax) {
					if ($tax['attribute_name'] === $attribute_name) {
						$tax_array = $attribute_taxonomies[$key];
					}
				}

				// check if attribute taxonomy already exists
				$att_tax = $woo_model->get_attribute_taxonomy($attribute_name);

SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' found attribute taxonomy: ' . var_export($att_tax, TRUE));

				if (NULL === $att_tax || is_wp_error($att_tax)) {
					// add attribute taxonomy if it doesn't exist
					$args = array(
						'attribute_label' => $tax_array['attribute_label'],
						'attribute_name' => $tax_array['attribute_name'],
						'attribute_type' => $tax_array['attribute_type'],
						'attribute_orderby' => $tax_array['attribute_orderby'],
						'attribute_public' => $tax_array['attribute_public'],
					);
					global $wpdb;

					$insert = $wpdb->insert(
						$wpdb->prefix . 'woocommerce_attribute_taxonomies',
						$args,
						array('%s', '%s', '%s', '%s', '%d')
					);
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' insert result=' . var_export($insert, TRUE));
					if (FALSE !== $insert)
						$id = $wpdb->insert_id;
				} else {
					$id = $att_tax->id;
				}
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' attribute taxonomy id: ' . var_export($id, TRUE));
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
			$post_data = $variation['post_data'];
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' adding variation id ' . var_export($post_data['ID'], TRUE));
			$index = $variation_index + 1;
			$sync_data = NULL;
			$post = NULL;
			$variation_post_id = 0;

			// check sync table for variations
			$sync_data = $this->_sync_model->get_sync_data(abs($post_data['ID']), $this->_api_controller->source_site_key);
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' variation sync_data: ' . var_export($sync_data, TRUE));
			if (NULL !== $sync_data) {
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' found target post #' . $sync_data->target_content_id);
				if (0 !== ($variation_post_id = abs($sync_data->target_content_id)))
					$post = get_post($variation_post_id);
			}

			// add or update variation
			if (NULL !== $post) {
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' check permission for updating post id#' . $post->ID);
				// make sure the user performing API request has permission to perform the action
				// TODO: move permission check outside loop and abort if no permissions exist
				if (SyncOptions::has_permission('edit_posts', $post->ID)) {
//					$variation_post_id = abs($post->ID);		// unnecessary since it's already set
					$post_data['post_title'] = 'Variation #' . $index . ' of ' . count($variations) . ' for product #' . $post_id;
					$post_data['post_name'] = 'product-' . $post_id . '-variation-' . $index;
					$post_data['post_parent'] = $post_id;
					$post_data['guid'] = home_url() . '/?product_variation=product-' . $post_id . '-variation-' . $index;
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' updating ' . var_export($post_data, TRUE));
					wp_update_post($post_data, TRUE);
				}
			} else {
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' check permission for creating new variation from source id#' . $post_data['ID']);
				if (SyncOptions::has_permission('edit_posts')) {
					// copy to new array so ID can be unset
					$new_post_data = $post_data;
					unset($new_post_data['ID']);
					$new_post_data['post_title'] = 'Variation #' . $index . ' of ' . count($variations) . ' for product #' . $post_id;
					$new_post_data['post_name'] = 'product-' . $post_id . '-variation-' . $index;
					$new_post_data['post_parent'] = $post_id;
					$new_post_data['guid'] = home_url() . '/?product_variation=product-' . $post_id . '-variation-' . $index;
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' inserting ' . var_export($new_post_data, TRUE));
					$variation_post_id = wp_insert_post($new_post_data);
				}
			}

			foreach ($variation['post_meta'] as $meta_key => $meta_value) {
				foreach ($meta_value as $value) {
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' adding variation meta value ' . var_export($value, TRUE));
					update_post_meta($variation_post_id, $meta_key, maybe_unserialize(stripslashes($value)));
				}
			}

			// save the source and target post information for this variation for later reference
			$save_sync = array(
				'site_key' => $this->_api_controller->source_site_key,
				'source_content_id' => abs($post_data['ID']),
				'target_content_id' => $variation_post_id,
				'content_type' => 'post',
			);
			$this->_sync_model->save_sync_data($save_sync);

			$variation_ids[] = $variation_post_id;
			$variation_data[] = array('target_id' => $variation_post_id, 'source_id' => $post_data['ID']);
		} // foreach ($variations)

		// delete variations if not in current sync data
		// Note: this had to be moved to handle_push since only the last variation Push has the ['variation_list'] data element
/*		$args = array(
			'post_type' => 'product_variation',
			'post_status' => array('private', 'publish'),
			'numberposts' => -1,
			'post_parent' => $post_id,
		);
		$existing_variations = new WP_Query($args);
		if ($existing_variations->have_posts()) {
			while ($existing_variations->have_posts()) {
				$existing_variations->the_post();
				$remove_id = abs(get_the_ID());
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' found existing variation ' . var_export($remove_id, TRUE));
				if (!in_array($remove_id, $variation_ids, TRUE)) {
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' deleting variation id ' . $remove_id);
					wp_delete_post($remove_id);
					$this->_sync_model->remove_sync_data($remove_id, 'post');
				}
			}
		}
//		wp_reset_postdata();	// not necessary since we're doing this during an API call not a page request
*/
		return $variation_data;
	}

	/**
	 * Callback for 'spectrom_sync_media_processed', called from SyncApiController->upload_media()
	 * @param int $target_post_id The Post ID of the Content being pushed
	 * @param int $attach_id The attachment's ID
	 * @param int $media_id The media id
	 */
	public function media_processed($target_post_id, $attach_id, $media_id)
	{
SyncDebug::log(__METHOD__ . "({$target_post_id}, {$attach_id}, {$media_id}):" . __LINE__ . ' post= ' . var_export($_POST, TRUE));
		$this->_sync_model = new SyncModel();
		$this->_api_controller = SyncApiController::get_instance();
		$action = $this->post('operation', 'push');
		$pull_media = $this->post_raw('pull_media', array());
		$post_meta = $this->post_raw('post_meta', array());
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' pull_media: ' . var_export($pull_media, TRUE));

		// if a downloadable product, replace the url with new URL
		$downloadable = $this->get_int('downloadable', 0);
		if (0 === $downloadable && isset($_POST['downloadable']))
			$downloadable = (int)$_POST['downloadable'];

		if (0 === $downloadable && 'pull' === $action && !empty($pull_media)) {
			$downloadables = maybe_unserialize($post_meta['_downloadable_files'][0]);
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' downloadables: ' . var_export($downloadables, TRUE));
			if (NULL !== $downloadables && !empty($downloadables)) {
				foreach ($pull_media as $key => $media) {
					if (array_key_exists('downloadable', $media) && 1 === $media['downloadable']) {
						$url = $media['img_url'];
						foreach ($downloadables as $download) {
							if ($url === $download['file']) {
								$downloadable = 1;
							}
						}
					}
				}
			}
		}

		if (1 === $downloadable) {
			$this->_process_downloadable_files($target_post_id, $attach_id, $media_id);
			return;
		}

		// if the media was in a product image gallery, replace old id with new id or add to existing
		$product_gallery = $this->get_int('product_gallery', 0);
		if (0 === $product_gallery && isset($_POST['product_gallery']))
			$product_gallery = $this->post_int('product_gallery', 0);

		if (0 === $product_gallery && 'pull' === $action && !empty($pull_media)) {
			$galleries = $post_meta['_product_image_gallery'];
			if (NULL !== $galleries && ! empty($galleries)) {
				foreach ($pull_media as $key => $media) {
					if (array_key_exists('product_gallery', $media) && 1 === $media['product_gallery']) {
						$old_attach_id = $media['attach_id'];
						if (in_array($old_attach_id, $galleries)) {
							$product_gallery = 1;
						}
					}
				}
			}
		}

		if (1 === $product_gallery) {
			$this->_process_product_gallery_image($target_post_id, $attach_id, $media_id);
			return;
		}

		// check for variation product if no target post id was found and set as featured image
		if (0 === $target_post_id) {
			$site_key = $this->_api_controller->source_site_key;
			$sync_data = $this->_sync_model->get_sync_data(abs($this->post_int('post_id', 0)), $site_key, 'post');
			$new_variation_id = $sync_data->target_content_id;
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' processing variation image - new id= ' . var_export($new_variation_id, TRUE));
			if (NULL !== $sync_data && 0 !== $media_id) {
SyncDebug::log(__METHOD__ . '():' . __LINE__ . " update_post_meta({$new_variation_id}, '_thumbnail_id', {$media_id})");
				update_post_meta($new_variation_id, '_thumbnail_id', $media_id);
			}
		}
	}

	/**
	 * Process associated products. Modifies the list of Source IDs for upsell/cross sell to be Target IDs
	 * @param array $target_ids The list of IDs to be updated
	 * @param string $meta_key One of the keys '_upsell_ids' or '_crosssell_ids'
	 * @param int $meta_source_id Post ID for associated product
	 * @param array $new_meta_ids List of Post IDs for the meta value on the Target
	 * @return array The updated list of meta IDs
	 */
	// TODO: rename $target_ids parameter- these are source ID values
	// TODO: make $new_meta_ids pass by reference or a class property
	private function _process_associated_products($target_ids, $meta_key, $meta_source_id, $new_meta_ids)
	{
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' meta_key=' . $meta_key . ' meta_source_id=' . $meta_source_id);
		$new_target_id = NULL;
		$meta_post = NULL;
		if (isset($target_ids[$meta_key][$meta_source_id]) && is_array($target_ids[$meta_key][$meta_source_id]) &&
			array_key_exists('target_id', $target_ids[$meta_key][$meta_source_id])) {
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' found push target post #' . $target_ids[$meta_key][$meta_source_id]['target_id']);
			$meta_post = get_post($target_ids[$meta_key][$meta_source_id]['target_id']);
		} else {
if (isset($target_ids[$meta_key][$meta_source_id]))
	SyncDebug::log(__METHOD__.'():' . __LINE__ . ' ERROR: set but not array ' . var_export($target_ids[$meta_key][$meta_source_id], TRUE));
else
	SyncDebug::log(__METHOD__.'():' . __LINE__ . ' ERROR: not set');
		}
		// lookup source_id in sync table
		if (NULL === $meta_post) {
			$sync_data = $this->_sync_model->get_sync_data($meta_source_id, $this->_api_controller->source_site_key, 'post');
			if (NULL !== $sync_data) {
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' found target post #' . $sync_data->target_content_id);
				$new_target_id = $sync_data->target_content_id;
			} else {
				// if no match, check for matching title
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' still no product found - look up by title');
				WPSiteSync_WooCommerce::get_instance()->load_class('woocommercemodel');
				$woo_model = new SyncWooCommerceModel();
				$meta_post = $woo_model->get_product_by_title($target_ids[$meta_key][$meta_source_id]['source_title']);
				if (NULL !== $meta_post) {
					$new_target_id = $meta_post->ID;
				}
			}
		} else {
			$new_target_id = $meta_post->ID; // TODO:  PHP Notice:  Undefined variable: meta_post in \wpsitesync-woocommerce\classes\woocommerceapirequest.php on line 694
		}
		if (NULL !== $new_target_id) {
			$new_meta_ids[] = $new_target_id;
		}
		return $new_meta_ids;
	}

	/**
	 * Process variation ids
	 * @param array $meta_value Array of Source product IDs
	 * @param $source_id
	 * @return int|NULL The Target variation ID on success or NULL on error
	 */
	private function _process_variation_ids($meta_value, $source_id)
	// TODO: verify parameters used
	{
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' source id: ' . var_export($source_id, TRUE) . ' meta value: ' . var_export($meta_value, TRUE));
		$new_id = NULL;
		$meta_post = NULL;
		if (array_key_exists('target_id', $meta_value[$source_id])) {
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' found target post #' . $meta_value[$source_id]['target_id']);
			$meta_post = get_post($meta_value[$source_id]['target_id']);
		}
		// lookup source_id in sync table
		if (NULL === $meta_post) {
			$sync_data = $this->_sync_model->get_sync_data($source_id, $this->_api_controller->source_site_key, 'post');
			if (NULL !== $sync_data) {
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' found target post #' . $sync_data->target_content_id);
				$new_id = $sync_data->target_content_id;
				return $new_id;
			} else {
				// if no match, check for matching title
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' still no product found - look up by title');
				WPSiteSync_WooCommerce::get_instance()->load_class('woocommercemodel');
				$woo_model = new SyncWooCommerceModel();
				$meta_post = $woo_model->get_product_by_title($meta_value[$source_id]['source_title']);
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
	 * @param int $target_post_id Target post ID of Product
	 * @param int $attach_id Post ID of the attachment
	 * @param $media_id The media ID passed to media_processed()
	 */
	private function _process_product_gallery_image($target_post_id, $attach_id, $media_id)
	{
		$old_attach_id = $this->post_int('attach_id', 0);
		$gallery_ids = explode(',', get_post_meta($target_post_id, '_product_image_gallery', TRUE));

		if (empty($gallery_ids)) {
			return;
		}

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
	 * Process downloadable files
	 * @param int $target_post_id Target post ID of the product
	 * @param int $attach_id The attachment ID
	 * @param int $media_id The media ID passed to media_processed()
	 */
	private function _process_downloadable_files($target_post_id, $attach_id, $media_id)
	{
		if (0 === $target_post_id) {
			$site_key = $this->_api_controller->source_site_key;
			// TODO: use this->post_int('post_id', 0)
			$sync_data = $this->_sync_model->get_sync_data(abs($this->post_int('post_id', 0)), $site_key, 'post');
			$target_post_id = $sync_data->target_content_id;
		}

		$old_attach_id = abs($_POST['attach_id']);
		$downloads = get_post_meta($target_post_id, '_downloadable_files', TRUE);

		if (empty($downloads) || ! is_array($downloads)) {
			return;
		}

SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' downloadable file target id=' . $target_post_id . ' old_attach_id=' . $old_attach_id . ' attach_id=' . $attach_id . ' downloads=' . var_export($downloads, TRUE));
		foreach ($downloads as $key => $download) {
			if ($download['file'] === $_POST['img_url']) {
				// get new attachment url
				$downloads[$key]['file'] = wp_get_attachment_url($media_id);
			} else if (array_key_exists('pull_media', $_POST) && ! empty($_POST['pull_media'])) {
				foreach ($_POST['pull_media'] as $media) {
					if ($download['file'] === $media['img_url']) {
						$downloads[$key]['file'] = wp_get_attachment_url($media_id);
					}
				}
			}
		}

		// update post meta
SyncDebug::log(__METHOD__ . '():' . __LINE__ . " update_post_meta({$target_post_id}, '_downloadable_files', {$downloads})");
		update_post_meta($target_post_id, '_downloadable_files', $downloads);
	}
}

// EOF
