# @askmerra/scandipwa

The ScandiPWA side of [AskMerra for Magento](../README.md). The Magento module already puts the
AskMerra chat into the `<head>` of every ScandiPWA page; this extension connects the chat to the PWA:

- **Add to cart from the chat** with the PWA's own cart - the mini cart, the "added" notification and
  any cart customization of the project behave as with the PWA's own button. Products with options,
  out of stock, or refused by the cart (quantity...) open their page, without reloading the PWA.
  `afterAdd: 'cart'` in the settings goes to the cart page. The chat's button shows "added" once the
  product is in the cart.
- **The cart icon and mini cart stay current** when the AskMerra widget fills the cart itself. With
  the chat's add to cart turned off in the settings, no `add_to_cart` handler is registered and the
  widget adds the product through Magento's GraphQL on its own. ScandiPWA keeps its cart in memory and
  does not notice, so on the widget's `askmerra:cart-added` event the extension reads the cart again
  (`updateInitialCartData`, as ScandiPWA does after a sign-in). The product shows without a page load.
- **Orders** on the checkout success step are reported to AskMerra (order number, total, currency,
  products identified like the catalog) - sent once the shopper's analytics consent allows it.
- **The product being viewed** is passed to the chat as the shopper moves between product pages
  (with AskMerra widgets that offer `AskMerra.setProduct()`).

Settings come from the Magento configuration (Stores > Configuration > AskMerra) through
`window.AskMerraMagento`; nothing is configured in the theme.

Tested with ScandiPWA 6.4; it uses only APIs that ScandiPWA 5 has as well.

## Install

The extension ships inside the Composer package of the Magento module. In the theme's
`package.json`:

```json
{
    "dependencies": {
        "@askmerra/scandipwa": "file:../../../../vendor/askmerra/magento2-connector/scandipwa"
    },
    "scandipwa": {
        "extensions": {
            "@askmerra/scandipwa": true
        }
    }
}
```

The path is relative to the theme folder (`app/design/frontend/<Vendor>/<theme>` -> four levels up to
the Magento root). Then `yarn install` and build the theme as usual.

After updating the Magento module with Composer, run `yarn upgrade @askmerra/scandipwa` before the
build. Yarn copies a `file:` package into `node_modules` once and keeps that copy while its version
stays the same (`yarn install --check-files` does not refresh it either). `yarn upgrade` copies it
again and leaves `package.json` and `yarn.lock` as they are.

## What it hooks into

| Namespace | Method | Purpose |
| --- | --- | --- |
| `Component/App/Component`, `Component/Router/Container`, `Component/Header/Container` | `__construct` / `componentDidMount` | Registers `AskMerra.on('add_to_cart')` and the `askmerra:cart-added` listener once the app starts (several entry points: projects often replace one of these without its namespace) |
| `Route/Checkout/Container` | `savePaymentInformation` | Keeps the cart as ordered (payments that come back later) |
| `Route/Checkout/Container` | `setDetailsStep(orderID, ...)` | Reports the order, before the cart is emptied |
| `Route/ProductPage/Container` | `componentDidMount` / `componentDidUpdate` / `componentWillUnmount` | Product context |

Every helper in `src/util/AskMerra` has a `@namespace` (`AskMerra/Util/AskMerra/...`), so a project
can adjust one with a plugin of its own - e.g. `AskMerra/Util/AskMerra/Cart/addToCart` to add
gift-wrap options, or `AskMerra/Util/AskMerra/Cart/canAddDirectly` to allow more product types.
`addToCart` resolves `true` once the product is in the cart (the chat's button then shows it was
added); a plugin that replaces it should do the same. A project whose cart cannot be read again with
`updateInitialCartData` changes `AskMerra/Util/AskMerra/Cart/refreshCart`.

`document` receives an `askmerra:cart-added` event after each add, for anything else the project
wants to do (open the mini cart, analytics...). After an add by this extension its `detail` is
`{externalId, sku, name, cartQty}` (`cartQty`: the items in the cart after the add); after an add by
the widget itself, `{externalId, sku}`.

A component a project replaced **without** its `@namespace` comment cannot be extended by any
plugin. Checkouts that replace ScandiPWA's flow without `setDetailsStep(orderID, ...)` (or drop the
`Route/Checkout/Container` namespace) call `reportPurchase(orderID, totals)` from `src/util/AskMerra`
themselves; product pages without the `Route/ProductPage/Container` namespace call
`setProductContext(product)`.
