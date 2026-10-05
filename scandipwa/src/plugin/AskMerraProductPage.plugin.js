/**
 * Tells the chat which product is being viewed, as the shopper moves between product pages
 * without reloading. Active once the AskMerra widget offers AskMerra.setProduct().
 */
import { setProductContext } from '../util/AskMerra';

const componentDidMount = (args, callback, instance) => {
    const result = callback(...args);

    setProductContext(instance.props.product);

    return result;
};

const componentDidUpdate = (args, callback, instance) => {
    const result = callback(...args);
    const [prevProps = {}] = args;
    const { product } = instance.props;

    if (product?.id !== prevProps.product?.id) {
        setProductContext(product);
    }

    return result;
};

const componentWillUnmount = (args, callback) => {
    setProductContext(null);

    return callback(...args);
};

export default {
    'Route/ProductPage/Container': {
        'member-function': {
            componentDidMount: [{ position: 100, implementation: componentDidMount }],
            componentDidUpdate: [{ position: 100, implementation: componentDidUpdate }],
            componentWillUnmount: [{ position: 100, implementation: componentWillUnmount }],
        },
    },
};
