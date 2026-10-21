# How OpenImport compares

An honest comparison with the tools merchants usually look at. Everything about other products comes from their
public product pages and documentation as read on 2026-10-13; nothing was installed or inspected (OpenImport is a
clean-room project). Prices change; check the vendor. If something here is wrong, open an issue and it gets fixed.

✓ yes · ○ partly, add-on or higher tier · ✗ not found in the public docs

## Prices and licences

| | OpenImport | Magento Open Source (built in) | Adobe Commerce (built in) | Firebear Improved Import & Export | Amasty Import and Export | Xtento (per module) |
|---|---|---|---|---|---|---|
| Price | **free** | free | Commerce licence | $599 (CE), Commerce edition +$900, B2B +$999 | $209 / $529 / $739 by tier, or $199 + $125/yr renewal | about $199 per module (Order Export, Stock Import, Order Import, Tracking Import) |
| Licence model | MIT, no key, no subscription | OSL | subscription | lifetime, 6 months of updates and support | yearly subscription | one-time |
| Source code | open, GitHub | open | — | open code with the licence | open code with the licence | open code with the licence |
| Telemetry, phone-home | none | none | — | — | — | — |

## Features

| Feature | OpenImport 1.5 | Open Source | Commerce | Firebear | Amasty Premium | Xtento |
|---|---|---|---|---|---|---|
| Saved profiles | ✓ | ✗ | ○ scheduled jobs | ✓ | ✓ (Premium) | ✓ |
| Schedules (cron) | ✓ presets and cron expressions, each due minute exactly once | ✗ | ✓ | ✓ | ○ (Pro and up) | ✓ |
| Column mapping with auto-match | ✓ English and German synonyms, store-view columns | ✗ | ✗ | ✓ | ✓ | ✓ |
| Value maps and transforms | ✓ 20 transforms, expression language, no `eval` | ✗ | ✗ | ✓ (one reviewer missed cell formulas) | ✓ modifiers | ○ |
| **Dry run with a field-level preview of changes** | **✓ every new profile starts with one** | ○ "Check data" validates only | ○ | ✗ (on the roadmap) | ○ file validation | ○ test mode |
| **Typed confirmation above a delete/replace threshold** | **✓** | ✗ | ✗ | ✗ | ✗ | ✗ |
| Delta: only changed rows go to Magento | ✓ per row and store view | ✗ | ✗ | ✓ "import cache" | ✗ | ✗ |
| CSV / XLSX / ODS / JSON / XML | ✓ all five, streamed (flat memory) | CSV | CSV | ✓ | ○ (Pro and up) | ✓ |
| ZIP, gzip, tar archives in and out; XSLT for XML | ✓ zip-slip and zip-bomb safe, XSLT without file or network access | ✗ | ✗ | ✓ | ○ ZIP | ✓ XSLT |
| Upload, server folder, URL, SFTP, FTP | ✓ sandboxed folders, SSRF guard, key login | ✗ | ○ FTP for schedules | ✓ | ✓ (Pro and up) | ✓ |
| Google Sheets | ✓ published links, and private Sheets with a login | ✗ | ✗ | ✓ | ○ | ✗ |
| Saved connections shared by profiles | ✓ encrypted, Test connection, OAuth with the shop's own app | ✗ | ✗ | ○ | ○ | ○ |
| Amazon S3 and S3-compatible storage | ✓ own SigV4 client, R2/Wasabi/MinIO | ✗ | ✗ | ✓ | ○ | ✗ |
| Google Drive, Dropbox, OneDrive / SharePoint | ✓ service account or sign-in, mock-tested in CI | ✗ | ✗ | ✓ | ○ | ✗ |
| REST API as source and destination | ✓ paging (page, offset, cursor, next, Link), three logins, retries | ✗ | ✗ | ✓ | ○ | ○ |
| Pictures from web addresses, downloaded once and cached | ✓ SSRF guard, ETag/Last-Modified | ○ downloads every run | ○ downloads every run | ○ downloads, no cache found | ○ downloads, no cache found | ✗ |
| Configurable products from simple rows (parent column) | ✓ name, variants, own parent rows | ✗ | ✗ | ✓ | ✗ | ✗ |
| Products missing from the file: disable, out of stock, delete | ✓ per scope (all, website, supplier), under the safety limit | ✗ | ✗ | ✓ | ✗ | ✗ |
| Quantities as changes (+5 / -3) | ✓ default stock and MSI sources | ✗ | ✗ | ✓ | ✗ | ✗ |
| URL keys from a pattern, 301 for changed URL keys | ✓ | ✗ | ✗ | ✓ | ✗ | ✗ |
| Conditional transforms (price +15 % only for one brand) | ✓ "only when" on every transform | ✗ | ✗ | ✓ | ○ modifiers | ✗ |
| Several files per run, one report each | ✓ | ✗ | ✗ | ✓ | ✗ | ✗ |
| Shopify, WooCommerce, Magento 1 presets | ✓ | ✗ | ✗ | ✓ | ✗ | ✗ |
| Products, advanced pricing, customers, addresses, MSI stock | ✓ through Magento's own importer | ✓ | ✓ | ✓ | ✓ | ○ stock and price |
| Fast stock & price update of existing products | ✓ | ✗ | ✗ | ○ | ○ | ✓ |
| Order export (per order or per line, only new or changed) | ✓ | ✗ | ✗ | ✓ | ✓ | ✓ |
| Shipment and tracking import | ✓ via Magento's shipment service, duplicates skipped | ✗ | ✗ | ✓ | ✓ | ✓ |
| Order import | ✓ historic orders as they are, round-trip with the export, invoices/shipments/credit memos optional | ✗ | ✗ | ✓ | ✓ with invoice/ship actions | ✓ |
| Categories (paths, store views) | ✓ | ✗ | ✗ | ✓ | ✗ | ✗ |
| Product attributes and attribute sets (options, store labels) | ✓ | ✗ | ✗ | ✓ | ✓ | ✗ |
| CMS pages and blocks | ✓ | ✗ | ✗ | ✓ | ✓ | ✗ |
| URL rewrites (custom, product, category, CMS page; 301/302; conflicts with generated URLs reported) | ✓ import and export | ✗ | ✗ | ✓ | ✓ | ✗ |
| Cart price rules with coupons and labels, catalog price rules; conditions as simple columns or Magento's tree as JSON | ✓ import and export | ✗ | ✗ | ✓ | ✓ | ✗ |
| Product reviews with ratings, newsletter subscribers, search terms and synonyms, product positions in categories | ✓ import and export, round trips | ✗ | ✗ | ✓ | ○ reviews, newsletter | ✗ |
| Export destinations | ✓ download, folder, SFTP, FTP, S3, Google Drive/Sheet, Dropbox, OneDrive, REST, e-mail | ✗ | ○ | ✓ | ✓ | ✓ |
| Run history, error report with your file's line numbers | ✓ original rows in the report | ○ history | ○ last outcome | ✓ | ✓ | ✓ |
| E-mail on failure or always | ✓ | ✗ | ○ | ✓ | ✓ | ✓ |
| CLI | ✓ run, status, list, queue, profiles export/import, doctor, secrets | ✗ (REST only) | ✗ | ✓ | ✗ | ○ |
| Profiles as JSON for version control | ✓ | ✗ | ✗ | ○ | ○ | ✗ |
| Health check (`doctor`) and cron watchdog | ✓ | ✗ | ✗ | ✗ | ✗ | ✗ |
| Spreadsheet formula-injection guard | ✓ | ✗ | ✗ | ✗ | ✗ | ✗ |
| Encrypted credentials, never shown again | ✓ | — | — | ○ | ○ | ○ |
| Event-triggered export (order placed → file) | ✓ debounced, one run per minute | ✗ | ✗ | ✓ | ✓ | ✓ |
| Job chains (run B after A) | ✓ after success or always, parent run, loops refused at save | ✗ | ✗ | ✓ | ✗ | ✗ |
| Trigger profiles by REST, read run status | ✓ | ○ raw CSV/JSON import API | ○ | ○ | ○ | ✗ |
| B2B entities | ✗ | ✗ | ○ | ✓ (add-on) | ✗ | ✗ |

## Where OpenImport is different by design

- **Magento writes, not OpenImport.** Rows go through `Magento_ImportExport`, so the result is exactly what core
  import would produce and upgrades keep working. No raw SQL into catalog, customer or sales tables.
- **You see before you write.** The dry run shows every field that would change; delete and replace runs above a
  threshold wait for a typed word. Nobody else ships this today.
- **Measured, not claimed.** Overhead against core's own CSV import is under 6 % on 10k and 50k products, a file with
  1 % changed rows costs 2 % of a full run, and memory is flat from 10k to 50k rows. See [Baseline](baseline.md).
- **Hardened.** SSRF guard with DNS re-checks per redirect, no XML entities, zip-bomb limits, formula guard,
  credentials never leave the database. See [Security](security.md).

## Where the paid tools still have more

More triggers, webhook and Slack notifications, reindex after a run, undo, B2B. Automation is
planned for 1.6 (job chains shipped); B2B is declined (Adobe Commerce only). See the [roadmap](https://github.com/iranimij/openimport/milestones).

## Free alternatives for developers

| | What it is | What it lacks for merchants |
|---|---|---|
| `firegento/fastsimpleimport` (GPL-3) | PHP array API around core import, 1.2M installs | no admin, no files, no schedules |
| `techdivision/import` (Pacemaker, MIT) | CLI import framework writing to the database directly | no admin, developer setup, many dependencies |
| Magento's import REST API (`/V1/import/csv`) | base64 CSV or JSON to core import | no mapping, no sources, no schedules, no preview |

Sources: [Firebear product page](https://firebearstudio.com/the-improved-import.html), [Amasty Import and Export](https://amasty.com/import-and-export-for-magento-2.html), [Xtento](https://xtento.com/), [Adobe: scheduled import and export (Commerce only)](https://experienceleague.adobe.com/en/docs/commerce-admin/systems/data-transfer/data-scheduled-import-export), [Adobe: import REST API](https://developer.adobe.com/commerce/webapi/rest/modules/import), [fastsimpleimport](https://packagist.org/packages/firegento/fastsimpleimport), [techdivision/import](https://packagist.org/packages/techdivision/import).
