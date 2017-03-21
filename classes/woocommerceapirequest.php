<?php

/*
 * Allows syncing of WooCommerce Product data between the Source and Target sites
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
	const NOTICE_WOOCOMMERCE_MEDIA_PERMISSION = 601;

	const HEADER_WOOCOMMERCE_VERSION = 'x-woo-commerce-version'; // WooCommerce version number; used in requests and responses

	private $_api;
	private $_sync_model;
	private $_api_controller;
	public $media_id;
	public $local_media_name;



	/**
	 * Callback for filtering the post data before it's sent to the Target. Here we check for additional data needed.
	 * @param array $data The data being Pushed to the Target machine
	 * @param SyncApiRequest $apirequest Instance of the API Request object
	 * @return array The modified data
	 */
	public function filter_push_content($data, $apirequest)
	{
		$post_id = 0;
		$action = 'push';
		if (isset($data['post_id']))					// present on Push operations
			$post_id = abs($data['post_id']);
		else if (isset($data['post_data']['ID'])) {		// present on Pull operations
			$post_id = abs($data['post_data']['ID']);
			$action = 'pull';
		}

		if ('product' !== get_post_type($post_id)) {
			return $data;
		}

SyncDebug::log(__METHOD__ . '() filtering push content=' . var_export($data, TRUE));
SyncDebug::log(__METHOD__ . '() for post id=' . var_export($post_id, TRUE));
		$this->_sync_model = new SyncModel();
		$this->_api = $apirequest;

		$site_url = site_url();
		$data['source_domain'] = site_url();
		$apirequest->set_source_domain($site_url);

		// get target post id from synced data
		if (NULL !== ($sync_data = $this->_sync_model->get_sync_target_post($post_id, SyncOptions::get('target_site_key'), 'wooproduct'))) {
			$data['target_post_id'] = $sync_data->target_content_id;
SyncDebug::log(__METHOD__ . '() found product target post id=' . var_export($data['target_post_id'], TRUE));
		}

		// get product type
		$product = wc_get_product($post_id);
		$data['product_type'] = $product->get_type();

		// if variable product, add variations
		if ($product->is_type('variable')) {
			remove_filter('spectrom_sync_api_push_content', array($this, 'filter_push_content'));

			$ids = $product->get_children();

			foreach ($ids as $key => $id) {
SyncDebug::log(__METHOD__ . '() adding variation id=' . var_export($id, TRUE));
				$data['product_variations'][] = $this->_api->get_push_data($id, $data);
			}
		}

		// process meta values
		foreach ($data['post_meta'] as $meta_key => $meta_value) {
			if (NULL !== $meta_value && !empty($meta_value)) {
				switch ($meta_key) {
				case '_product_image_gallery':
					$this->_get_product_gallery($post_id, $meta_value);
					break;

				case '_upsell_ids':
				case '_crosssell_ids':
					$ids = maybe_unserialize($meta_value[0]);
					foreach ($ids as $associated_id) {
						$data[$meta_key][$associated_id] = $this->_get_associated_products($associated_id, 'wooproduct', $action);
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
					$data[$meta_key][$associated_id] = $this->_get_associated_products($associated_id, 'woovariableproduct', $action);
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
	 * Handles fixup of data on the Target after SyncApiController has finished processing Content.
	 * @param int $target_post_id The post ID being created/updated via API call
	 * @param array $post_data Post data sent via API call
	 * @param SyncApiResponse $response Response instance
	 */
	public function handle_push($target_post_id, $post_data, $response)
	{
SyncDebug::log(__METHOD__ . "({$target_post_id})");

		if ('product' !== $post_data['post_type']) {
			return;
		}

		// Check if WooCommerce versions match when strict mode is enabled
		if (1 === SyncOptions::get_int('strict', 0) && SyncApiController::get_instance()->get_header(self::HEADER_WOOCOMMERCE_VERSION) !== WC()->version) {
			$response->error_code(self::ERROR_WOOCOMMERCE_VERSION_MISMATCH);
			return TRUE;			// return, signaling that the API request was processed
		}

		add_filter('spectrom_sync_upload_media_allowed_mime_type', array(WPSiteSync_WooCommerce::get_instance(), 'filter_allowed_mime_type'), 10, 2);

SyncDebug::log(__METHOD__ . '() found post_data information: ' . var_export($post_data, TRUE));

		$this->_api = new SyncApiRequest();
		$this->_sync_model = new SyncModel();
		$this->_api_controller = SyncApiController::get_instance();

		// set source domain- needed for handling media operations
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
SyncDebug::log('  updating post_meta with ' . var_export($new_id, TRUE));
SyncDebug::log('  updating post_meta for target id ' . var_export($target_post_id, TRUE));
						update_post_meta($target_post_id, $meta_key, $new_id);
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
					global $wpdb;

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
			$variation_data[] = array('target_id' => $variation_post_id, 'source_id' => $post_data['ID']);
		}

		// delete variations if not in current sync data
		$args = array(
			'post_type' => 'product_variation',
			'post_status' => array('private', 'publish'),
			'numberposts' => -1,
			'post_parent' => $post_id,
		);
		$existing_variations = new WP_Query($args);
		if ($existing_variations->have_posts()) {
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

		register_taxonomy($attribute_name, array('product'), $taxonomy_data);
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
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' media fields= ' . var_export($fields, TRUE));
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
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' media fields= ' . var_export($fields, TRUE));
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
			$sync_data = $this->_sync_model->get_sync_data($_POST['post_id'], $site_key, 'woovariableproduct');
			$new_variation_id = $sync_data->target_content_id;
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' processing variation image - new id= ' . var_export($new_variation_id, TRUE));
			if (NULL !== $sync_data && 0 !== $media_id) {
SyncDebug::log(__METHOD__ . '():' . __LINE__ . " update_post_meta({$new_variation_id}, '_thumbnail_id', {$media_id})");
				update_post_meta($new_variation_id, '_thumbnail_id', $media_id);
			}
		}
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
		$ids = explode(',', $meta_value[0]);
		foreach ($ids as $image_id) {
SyncDebug::log(__METHOD__ . '() adding product image id=' . var_export($image_id, TRUE));
			$img = wp_get_attachment_image_src($image_id, 'full', FALSE);
			if (FALSE !== $img) {
				add_filter('spectrom_sync_upload_media_fields', array($this, 'filter_upload_media_fields'), 10, 1);
				$this->_api->send_media($img[0], $post_id, 0, $image_id);
				remove_filter('spectrom_sync_upload_media_fields', array($this, 'filter_upload_media_fields'));
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
			add_filter('spectrom_sync_upload_media_fields', array($this, 'filter_downloadable_upload_media_fields'), 10, 1);
			$this->_api->send_media($file['file'], $post_id, 0, $file_id);
			remove_filter('spectrom_sync_upload_media_fields', array($this, 'filter_downloadable_upload_media_fields'));
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
SyncDebug::log(__METHOD__ . '() associated id: ' . var_export($associated_id, TRUE));
		$associated = array();
		$this->_sync_model = new SyncModel();

		if ('pull' === $action) {
			$sync_data = $this->_sync_model->get_sync_data($associated_id, SyncOptions::get('target_site_key'), $type);
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
		$new_target_id = NULL;
		if (array_key_exists('target_id', $target_ids[$meta_key][$meta_source_id])) {
SyncDebug::log(' - found push target post #' . $target_ids[$meta_key][$meta_source_id]['target_id']);
			$meta_post = get_post($target_ids[$meta_key][$meta_source_id]['target_id']);
		}
		// lookup source_id in sync table
		if (NULL === $meta_post) {
			$sync_data = $this->_sync_model->get_sync_data($meta_source_id, $this->_api_controller->source_site_key, 'wooproduct');
			if (NULL !== $sync_data) {
SyncDebug::log(' - found target post #' . $sync_data->target_content_id);
				$new_target_id = $sync_data->target_content_id;
			} else {
				// if no match, check for matching title
SyncDebug::log(' - still no product found - look up by title');
				WPSiteSync_WooCommerce::get_instance()->load_class('woocommercemodel');
				$woo_model = new SyncWooCommerceModel();
				$meta_post = $woo_model->get_product_by_title($target_ids[$meta_key][$meta_source_id]['source_title']);
				if (NULL !== $meta_post) {
					$new_target_id = $meta_post->ID;
				}
			}
		} else {
			$new_target_id = $meta_post->ID;
		}
		if (NULL !== $new_target_id) {
			$new_meta_ids[] = $new_target_id;
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
	 *
	 * @since 1.0.0
	 * @param $target_post_id
	 * @param $attach_id
	 * @param $media_id
	 * @return void
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
