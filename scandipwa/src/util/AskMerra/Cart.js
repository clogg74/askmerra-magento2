/**
 * "Add to cart" in the AskMerra chat, with the PWA's own cart: the mini cart, the notifications and
 * any cart customization behave as with the PWA's own button. Products with options, out of stock
 * or refused by the cart open their page instead.
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

/** Whether the chat handlers are registered (once per page load). */
export const askMerraCartState = { isStarted: false };

/** @namespace AskMerra/Util/AskMerra/Cart/getCartQuantity */
export const getCartQuantity = () => Number(getStore().getState()?.CartReducer?.cartTotals?.total_quantity) || 0;

/**
 * Type and stock of the product behind an add_to_cart event.
 * @namespace AskMerra/Util/AskMerra/Cart/getProduct
 */
export const getProduct = async (sku) => {
    const query = new Query('products')
        .addArgument('filter', 'ProductAttributeFilterInput', { sku: { eq: sku } })
        .addField(new Field('items', true).addFieldList(['__typename', 'sku', 'stock_status']));

    const { products: { items = [] } = {} } = await fetchQuery(query);

    return items.find((item) => item.sku === sku) || items[0] || null;
};

/** @namespace AskMerra/Util/AskMerra/Cart/canAddDirectly */
export const canAddDirectly = (product) => !!product
    && DIRECT_TYPES.includes(product.__typename)
    && product.stock_status !== OUT_OF_STOCK;

/**
 * The chat's add_to_cart event: {externalId, sku, url}.
 * @namespace AskMerra/Util/AskMerra/Cart/addToCart
 */
export const addToCart = async ({ externalId, sku, url } = {}) => {
    if (!sku) {
        openProduct(url);

        return;
    }

    try {
        if (!canAddDirectly(await getProduct(sku))) {
            openProduct(url);

            return;
        }

        const { dispatch } = getStore();
        const quantityBefore = getCartQuantity();
        const { default: dispatcher } = await CartDispatcher;

        await dispatcher.addProductToCart(dispatch, { products: [{ sku, quantity: 1 }] });

        // The cart refused it (quantity, required options...): it said why; the page shows the rest.
        if (getCartQuantity() <= quantityBefore) {
            openProduct(url);

            return;
        }

        document.dispatchEvent(new CustomEvent('askmerra:cart-added', { detail: { externalId, sku } }));

        if (getConfig().afterAdd === 'cart') {
            history.push(appendWithStoreCode('/cart'));
        }
    } catch (e) {
        openProduct(url);
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

    if (getConfig().addToCart) {
        whenAskMerra((api) => api.on('add_to_cart', (item) => {
            addToCart(item);
        }));
    }
};
