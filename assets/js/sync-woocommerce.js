/*
 * @copyright Copyright (C) 2015 SpectrOMtech.com. - All Rights Reserved.
 * @license GNU General Public License, version 2 (http://www.gnu.org/licenses/gpl-2.0.html)
 * @author SpectrOMtech.com <hello@SpectrOMtech.com>
 * @url https://www.SpectrOMtech.com/products/
 * The PHP code portions are distributed under the GPL license. If not otherwise stated, all images, manuals, cascading style sheets, and included JavaScript *are NOT GPL, and are released under the SpectrOMtech Proprietary Use License v1.0
 * More info at https://SpectrOMtech.com/products/
 */

function WPSiteSyncContent_WooCommerce()
{
    this.inited = false;
    this.$content = null;
    this.disable = false;
    this.post_id = null;
}

/**
 * Init
 */
WPSiteSyncContent_WooCommerce.prototype.init = function ()
{
    this.inited = true;

    var _self = this,
        target = document.querySelector('#woocommerce-product-data'),
        observer = new MutationObserver(function (mutations)
        {
            mutations.forEach(function (mutation)
            {
                _self.on_content_change();
            });
        });

    var config = {attributes: true, childList: true, characterData: true};

    observer.observe(target, config);

    this.$content = jQuery('#woocommerce-product-data');
    this.$content.on('keypress change', function ()
    {
        _self.on_content_change();
    });
};

/**
 * Disables Sync Button every time the content changes.
 */
WPSiteSyncContent_WooCommerce.prototype.on_content_change = function ()
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
WPSiteSyncContent_WooCommerce.prototype.push_woocommerce = function (post_id)
{
//console.log('PUSH' + settings);

    if (true === wpsitesynccontent.woocommerce.disable) {
        wpsitesynccontent.set_message(jQuery('#sync-msg-update-changes').html());
        return;
    }

    // Do nothing when in a disabled state
    if (this.disable || !this.inited)
        return;

    wpsitesynccontent.set_message(jQuery('#sync-msg-working').text('Pushing Content to Target... Please Stay on This Page'), true);

    this.post_id = post_id;

    var data = {
        action: 'spectrom_sync',
        //operation: 'pushwoocommerce',
        operation: 'push',
        post_id: post_id,
        _sync_nonce: jQuery('#_sync_nonce').val()
    };

    jQuery.ajax({
        type: 'post',
        async: true, // false,
        data: data,
        url: ajaxurl,
       // timeout: 20000,
        success: function (response)
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
        error: function (response, textstatus, message)
        {
            // if (textstatus === 'timeout') {
            //     wpsitesynccontent.woocommerce.push_woocommerce(post_id);
            // } else {
                if ('undefined' !== typeof(response.error_message))
                    wpsitesynccontent.set_message('<span class="error">' + response.error_message + '</span>', false, true);
            //}
        }
    });
};

/**
 * Pulls WooCommerce products from target site
 * @param {int} post_id The post id to perform Push operations on
 */
WPSiteSyncContent_WooCommerce.prototype.pull_woocommerce = function (post_id)
{
    if (true === wpsitesynccontent.woocommerce.disable) {
        wpsitesynccontent.set_message(jQuery('#sync-msg-update-changes').html());
        return;
    }

    // Do nothing when in a disabled state
    if (this.disable || !this.inited)
        return;

    jQuery('.pull-actions').hide();
    jQuery('.pull-loading-indicator').show();
    wpsitesynccontent.set_message(jQuery('#sync-msg-pull-working').text('Pulling Content From Target... Please Stay on This Page'), true);

    this.post_id = post_id;

    var data = {
        action: 'spectrom_sync',
        operation: 'pull',
        post_id: post_id,
        _sync_nonce: jQuery('#_sync_nonce').val()
    };

    jQuery.ajax({
        type: 'post',
        async: true, // false,
        data: data,
        url: ajaxurl,
        success: function (response)
        {
//console.log('in ajax success callback - response');
            console.log(response);
            if (response.success) {
                wpsitesynccontent.set_message(jQuery('#sync-msg-pull-complete').text());
                window.location.reload();
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
        error: function (response)
        {
            if ('undefined' !== typeof(response.error_message))
                wpsitesynccontent.set_message('<span class="error">' + response.error_message + '</span>', false, true);
        }
    });
};

wpsitesynccontent.woocommerce = new WPSiteSyncContent_WooCommerce();

// initialize the WPSiteSync operation on page load
jQuery(document).ready(function ()
{
    var post_id = jQuery('#sync-content').attr('onclick');

    wpsitesynccontent.woocommerce.init();

    post_id = post_id.slice(23, -1);

    jQuery('#sync-content').attr('onclick', 'wpsitesynccontent.woocommerce.push_woocommerce(' + post_id + ')');

    if (wpsitesynccontent.pull) {
        jQuery('#sync-pull-content').attr('onclick', 'wpsitesynccontent.woocommerce.pull_woocommerce(' + post_id + ')')
    } else {
        wpsitesynccontent.set_message(jQuery('#sync-pull-msg').html());
        jQuery('#sync-pull-content').blur();
    }
});
