/**
 * AskMerra for ScandiPWA: connects the AskMerra chat to the PWA.
 *
 * The Magento module askmerra/magento2-connector puts the widget script into the page head and its
 * settings into window.AskMerraMagento: addToCart, afterAdd, productIdentifier, trackPurchases.
 * Every exported function has a namespace, so a project can change it with a Mosaic plugin.
 */
import history from 'Util/History';

/** How long to wait for the widget script: 240 x 250 ms = one minute. */
export const WIDGET_WAIT_INTERVAL_MS = 250;
export const WIDGET_WAIT_ATTEMPTS = 240;

/** @namespace AskMerra/Util/AskMerra/Config/getConfig */
export const getConfig = () => (typeof window !== 'undefined' && window.AskMerraMagento) || {};

/**
 * Calls back with window.AskMerra once the widget script has run (it loads async in the head, or
 * later from a tag manager).
 * @namespace AskMerra/Util/AskMerra/Config/whenAskMerra
 */
export const whenAskMerra = (callback) => {
    const getApi = () => {
        const api = window.AskMerra;

        return api && typeof api.on === 'function' ? api : null;
    };

    if (getApi()) {
        callback(getApi());

        return;
    }

    const waiting = { attempts: 0 };
    const timer = setInterval(() => {
        const api = getApi();

        if (api) {
            clearInterval(timer);
            callback(api);
        } else if (++waiting.attempts > WIDGET_WAIT_ATTEMPTS) {
            clearInterval(timer);
        }
    }, WIDGET_WAIT_INTERVAL_MS);
};

/**
 * The path of a storefront URL inside the PWA, or null for another site.
 * @namespace AskMerra/Util/AskMerra/Config/toPwaPath
 */
export const toPwaPath = (url) => {
    try {
        const target = new URL(url, window.location.origin);

        return target.origin === window.location.origin ? `${target.pathname}${target.search}` : null;
    } catch (e) {
        return null;
    }
};

/**
 * Opens a product page without reloading the PWA.
 * @namespace AskMerra/Util/AskMerra/Config/openProduct
 */
export const openProduct = (url) => {
    const path = url ? toPwaPath(url) : null;

    if (path) {
        history.push(path);
    } else if (url && url !== '#') {
        window.location.href = url;
    }
};

/** @namespace AskMerra/Util/AskMerra/Config/toExternalId */
export const toExternalId = (product = {}) => {
    const id = getConfig().productIdentifier === 'sku' ? product.sku : product.id;

    return id === undefined || id === null || id === '' ? null : String(id);
};
