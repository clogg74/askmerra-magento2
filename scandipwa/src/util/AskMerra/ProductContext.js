/**
 * Tells the chat which product is being viewed, as the shopper moves between product pages without
 * reloading. Active once the AskMerra widget offers AskMerra.setProduct().
 */
import { toExternalId, whenAskMerra } from './Config';

export const productContextState = { externalId: null, isWaiting: false };

/**
 * The product being viewed, or null; applied as soon as the widget is there (a product page opened
 * directly mounts before the widget loads).
 * @namespace AskMerra/Util/AskMerra/ProductContext/setProductContext
 */
export const setProductContext = (product) => {
    productContextState.externalId = product ? toExternalId(product) : null;

    if (productContextState.isWaiting) {
        return;
    }

    productContextState.isWaiting = true;

    whenAskMerra((api) => {
        productContextState.isWaiting = false;

        if (typeof api.setProduct === 'function') {
            api.setProduct(productContextState.externalId);
        }
    });
};
