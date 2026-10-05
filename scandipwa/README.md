# @askmerra/scandipwa

The ScandiPWA side of [AskMerra for Magento](../README.md). The Magento module already puts the
AskMerra chat into the `<head>` of every ScandiPWA page; this extension connects the chat to the PWA:

- **Add to cart from the chat** with the PWA's own cart - the mini cart, the "added" notification and
  any cart customization of the project behave as with the PWA's own button. Products with options,
  out of stock, or refused by the cart (quantity...) open their page, without reloading the PWA.
  `afterAdd: 'cart'` in the settings goes to the cart page.
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
the Magento root). Then `yarn install` and build the theme as usual. After updating the Magento module
with Composer, run `yarn install --check-files` (or `yarn upgrade @askmerra/scandipwa`) before the
build, so the theme gets the new version.

## What it hooks into

| Namespace | Method | Purpose |
| --- | --- | --- |
| `Component/App/Component`, `Component/Router/Container`, `Component/Header/Container` | `__construct` / `componentDidMount` | Registers `AskMerra.on('add_to_cart')` once the app starts (several entry points: projects often replace one of these without its namespace) |
| `Route/Checkout/Container` | `savePaymentInformation` | Keeps the cart as ordered (payments that come back later) |
| `Route/Checkout/Container` | `setDetailsStep(orderID, ...)` | Reports the order, before the cart is emptied |
| `Route/ProductPage/Container` | `componentDidMount` / `componentDidUpdate` / `componentWillUnmount` | Product context |

Every helper in `src/util/AskMerra` has a `@namespace` (`AskMerra/Util/AskMerra/...`), so a project
can adjust one with a plugin of its own - e.g. `AskMerra/Util/AskMerra/Cart/addToCart` to add
gift-wrap options, or `AskMerra/Util/AskMerra/Cart/canAddDirectly` to allow more product types.

`document` receives an `askmerra:cart-added` event (`detail: {externalId, sku}`) after each add, for
anything else the project wants to do (open the mini cart, analytics...).

A component a project replaced **without** its `@namespace` comment cannot be extended by any
plugin. Checkouts that replace ScandiPWA's flow without `setDetailsStep(orderID, ...)` (or drop the
`Route/Checkout/Container` namespace) call `reportPurchase(orderID, totals)` from `src/util/AskMerra`
themselves; product pages without the `Route/ProductPage/Container` namespace call
`setProductContext(product)`.
