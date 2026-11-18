# Magento 2 Catalog Sorting: Now in Wishlists

Adds **Now in Wishlists** to the storefront Sort By list: the products that sit in the most wishlists first, per store view.

## What it does for a store

- **A Now in Wishlists sort order** (`product_list_order=wished`), offered in the store views where it is enabled. A listing sorted by it opens most-wished first when the shopper chose no direction.
- **Wishlist items, per store.** A product's value in a store is the number of wishlist items that hold it and were added in that store within the period. Every item counts once, whoever owns the wishlist and however many units it asks for.
- **Computed once a day, not per request.** The value is one aggregate query over the wishlist items, run by the popularity data refresh of `hryvinskyi/magento2-catalog-sorting-metrics`. Only products whose value changed get their search documents rebuilt.
- **Sorted in the search engine** on the numeric document field `sort_wished`; no join and no computation per query. A product in no wishlist sorts as 0.

## Requirements

- PHP 8.1 to 8.5.
- `hryvinskyi/magento2-catalog-sorting`, `hryvinskyi/magento2-catalog-sorting-options` and `hryvinskyi/magento2-catalog-sorting-metrics`, ^1.0 each.
- The platform's Wishlist, Catalog, Store, Config and Backend modules (what `composer.json` and `etc/module.xml` declare).

## Installation

```bash
composer require hryvinskyi/magento2-catalog-sorting-wishlist
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento cache:flush
bin/magento indexer:reindex catalogsearch_fulltext
```

The module adds the field `sort_wished` to the product search documents. One full `catalogsearch_fulltext` reindex puts it into the index mapping; listings keep working before it. After enabling the option in a store view, run `bin/magento catalog-sorting:metrics:refresh --metric=wished`, or wait for the next scheduled refresh.

## Configuration

**Stores › Configuration › Catalog › Catalog Sorting › Now in Wishlists**

| Path | Label | Default | Scope |
|---|---|---|---|
| `catalog_sorting/wished/enabled` | Enabled | No | default, website, store view |
| `catalog_sorting/wished/period` | Period, Days (0 counts all additions) | 0 | default |

The label, the place and the visibility of the option in Sort By are set in **Catalog Sorting › Sort Options**. The refresh schedule is in **Catalog Sorting › Popularity Data Refresh**.

## Registrations

| Pool | Item |
|---|---|
| `metrics` of `Hryvinskyi\CatalogSortingMetrics\Model\MetricRegistry` | metric `wished`, field `sort_wished` (`long`) |
| `sortFields` of `Hryvinskyi\CatalogSorting\Model\Search\ShopperSortFields` | code `wished` on `sort_wished` |
| `options` of `Hryvinskyi\CatalogSortingOptions\Model\ListingSortOptions` | option `wished`, "Now in Wishlists", descending, `sortOrder` 120 |
