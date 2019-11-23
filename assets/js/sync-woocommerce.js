/*
 * @copyright Copyright (C) 2015-2019 SpectrOMtech.com. - All Rights Reserved.
 * @license GNU General Public License, version 2 (http://www.gnu.org/licenses/gpl-2.0.html)
 * @author WPSiteSync <hello@wpsitesync.com>
 * @url https://wpsitesync.com/downloads/wpsitesync-woocommerce-products/
 * The PHP code portions are distributed under the GPL license. If not otherwise stated, all images, manuals, cascading style sheets, and included JavaScript *are NOT GPL, and are released under the SpectrOMtech Proprietary Use License v1.0
 * More info at https://SpectrOMtech.com/products/
 */

function WPSiteSyncContent_WooCommerce()
{
	this.inited = false;				// set to true after initialization
	this.$content = null;				// jQuery instance for the content
	this.disable = false;				// set to true when Push button is disabled
	this.post_id = null;				// post id of content being Pushed
	this.offset = 0;					// starting variation when pushing variations
}

/**
 * Init
 */
WPSiteSyncContent_WooCommerce.prototype.init = function()
{
	this.inited = true;
console.log('wc init');
	var _self = this;

	this.$content = jQuery('#woocommerce-product-data');
	this.$content.on('keypress change', function() { wpsitesynccontent.on_field_change(); });
	// check to see if it's a product variation
	var prod_type = jQuery('#sync-woo-product-type').text();
	if ('product-variable' === prod_type) {
console.log('wc variable product');
		wpsitesynccontent.set_push_callback(this.push_callback);
		jQuery(document).on('sync_api_data', this.filter_sync_data);
console.log('wc offset value:');
console.log(this.offset);
	}
};

// TODO:
WPSiteSyncContent_WooCommerce.prototype.filter_sync_data = function(event, data)
{
console.log('wc filter_sync_data() offset=');
console.log(wpsitesynccontent.woocommerce.offset);
console.log('wc filter_sync_data() data=');
	data.offset = wpsitesynccontent.woocommerce.offset;
console.log(data);
	return data;
};

// TODO:
WPSiteSyncContent_WooCommerce.prototype.push_callback = function(data)
{
	var type = typeof(data);
console.log('wc push_callback() type="' + type + '"');
console.log(data);
	if ('number' === type) {
		// called at beginning of push()
		if (null === wpsitesynccontent.woocommerce.post_id) {
			// when post ID is zero, this is the first Push of a variable product
console.log('wc push_callback() setting post id to ' + parseInt(data));
			wpsitesynccontent.woocommerce.setup_ui(parseInt(data));
		}
	} else if ('object' === type) {
		var response = data;
console.log('wc push_callback data=');
console.log(response);
		var incomplete_update = false;
		if ('undefined' !== typeof(response.notice_codes)) {
console.log('wc push_callback found notice codes');
			for (var idx = 0; idx < response.notice_codes.length; idx++) {
				if (602 === response.notice_codes[idx])
					incomplete_update = true;
			}
		}
		if (incomplete_update) {
			// got a 602 response from the Push - need to do another one
console.log('wc push_callback() incomplete update restart on variation id');
			wpsitesynccontent.woocommerce.set_progress(response);
		} else {
			if (0 !== wpsitesynccontent.woocommerce.post_id) {
				wpsitesynccontent.woocommerce.remove_ui();
			}
console.log('wc push_callback() completed all updates; resetting post_id to null');
			wpsitesynccontent.woocommerce.post_id = null;
		}
	} else {
console.log('unrecognized type');
	}
	return true;
};

/**
 * Sets up the Progress Bar to provide a UI and user feedback on Push status
 * @param {int} post_id The post ID for the Push operation
 */
WPSiteSyncContent_WooCommerce.prototype.setup_ui = function(post_id)
{
	// initialize the post_id and offset values in preparation of multiple Push calls
	wpsitesynccontent.woocommerce.post_id = post_id;
	wpsitesynccontent.woocommerce.offset = 0;

	// initialize progress bar UI
	var html = jQuery('#sync-woo-progress').html();
	jQuery('#sync-message-container').after(html);
	jQuery('#spectrom_sync .sync-woo-progress .sync-woo-indicator').css('width', '1');
	jQuery('#spectrom_sync .sync-woo-progress .percent').text('1');
	jQuery('#spectrom_sync .sync-woo-ui').show();
};

/**
 * Removes the progress bar UI element from the DOM
 */
WPSiteSyncContent_WooCommerce.prototype.remove_ui = function()
{
	jQuery('#spectrom_sync .sync-woo-ui').remove();
};

/**
 * Updates the UI for the Progress Bar and performs the next Push operation
 * @param {object} response The SyncApiResponse object returned from the Source site
 */
WPSiteSyncContent_WooCommerce.prototype.set_progress = function(response)
{
	pcnt = 1;
	if ('undefined' !== response.data.percent) {
		pcnt = parseInt(response.data.percent);
	}
	jQuery('#spectrom_sync .sync-woo-indicator').css('width', pcnt + '%');
	jQuery('#spectrom_sync .percent').text(pcnt + '');

	var incr = 0;
	if ('undefined' !== response.data.offset_increment) {
		incr = parseInt(response.data.offset_increment);
	}
	this.offset += incr;

	// click the Push button again
	setTimeout(function() {
console.log('wc set_progress() resubmitting push, id=' + wpsitesynccontent.woocommerce.post_id + ' offset=' + wpsitesynccontent.woocommerce.offset);
		wpsitesynccontent.push(wpsitesynccontent.woocommerce.post_id);
	}, 10);
};

/**
 * Disables Sync Button every time the content changes.
 */
WPSiteSyncContent_WooCommerce.prototype.on_content_change = function()
{
	this.disable = true;
	jQuery('#sync-content').attr('disabled', true);
	wpsitesynccontent.set_message(jQuery('#sync-msg-update-changes').html());
	jQuery('#disabled-notice-sync').show();
};

/**
 * Push WooCommerce products from target site
 * @param {int} post_id The post id to perform Push operations on
 */
WPSiteSyncContent_WooCommerce.prototype.push_woocommerce = function(post_id)
{
	// TODO: this method not needed. Pull handled by WPSS Core
console.log('PUSH ' + post_id);
	// TODO: use .api() method

	if (wpsitesynccontent.woocommerce.disable) {
		wpsitesynccontent.set_message(jQuery('#sync-msg-update-changes').html());
		return;
	}

	if (!this.inited)
		return;

	wpsitesynccontent.set_message(jQuery('#sync-woo-push-working').html(), true);

	this.post_id = post_id;

	var data = {
		action: 'spectrom_sync',
		operation: 'push',
		post_id: post_id,
		_sync_nonce: jQuery('#_sync_nonce').val()
	};

	// TODO: can use wpsitesynccontent.api() method rather than re-implementing. can add options parameter to api() method for timeout value
	jQuery.ajax({
		type: 'post',
		async: true, // false,
		data: data,
		url: ajaxurl,
		timeout: 20000,
		success: function(response)
		{
//console.log('in ajax success callback - response');
console.log(response);
			if (response.success) {
				wpsitesynccontent.set_message(jQuery('#sync-success-msg').text(), false, true);
				if ('undefined' !== typeof(response.notice_codes) && response.notice_codes.length > 0) {
					for (var idx = 0; idx < response.notice_codes.length; idx++) {
						wpsitesynccontent.add_message(response.notices[idx]);
					}
				}
			} else {
				if ('undefined' !== typeof(response.data.message))
					wpsitesynccontent.set_message(response.data.message, false, true);
			}
		},
		error: function(response, textstatus, message)
		{
			if ('timeout' === textstatus) {
				wpsitesynccontent.woocommerce.push_woocommerce(post_id);
			} else {
				if ('undefined' !== typeof(response.error_message))
					wpsitesynccontent.set_message(response.error_message, false, true, 'sync-error');
			}
		}
	});
};

/**
 * Pulls WooCommerce products from target site
 * @param {int} post_id The post id to perform Push operations on
 */
WPSiteSyncContent_WooCommerce.prototype.pull_woocommerce = function(post_id)
{
	// TODO: this method not needed. Pull handled by WPSS Core
	if (wpsitesynccontent.woocommerce.disable) {
		wpsitesynccontent.set_message(jQuery('#sync-msg-update-changes').html());
		return;
	}

	// do nothing when in a disabled state
	if (!this.inited)
		return;

	jQuery('.pull-actions').hide();
	jQuery('.pull-loading-indicator').show();
	wpsitesynccontent.set_message(jQuery('#sync-woo-pull-working').html(), true);

	this.post_id = post_id;

	var data = {
		action: 'spectrom_sync',
		operation: 'pull',
		post_id: post_id,
		timeout: 20000,
		_sync_nonce: jQuery('#_sync_nonce').val()
	};

	// TODO: can use wpsitesynccontent.api() method rather than re-implementing. can add options parameter to api() method for timeout value
	jQuery.ajax({
		type: 'post',
		async: true, // false,
		data: data,
		url: ajaxurl,
		success: function(response)
		{
//console.log('in ajax success callback - response');
console.log(response);
			if (response.success) {
				wpsitesynccontent.set_message(jQuery('#sync-msg-pull-complete').text());
				if ('undefined' !== typeof(response.notice_codes) && response.notice_codes.length > 0) {
					for (var idx = 0; idx < response.notice_codes.length; idx++) {
						wpsitesynccontent.add_message(response.notices[idx]);
					}
				}
				window.location.reload();
			} else {
				if ('undefined' !== typeof(response.data.message))
					wpsitesynccontent.set_message(response.data.message, false, true);
			}
		},
		error: function(response, textstatus, message)
		{
			if ('timeout' === textstatus) {
				wpsitesynccontent.woocommerce.pull_woocommerce(post_id);
			} else {
				if ('undefined' !== typeof(response.error_message))
					wpsitesynccontent.set_message(response.error_message, false, true, 'sync-error');
			}
		}
	});
};

wpsitesynccontent.woocommerce = new WPSiteSyncContent_WooCommerce();

// initialize the WPSiteSync operation on page load
jQuery(document).ready(function()
{
	wpsitesynccontent.woocommerce.init();

	// TODO: these should only be initialized when editing products
//	wpsitesynccontent.set_push_callback(wpsitesynccontent.woocommerce.push_woocommerce);
//	wpsitesynccontent.set_pull_callback(wpsitesynccontent.woocommerce.pull_woocommerce);
});

// EOF
