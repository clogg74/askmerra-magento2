/*
 * AskMerra for Magento: connects the chat widget to the store. Plain JavaScript, no RequireJS, so
 * it runs on Luma, Hyvä, Breeze and other server-rendered themes.
 *
 * - "Add to cart" in the chat puts the product into the Magento cart and refreshes the mini cart;
 *   products with options open their page.
 * - With "Magento cookie notice" consent, orders are reported once the shopper allows cookies.
 * - On the order success page, the order is reported for sales attribution.
 *
 * Settings come from window.AskMerraMagento (Block\Widget). Themes can react to
 * document "askmerra:cart-added" events, e.g. to open their cart drawer.
 */
(function () {
    'use strict';

    var config = window.AskMerraMagento || {};

    /** Calls back with window.AskMerra once the widget script has run. */
    function whenApi(callback) {
        var done = false;
        var tries = 0;
        var timer;

        function attempt() {
            var api = window.AskMerra;

            if (!done && api && typeof api.on === 'function') {
                done = true;
                callback(api);
            }

            return done;
        }

        if (attempt()) {
            return;
        }

        var script = document.getElementById('askmerra-widget-script');

        if (script) {
            script.addEventListener('load', attempt);
        }

        // The script can also be loaded late, e.g. by a tag manager: keep looking for a minute.
        timer = setInterval(function () {
            if (attempt() || ++tries > 240) {
                clearInterval(timer);
            }
        }, 250);
    }

    function getCookie(name) {
        var parts = document.cookie ? document.cookie.split('; ') : [];

        for (var i = 0; i < parts.length; i++) {
            var index = parts[i].indexOf('=');

            if (parts[i].substring(0, index) === name) {
                try {
                    return decodeURIComponent(parts[i].substring(index + 1));
                } catch (e) {
                    return parts[i].substring(index + 1);
                }
            }
        }

        return null;
    }

    /** Magento's form key, kept in a cookie by Magento's own scripts; created as they would. */
    function getFormKey() {
        var key = getCookie('form_key');
        var input;

        if (!key) {
            input = document.querySelector('input[name="form_key"]');
            key = input && input.value;
        }

        if (!key) {
            var chars = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
            var cookies = window.cookiesConfig || {};

            key = '';

            for (var i = 0; i < 16; i++) {
                key += chars.charAt(Math.floor(Math.random() * chars.length));
            }

            document.cookie = 'form_key=' + key + '; path=/' + (cookies.secure ? '; secure' : '')
                + '; samesite=' + (cookies.samesite || 'lax');
        }

        return key;
    }

    function open(url) {
        if (url && url !== '#') {
            window.location.href = url;
        }
    }

    /** A short message over the page and the chat. */
    function notify(text, link, isError) {
        var previous = document.getElementById('askmerra-magento-notice');
        var box = document.createElement('div');

        if (previous && previous.parentNode) {
            previous.parentNode.removeChild(previous);
        }

        if (!text) {
            return;
        }

        box.id = 'askmerra-magento-notice';
        box.setAttribute('role', isError ? 'alert' : 'status');
        box.style.cssText = 'position:fixed;left:50%;top:16px;transform:translateX(-50%);z-index:2147483647;'
            + 'max-width:calc(100% - 32px);box-sizing:border-box;padding:12px 16px;border-radius:8px;'
            + 'font:14px/1.4 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;color:#fff;'
            + 'background:' + (isError ? '#b42318' : '#1f2937') + ';box-shadow:0 8px 24px rgba(0,0,0,.25)';
        box.appendChild(document.createTextNode(text));

        if (link) {
            var anchor = document.createElement('a');

            anchor.href = link.href;
            anchor.textContent = link.text;
            anchor.style.cssText = 'color:inherit;text-decoration:underline;font-weight:600;margin-left:12px';
            box.appendChild(anchor);
        }

        document.body.appendChild(box);
        setTimeout(function () {
            if (box.parentNode) {
                box.parentNode.removeChild(box);
            }
        }, 6000);
    }

    /** Refreshes the mini cart of Luma/Breeze (customer data) and Hyvä themes. */
    function refreshCart(detail) {
        if (typeof window.require === 'function') {
            try {
                window.require(['Magento_Customer/js/customer-data'], function (customerData) {
                    customerData.invalidate(['cart']);
                    customerData.reload(['cart'], true);
                }, function () {});
            } catch (e) {
                // not a RequireJS theme
            }
        }

        try {
            window.dispatchEvent(new CustomEvent('reload-customer-section-data'));
            document.dispatchEvent(new CustomEvent('askmerra:cart-added', { detail: detail }));
        } catch (e) {
            // very old browser
        }
    }

    var busy = {};

    function addToCart(item) {
        item = item || {};

        var externalId = item.externalId === undefined || item.externalId === null ? '' : String(item.externalId);
        var sku = item.sku === undefined || item.sku === null ? '' : String(item.sku);
        var key = externalId || sku;
        var body;

        if (!config.addToCartUrl || !key || typeof window.fetch !== 'function') {
            return open(item.url);
        }

        if (busy[key]) {
            return;
        }

        busy[key] = true;
        body = new URLSearchParams();
        body.append('external_id', externalId);
        body.append('sku', sku);
        body.append('form_key', getFormKey());

        window.fetch(config.addToCartUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: body
        }).then(function (response) {
            return response.json();
        }).then(function (result) {
            busy[key] = false;
            result = result || {};

            if (result.success) {
                refreshCart({ externalId: externalId, sku: sku, name: result.name, qty: result.qty });

                if (config.afterAdd === 'cart' && result.cartUrl) {
                    return open(result.cartUrl);
                }

                return notify(result.message, result.cartUrl ? { href: result.cartUrl, text: result.cartLabel } : null, false);
            }

            if (result.redirect) {
                return open(result.redirect);
            }

            notify(result.message || config.errorMessage, null, true);
        })['catch'](function () {
            busy[key] = false;
            open(item.url);
        });
    }

    /** "Magento cookie notice": consent once the shopper allows cookies (Magento_Cookie). */
    function watchCookieNotice(api) {
        function allowed() {
            var value = getCookie('user_allowed_save_cookie');

            try {
                return !!(value && JSON.parse(value)[config.websiteId]);
            } catch (e) {
                return false;
            }
        }

        function check() {
            if (allowed()) {
                api.setConsent({ analytics: true });
                document.removeEventListener('click', onClick, true);
            }
        }

        function onClick() {
            setTimeout(check, 300);
        }

        if (allowed()) {
            api.setConsent({ analytics: true });
        } else {
            document.addEventListener('click', onClick, true);
        }
    }

    /** The order on the success page (Block\Purchase), reported once. */
    function reportPurchase(api) {
        var order = window.AskMerraMagentoPurchase;
        var storageKey;

        if (!order || !order.transaction_id || typeof api.trackPurchase !== 'function') {
            return;
        }

        storageKey = 'askmerra:order:' + order.transaction_id;

        try {
            if (window.sessionStorage.getItem(storageKey)) {
                return;
            }

            window.sessionStorage.setItem(storageKey, '1');
        } catch (e) {
            // storage blocked: AskMerra ignores an order reported twice on one page anyway
        }

        api.trackPurchase(order);
    }

    whenApi(function (api) {
        if (config.addToCartUrl) {
            api.on('add_to_cart', addToCart);
        }

        if (config.consentCookie && typeof api.setConsent === 'function') {
            watchCookieNotice(api);
        }

        reportPurchase(api);
    });
})();
