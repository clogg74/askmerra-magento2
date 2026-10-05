# AskMerra store connector - specification

What a store-platform connector for AskMerra does, as built and tested in this Magento module. It is
written to be ported: section 9 maps every part to WooCommerce. Behaviour should stay the same on
every platform, so merchants, support and AskMerra see one product.

Status of the AskMerra contract: October 2026 (Push API v1, widget v1).

## 1. Responsibilities

| Area | What the connector does |
| --- | --- |
| Catalog | Keeps each storefront's catalog in AskMerra, by **Push API** or a **product feed**, sending only products whose content changed |
| Storefront | Loads the chat widget, tells it the product being viewed, handles **add to cart** with the platform's cart, reports **orders** for attribution, passes **analytics consent** |
| Admin | Settings, connection check, sync status with errors and actions, product preview, product-level exclusion, bulk "send now", CLI, warnings |

A **storefront** is one language + one currency of the store (Magento: store view; WooCommerce: the
site, or one WPML/Polylang language). Each storefront is connected to one AskMerra shop (one shop =
one currency, one site key) and sends one AskMerra locale. Several storefronts can share a shop when
they have the same currency and different locales.

## 2. AskMerra contract

### 2.1 Push API

| Call | Body | Answer |
| --- | --- | --- |
| `GET /v1/catalog/ping` | - | `{ok, shopId, shopName}` |
| `POST /v1/catalog/products:batchUpsert` | `{products: [1..500], locale?}`, max 10 MB | `{received, created, updated, unchanged, failed, errors: [{index, external_id?, message}]}` |
| `POST /v1/catalog/products:batchDelete` | `{external_ids: [1..1000], locale?}` | `{deleted}` |

- Auth: `Authorization: Bearer sk_live_...` (secret key, server side only).
- 120 requests per minute per key; `429` with `Retry-After`. Errors: `{error: {code, message, details?, requestId}}`.
- Upsert **replaces** the product: every field left out is cleared. Always send complete products.
- Delete deactivates (soft); upserting the id again brings it back.
- There is no "replace all" for push: the connector must find and delete products that left the catalog.
- AskMerra re-reads a product with AI (billed usage) when its content hash changes: name, description,
  categories, brand, attributes. Send stable content; never send volatile values as attributes.
- Push stores text as sent - no HTML conversion. The connector sends plain text.
- `errors[]` lists products rejected by validation (by index in the batch); the rest is stored.

### 2.2 Product (ProductInput)

| Field | Rules | Notes |
| --- | --- | --- |
| `external_id` | string 1-255, required | The connector's product id; the same value everywhere (catalog, product context, cart, orders) |
| `sku` | string <= 255 | |
| `parent_external_id` | string <= 255 | Not used: one AskMerra product per sellable parent (see 4) |
| `name` | string 1-500, required | |
| `description` | string <= 20,000 | Plain text |
| `url` | http(s), <= 2,048 | |
| `image_urls` | <= 20 http(s) URLs | Main image first |
| `price`, `sale_price` | 0 - 100,000,000, cents | `sale_price` only when lower than `price` |
| `currency` | 3 letters | The storefront's display currency |
| `in_stock` | boolean | Can be bought now |
| `stock_qty` | integer >= 0 | Optional |
| `categories` | <= 50 strings of 1-500 | Full paths, "Parent > Child" |
| `brand` | <= 255 | |
| `attributes` | object; keys <= 100; values: string <= 2,000, number, boolean, or <= 50 strings of <= 500 | Must be `{}` when empty, never `[]` |
| `locale` | en, ro, it, fr, de, es | Also sent per call |
| `source_updated_at` | ISO 8601 with offset | Excluded from the change hash |

Lengths are counted like JavaScript (UTF-16 code units): an emoji counts 2. Cut texts accordingly.

### 2.3 Feed

- Formats: JSON (array, or an object with `products`; `attributes` objects kept as they are), CSV,
  Google Shopping XML (only known attribute names: color, colour, size, material, gender, age_group,
  pattern, condition, size_type, volume, weight, shipping_weight, gtin, ean, skin_type, ingredients,
  capacity).
- AskMerra downloads it every 1-6 hours depending on the plan. No authentication: the URL must hold
  an unguessable secret part.
- Every import is a full sync: **products missing from the file are deactivated** (unless the file
  is empty). A connector must never publish a partial file.

### 2.4 Widget

```html
<script>window.AskMerraSettings = {productId: '33', consent: {analytics: true}};</script>
<script id="askmerra-widget-script" src="https://cdn.askmerra.com/v1/widget.js"
        data-site-key="pk_live_..." data-locale="ro" data-position="bottom-left" data-open="true"
        data-api-url="..." async></script>
```

- `AskMerraSettings` is read once, when the script starts: `siteKey, locale, open, position,
  productId, apiUrl, onReady, consent, dataLayer`. Data attributes win over settings.
- `window.AskMerra` exists as soon as the script ran; calls before the chat is ready are queued:
  `open, close, toggle, sendMessage, setLocale, identify({email, name}), trackPurchase(order),
  setConsent({analytics}), forget(), on(event, handler)`.
- Events: `ready, open, close, message, product_click, add_to_cart`. `add_to_cart` gives
  `{externalId, sku, url}`; when a handler is registered the widget does nothing else (no navigation).
- `trackPurchase({transaction_id, value, currency, items: [{item_id, item_name, price, quantity}]})`
  (GA4 shape). `item_id` is matched against `external_id`, then `sku`. A GA4 `purchase` pushed to the
  dataLayer is picked up as well; one order is counted once.
- Purchases are sent only with analytics consent: `setConsent()` wins, then Google Consent Mode
  (`analytics_storage`) in the dataLayer, then Tag Manager's consent state; no signal = not sent.
- The shop's allowed domains must include the storefront's domain.

## 3. Settings

| Setting | Scope | Default | Notes |
| --- | --- | --- | --- |
| Enabled | storefront | No | |
| Secret key | storefront | - | Stored encrypted; masked in the form; never in the page |
| Site key | storefront | - | Public |
| Language | storefront | Automatic | From the storefront locale when AskMerra serves it |
| Check connection | - | - | Tests the typed values before saving (see 8.1) |
| Sync method | storefront | Push API | Push API or Product feed |
| Product identifier | storefront | Product ID | Or SKU. Changing it replaces the catalog |
| Attributes to include | storefront | none | Multi-select of all product attributes, label (code) |
| Brand attribute | storefront | none | |
| Description | storefront | Description | Description, short description, or both |
| Include variant options | storefront | Yes | |
| Images per product | storefront | 4 | 1-20 |
| Product types | storefront | all sellable | |
| Visibility | storefront | catalog, search, both | |
| Include out-of-stock | storefront | Yes | Known but not recommended by AskMerra |
| Send stock quantity | storefront | No | |
| Excluded categories | storefront | - | With subcategories |
| Products per request | global | 200 | 1-500 |
| Daily maintenance time | global | 03:15 | |
| Full rebuild | global | Weekly (Sunday) | Daily, weekly, monthly, never |
| Feed format | storefront | JSON | JSON or Google Shopping XML |
| Feed regeneration | global | Hourly | 1, 3, 6, 8, 12, 24 h; written only when changed |
| Widget: show, position, open on load, show on checkout (No), product context, add to cart, after add (stay / go to cart), report orders, consent mode | storefront | | |
| API URL, widget URL, timeout (30 s), debug log | mostly global | | Test environments and support |

Saving connection or catalog settings queues a rebuild of the affected storefronts.

## 4. Product mapping

- One AskMerra product per **sellable parent**: simple, virtual, downloadable, bundle, grouped and
  configurable (Woo: simple, variable, grouped, external). Variants are not sent on their own; the
  options a shopper can choose become attributes of the parent (`{"Size": ["S", "M"]}`).
- `name`, `description`, `url`, `categories`, attribute labels and values are those of the storefront
  (its language).
- `description`: description, short description or both; shortcodes, widget directives, scripts and
  styles removed; lists kept as "- item" lines; HTML entities decoded; whitespace collapsed.
- `price`: the price a guest sees (catalog price rules, tax display setting), lowest for products
  with options; `sale_price` only when lower than the regular price; both in the storefront currency.
- `in_stock`: can be bought now (salable quantity, not only the stock flag).
- `url`: the storefront's own product URL (base URL + URL rewrite, built independently of the area
  the sync runs in), with unsafe characters (spaces, non-ASCII) percent-encoded.
- `image_urls`: main image first, then the gallery order, unique, URL-encoded file names.
- `categories`: active categories under the storefront's root, as full paths without the root,
  most specific first; separators inside names replaced.
- `attributes`: chosen attributes under their storefront labels; dropdown values become labels,
  multi-selects lists, yes/no booleans, numbers numbers, dates `YYYY-MM-DD`; empty values skipped; a
  label used twice gets the code appended.
- `source_updated_at`: the product's last change (UTC with offset).

**Eligible** = enabled, in the storefront's website, visible as configured, of an included type,
not hidden ("Hide from AskMerra"), not in an excluded category, in stock or out-of-stock allowed,
with a name. An ineligible product that AskMerra has is removed.

## 5. Sync engine

### 5.1 Tables

| Table | Columns | Purpose |
| --- | --- | --- |
| queue | storefront, product, attempts, available_at (UTC), created_at, last_error; unique (storefront, product) | Products to build and send. A product queued again stays one row, is due at once, and its attempts start over |
| state | storefront, product, external_id, locale, payload_hash, in_stock, payload (feed entry, compressed), synced_at; PK (storefront, product) | What AskMerra has. Outlives the product (needed to delete it) |
| run log | storefront, type (rebuild, reconcile, feed, remove_all), status, started/finished, stats JSON, message | Status page history (30 days) |

### 5.2 Change detection

1. **Change log** (Magento: database triggers via the indexer on "Update by Schedule"): every write
   to product data, prices, websites, categories, media, stock items, product relations and catalog
   rule prices queues the product in every syncing storefront; variants also queue their parents.
   Database-level detection is what catches imports and ERP integrations writing SQL.
2. **Stock check** every 15 minutes: compare each product's current "can be bought" with `in_stock` in
   the state; queue the differences (orders change salability through reservations without a write
   to product rows).
3. **Daily check** at the maintenance time: queue products that should be in AskMerra but are not
   (missing), products in AskMerra that should not be (gone), and products whose price changes
   with today's date (special price from/to; catalog rule prices that differ between yesterday and
   today).
4. **Catalog rule full reindex**: snapshot guest rule prices before and after; queue the differences.
5. **Full rebuild** (weekly by default, on settings changes, by hand): queue every candidate plus
   everything in the state.
6. **Bulk "send now"** from the products grid, **send this product** from the preview, CLI `--product`.

### 5.3 Processing (cron every minute, up to 50 seconds, one runner at a time via a lock)

```
for storefront in round_robin(syncing storefronts) until time is up or nothing is due:
    ids = queue.due(storefront, batch_size)                     # oldest first
    built = build(storefront, ids)  -> payloads | ineligible (with reason) | errors (retry later)
    for each payload:
        hash = sha1(json(payload without source_updated_at))
        if state[id] has same hash, external_id and locale: drop from queue (unchanged)
        else: changed
    push storefront:  batchUpsert(changed) in chunks <= 500 products and <= 9.5 MB
                      -> rejected (errors[index]) = failed for good, with AskMerra's message
                      -> others saved to state, dropped from queue
                      -> if id or locale changed: batchDelete the old copy
    feed storefront:  save each changed payload JSON (compressed) in the state; mark feed changed
    ineligible ids that are in the state: push -> batchDelete (grouped by locale); feed -> mark
                      changed; delete from state
```

### 5.4 Errors

| Case | Handling |
| --- | --- |
| No answer, timeout, 408, 5xx | Retry: pause 60 s x 2^attempts (max 6 h), attempts + 1; failed after 8 |
| 429 | Pause for `Retry-After` (default 60 s); attempts unchanged |
| 413 | Split the chunk in halves and retry; single product too large -> failed |
| 401 / 403 / 404 | Store a "key refused" problem for the storefront (admin warning), pause 10 minutes, attempts unchanged. Cleared by the next successful call |
| Product in `errors[]` | Failed for good with AskMerra's message; retried when the product changes or by "Retry failed" |
| Product cannot be built | Retry with the pauses above |

Failed products stay listed on the status page with the error and a link to the product.

### 5.5 Feed

- Feed storefronts keep each product's finished entry in the state; the file is streamed from there
  (seconds for 50,000 products) to a temporary file, then renamed into place. No product is rebuilt
  to write the file.
- Written on the configured schedule **only when something changed** (a "changed" flag per storefront).
- Not written before the storefront's catalog is complete ("ready" flag: set once the queue of a
  new feed storefront has drained); switching a storefront to feed resets it.
- If a stored entry cannot be read, the file is not written: the product is rebuilt and the next run
  writes the complete file.
- File name: `<storefront code>-<32 hex secret>.<json|xml>` in a public media folder. A new secret
  can be generated (all feed URLs change; old files removed).
- Files of storefronts that no longer publish a feed (switched to push, turned off, deleted) are
  deleted by the feed job: AskMerra would keep importing an outdated file over the pushed catalog.
- JSON: `{generator, generated_at, store, store_name, store_url, locale, currency, products: [...],
  count}`. Google XML: RSS 2.0 + `g:` namespace; `g:id, title, description (<= 5,000), link,
  g:image_link, g:additional_image_link, g:price, g:sale_price, g:availability, g:brand, g:mpn,
  g:product_type (category paths), g:content_language` and the known attributes.

### 5.6 Switching between push and feed

The connector remembers each storefront's method. When it differs - whatever changed the setting - at
the next queue run, feed run or daily check: every state hash is reset (feed entries dropped when
switching to push), the "ready" flag is reset when switching to a feed, and a full rebuild is queued.
So a feed gets every product's entry before it is published, and pushed products never count on what
an old feed delivered.

### 5.7 Removing a storefront

"Remove from AskMerra" (only once the storefront no longer syncs, otherwise the daily check sends it
back): batchDelete everything in the state (by locale, 1,000 per call), clear queue and state, delete
feed files. A feed source must also be deleted in the AskMerra dashboard.

## 6. Storefront

- **Widget**: the snippet of 2.4 in the `<head>` of every page (`async`), with the storefront's site
  key and locale; the script URL and site key are settings. Themes that render the platform's layout
  get it from there; a storefront that serves its own HTML (ScandiPWA) gets it inserted before
  `</head>` of the finished page, in single-page-app mode (no product id, the PWA handles the cart);
  position / open on load when set; `data-api-url` only for test environments. Off on checkout pages
  unless enabled (other one-step checkouts configurable). Settings defined earlier by the theme win.
- **Product context**: on product pages `AskMerraSettings.productId = external_id` (before the script).
- **Add to cart** (when enabled): register `AskMerra.on('add_to_cart')`. POST `{external_id, sku,
  form key}` to the connector's endpoint; it resolves the product (external id, then SKU), and adds it
  to the platform cart when it is a simple/virtual product, salable, without required options;
  otherwise answers the product URL and the page opens. On success: refresh the mini cart, fire
  `askmerra:cart-added` on `document`, then stay (short notice with "View cart") or go to the cart.
  Errors from the platform (stock, quantity) open the product page with the platform's message.
- **Orders**: on the order success page call `AskMerra.trackPurchase` once (session storage guard)
  with order number, grand total, currency, tax, shipping and, per visible line, `item_id` = the
  external id of the **parent** product (the line of a configurable carries the variant's SKU),
  name, unit price after discount incl. tax, quantity. No personal data.
- **Consent modes**: automatic (do nothing: Google Consent Mode / Tag Manager); granted (no banner
  needed: `AskMerraSettings.consent = {analytics: true}`); platform cookie notice (Magento's
  `user_allowed_save_cookie`; granted at once when the notice is disabled); manual (the shop's banner
  calls `AskMerra.setConsent`).
- **CSP**: allow `script-src https://cdn.askmerra.com` and `connect-src https://api.askmerra.com`
  (plus custom hosts).
- **Headless**: expose the widget settings through the platform API (Magento: GraphQL
  `askMerraWidgetConfig`); the storefront loads the script and wires add to cart and orders itself.

## 7. Performance targets

- 50,000 products: first push sync in minutes (about 250 calls at 200 per call, within 120 per
  minute); later only changed products; a full rebuild that changes nothing sends nothing.
- Change to AskMerra: about 1 minute (push), next feed run (feed).
- Feed file: seconds, from stored entries, only when changed.
- No work on storefront requests except rendering a script tag.

## 8. Admin

### 8.1 Connection check

Uses the values typed in the form (the saved key when the field still shows the mask): pings the API
with the secret key and shows the shop name; detects a site key pasted as secret key and vice versa
(warning that the secret key must never be public); reminds to allow the storefront domain in
AskMerra; shows the feed URL for feed storefronts; a successful check clears the "key refused"
warning.

### 8.2 Status page

Per storefront: sync method, language/currency, products in AskMerra, waiting, failed, last sent,
last daily check and full rebuild with their counts, feed URL and file (size, time, being prepared,
changed since), the latest errors (product link, attempts, next attempt, message). Actions: send
changes now (in the request, up to 25 s), write the feed now, retry failed, run the daily check,
full rebuild (confirm), new feed URLs (confirm), remove from AskMerra (only when no longer syncing,
confirm). Warnings: cron not running (no successful queue run in 15 minutes), indexer not scheduled,
two storefronts sending the same locale to the same shop, a language AskMerra does not serve.
**Preview a product** (id or SKU, storefront): the payload as sent, or why it is not sent, and
whether AskMerra has the latest version, with "send this product now".

### 8.3 Other

- Per-product "Hide from AskMerra" (per storefront), filterable in the products grid.
- Bulk action "Send to AskMerra now" on the products grid.
- Admin notification while a storefront's key is refused.
- CLI: `test`, `status`, `sync [--rebuild|--check|--product|--retry-failed|--store|--time]`,
  `feed:generate [--force]`, `payload <id|sku> [--store]`, `remove --store [--yes]`.
- Log file; debug mode logs every request and response (bodies cut to 4 KB).
- Uninstall: tables, attribute, settings, flags, change log removed.

## 9. WooCommerce port

| Part | WooCommerce |
| --- | --- |
| Storefront | The site; with WPML/Polylang each language is a storefront (locale); WooCommerce Multi-Currency / CURCY: one AskMerra shop per currency |
| Settings | WooCommerce > Settings > AskMerra tab (`WC_Settings_Page`); per-language values via WPML string/option translation or per-language option keys. Secret key: `autoload = no`, encrypted with a key derived from `AUTH_KEY`/`SECURE_AUTH_KEY` (`openssl_encrypt`, AES-256-GCM) |
| Capabilities | `manage_woocommerce`; nonces on every admin action and AJAX call |
| Tables | `{$wpdb->prefix}askmerra_queue`, `_state`, `_run` created with `dbDelta` on activation; versioned upgrades |
| Scheduling | Action Scheduler (ships with WooCommerce): recurring actions every minute (queue), 15 minutes (stock), daily (check/rebuild) and the feed interval. Recommend a real cron for `wp-cron.php` or `wp action-scheduler run` |
| Change detection | Hooks: `woocommerce_new_product`, `woocommerce_update_product`, `woocommerce_new_product_variation` / `woocommerce_update_product_variation` (queue the parent), `woocommerce_product_set_stock`, `woocommerce_variation_set_stock`, `woocommerce_product_set_stock_status`, `woocommerce_variation_set_stock_status`, `set_object_terms` (categories, `pa_*` attributes, brands), `wp_trash_post` / `untrashed_post` / `before_delete_post` / `woocommerce_delete_product`, `updated_post_meta` for `_price`, `_regular_price`, `_sale_price`, `_thumbnail_id`, `_product_image_gallery`, `woocommerce_scheduled_sales` (sale start/end). No database triggers: add a **modified check** every 15 minutes - products with `post_modified_gmt` after the last check - to catch SQL imports, plus the daily missing/gone check |
| Product data | `wc_get_product()`; `get_permalink()`; `get_image_id()` + `get_gallery_image_ids()` -> `wp_get_attachment_url()`; `wc_get_price_to_display()` (tax display); variable: `get_variation_price('min', true)` / regular; `is_in_stock()`, `get_stock_quantity()`; categories `product_cat` paths; brand: `product_brand` taxonomy (WooCommerce 9.6+) or Perfect Brands / YITH; attributes: `get_attributes()` (taxonomy `pa_*` -> term names in the language; custom -> options); variation options from `get_variation_attributes()` |
| Description | `get_description()` / `get_short_description()`; `strip_shortcodes()`, remove block comments (`<!-- wp:... -->`), page-builder markup, then the same plain-text cleaner |
| Exclusion | Product checkbox "Hide from AskMerra" (`_askmerra_exclude` meta) in the product data panel; products list column / filter |
| Bulk action | `bulk_actions-edit-product` "Send to AskMerra now" |
| Feed files | `wp-content/uploads/askmerra/<site or language>-<secret>.json`; `.htaccess` deny listing; same rules (ready, changed, atomic rename) |
| Widget | `wp_enqueue_script('askmerra-widget', $url, [], null, ['strategy' => 'async', 'in_footer' => true])`, data attributes through `wp_script_attributes` / `script_loader_tag`; settings with `wp_add_inline_script(..., 'before')`; product context `is_product()`; hidden on `is_checkout()` unless enabled |
| Add to cart | Classic: `POST ?wc-ajax=add_to_cart` (`product_id`, `quantity`) then `jQuery(document.body).trigger('added_to_cart', [fragments, cart_hash])` / `wc_fragment_refresh`. Cart & Checkout blocks: Store API `POST /wp-json/wc/store/v1/cart/add-item` with the `Nonce` header, then `wc-blocks_added_to_cart`. Variable and grouped products, required add-ons: open the product page |
| Orders | `woocommerce_thankyou` (once per order: order meta flag); `item_id` = parent product id (`get_product_id()`), or the parent's SKU in SKU mode. HPOS compatible (`FeaturesUtil::declare_compatibility('custom_order_tables', ...)`) |
| Consent | WP Consent API: `wp_has_consent('statistics')`, `wp_listen_for_consent_change` (CookieYes, Complianz, Cookiebot...) as the "platform cookie notice" mode |
| Status page | WooCommerce > AskMerra (or WooCommerce > Status tab) with the same contents and actions; Site Health test for cron and keys |
| CLI | WP-CLI `wp askmerra test|status|sync|feed generate|payload|remove` with the same options |
| Uninstall | `uninstall.php`: delete options, tables, meta, scheduled actions, feed files (after "remove from AskMerra") |

## 10. Nice to have (not built yet)

- Ratings and review counts as attributes (`rating`, `review_count`) - AskMerra can answer "best rated".
- Popularity / best-seller rank and "new" flag as attributes, refreshed daily (volatile: keep them out
  of the content hash if AskMerra adds non-enriching fields).
- Identify logged-in customers (`AskMerra.identify`) when the shop enables it and consent allows.
- Product tags; size charts; per-attribute renaming ("send *Tip ten* as *Skin type*").
- Customer-group / B2B prices; per-source stock in multi-warehouse setups.
- E-mail digest of sync problems to the store admin.
- Knowledge pages (shipping, returns, FAQ) sent to AskMerra from CMS pages.

## 11. Requests for AskMerra (gaps found while building)

1. **Feed authentication** (a token header or basic auth): today a secret URL is the only protection.
2. **Server-side order reporting** (`POST /v1/orders` with the secret key): independent of ad
   blockers, page scripts and SPAs; consent would be the shop's responsibility.
3. **Catalog status endpoint** (`GET /v1/catalog/status`: active products per locale, last change)
   so the connector can verify and show what AskMerra really has.
4. **Replace-all for push** (a sync session or "delete not in this set"), to remove leftovers without
   a local state table.
5. **HTML to text on push** like the feed import, so connectors stay thin.
6. ~~`AskMerra.setProduct(id)` for single-page apps~~ - added to the widget (October 2026, with an
   **add-to-cart link** template in the widget designer, e.g. WooCommerce `/?add-to-cart={external_id}`,
   opened when the page registers no `add_to_cart` handler).
7. **Which fields trigger re-enrichment**, and a field for volatile data (rank, rating) that does not.
8. **Rate-limit headers** (`X-RateLimit-Remaining`, `X-RateLimit-Reset`) to pace large syncs.
9. **Signed customer identity** for `identify` (HMAC with the secret key) so it cannot be spoofed.
10. **A platform marker** (we send `User-Agent: AskMerra-Magento/<version> Magento/<version>`) shown in
    the dashboard, to help support.
