/**
 * The order on the checkout success step, for AskMerra's sales attribution: order number, total,
 * currency and products (GA4 "purchase" shape), no personal data. AskMerra sends it once the
 * shopper's analytics consent allows it.
 */
import { getConfig, toExternalId, whenAskMerra } from './Config';

export const CHECKOUT_CART_KEY = 'askmerra:checkout-cart';
export const REPORTED_ORDER_KEY_PREFIX = 'askmerra:order:';
export const CENTS = 100;

/** @namespace AskMerra/Util/AskMerra/Purchase/roundMoney */
export const roundMoney = (value) => Math.round((Number(value) || 0) * CENTS) / CENTS;

/**
 * One cart line as an order item; the line of a configurable carries the chosen variant's SKU, so
 * the product (the parent AskMerra knows) identifies it.
 * @namespace AskMerra/Util/AskMerra/Purchase/toPurchaseItem
 */
export const toPurchaseItem = (item) => {
    const { product = {}, prices = {} } = item;
    const quantity = Number(item.quantity || item.qty) || 1;
    const rowTotal = prices.row_total_including_tax?.value ?? prices.row_total?.value ?? 0;
    const discount = prices.total_item_discount?.value ?? 0;

    return {
        item_id: toExternalId({ id: product.id || item.product_id, sku: product.sku || item.sku }) || '',
        item_name: product.name || item.name || '',
        price: roundMoney(Math.max(0, rowTotal - discount) / quantity),
        quantity,
    };
};

/** @namespace AskMerra/Util/AskMerra/Purchase/buildPurchase */
export const buildPurchase = (orderID, totals = {}) => {
    const { items = [], prices = {} } = totals;

    return {
        transaction_id: String(orderID),
        value: roundMoney(prices.grand_total?.value ?? totals.grand_total),
        currency: prices.grand_total?.currency || prices.quote_currency_code || totals.quote_currency_code || undefined,
        items: items.map(toPurchaseItem).filter(({ item_id }) => item_id !== ''),
    };
};

/**
 * Keeps the cart as it is before the order is placed, for payments that come back later.
 * @namespace AskMerra/Util/AskMerra/Purchase/keepCheckoutCart
 */
export const keepCheckoutCart = (totals) => {
    if (!totals || !Array.isArray(totals.items) || !totals.items.length) {
        return;
    }

    try {
        sessionStorage.setItem(CHECKOUT_CART_KEY, JSON.stringify({ items: totals.items, prices: totals.prices }));
    } catch (e) {
        // storage full or blocked: the success step still has the cart in most flows
    }
};

/** @namespace AskMerra/Util/AskMerra/Purchase/takeCheckoutCart */
export const takeCheckoutCart = () => {
    try {
        const value = JSON.parse(sessionStorage.getItem(CHECKOUT_CART_KEY) || 'null');

        sessionStorage.removeItem(CHECKOUT_CART_KEY);

        return value;
    } catch (e) {
        return null;
    }
};

/**
 * Whether this order is reported for the first time in this browser session.
 * @namespace AskMerra/Util/AskMerra/Purchase/isFirstReport
 */
export const isFirstReport = (orderID) => {
    const key = `${REPORTED_ORDER_KEY_PREFIX}${orderID}`;

    try {
        if (sessionStorage.getItem(key)) {
            return false;
        }

        sessionStorage.setItem(key, '1');
    } catch (e) {
        // storage blocked: AskMerra counts an order id once anyway
    }

    return true;
};

/** @namespace AskMerra/Util/AskMerra/Purchase/reportPurchase */
export const reportPurchase = (orderID, totals) => {
    const kept = takeCheckoutCart();

    if (!orderID || getConfig().trackPurchases === false || !isFirstReport(orderID)) {
        return;
    }

    const source = totals && Array.isArray(totals.items) && totals.items.length ? totals : kept;
    const purchase = buildPurchase(orderID, source || {});

    whenAskMerra((api) => {
        if (typeof api.trackPurchase === 'function') {
            api.trackPurchase(purchase);
        }
    });
};
