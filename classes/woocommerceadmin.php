<?php

/*
 * Allows management of WooCommerce products between the Source and Target sites
 * @package Sync
 * @author WPSiteSync
 */

class SyncWooCommerceAdmin
{
	private static $_instance = NULL;

	private function __construct()
	{
		add_action('admin_enqueue_scripts', array(&$this, 'admin_enqueue_scripts'));
		add_action('spectrom_sync_ajax_operation', array(&$this, 'check_ajax_query'), 10, 3);
	}

	/**
	 * Retrieve singleton class instance
	 *
	 * @since 1.0.0
	 * @static
	 * @return null|SyncWooCommerceAdmin instance reference to plugin
	 */
	public static function get_instance()
	{
		if (NULL === self::$_instance)
			self::$_instance = new self();
		return self::$_instance;
	}

	/**
	 * Registers js to be used.
	 *
	 * @since 1.0.0
	 * @param $hook_suffix Admin page hook
	 * @return void
	 */
	public function admin_enqueue_scripts($hook_suffix)
	{
		wp_register_script('sync-woocommerce', WPSiteSync_WooCommerce::get_asset('js/sync-woocommerce.js'), array('sync'), WPSiteSync_WooCommerce::PLUGIN_VERSION, TRUE);

		if ('post.php' === $hook_suffix && 'product'=== get_current_screen()->post_type) {
			wp_enqueue_script('sync-woocommerce');
		}
	}

	/**
	 * Checks if the current ajax operation is for this plugin
	 *
	 * @param  boolean $found Return TRUE or FALSE if the operation is found
	 * @param  string $operation The type of operation requested
	 * @param  SyncApiResponse $resp The response to be sent
	 *
	 * @return boolean Return TRUE if the current ajax operation is for this plugin, otherwise return $found
	 */
	public function check_ajax_query($found, $operation, SyncApiResponse $resp)
	{
SyncDebug::log(__METHOD__ . '() operation="' . $operation . '"');

//		if (!WPSiteSyncContent::get_instance()->get_license()->check_license('sync_woocommerce', WPSiteSync_WooCommerce::PLUGIN_KEY, WPSiteSync_WooCommerce::PLUGIN_NAME))
//			return $found;

		if ('pushwoocommerce' === $operation) {
SyncDebug::log(' - post=' . var_export($_POST, TRUE));

			$ajax = WPSiteSync_WooCommerce::get_instance()->load_class('woocommerceajaxrequest', TRUE);
			$ajax->push_woocommerce($resp);
			$found = TRUE;
		} else if ('pullwoocommerce' === $operation) {
SyncDebug::log(' - post=' . var_export($_POST, TRUE));

			$ajax = WPSiteSync_WooCommerce::get_instance()->load_class('woocommerceajaxrequest', TRUE);
			$ajax->pull_woocommerce($resp);
			$found = TRUE;
		}

		return $found;
	}
}

// EOF
