<?php

/*
 * Allows management of WooCommerce Products between the Source and Target sites
 * @package Sync
 * @author WPSiteSync
 */

class SyncWooCommerceAjaxRequest extends SyncInput
{

	/**
	 * Push WooCommerce products ajax request
	 *
	 * @since 1.0.0
	 * @param SyncApiResponse $resp The response object after the API request has been made
	 * @return void
	 */
	public function push_woocommerce($resp)
	{
		$post_id = $this->post_int('post_id', 0);

		if (0 === $post_id) {
			// No product. Return error message
			WPSiteSync_WooCommerce::get_instance()->load_class('woocommerceapirequest');
			$resp->error_code(SyncWooCommerceApiRequest::ERROR_NO_WOOCOMMERCE_PRODUCT_SELECTED);
			return TRUE;        // return, signaling that we've handled the request
		}

		$args = array('post_id' => $post_id);
		$api_response = WPSiteSync_WooCommerce::get_instance()->api->api('pushwoocommerce', $args);

		// copy contents of SyncApiResponse object from API call into the Response object for AJAX call
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' - returned from api() call; copying response');
		$resp->copy($api_response);

		if (0 === $api_response->get_error_code()) {
SyncDebug::log(' - no error, setting success');
			$resp->success(TRUE);
		} else {
			$resp->success(FALSE);
SyncDebug::log(' - error code: ' . $api_response->get_error_code());
		}

		return TRUE; // return, signaling that we've handled the request
	}

	/**
	 * Pull WooCommerce products ajax request
	 *
	 * @since 1.0.0
	 * @param SyncApiResponse $resp The response object after the API request has been made
	 * @return void
	 */
	public function pull_woocommerce($resp)
	{
		$post_id = $this->post_int('post_id', 0);

		if (0 === $post_id) {
			// No product. Return error message
			WPSiteSync_WooCommerce::get_instance()->load_class('woocommerceapirequest');
			$resp->error_code(SyncWooCommerceApiRequest::ERROR_NO_WOOCOMMERCE_PRODUCT_SELECTED);
			return TRUE;        // return, signaling that we've handled the request
		}

		$args = array('post_id' => $post_id);
		$api_response = WPSiteSync_WooCommerce::get_instance()->api->api('pullwoocommerce', $args);

		// copy contents of SyncApiResponse object from API call into the Response object for AJAX call
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' - returned from api() call; copying response');
		$resp->copy($api_response);

		if (0 === $api_response->get_error_code()) {
SyncDebug::log(' - no error, setting success');
			$resp->success(TRUE);
		} else {
			$resp->success(FALSE);
SyncDebug::log(' - error code: ' . $api_response->get_error_code());
		}

		return TRUE; // return, signaling that we've handled the request
	}
}

// EOF
