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
3. **File**: CSV (delimiter, encoding UTF-8 or Windows-1252, BOM, line ending), Excel (.xlsx, sheet name),
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
