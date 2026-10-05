# AskMerra for Magento 2

Connects a Magento 2.4 store to [AskMerra](https://askmerra.com), the AI shopping assistant that
answers shoppers' questions and recommends products from your catalog.

- **Catalog sync, two ways** - per store view, choose **Push API** (changes reach AskMerra within
  about a minute) or **Product feed** (a JSON or Google Shopping XML file AskMerra downloads).
- **Only what changed** - built for catalogs of 50,000+ products. Product, price and stock changes
  are picked up by Magento's change log (imports, ERP integrations and direct SQL included); only
  products whose content changed are rebuilt and sent. Feed files are rewritten from stored entries
  in seconds, never rebuilt from scratch.
- **You choose what AskMerra knows** - pick the product attributes to send (skin type, ingredients,
  size, colour...), the brand attribute, the description, images, product types, visibility, stock
  rules and excluded categories, or hide single products.
- **The chat on your storefront** - the widget script goes into the `<head>` of every page, from the
  settings (script URL + site key): Luma, Hyvä and Breeze through the layout, ScandiPWA by adding it
  to the PWA's page; other headless storefronts read the settings over GraphQL.
- **Add to cart from the chat**, into the real Magento cart, with the mini cart refreshing.
- **Sales attribution** - orders are reported (with the shopper's analytics consent) so AskMerra can
  show the sales the assistant helped with.
- **Admin tools** - connection check, sync status page with errors and actions, product payload
  preview, a products grid action, CLI commands, an admin warning when AskMerra refuses the key.

## Requirements

- Magento Open Source / Adobe Commerce 2.4.4 or later, PHP 8.1 - 8.5 (tested on 2.4.7-p4 with ScandiPWA on
  PHP 8.3, and 2.4.9 with Luma on PHP 8.4 and with Hyvä 1.5 on PHP 8.5)
- Magento cron running every minute (`bin/magento cron:run`)
- An AskMerra shop with its keys (AskMerra dashboard > API keys): the **secret key** (`sk_live_...`)
  for the Push API, the **site key** (`pk_live_...`) for the widget

## Install

The module is installed with Composer from its public GitHub repository. Add the repository and the
install preference to the project's `composer.json` (merge them into the `repositories` and `config`
sections it already has):

```json
"repositories": {
    "askmerra": {
        "type": "vcs",
        "url": "https://github.com/clogg74/askmerra-magento2.git",
        "no-api": true
    }
},
"config": {
    "preferred-install": {
        "askmerra/*": "source",
        "*": "dist"
    }
}
```

With `no-api` and the `source` preference, Composer clones the repository over HTTPS and never calls
the GitHub API for it: servers need no GitHub token or SSH key, and API rate limits or an old token
configured on the server do not get in the way.

```bash
composer require askmerra/magento2-connector:^1.0
bin/magento module:enable AskMerra_Connector
bin/magento setup:upgrade
bin/magento setup:di:compile                 # production mode
bin/magento setup:static-content:deploy      # production mode
bin/magento cache:flush
```

Releases are Git tags (`v1.0.0`); Composer picks the versions from them. Updating:
`composer update askmerra/magento2-connector`.

`composer config repositories.askmerra vcs https://github.com/clogg74/askmerra-magento2.git` also adds
the repository, but recent Composer versions rewrite the whole `repositories` section into a list when
they do; editing `composer.json` keeps the diff to these lines.

`setup:upgrade` creates three tables, the **Hide from AskMerra** product attribute, the secret part of
the feed URLs, and puts the **AskMerra product sync** indexer on **Update by Schedule**.

## Set up (5 minutes)

1. **Stores > Configuration > AskMerra > AI Shopping Assistant**, at the scope of the store view
   (or website) to connect:
   - **Enable AskMerra**: Yes
   - **Secret key** and **Site key** from the AskMerra dashboard
   - **Check connection** - checks the keys as typed, before saving
2. **Catalog**: choose **How AskMerra gets the catalog**, the **Product attributes to include** and
   the **Brand attribute**. Save.
3. Push API: products start flowing within a minute. Product feed: copy the **Feed URL** shown under
   *Product feed* into AskMerra (Catalog > Add source > Feed URL) once it says *Published*.
4. In AskMerra, add the store's domain to the widget's allowed domains. The chat appears on the
   storefront (see [Storefront](#storefront) for headless stores).
5. Follow it under **Marketing > AskMerra > Sync status**.

One AskMerra shop has one currency. Store views in another currency (or another brand) connect to
their own AskMerra shop: switch the configuration scope to that store view and enter its keys there.
Store views in the same currency but another language can share a shop: each sends its own language.

## How the sync works

```
 product saved / imported / ERP SQL / stock change
        |  database triggers (Magento change log, "Update by Schedule")
        v
 askmerra_products_cl --(indexer cron)--> askmerra_queue (store view, product)
        |                                     ^  daily check: missing products, products that left the
        |                                     |  catalog, special prices and catalog rules starting or
        |                                     |  ending today; weekly full rebuild (configurable)
        |                                     |  every 15 min: products that sold out through orders
        v
 askmerra_process_queue (cron, every minute, up to 50 s)
        |  build the product as AskMerra sees it, hash it, compare with askmerra_product_state
        |  unchanged -> nothing is sent
        +--> Push API store view:  batchUpsert (up to 500 per call, 10 MB) / batchDelete
        +--> Feed store view:      store the finished entry, mark the feed changed
                                         |
                       askmerra_feed_generate (cron, configurable) - writes the file from the
                       stored entries, only when something changed, never half built
```

- **Change detection** - triggers on the product tables (attributes, websites, categories, media,
  stock items, product relations, catalog rule prices) fill Magento's change log, so changes made by
  imports, ERP integrations or plain SQL are seen like admin saves. Variants queue their configurable,
  bundle or grouped product.
- **Stock** - orders change what can be bought through MSI reservations without touching a product
  row; a light check every 15 minutes compares each product's salable state with what was sent.
- **Dates** - a special price or catalog price rule that starts or ends today changes no row; the
  daily check (at **Daily maintenance at**) queues those products. A full catalog rule reindex is
  compared before/after, and products whose rule price changed are queued.
- **Only changed content is sent** - every product is built, hashed and compared with what AskMerra
  has. A full rebuild of 50,000 products sends nothing when nothing changed. This matters: AskMerra
  re-reads a product with AI (billed usage) when its name, description, categories, brand or
  attributes change.
- **Removals** - products that are deleted, disabled, hidden, out of stock (when excluded) or moved
  out of the website are removed from AskMerra; the daily check also catches products that left the
  catalog in ways no trigger saw.
- **Failures** - every kind of failure is handled and recovers by itself:
  - a product AskMerra rejects is marked failed with AskMerra's message (status page), as is a
    product too large to send or one with the same SKU as another product AskMerra has;
  - when AskMerra does not answer, the store view pauses with growing pauses (1 minute ... 6 hours)
    and resumes by itself, without giving up on any product;
  - a rate limit pauses the store view as long as AskMerra asks; a request too large is split;
  - a refused key pauses the store view, shows an admin warning and resumes by itself once the key
    works;
  - a product edited while it is being sent is sent again with the edit; an old copy (another id or
    language) is removed before the new one is recorded, and never when another product has that id.
- **Feed safety** - AskMerra deactivates every product missing from a feed, so a store view's first
  feed is written only once its whole catalog is ready, and a file is never published with products
  missing.
- **Switching between Push API and feed** (in the admin, with `config:set` or a deployed config) -
  noticed within a minute; the whole catalog is sent again the new way, the new feed is published
  once complete, and the feed file of a store view that no longer uses a feed is deleted. Remove the
  source you no longer use in the AskMerra dashboard.
- **A new key or store settings** - a new secret key or API URL (from a test shop to the live one,
  say) sends the whole catalog to the new AskMerra shop. A change to the locale, currencies, base
  URLs, URL suffix or tax display of a store view rebuilds its catalog within a minute, and only the
  products it changed are sent. Both are noticed however the configuration changes (admin,
  `config:set`, a deployed `config.php`).

Measured on a 1,145-product catalog: building and comparing every product takes about 2 seconds; a
feed file is written from stored entries in 0.3 seconds; the stock check takes 0.06 seconds.

## Settings

All settings are per store view unless marked *global*.

| Group | Setting | What it does |
| --- | --- | --- |
| Connection | Enable AskMerra | Turns everything on for the scope |
| | Secret key | Push API key, stored encrypted, never sent to the browser |
| | Site key | Public widget key |
| | Language | `Automatic` uses the store view locale (ro_RO -> ro); AskMerra serves en, ro, it, fr, de, es |
| Catalog | How AskMerra gets the catalog | **Push API** (recommended) or **Product feed** |
| | Product identifier | Product ID (recommended, never changes) or SKU. Changing it replaces the catalog in AskMerra |
| | Product attributes to include | Multi-select of every product attribute; sent under their store view labels. Dropdowns become their labels, multi-selects lists, yes/no booleans |
| | Brand attribute | Sent as the product's brand |
| | Description | Description, short description, or both; HTML, Page Builder markup and widget codes are removed |
| | Include variant options | Configurable products: the sizes/colours a shopper can pick become attributes |
| | Images per product | 1-20, main image first |
| | Product types / visibility | Which products are sent |
| | Include out-of-stock products | Yes (recommended): AskMerra knows them but does not recommend them |
| | Send stock quantity | Simple, virtual and downloadable products |
| | Exclude categories | Category IDs; subcategories too. Single products: **Hide from AskMerra** on the product |
| Sync | Products per request | *global*, 1-500 |
| | Daily maintenance at | *global*, server time |
| | Full rebuild | *global*, weekly (Sunday), daily, monthly or never |
| Product feed | Format | JSON (all attributes) or Google Shopping XML (standard attributes; also usable in Google Merchant Center) |
| | Regenerate the feed | *global*, every 1, 3, 6, 8, 12 or 24 hours (written only when something changed) |
| | Feed URLs | Per store view, ready to copy |
| Storefront widget | Show the chat widget | Adds the script to the `<head>` of every page |
| | Widget script URL | The `src` of AskMerra's snippet (dashboard > Install); with the site key it makes the snippet |
| | Position, Open on page load, Show on the checkout page (No) | |
| | Know the product being viewed | Product pages tell the assistant which product the shopper is looking at |
| | Add to cart from the chat / After adding to cart | Into the Magento cart; products with options open their page |
| | Report orders to AskMerra | Order number, total and products - no name, e-mail or address |
| | Analytics consent | Automatic (Google Consent Mode / Tag Manager), Magento cookie notice, Not needed, Manual. Without Google Consent Mode on the site, Automatic never gets consent and no order is reported: choose another mode |
| Advanced | API URL | Only for an AskMerra test environment |
| | Request timeout, Debug log | *global*; the debug log writes every request to `var/log/askmerra.log` |

Saving catalog or connection settings rebuilds the affected store views' catalog (still sending only
what changed).

## Admin

- **Marketing > AskMerra > Sync status** - per store view: products in AskMerra, waiting, failed,
  last sent, daily check and full rebuild results, the feed file, the latest errors with links to the
  products, and the actions *Send changes now*, *Write the feed now*, *Retry failed products*,
  *Run the daily check now*, *Full rebuild*, *Give the feeds new URLs* and *Remove its products from
  AskMerra* (for store views that stopped syncing). **Preview a product** shows any product exactly
  as AskMerra receives it, or why it is not sent, and whether AskMerra has the latest version.
- **Catalog > Products > Actions > Send to AskMerra now** queues the selected products.
- **Hide from AskMerra** (product page, AskMerra group, per store view).
- An admin warning appears when AskMerra refuses a store view's key, and the status page warns when
  cron is not running or the indexer is not on *Update by Schedule*.

## Command line

```bash
bin/magento askmerra:test                      # checks every store view's keys
bin/magento askmerra:status                    # what the Sync status page shows
bin/magento askmerra:sync                      # sends the queue now instead of waiting for cron
bin/magento askmerra:sync --rebuild            # first sync of a large catalog, with progress
bin/magento askmerra:sync --check              # runs the daily check first
bin/magento askmerra:sync --product=24-MB01,57 # only these products (ids or SKUs)
bin/magento askmerra:sync --retry-failed --store=default --time=300
bin/magento askmerra:feed:generate [--force]   # writes the feed files now
bin/magento askmerra:payload 24-MB01 --store=default   # a product as AskMerra receives it
bin/magento askmerra:remove --store=default    # takes a store view's products out of AskMerra
```

## Storefront

### Luma, Hyvä, Breeze

Nothing to do: with **Show the chat widget** on and a site key set, every page has the AskMerra script
in its `<head>` (layout container `head.additional`, `async`: it never delays the page) with the store
view's site key and language. On product pages the assistant knows the product. The chat stays off
the checkout page unless asked for; other one-step checkouts can be added in `etc/frontend/di.xml`
(`checkoutActions`).

`view/frontend/web/js/storefront.js` (plain JavaScript, no RequireJS) connects the chat to Magento:

- **Add to cart** - `POST /askmerra/cart/add` with the form key. Simple and virtual products go into
  the cart (the mini cart refreshes: Luma customer data, Hyvä `reload-customer-section-data`);
  products with options, out of stock or with required custom options open their page.
  `document` receives an `askmerra:cart-added` event for themes that open a cart drawer, with
  `detail: {externalId, sku, name, cartQty}` (`cartQty`: the items in the cart after the add). The
  handler resolves `true` once the product is in the cart, so the chat's button shows it was added.
- **Orders** - on the success page the order is passed to `AskMerra.trackPurchase()`; AskMerra sends
  it once the shopper's analytics consent allows it.
- **Consent** - *Magento cookie notice* grants it when the shopper accepts the notice.

The widget script and API hosts are allowed in Magento's Content Security Policy
(`etc/csp_whitelist.xml`, plus the hosts set under Advanced). If the chat shows an avatar from
another host, allow it in `img-src`.

### ScandiPWA

ScandiPWA serves its own HTML page instead of Magento's layout, so the module adds the same script to
the `<head>` of that page (`Plugin\View\AddWidgetToHead`, before the full page cache stores it).
Nothing to change in the theme for the chat to show. The settings the PWA needs are in
`window.AskMerraMagento`: `singlePageApp: true`, `addToCart`, `afterAdd`, `productIdentifier`.

The cart and the order success page live in the PWA. The ScandiPWA extension in
[`scandipwa/`](scandipwa/README.md) (`@askmerra/scandipwa`, shipped in this Composer package) connects
them - two lines in the theme's `package.json`, then build the theme:

- **Add to cart** from the chat with the PWA's own cart (mini cart, notifications and cart
  customizations as with the PWA's own button); products with options open their page.
- **The cart icon and mini cart** also show products the AskMerra widget adds by itself (with the
  chat's add to cart turned off here): the PWA reads its cart again on the widget's
  `askmerra:cart-added` event.
- **Orders** reported on the checkout success step.
- **The product being viewed**, once the AskMerra widget offers `AskMerra.setProduct()` (the widget
  reads `productId` only when it starts, and a single-page app changes product without loading a
  page).

### Other headless storefronts (PWA Studio...)

The storefront loads the widget itself; Magento gives it the settings:

```graphql
{
  askMerraWidgetConfig {
    enabled script_url site_key locale position open_on_load api_url
    product_identifier product_context add_to_cart after_add_to_cart track_purchases consent_mode
  }
}
```

```js
// Once, when the app starts (send the Store header of the store view).
const { data } = await fetch('/graphql', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Store: storeCode },
    body: JSON.stringify({ query: '{ askMerraWidgetConfig { enabled script_url site_key locale position open_on_load api_url consent_mode } }' }),
}).then((response) => response.json());
const config = data.askMerraWidgetConfig;

if (config.enabled) {
    window.AskMerraSettings = Object.assign({}, window.AskMerraSettings);
    if (config.consent_mode === 'granted') window.AskMerraSettings.consent = { analytics: true };
    // On a product page opened directly: window.AskMerraSettings.productId = product.id (or sku).

    const script = document.createElement('script');
    script.src = config.script_url;
    script.async = true;
    script.dataset.siteKey = config.site_key;
    if (config.locale) script.dataset.locale = config.locale;
    if (config.position) script.dataset.position = config.position;
    if (config.open_on_load) script.dataset.open = 'true';
    if (config.api_url) script.dataset.apiUrl = config.api_url;
    document.body.appendChild(script);
}

// Add to cart with the PWA's own cart (GraphQL addProductsToCart); externalId is the product id
// or SKU, as product_identifier says. Return true (or a promise of true) once the product is in
// the cart: the chat's button then shows it was added.
window.AskMerra?.on('add_to_cart', ({ externalId, sku, url }) => { /* add sku to the cart, or open url */ });

// Order success page:
window.AskMerra?.trackPurchase({
    transaction_id: orderNumber, value: grandTotal, currency: 'RON',
    items: [{ item_id: '33', item_name: 'Product', price: 44.6, quantity: 1 }],
});
```

`window.AskMerra` exists as soon as the script has run (calls made before the chat is ready are
queued). In ScandiPWA this belongs in a plugin of the app's root component; the module's layout
blocks do not render there because ScandiPWA serves its own HTML shell.

## Troubleshooting

| Symptom | Check |
| --- | --- |
| Nothing is sent | `bin/magento askmerra:status`. Magento cron must run every minute; the indexer must be on *Update by Schedule* (`bin/magento indexer:set-mode schedule askmerra_products`) |
| A product is missing in AskMerra | *Preview a product* or `bin/magento askmerra:payload <sku>` says why |
| "AskMerra refuses this store view's key" | The secret key was revoked or the AskMerra shop is suspended; fix the key - the queue resumes by itself |
| Feed URL returns 404 | The first feed is written once the whole catalog is ready (status page shows how many products are left); `bin/magento askmerra:sync` speeds it up |
| The chat does not show | Site key set, store domain allowed in AskMerra, browser console; on checkout it is off by default; headless storefronts load it themselves |
| Details | `var/log/askmerra.log`; turn on **Debug log** to see every request and response |

## Uninstall

```bash
# For each store view that synced: turn AskMerra off for it, then
bin/magento askmerra:remove --store=<code>
bin/magento module:uninstall AskMerra_Connector    # Composer installs: removes tables, attribute, settings
```

Feed sources must also be deleted in the AskMerra dashboard: AskMerra keeps a feed's products when the
file disappears.

## Development

`dev/mock-askmerra-api.php` is a local stand-in for the AskMerra Push API that validates every product
like AskMerra does and can simulate rate limits, rejected products, large requests and outages:

```bash
php -S 127.0.0.1:8765 dev/mock-askmerra-api.php
bin/magento config:set askmerra/advanced/api_url http://127.0.0.1:8765
# secret keys: any sk_test_..., sk_test_revoked answers 401
# what it received and a request log: /tmp/askmerra-mock (or $ASKMERRA_MOCK_DIR)
echo '{"mode":"rate_limit"}' > /tmp/askmerra-mock/mode.json   # normal | rate_limit | server_error | reject_first | too_large_over:N
```

See [docs/CONNECTOR_SPEC.md](docs/CONNECTOR_SPEC.md) for the platform-independent design (the base
for the WooCommerce plugin) and [CHANGELOG.md](CHANGELOG.md).
