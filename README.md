# OpenImport

Import and export for Magento 2 and Mage-OS: saved profiles, column mapping, value transforms, a dry run that shows
every change before anything is written, schedules, saved connections, and remote sources and destinations (SFTP,
FTP, S3, URL, server folder, e-mail). Built on Magento's own import engine, so what lands in the catalog is exactly what core import would
produce. Free forever, MIT, no licence keys, no telemetry.

[![CI](https://github.com/iranimij/openimport/actions/workflows/ci.yml/badge.svg)](https://github.com/iranimij/openimport/actions/workflows/ci.yml)
[![Packagist](https://img.shields.io/packagist/v/iranimij/openimport)](https://packagist.org/packages/iranimij/openimport)
[![Licence](https://img.shields.io/badge/licence-MIT-green)](LICENSE)

Documentation: **https://iranimij.github.io/openimport/**

## Install

```bash
composer require iranimij/openimport
bin/magento module:enable Iranimij_Base Iranimij_OpenImport
bin/magento setup:upgrade
bin/magento cache:flush
```

Requirements: Magento Open Source 2.4.7, 2.4.8 or 2.4.9 (or Mage-OS), PHP 8.2 to 8.4, `ext-zip` and `ext-xmlreader`
(both required by Magento anyway). Magento's cron must run (`bin/magento cron:run` every minute) for schedules and
queued runs. Adobe Commerce should work but is not tested. Hyvä is not involved: the module is admin-only.

Uninstall without a trace: `bin/magento module:uninstall Iranimij_OpenImport --remove-data`.

## Five-minute start

1. **System › Data Transfer › OpenImport › Profiles › New profile.** Two sample profiles are there already.
2. Choose what to import (products, prices, stock, customers, addresses, MSI stock, or the fast *Stock & prices* update)
   and where the file comes from (upload, server folder, web address, SFTP, FTP, S3). *Test connection* fetches the
   first rows right away. Or start from a preset for Shopify, WooCommerce or Magento 1 product files.
3. Upload or fetch a file, press **Auto-match**: `Artikelnummer` becomes `sku`, `Preis` becomes `price`, `name_de`
   becomes the German store view's name. Add transforms (trim, decimal comma, maths, `.99` endings, dates, value maps,
   expressions) where needed, and row filters if only some rows should go in.
4. **Save and dry run.** The run page shows, field by field, what would be created, changed or deleted, with the
   errors of your own file's line numbers. Nothing is written yet.
5. **Run for real.** Or set a schedule (hourly, nightly, weekdays, or any cron expression) and let cron do it.

Exports work the same way in the other direction: products, customers, addresses, advanced prices, MSI stock and
orders to CSV, XLSX, JSON or XML, downloaded, dropped in a folder, pushed to SFTP/FTP/S3, or e-mailed.

## What it does

| | |
|---|---|
| **Profiles** | Saved import and export settings, duplicated, exported to JSON and imported again (version control, staging → production). |
| **Files** | CSV/TSV (delimiter and encoding detected, UTF-16 and Windows-1252 too), Excel `.xlsx`, JSON, XML. Streamed: a 50k-row file needs about the same memory as a 1k-row file. |
| **Sources** | Upload, server folder (sandboxed, with "move to processed" or delete after success), HTTP(S) URL including published Google Sheets, SFTP (password or key), FTP(S), Amazon S3 and S3-compatible storage (R2, Wasabi, MinIO). All matching files, one run each. Private and internal addresses are refused unless you allow them. |
| **Connections** | Saved logins shared by profiles (System › Data Transfer › OpenImport › Connections) with *Test connection*; secrets encrypted and never shown again; profile JSON names the connection instead of carrying a login. |
| **Catalog helpers** | Product pictures from web addresses (downloaded by OpenImport, cached by ETag), configurable products from simple rows (*Parent SKU* column), products missing from the file disabled, set out of stock or deleted (under the safety limit), quantities as changes (`+5`, `-3`), URL keys from a pattern with 301 redirects. |
| **Mapping** | Columns to fields, fixed values, required fields, store-view columns (`name_de`, `price_ch`), auto-match with English and German synonyms. |
| **Transforms** | 21 transforms and a safe expression language (`{price} * 1.19`, `if(...)`, `round(...)`), no `eval`, parsed once per run; every transform can be conditional (*Only when*). Own transforms through `di.xml`. |
| **Dry run** | Every new profile starts with one. Field-level before/after preview, counts of new, changed, unchanged and deleted records, error report with your file's line numbers. |
| **Safety** | Runs that would delete or replace more than a threshold wait for a typed confirmation. Formula-injection guard on every CSV the module writes. |
| **Delta** | Only rows that changed since the last run are sent to Magento (per line, per store view). |
| **Schedules** | Cron expressions and presets, each due minute runs exactly once, catch-up after a missed window, one run per profile at a time. |
| **Exports** | Core entities and orders (one row per order or per line, only new or changed orders), renamed and transformed columns, to CSV, XLSX, JSON, XML and six destinations (download, folder, SFTP, FTP, S3, e-mail). |
| **Operations** | Run history, e-mail on failure or always, a system message when cron stops, `openimport:doctor`, housekeeping of old run files. |
| **REST API** | `POST /V1/openimport/profiles/{id}/runs` starts a profile, `GET /V1/openimport/runs/{id}` reports on it; order exports can run after every placed order. |
| **CLI** | `openimport:run`, `openimport:run:status`, `openimport:profile:list`, `openimport:queue:run`, `openimport:profiles:export`, `openimport:profiles:import`, `openimport:secrets:reencrypt`, `openimport:doctor`. |

Entities: products (every type, with images, categories, related products and custom options exactly as core
import supports them), advanced pricing, customers, customer addresses, customers with addresses, MSI stock sources,
the fast *Stock & prices* update (existing products only), categories, product attributes and attribute sets, CMS
pages and blocks (import and export), and order export. Anything Magento's own import can do
with a CSV, OpenImport does with a mapped file.

## How it compares

✓ yes · ○ partly or higher tier · ✗ no · **1.4** / **1.5** planned ([roadmap](https://github.com/iranimij/openimport/milestones)).
From the vendors' public docs as of 2026-10-13; details and sources in [docs/comparison.md](docs/comparison.md).

| | **OpenImport** | Magento Open Source | Adobe Commerce | Firebear Improved I/E | Amasty Import & Export | Xtento |
|---|---|---|---|---|---|---|
| Price | **free, MIT** | free | Commerce licence | $599 + add-ons | $209–$739 or subscription | ~$199 per module |
| **Dry run with a field-level preview of every change** | **✓** | ○ validate only | ○ | ✗ roadmap | ○ validate only | ○ |
| **Typed confirmation above a delete/replace threshold** | **✓** | ✗ | ✗ | ✗ | ✗ | ✗ |
| Delta: only changed rows go to Magento | ✓ | ✗ | ✗ | ✓ | ✗ | ✗ |
| Schedules (cron) | ✓ | ✗ | ✓ | ✓ | ○ | ✓ |
| Column mapping with auto-match, store-view columns | ✓ | ✗ | ✗ | ✓ | ✓ | ✓ |
| Transforms and a safe expression language | ✓ | ✗ | ✗ | ✓ | ✓ | ○ |
| CSV, XLSX, JSON, XML, streamed | ✓ | CSV | CSV | ✓ | ○ | ✓ |
| Upload, folder, URL, SFTP, FTP, Google Sheets | ✓ | ✗ | ○ | ✓ | ○ | ✓ |
| Saved connections, S3-compatible storage | ✓ | ✗ | ✗ | ✓ | ○ | ✓ |
| Google Drive, Dropbox, OneDrive | **1.4** | ✗ | ✗ | ✓ | ○ | ✗ |
| Pictures from URLs, configurables from simple rows, missing products, quantities as changes, URL keys with 301s | ✓ | ○ pictures | ○ | ✓ | ✓ | ○ |
| Presets for Shopify, WooCommerce, Magento 1 files | ✓ | ✗ | ✗ | ✓ | ○ | ✗ |
| Products, prices, customers, addresses, MSI stock | ✓ via Magento's own importer | ✓ | ✓ | ✓ | ✓ | ○ |
| Fast stock & price update | ✓ | ✗ | ✗ | ○ | ○ | ✓ |
| Order export (only new or changed) | ✓ | ✗ | ✗ | ✓ | ✓ | ✓ |
| Categories, attributes and attribute sets, CMS pages and blocks | ✓ | ✗ | ✗ | ✓ | ✓ | ✗ |
| Cart and catalog price rules, URL rewrites | **1.5** | ✗ | ✗ | ✓ | ✓ | ✗ |
| Order import, tracking import | **1.5** | ✗ | ✗ | ✓ | ✓ | ✓ |
| Export to download, folder, SFTP, FTP, S3, e-mail | ✓ | ✗ | ○ | ✓ | ✓ | ✓ |
| Export right after an order is placed | ✓ | ✗ | ✗ | ✓ | ✓ | ✓ |
| Start profiles over REST, read run status | ✓ | ○ raw CSV import | ○ | ○ | ○ | ✗ |
| Error report with your file's own line numbers and rows | ✓ | ○ | ○ | ✓ | ✓ | ✓ |
| CLI, profiles as JSON for version control | ✓ | ✗ | ✗ | ○ | ✗ | ○ |
| Health check and cron watchdog (`doctor`) | ✓ | ✗ | ✗ | ✗ | ✗ | ✗ |
| SSRF guard, no XML entities, zip-bomb limit, formula guard | ✓ | ○ | ○ | ✗ found | ✗ found | ✗ found |
| Measured overhead vs core import (<6 %), flat memory | ✓ [baseline](docs/baseline.md) | — | — | claims only | claims only | — |
| Telemetry, licence keys, subscription | none | none | — | key | key, yearly | key |

## Why not Magento's built-in import?

Core import wants a CSV in its own column layout, imports everything or nothing, and tells you about errors only
after you tried. OpenImport keeps core's engine (and its correctness) but adds the parts that make imports a daily job
instead of a project: mapping, transforms, dry runs, schedules, remote files, history and alerts.

## Security

The module fetches files from remote servers and writes into your catalog, so it is careful: SSRF guard with DNS
re-checks on every redirect, sandboxed server folders, no XML entities, zip-bomb limits for `.xlsx`, encrypted
credentials that never leave the database (not in JSON exports, not in forms), CSRF-protected admin actions behind
six ACL resources, and a formula guard for spreadsheets. See [docs/security.md](docs/security.md) and
[SECURITY.md](SECURITY.md) for how to report a problem.

## Contributing

Issues and pull requests are welcome; see [CONTRIBUTING.md](CONTRIBUTING.md). Issues are triaged within 7 days.
Please attach a 10-row sample of your file. There is no SLA and no paid version.

## Licence

MIT. See [LICENSE](LICENSE) and [NOTICE](NOTICE).
