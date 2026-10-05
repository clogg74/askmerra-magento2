/**
 * "Add to cart" in the AskMerra chat, with the PWA's own cart: the mini cart, the notifications and
 * any cart customization behave as with the PWA's own button. Products with options, out of stock
 * or refused by the cart open their page instead.
 *
 * Without this handler (the chat's add to cart turned off here, or a build without this extension)
 * the AskMerra widget fills the shopper's cart itself and then fires "askmerra:cart-added"; the PWA
 * keeps its cart in memory, so the cart is read again then (refreshCart()).
 */
import { Field, Query } from '@tilework/opus';

import history from 'Util/History';
import { fetchQuery } from 'Util/Request/Query';
import { getStore } from 'Util/Store';
import { appendWithStoreCode } from 'Util/Url';

import { getConfig, openProduct, whenAskMerra } from './Config';

export const CartDispatcher = import(
    /* webpackMode: "lazy", webpackChunkName: "dispatchers" */
    'Store/Cart/Cart.dispatcher'
);

/** Product types the cart takes as they are; the others need options picked on their page. */
export const DIRECT_TYPES = ['SimpleProduct', 'VirtualProduct'];

export const OUT_OF_STOCK = 'OUT_OF_STOCK';

/**
 * Whether the chat handlers are registered (once per page load), and whether the
 * "askmerra:cart-added" being dispatched is this extension's own (its cart is up to date).
 */
export const askMerraCartState = { isStarted: false, isOwnEvent: false };

/** @namespace AskMerra/Util/AskMerra/Cart/getCartQuantity */
export const getCartQuantity = () => Number(getStore().getState()?.CartReducer?.cartTotals?.total_quantity) || 0;

/**
 * Type and stock of the product behind an add_to_cart event.
 * @namespace AskMerra/Util/AskMerra/Cart/getProduct
 */
export const getProduct = async (sku) => {
    const query = new Query('products')
        .addArgument('filter', 'ProductAttributeFilterInput', { sku: { eq: sku } })
        .addField(new Field('items', true).addFieldList(['__typename', 'sku', 'name', 'stock_status']));

    const { products: { items = [] } = {} } = await fetchQuery(query);

    return items.find((item) => item.sku === sku) || items[0] || null;
};

/** @namespace AskMerra/Util/AskMerra/Cart/canAddDirectly */
export const canAddDirectly = (product) => !!product
    && DIRECT_TYPES.includes(product.__typename)
    && product.stock_status !== OUT_OF_STOCK;

/**
 * The chat's add_to_cart event: {externalId, sku, url}.
 * @returns {Promise<boolean>} true once the product is in the cart - the chat then shows it so
 * @namespace AskMerra/Util/AskMerra/Cart/addToCart
 */
export const addToCart = async ({ externalId, sku, url } = {}) => {
    if (!sku) {
        openProduct(url);

        return false;
    }

    try {
        const product = await getProduct(sku);

        if (!canAddDirectly(product)) {
            openProduct(url);

            return false;
        }

        const { dispatch } = getStore();
        const quantityBefore = getCartQuantity();
        const { default: dispatcher } = await CartDispatcher;

        await dispatcher.addProductToCart(dispatch, { products: [{ sku, quantity: 1 }] });

        // The cart refused it (quantity, required options...): it said why; the page shows the rest.
        if (getCartQuantity() <= quantityBefore) {
            openProduct(url);

            return false;
        }

        // Themes may open a cart drawer on it; the PWA's cart is already up to date.
        askMerraCartState.isOwnEvent = true;

        try {
            document.dispatchEvent(new CustomEvent('askmerra:cart-added', {
                detail: { externalId, sku, name: product.name, cartQty: getCartQuantity() },
            }));
        } finally {
            askMerraCartState.isOwnEvent = false;
        }

        if (getConfig().afterAdd === 'cart') {
            history.push(appendWithStoreCode('/cart'));
        }

        return true;
    } catch (e) {
        openProduct(url);

        return false;
    }
};

/**
 * Reads the cart from Magento again, so the mini cart and the cart icon show a product added outside
 * the PWA - by the AskMerra widget itself (see the top of this file).
 * @namespace AskMerra/Util/AskMerra/Cart/refreshCart
 */
export const refreshCart = async () => {
    try {
        const { dispatch, getState } = getStore();
        const { default: dispatcher } = await CartDispatcher;
        const isSignedIn = !!getState()?.MyAccountReducer?.isSignedIn;

        await dispatcher.updateInitialCartData(dispatch, isSignedIn, true);
    } catch (e) {
        // The next page load shows it.
    }
};

/**
 * Registers the chat's add_to_cart handler once, when the app starts.
 * @namespace AskMerra/Util/AskMerra/Cart/startAskMerraCart
 */
export const startAskMerraCart = () => {
    if (askMerraCartState.isStarted || typeof window === 'undefined') {
        return;
    }

    askMerraCartState.isStarted = true;

    // Products the widget put into the cart itself.
    document.addEventListener('askmerra:cart-added', () => {
        if (!askMerraCartState.isOwnEvent) {
            refreshCart();
        }
    });

    if (getConfig().addToCart) {
        // The promise tells the chat whether the product is in the cart.
        whenAskMerra((api) => api.on('add_to_cart', (item) => addToCart(item)));
    }
};
