<?php

/*
 * Allows management of WooCommerce products between the Source and Target sites
 * @package Sync
 * @author WPSiteSync
 */

class SyncWooCommerceModel
{

	/**
	 * Check for existing attribute taxonomy
	 *
	 * @since 1.0.0
	 * @param $attribute
	 * @return object
	 */
	public function get_attribute_taxonomy($attribute)
	{
		global $wpdb;

		// check if attribute taxonomy already exists
		$taxonomy = $wpdb->get_row($wpdb->prepare("
					SELECT *
					FROM {$wpdb->prefix}woocommerce_attribute_taxonomies
					WHERE attribute_name = %s
				 ", $attribute));

		return $taxonomy;
	}

	/**
	 * Returns a post object for a given post title
	 * @param string $title The post_title value to search for
	 * @return WP_Post|NULL The WP_Post object if the title is found; otherwise NULL.
	 */
	public function get_product_by_title($title)
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
}
