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
	 * @return null|SyncWooCommerceAdmin instance reference to Admin class
	 */
	public static function get_instance()
	{
		if (NULL === self::$_instance)
			self::$_instance = new self();
		return self::$_instance;
	}

	/**
	 * Registers js to be used.
	 * @param $hook_suffix Admin page hook
	 */
	public function admin_enqueue_scripts($hook_suffix)
	{
		wp_register_script('sync-woocommerce', WPSiteSync_WooCommerce::get_asset('js/sync-woocommerce.js'), array('sync'), WPSiteSync_WooCommerce::PLUGIN_VERSION, TRUE);
		wp_register_style('sync-woocommerce', WPSiteSync_WooCommerce::get_asset('css/sync-woocommerce.css'), array('sync-admin'), WPSiteSync_WooCommerce::PLUGIN_VERSION);

		if ('post.php' === $hook_suffix && 'product' === get_current_screen()->post_type) {
			wp_enqueue_script('sync-woocommerce');
			wp_enqueue_style('sync-woocommerce');
		}
	}

	/**
	 * Print hidden div containing translatable text
	 */
	public function print_hidden_div()
	{
		$screen = get_current_screen();
		global $post;
		if ('product' === $screen->id && 'product' === $post->post_type) {
			// TODO: use Sync callback for outputting admin content
			echo '<div style="display:none">';
			echo '<div id="sync-woo-push-working">', esc_html__('Pushing Content to Target... Please Stay on This Page', 'wpsitesync-woocommerce'), '</div>';
			echo '<div id="sync-woo-pull-working">', esc_html__('Pulling Content From Target... Please Stay on This Page', 'wpsitesync-woocommerce'), '</div>';
			echo '<div id="sync-msg-update-changes">', esc_html__('Please save Content before Syncing', 'wpsitesync-woocommerce'), '</div>';
			global $post;
			$type = (isset($post) && isset($post->post_type)) ? $post->post_type : '';
			if ('product' === $type) {
				$product = wc_get_product($post->ID);
				$prod_type = $product->get_type();
				$type .= '-' . $prod_type;
			}
			echo '<div id="sync-woo-product-type">', $type, '</div>';
			echo '<div id="sync-woo-progress">
				<div class="sync-woo-ui">
					<div class="sync-woo-progress">
						<div class="sync-woo-indicator" style="width:1%">
							<span class="percent">1</span>%
						</div>
					</div>
				</div>'; // #sync-woo-progress
			echo '</div>'; // display:none
//		echo '<style type="text/css">', PHP_EOL;
//		echo '#spectrom_sync { border: 1px solid red; }', PHP_EOL;
//		echo '</style>', PHP_EOL;
		}
	}
}

// EOF
