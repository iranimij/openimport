# Presets: files from Shopify, WooCommerce and Magento 1

Since 1.3. *Profiles › Import profiles from JSON › Or start from a preset* creates an inactive product profile with
the mapping for a file from another shop system. Upload the file in step 2, check the mapping, run a dry run.

| Preset | File | What it maps |
|---|---|---|
| Shopify products | Shopify's product export (Title, URL handle, SKU, Price, Compare-at price, …) | name, description, price (the compare-at price becomes the price and the price the special price), cost, quantity, stock (also "continue selling"), weight (grams → kg), status, SEO title and description, the product picture, the parent SKU for variants |
| Shopify products (classic columns) | the same with Shopify's older names (Handle, Body (HTML), Variant SKU, Variant Price, …) | the same |
| WooCommerce products | WooCommerce's product export (ID, Type, SKU, Name, Regular price, …) | name, descriptions, prices and sale dates, stock, weight, published, visibility, product type (variable → configurable), parent, pictures |
| Magento 1 products | Magento 1's product export (sku, _store, _attribute_set, _type, …) | the standard columns, status, visibility (1–4), category paths with their root, pictures |

The mappings are written from the systems' public column lists. They are a start: add your own attributes, change
transforms, and save.

Things to know:

- **Shopify** writes a product's title, description and SEO texts only on its first row; the variant rows take them
  with the transform *Fill from the row above* (`fill_down`, grouped by the URL handle). Rows that only add an image
  and variants without a SKU are skipped. To get configurable products, map *Option1 value* (and *Option2 value*) to
  your Magento attributes and switch on [Build configurable products](configurable.md).
- **WooCommerce**: grouped and external products and products without a SKU are skipped. A variable product's own
  row becomes the configurable product; without a price it takes its first variation's. A parent written as `id:123`
  needs the parent's SKU instead.
- **Magento 1** writes extra categories, pictures and store-view values on rows without a SKU; those rows are
  skipped. Copy Magento 1's `media/catalog/product` folder to `var/import/images` so the picture paths are found.

The Shopify and WooCommerce presets make URL keys from `{name}-{sku}` (see [URL keys](url-keys.md)): variants
share their product's name, and Magento needs a unique key for each.

All presets read pictures from web addresses safely (see [Product pictures](images.md)) and import a product without
a picture that cannot be read.
