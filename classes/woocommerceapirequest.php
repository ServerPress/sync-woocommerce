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


	public static $gutenberg_props = array(					// array of block names and the properties they reference
		// properties for: WooCommerce Blocks
		'wp:woocommerce/featured-category' =>			'categoryId:t',				// 1 mediaId:i
		'wp:woocommerce/featured-product' =>			'productId:p',				// 2 mediaId:i
		'wp:woocommerce/handpicked-products' =>			'[products:p',				// 3
		'wp:woocommerce/product-best-sellers' =>		'[categories:t',			// 4 sharedAttributes
	//	'wp:woocommerce/product-categories' - no ids in json
		'wp:woocommerce/product-category' =>			'[categories:t',			// 5 sharedAttributes
		'wp:woocommerce/product-new' =>					'[categories:t',			// 6 sharedAttributes
		'wp:woocommerce/product-on-sale' =>				'[categories:t',			// 7 sharedAttributes
		'wp:woocommerce/product-tag' =>					'[tags:t',					// 8
		'wp:woocommerce/product-top-rated' =>			'[categories:t',			// 9
		'wp:woocommerce/products-by-attribute' =>		'',							// 10
		'wp:woocommerce/reviews-by-product' =>			'productId:p',				// 11
	//	product grid =>									'[categories:t',
	//	featured category =>							'mediaId:i',
	//	featured product =>								'mediaId:i',
	);
}

// EOF
