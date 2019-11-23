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
	const ERROR_WOOCOMMERCE_UNIT_MISMATCH = 604;
	const ERROR_WOOCOMMERCE_DEPENDENT_PRODUCT_NOT_PUSHED = 605;
	const ERROR_WOOCOMMERCE_TARGET_VARIATION_MISSING = 606;

	const NOTICE_PRODUCT_MODIFIED = 600;
	const NOTICE_WOOCOMMERCE_MEDIA_PERMISSION = 601;
	const NOTICE_PARTIAL_VARIATION_UPDATE = 602;

	const HEADER_WOOCOMMERCE_VERSION = 'x-woocommerce-version'; // WooCommerce version number; used in requests and responses

	const OFFSET_INCREMENT = 2;									// number of variations to process for each API call #@#

	public $media_id;
	public $local_media_name;


	/**
	 * Change the content type for get_sync_data
	 * @return string
	 */
	// TODO: remove- no need to change the data type
	public function change_media_content_type_variable()
	{
		return 'woovariableproduct';
	}
}

// EOF
