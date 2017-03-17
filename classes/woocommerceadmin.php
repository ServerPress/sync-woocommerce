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
		add_action('admin_enqueue_scripts', array($this, 'admin_enqueue_scripts'));
		add_action('admin_footer', array($this, 'print_hidden_div'));
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
	 * Print hidden div for translatable text
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function print_hidden_div()
	{
		echo '<div id="sync-woo-push-working" style="display:none">', esc_html__('Pushing Content to Target... Please Stay on This Page', 'wpsitesync-woocommerce'), '</div>';
		echo '<div id="sync-woo-pull-working" style="display:none">', esc_html__('Pulling Content From Target... Please Stay on This Page', 'wpsitesync-woocommerce'), '</div>';
	}
}

// EOF
