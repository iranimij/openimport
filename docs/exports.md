# Exports

An export profile (direction *Export*) writes a file from a Magento export entity: products, customers, customer
addresses, advanced pricing, stock sources (MSI) — every entity Magento's own System › Data Transfer › Export
offers — plus OpenImport's **Orders** export. The rows come from Magento's own export code, so the columns are
exactly those of a core export; OpenImport adds row filters, column mapping, formats and destinations, and runs it
on a schedule.

## Steps

1. **What**: direction Export and the entity.
2. **Where**: the destination (download from the run page, server folder, SFTP, FTP, e-mail) and the file name.
   Placeholders in the file name: `{entity}`, `{profile}`, `{date}` (2026-10-14), `{time}` (143000),
   `{datetime}`, `{run}`, `{ext}`. Default `{entity}-{datetime}.{ext}`.
3. **File**: CSV (delimiter, encoding UTF-8, Windows-1252 or ISO-8859-1/-15, BOM, line ending, header row on or off), Excel (.xlsx, sheet name),
   JSON (root key, pretty) or XML (root and record element names).
4. **Map**: without a mapping every column is written as Magento exports it. With a mapping, each row is one
   column of the file: its name, the Magento column it comes from (or a fixed value), transforms and value maps —
   for example `Preis` = `price` with `number(2, ",")` for a German decimal comma.
5. **Rules**: row filters on the Magento columns (`price > 15`, `status` in `pending,processing`, …).
   `core_filters` (attribute => value) are passed to Magento's export filter as well, for example
   `sku` = `SUP-` for a "like" filter.
6. **When**: schedule, e-mails, and for orders "only new since the last run".

A **dry run** writes the file into the run folder (download it from the run page to check it) but delivers it
nowhere. A live run delivers it and keeps a copy in the run folder for "Keep run files for" days.

## Safety

- Cells that would run as a formula in a spreadsheet (`=`, `+`, `-`, `@`, tab, CR first) are prefixed with `'`
  in CSV and Excel files (Stores › Configuration › iranimij › OpenImport › Safety › *Protect exports against
  spreadsheet formulas*). Negative numbers are left alone.
- Excel files are written without any library: plain numbers become numeric cells, everything else stays text
  (SKUs with leading zeros survive). Excel's limit of 1,048,575 rows is enforced with a plain message.
- Destinations use the same encrypted secrets and the same SSRF, path and folder rules as sources
  (see [Sources](sources.md)).

## Orders

The **Orders** entity is OpenImport's own (Magento has no order export). One row per order line by default
(order, customer, totals, billing and shipping address, payment, shipments and tracking numbers repeated on every
line; child lines of configurable and bundle products left out unless chosen), or one row per order. Filters:
statuses, created from/until (in the store time zone of Stores › Configuration › General › Locale, like the schedules; a date alone as "until" counts the whole day), store views, and *Which orders*: all, only orders this profile has not
exported before, or new and changed orders (an order whose `updated_at` moved after its export, for status
changes). Exported orders are remembered per profile (`openimport_export_mark`) after a successful live run; dry
runs remember nothing. Typical use: a nightly "new orders" file for an ERP over SFTP.

Columns: `increment_id`, `state`, `status`, `created_at`, `updated_at`, `store_code`, `store_name`,
`customer_email`, `customer_firstname`, `customer_lastname`, `customer_group`, `customer_is_guest`,
`order_currency_code`, `subtotal`, `subtotal_incl_tax`, `discount_amount`, `discount_description`, `coupon_code`,
`shipping_amount`, `shipping_incl_tax`, `tax_amount`, `grand_total`, `total_paid`, `total_refunded`, `total_due`,
`total_qty_ordered`, `weight`, `shipping_method`, `shipping_description`, `payment_method`,
`payment_last_trans_id`, `payment_po_number`, `customer_note`, `shipment_increment_ids`, `tracking_numbers`,
`tracking_carriers`, `remote_ip`, `billing_*` and `shipping_*` (firstname, lastname, company, street, city,
postcode, region, country_id, telephone, email, vat_id), and per line `item_id`, `item_sku`, `item_name`,
`item_product_type`, `item_parent_sku`, `item_qty_ordered`, `item_qty_invoiced`, `item_qty_shipped`,
`item_qty_refunded`, `item_qty_canceled`, `item_price`, `item_price_incl_tax`, `item_row_total`,
`item_row_total_incl_tax`, `item_discount_amount`, `item_tax_amount`, `item_tax_percent`, `item_weight`,
`item_product_options`. Amounts are in the order's currency without trailing zeros.

## Destinations

- **Download**: the file stays in the run folder, with a button on the run page.
- **Server folder**: under `var/openimport/<path>` or an allowed absolute folder (created when missing). A file of
  the same name is kept and the new one gets the date and time, or overwritten (profile setting).
- **SFTP / FTP**: uploaded into the remote folder; same login settings and secrets as sources.
- **E-mail**: attached (up to 10 MB) to an e-mail sent through the store's mail settings, template
  *OpenImport: an exported file*.
