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

| Feature | OpenImport 1.0 | Open Source | Commerce | Firebear | Amasty Premium | Xtento |
|---|---|---|---|---|---|---|
| Saved profiles | ✓ | ✗ | ○ scheduled jobs | ✓ | ✓ (Premium) | ✓ |
| Schedules (cron) | ✓ presets and cron expressions, each due minute exactly once | ✗ | ✓ | ✓ | ○ (Pro and up) | ✓ |
| Column mapping with auto-match | ✓ English and German synonyms, store-view columns | ✗ | ✗ | ✓ | ✓ | ✓ |
| Value maps and transforms | ✓ 20 transforms, expression language, no `eval` | ✗ | ✗ | ✓ (one reviewer missed cell formulas) | ✓ modifiers | ○ |
| **Dry run with a field-level preview of changes** | **✓ every new profile starts with one** | ○ "Check data" validates only | ○ | ✗ (on the roadmap) | ○ file validation | ○ test mode |
| **Typed confirmation above a delete/replace threshold** | **✓** | ✗ | ✗ | ✗ | ✗ | ✗ |
| Delta: only changed rows go to Magento | ✓ per row and store view | ✗ | ✗ | ✓ "import cache" | ✗ | ✗ |
| CSV / XLSX / JSON / XML | ✓ all four, streamed (flat memory) | CSV | CSV | ✓ plus ODS, XSLT, ZIP | ○ (Pro and up) | ✓ |
| Upload, server folder, URL, SFTP, FTP | ✓ sandboxed folders, SSRF guard, key login | ✗ | ○ FTP for schedules | ✓ | ✓ (Pro and up) | ✓ |
| Google Sheets | ✓ published links | ✗ | ✗ | ✓ | ○ | ✗ |
| Google Drive, Dropbox, OneDrive | ✗ (1.2) | ✗ | ✗ | ✓ | ○ | ✗ |
| Products, advanced pricing, customers, addresses, MSI stock | ✓ through Magento's own importer | ✓ | ✓ | ✓ | ✓ | ○ stock and price |
| Fast stock & price update of existing products | ✓ | ✗ | ✗ | ○ | ○ | ✓ |
| Order export (per order or per line, only new or changed) | ✓ | ✗ | ✗ | ✓ | ✓ | ✓ |
| Order import, tracking import | ✗ (1.2) | ✗ | ✗ | ✓ | ✓ with invoice/ship actions | ✓ |
| Categories, attributes, CMS, URL rewrites, rules | ✗ (1.1) | ✗ | ✗ | ✓ | ✓ | ✗ |
| Export destinations | ✓ download, folder, SFTP, FTP, e-mail | ✗ | ○ | ✓ | ✓ | ✓ |
| Run history, error report with your file's line numbers | ✓ original rows in the report | ○ history | ○ last outcome | ✓ | ✓ | ✓ |
| E-mail on failure or always | ✓ | ✗ | ○ | ✓ | ✓ | ✓ |
| CLI | ✓ run, status, list, queue, profiles export/import, doctor, secrets | ✗ (REST only) | ✗ | ✓ | ✗ | ○ |
| Profiles as JSON for version control | ✓ | ✗ | ✗ | ○ | ○ | ✗ |
| Health check (`doctor`) and cron watchdog | ✓ | ✗ | ✗ | ✗ | ✗ | ✗ |
| Spreadsheet formula-injection guard | ✓ | ✗ | ✗ | ✗ | ✗ | ✗ |
| Encrypted credentials, never shown again | ✓ | — | — | ○ | ○ | ○ |
| Event-triggered export (order placed → file) | ○ schedule every minute with "only new" (1.1: events) | ✗ | ✗ | ✓ | ✓ | ✓ |
| Trigger profiles by REST | ✗ (1.1) | ○ raw CSV/JSON import API | ○ | ○ | ○ | ✗ |
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

Entity breadth (categories, attributes, CMS, orders import, rules, URL rewrites), cloud drives (Google Drive, Dropbox,
OneDrive) and REST API sources, event-driven exports, image download helpers, B2B. These are the 1.1 and 1.2
milestones; see the [roadmap](https://github.com/iranimij/openimport/milestones).

## Free alternatives for developers

| | What it is | What it lacks for merchants |
|---|---|---|
| `firegento/fastsimpleimport` (GPL-3) | PHP array API around core import, 1.2M installs | no admin, no files, no schedules |
| `techdivision/import` (Pacemaker, MIT) | CLI import framework writing to the database directly | no admin, developer setup, many dependencies |
| Magento's import REST API (`/V1/import/csv`) | base64 CSV or JSON to core import | no mapping, no sources, no schedules, no preview |

Sources: [Firebear product page](https://firebearstudio.com/the-improved-import.html), [Amasty Import and Export](https://amasty.com/import-and-export-for-magento-2.html), [Xtento](https://xtento.com/), [Adobe: scheduled import and export (Commerce only)](https://experienceleague.adobe.com/en/docs/commerce-admin/systems/data-transfer/data-scheduled-import-export), [Adobe: import REST API](https://developer.adobe.com/commerce/webapi/rest/modules/import), [fastsimpleimport](https://packagist.org/packages/firegento/fastsimpleimport), [techdivision/import](https://packagist.org/packages/techdivision/import).
