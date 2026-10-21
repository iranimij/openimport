# Order import

Since 1.5. Entity **Orders** (`openimport_order`), direction import. Historic orders — from an old shop, another
Magento, an ERP, or OpenImport's own order export — land in `sales_order` **as they are**: the file's state and
status, created date, totals, addresses, items and the recorded payment method. Nothing is quoted, priced, charged
or e-mailed; the shop's current prices, taxes, shipping rates and payment settings play no part. Optionally the
invoices, shipments (with tracking numbers) and credit memos are created from the quantities in the file, and the
stock is reduced.

Orders are **only added** (behaviour *Add/Update* or *Only add*): an order number that exists is skipped and
reported, the run ends as *partial*. Nothing updates, replaces or deletes an order.

## The file

One row per order line, the layout of the [order export](exports.md#orders): the first row of an order carries the
order, every row one item. Rows of one order are grouped by `increment_id` and may sit anywhere in the file.
Export an order from a store with the *Orders* export (per line) to see every column with real values.

Required for every order: `increment_id`, `customer_email`, `status` (or `state`), `billing_firstname`,
`billing_lastname`, `billing_street`, `billing_city`, `billing_country_id`, and per row `item_sku`,
`item_qty_ordered`, `item_price`. The rest is optional.

| Columns | Meaning |
|---|---|
| `increment_id` | The order number. With *Keep the file's numbers* it becomes the shop's order number; with *Generate new numbers* it only groups the rows and the store's next number is used (the file's number is noted in the order comment). |
| `state`, `status` | Any status of Stores › Order Status; the state comes from the status when empty. A state alone takes the state's default status. |
| `created_at` | The order date (`2024-03-01 14:02:00`, in UTC like the export); empty: now. |
| `store_code` | The store view (`default`); empty: the default store view. |
| `customer_email`, `customer_firstname`, `customer_lastname`, `customer_group`, `customer_is_guest` | A customer with that e-mail on the store's website is linked to the order; otherwise the order is a guest order with the names of the file. The group by code (`General`) or id; empty: the customer's group or *NOT LOGGED IN*. |
| `order_currency_code` | ISO code; empty: the store's base currency. Base amounts are copied from the order amounts (rate 1). |
| `subtotal`, `discount_amount`, `shipping_amount`, `tax_amount`, `grand_total`, `total_paid`, `total_refunded`, `total_due`, `total_qty_ordered`, `weight`, `subtotal_incl_tax`, `shipping_incl_tax` | Taken from the file. Empty `subtotal`, `total_qty_ordered` and `grand_total` are computed from the items; given ones must match (subtotal = Σ row totals; grand total = subtotal + shipping + tax − discount, 1 cent tolerance). `total_due` empty: grand total − paid. |
| `discount_description`, `coupon_code` | Recorded on the order; no rule is applied or counted. |
| `shipping_method`, `shipping_description` | `flatrate_flatrate` and its title, as recorded. Nothing is rated. |
| `payment_method`, `payment_last_trans_id`, `payment_po_number` | The method code (`checkmo`, `banktransfer`, `paypal_express` …), recorded as the order's payment; empty: `checkmo`. Nothing is charged. |
| `customer_note`, `remote_ip` | Recorded. |
| `tracking_numbers`, `tracking_carriers` | `;`-separated, used by *Create invoices, shipments and credit memos*: one shipment with these tracking numbers (carrier codes, or `custom`). |
| `billing_*`, `shipping_*` | `firstname`, `lastname`, `company`, `street` (lines separated by `,` or a line break), `city`, `postcode`, `region`, `country_id`, `telephone`, `email`, `vat_id`. No shipping address: the billing address is used. |
| `item_sku`, `item_name`, `item_product_type`, `item_parent_sku`, `item_qty_ordered`, `item_qty_invoiced`, `item_qty_shipped`, `item_qty_refunded`, `item_qty_canceled`, `item_price`, `item_price_incl_tax`, `item_row_total`, `item_row_total_incl_tax`, `item_discount_amount`, `item_tax_amount`, `item_tax_percent`, `item_weight`, `item_product_options` | One item per row. A SKU that exists in the catalogue links the product (id, type); a SKU that doesn't is kept as an item of a deleted product, as Magento keeps them. `item_parent_sku` names the parent of a configurable or bundle child in the same order (children carry no row total). `item_product_options` is the JSON of the export (chosen options, for the admin's order view). Empty `item_row_total`: price × quantity. |

## Options

Under *Import options* when the entity is *Orders*:

- **Order numbers**: *Keep the file's numbers* (the default; an order with that number is skipped) or *Generate new
  numbers* from the store's sequence, for a file whose numbers clash with the shop's own.
- **Create invoices, shipments and credit memos**: from `item_qty_invoiced`, `item_qty_shipped` (with the tracking
  numbers) and `item_qty_refunded`, through Magento's own services — offline, nothing is charged or refunded, no
  e-mails. Afterwards the file's state, status and paid/refunded/due totals are set again, so a `complete` order
  stays complete. Off: the order carries the quantities as numbers but has no documents.
- **Reduce stock**: subtract the ordered quantities from the products' stock (simple, virtual and downloadable
  items), as a new order would. Off (the default): historic orders leave the stock alone.

## What a run does

- Every order is checked first: e-mail, status, group, date, currency, numbers, the billing address, items with a
  quantity and a price, parents present, totals consistent. A **dry run** counts the orders that would be created
  and names every problem with the file's row number; nothing is written.
- One transaction per order: an order whose creation fails (a database error, a document service refusing) is
  rolled back and reported; the others are kept.
- Every imported order gets a history comment *Imported by OpenImport (order … in the file)*.
- Orders that exist already are skipped and reported, so a file can be imported twice without duplicates.
- Auto-match knows the export's column names and `Bestellnummer`, `order number`, `E-Mail`, `SKU`, `Menge` and
  the like.

## Round trip

The order import is tested against the order export: the test exports Magento's fixture orders per line, imports
the file with new numbers, exports the new orders and compares the rows. What the export writes, the import reads.
A migration from another OpenImport store is therefore: export orders per line → import with *Keep the file's
numbers* and *Create invoices, shipments and credit memos*.

Sample file (two orders, three lines):

```csv
increment_id;status;created_at;customer_email;customer_firstname;customer_lastname;order_currency_code;subtotal;shipping_amount;grand_total;total_paid;shipping_method;payment_method;billing_firstname;billing_lastname;billing_street;billing_city;billing_postcode;billing_country_id;item_sku;item_name;item_qty_ordered;item_qty_invoiced;item_qty_shipped;item_price
2019-4711;complete;2019-05-02 09:12:00;anna@example.com;Anna;Berg;EUR;39.9;4.9;44.8;44.8;flatrate_flatrate;banktransfer;Anna;Berg;Ringstraße 7;Wien;1010;AT;SHIRT-RED-M;Red shirt M;1;1;1;19.95
2019-4711;;;;;;;;;;;;;;;;;;;SHIRT-RED-L;Red shirt L;1;1;1;19.95
2019-4712;canceled;2019-05-03 18:40:00;ben@example.com;Ben;Kurz;EUR;59;0;59;0;flatrate_flatrate;checkmo;Ben;Kurz;Am Markt 2;Graz;8010;AT;JACKET-BLK;Black jacket;1;0;0;59
```
