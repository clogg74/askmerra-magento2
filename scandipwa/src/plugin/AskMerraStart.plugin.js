/**
 * Starts AskMerra once the PWA runs: the chat's "Add to cart" goes into the PWA cart.
 * Hooked in several places because projects often replace one of these components without its
 * namespace; the start runs only once.
 */
import { startAskMerraCart } from '../util/AskMerra';

const startAfter = (args, callback) => {
    const result = callback(...args);

    startAskMerraCart();

    return result;
};

export default {
    'Component/App/Component': {
        'member-function': {
            __construct: [{ position: 100, implementation: startAfter }],
        },
    },
    'Component/Router/Container': {
        'member-function': {
            componentDidMount: [{ position: 100, implementation: startAfter }],
        },
    },
    'Component/Header/Container': {
        'member-function': {
            componentDidMount: [{ position: 100, implementation: startAfter }],
        },
    },
};
