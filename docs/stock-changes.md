# Quantities as changes

Since 1.3. Some systems send what changed, not what is in stock: a delivery of 5, three sold in the shop on the
corner. Step 5, **Quantities in the file**:

- **are the new stock** (default): the quantity replaces the current one, as in Magento's import;
- **are changes (+5, -3)**: the quantity is added to the current one. `+5` adds five, `-3` takes three away, a plain
  `4` adds four. Decimals take a comma or a point (`+2,5`).

It works for **Products**, **Stock & prices** (column `qty`, Magento's default stock) and **Stock per source (MSI)**
(column `quantity`, per SKU and source).

- The current quantity is read for each batch, and a SKU that appears twice in a file adds up (`+5` then `+2` on 10
  gives 17). Magento receives one row per SKU (per SKU and source) with the result, after the file's last row.
- A product that does not exist yet starts at 0.
- A value that is not a change (`many`) is a row error naming the column.
- Live runs hold the lock of Magento's entity: products, Stock & prices and Stock per source runs never change the
  same quantities at the same time.
- The dry run shows the quantity Magento would receive (*qty: 10 → 17*).
- **Skip unchanged rows** cannot be used together with it: the same `+5` in tomorrow's file is a new delivery.
