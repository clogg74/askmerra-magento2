# Changelog

## 1.0.0 - unreleased

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
