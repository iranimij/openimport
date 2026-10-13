# Mapping, store views and row filters

## Mapping

A profile maps Magento fields (core import columns) to the file:

- **Source column**: the value comes from that column of the file.
- **Fixed value**: the same value for every row (for example `attribute_set_code` = `Default`, `_website` = `base`).
- **Transforms**: an ordered list applied to the value (see [Transforms and expressions](transforms.md)). A field
  with no source column and an `expr` transform computes its value from the whole row.
- **Required**: an empty result makes the row an error instead of sending it.

Magento fields that are not mapped are not sent, so Magento leaves them unchanged. Columns of the file that are not
mapped are ignored. The identifier field of the entity must be mapped:

| Entity | Identifier |
|---|---|
| Products (`catalog_product`), Stock & prices | `sku` |
| Advanced pricing | `sku`, `tier_price_website`, `tier_price_customer_group`, `tier_price_qty` |
| Customers, customers with address | `email`, `_website` |
| Customer addresses | `_email`, `_website`, `_entity_id` |
| Stock sources (MSI) | `sku`, `source_code` |

### Stock & prices

The *Stock & prices* entity (`openimport_stock_price`) is made for supplier feeds: it can set `qty`, `is_in_stock`,
`price`, `special_price`, `special_price_from_date`, `special_price_to_date` and `cost` of products that already
exist, through Magento's own product import (with MSI, `qty` goes to the default source). SKUs the shop does not
have are skipped and counted as *skipped by behaviour*; they are never created. For quantities per MSI source, use the
*Stock sources* entity.

### Auto-match

The mapping editor suggests a mapping from the file's column names, in this order: the exact name; the name ignoring
case, spaces, underscores, hyphens and dots (`Special Price` → `special_price`); known synonyms (`Artikelnummer` →
`sku`, `Bestand` / `Menge` → `qty`, `Preis` → `price`, `Bezeichnung` → `name`, `E-Mail` → `email`, `Vorname` →
`firstname` …); and store-view columns (`name_de`, `name (de)`, `name [de]`). Each column is used once.

## Store-view values (products)

Map a field as `store:<store view code>:<field>`, for example `store:de:name`. One row of the file then becomes the
default row plus one row per store view that has at least one non-empty value. Store-view rows carry the SKU, the
attribute set and the product type (Magento needs those for new products) and the store-view fields only.

## Row filters

Row filters decide which rows of the file are imported, before mapping:

- **Mode**: *include* imports only the matching rows; *exclude* skips them.
- **Match**: *all* conditions or *any* condition.
- **Conditions**: a column, an operator and a value, or an [expression](transforms.md#expressions).

| Operator | Matches when the cell (trimmed, ignoring case) … |
|---|---|
| `equals`, `not_equals` | is / is not the value |
| `contains`, `not_contains`, `starts_with`, `ends_with` | contains / starts / ends with the value |
| `in`, `not_in` | is / is not one of a comma-separated list |
| `empty`, `not_empty` | is empty / not empty (a missing column is empty) |
| `greater_than`, `less_than` | is a number above / below the value (decimal commas allowed) |
| `regex` | matches the pattern |

Rows skipped by a filter are counted as "skipped by filter" on the run page, not as errors.
