# Configurable products from simple rows

Since 1.3. Supplier files usually have one row per variant: a T-shirt in red M, red L and blue M, with a column that
names the model they belong to. OpenImport turns such rows into a configurable product with its variants, in the
same run.

## Set it up

1. **Step 4 · Mapping**: map the column with the model number to **Parent SKU (configurable products)**. Map the
   attributes the variants differ in (color, size …) like any attribute. Optionally map a column to **Parent name
   (configurable products)**.
2. **Step 5 · Rules**: switch on **Build configurable products** and choose the **Variation attributes**. Only
   dropdowns with global scope that the shop defined are offered: Magento accepts only those for variants.
3. Choose what happens to the variants: **Not visible on their own** (default) or **Visibility as mapped**.

## What a run does

- Rows with the same parent SKU are the variants of one configurable product. Rows without a parent SKU are imported
  as they are.
- After the last row, OpenImport adds the configurable product with all its variants (Magento's
  `configurable_variations`). It takes the values of the first variant in the file (attribute set, websites,
  categories, description, price, pictures), with:
    - **name**: *Parent name* when mapped; otherwise the words all variant names start with ("T-Shirt Basic Red M"
      and "T-Shirt Basic Blue L" give "T-Shirt Basic"), per store view;
    - **visibility** *Catalog, Search*; **URL key** from the name;
    - no values for the variation attributes, no quantity, in stock.
- New variants get the URL key `<name>-<sku>` when the mapping has no URL key, so variants with equal names do not
  collide. Existing products keep theirs.
- The run message says *Configurable products: 3 built from 12 variants.*

## The parent's own row

Some files, WooCommerce exports among them, have a row for the parent itself. A row whose **product type** is
`configurable` and which has no parent SKU is that row: OpenImport sends it once, at the end, with the variations.
Its own name, URL key, visibility and stock stay as mapped. Map the file's type column to `product_type` with a
value map (`variable` → `configurable`, `variation` → `simple`).

A **simple** row with a parent's SKU conflicts with that parent: the parent is not built and the run message names
it.

## Rows that cannot be variants

These rows are row errors (the column is named, the rest of the run goes on):

| Problem | Message |
|---|---|
| A variation attribute is empty | *The variation attribute "size" is empty.* |
| Two variants of one parent have the same values | *Another variant of TS, TS-RED-M, has the same color, size.* |
| A row is its own parent | *The parent SKU is the row's own SKU; a variant needs another parent.* |
| A parent SKU is also a variant | *This SKU is the parent of other rows; it cannot be a variant too.* |

The values must be existing options of the attributes (as for any dropdown in Magento's import). Create options with
the [attribute import](attributes.md) first.

## Skip unchanged rows

With *Skip unchanged rows* on, unchanged variants are not sent again, but the parent still lists **every** variant
in the file: Magento removes links a parent's list does not name. The parent itself is sent only when its list or
values changed.

## Settings checked before a run

Saving the profile needs the parent SKU column, the variation attributes and their columns. A run stops before
reading the file when an attribute is not a global dropdown.
