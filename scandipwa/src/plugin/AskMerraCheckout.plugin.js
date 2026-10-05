/**
 * Reports the order to AskMerra on the checkout success step, with the cart as it was ordered.
 * Works with checkouts that keep ScandiPWA's savePaymentInformation / setDetailsStep(orderID, ...).
 */
import { keepCheckoutCart, reportPurchase } from '../util/AskMerra';

const savePaymentInformation = (args, callback, instance) => {
    keepCheckoutCart(instance.props.totals);

    return callback(...args);
};

const setDetailsStep = (args, callback, instance) => {
    const [orderID] = args;

    // Before the original: it empties the cart.
    reportPurchase(orderID, instance.props.totals);

    return callback(...args);
};

export default {
    'Route/Checkout/Container': {
        'member-function': {
            savePaymentInformation: [{ position: 100, implementation: savePaymentInformation }],
            setDetailsStep: [{ position: 100, implementation: setDetailsStep }],
        },
    },
};
