# Changelog

## 1.0.1 - 2026-10-05

Sync fixes (found while building the WooCommerce connector; the database gets one column and one
index - run `bin/magento setup:upgrade`):

- A product edited while a queue run was sending it is no longer dropped from the queue (queue rows
  carry a revision).
- A new secret key or API URL sends the whole catalog to the new AskMerra shop; before, every
  product counted as unchanged and the new shop stayed empty. Changes to a store view's locale,
  currencies, base URLs, URL suffix or tax settings rebuild its catalog.
- Removing an old copy (product identifier or language changed) happens before the new one is
  recorded: a failed removal no longer leaves a duplicate in AskMerra.
- A reused or swapped SKU no longer deletes another product's copy; two products with the same id
  are reported ("same SKU as product N") instead of overwriting each other.
- A rate limit or a refused key pauses the store view at once instead of building and sending every
  following batch; AskMerra not answering pauses it with a growing backoff (1 minute ... 6 hours)
  without counting failed attempts, so an outage no longer marks products "failed for good".
- One product too large to send fails on its own; the rest of its batch is sent.
- "Remove from AskMerra" without a secret key is refused instead of forgetting products that stay in
  AskMerra.
- The status page says when a run was paused instead of reporting an empty success.

## 1.0.0 - 2026-10-05

First release.

- Catalog sync per store view: Push API or product feed (JSON, Google Shopping XML).
- Incremental sync for large catalogs: Magento change log (Update by Schedule indexer), queue with
  retries, content hashes (only changed products are sent), daily check, 15-minute stock check,
  catalog price rule changes, weekly full rebuild.
- Feed files written from stored entries, only when changed, never partial; secret feed URLs.
- Settings: product attributes, brand, description, images, variant options, product types,
  visibility, stock, excluded categories, product identifier, language; "Hide from AskMerra" per
  product.
- Storefront widget in the `<head>` of every page (Luma, Hyvä, Breeze through the layout; ScandiPWA by
  adding it to the PWA's page), product context, add to cart into the Magento cart, order reporting
  with analytics consent modes, CSP; GraphQL `askMerraWidgetConfig` for headless storefronts.
- ScandiPWA extension `@askmerra/scandipwa` (folder `scandipwa/`): add to cart with the PWA cart,
  order reporting, product context.
- Admin: connection check, Sync status page with actions and product preview, products grid
  action, key-refused notification; CLI commands; debug log.
- Translations: en_US, ro_RO, it_IT, fr_FR, de_DE, es_ES.
