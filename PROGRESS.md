# PROGRESS

Fine-grained checkpoint for the OpenImport build. Updated after every slice. The Notion build log
(07 · Build Brief, section 10) holds the per-milestone summary. GitHub: the working-hours rule does **not** apply to
OpenImport (Iman, 2026-10-13); every slice is pushed as soon as it is done.

Work split (Iman, 2026-10-13): code, CI and Notion run in cloud threads; browser checks and Playwright run locally on
Iman's Mac (`~/Projects/openimport-dev-env`, Warden) whenever the laptop is open. Local testing never blocks cloud work.

## Done

- **M0 · Foundation + core-bridge spike** (2026-10-13): skeleton, CI on 7 Magento/PHP pairs, core bridge with oracle
  tests (#1, #2, #3), dev-env with Warden fixture, Playwright baseline and benchmark workflow (dev-env #1), baselines
  in `docs/baseline.md` (10k/20k/50k).
- **M1 · Engine** (2026-10-13/14): readers, transforms, mapping, schema and repositories, pipeline, stock & prices,
  CLI, runner tests and benchmark (#4–#11 merged).
- **M2 · Admin** (2026-10-14): grids, profile form, mapping editor, run page, `openimport:queue:run` (#12 merged).
- **M3 · Automation** (2026-10-14): S1 cron and schedules (#13), S2 sources (#20, replaces #14) merged; S3 e-mails
  (#15), S4a export engine (#16), S4b orders and destinations (#17), S5 profiles JSON and doctor (#18), S6 sample
  profiles and notices (#19) are a stacked chain, merged bottom-up as each goes green.

## Released

**1.0.0 (2026-10-15):** M3 chain merged (#13, #20, #15–#19), M4 (#21 uninstall, security docs, README, changelog;
#22 release workflow), tag and GitHub release `v1.0.0` through the Release workflow (cloud sessions cannot push tags
or create releases; Actions › Release › Run workflow does it). Dev-env: 14 Playwright scenarios green on 2.4.9
(dev-env #3, #4 merged; `.github/ci/openimport-ref` back to `main`).

**1.1.0 (2026-10-15), M5:** own importers behind `ImporterPool` for categories (#26, fixes #31 and #32), product
attributes and sets (#27), CMS pages and blocks (#28); REST API to start runs and read their status, order exports
after every placed order (#29); comparison tables, README and changelog (#30). Release `v1.1.0` through the Release
workflow, docs redeployed, Packagist shows 1.1.0. Dev-env: E2E for German categories and CMS blocks (dev-env #5),
green against `main`.

Lessons from M5: the pipeline pads every row to the same columns, so an own importer must treat an empty cell as
"leave unchanged"; Magento saves store-view values in the default scope when the shop has one store view; category
URL clashes need the taken URLs in the message to be debuggable.

**1.1.1 (2026-10-15):** fixes from a hands-on browser review of 1.1.0 (Notion "13 · Browser review 1.1.0"): #34 form
and mapping, #35 grids and run page, #36 counts and messages, #37 changelog and docs; dev-env E2E updated.

**1.1.2 (2026-10-15):** every QA item (Q1–Q21) and P11/P12 of the second browser review (Notion "14 · Browser review
2"): grid row actions post (no 404), filters and keyword search, cascading category deletes counted, no schedules on
uploads, form fields per direction and setting (shared `conditional.js`), private key field and repair, save problems
at their fields, leave guard, dialogs, translations CI check (#39). Release `v1.1.2`. Dev-env #7: E2E for every grid
row action, export-only settings, save errors, SFTP key login (the workflow makes a key pair).

**1.2.0 (2026-10-15, #40):** the design (D1–D15) and product (P1–P10) items of the same review: run page chip and
headline, one row per record in the preview, mapping rows, toggles, drop zone, step navigation with summaries and
docs links, order filters in Rules, row click, recent runs, cancel running runs, run again, mass actions, schedules
every 15/30 min and every day at a time with next runs, profile JSON in the admin, e-mail sender, first rows of
exports, status line above the grid. Release `v1.2.0`, docs redeployed. Dev-env #8: E2E for the new form (fields
from the list, daily schedule with next runs, Download as JSON), a11y names on the grid checkboxes.

Lessons from the second review: Magento grid actions with `post` but no confirm are plain links (a POST-only
controller answers 404), so the actions column needs its own handler; a grid select filter needs `dataType select`
on the column; `FulltextFilter` does nothing without a fulltext index; in Knockout attribute bindings (`text="…"`) a
colon must be written `\:`, also inside strings.

Open for Iman: delete the temporary branch `ci/m5-all` (shows as a dev version on Packagist; cloud sessions cannot
delete branches), repository settings (squash only, delete branch on merge,
`main` protected with `ci-ok`), a look at the admin on the Mac. Next product work: Notion "12 · Roadmap 1.x".

## Lessons kept (also in Notion)

- Magento's knockout template renderer wraps any attribute value with a colon in braces: write `text="$t('Label\:')"`
  and `options="..., optionsText\: 'label'"`; native `value`/`checked`/`disabled` attributes are plain HTML, use
  `ko-value`, `ko-checked`, `ko-disabled`.
- `foreach ($data['items'] ?? [] as &$row)` iterates a copy; test by reference on the array itself.
- `setup:install` builds every console command before stores exist: proxy anything that reaches the store manager.
- A UI toggle without data posts `0`: give the form data explicit defaults.
- Integration tests: every shared service keeps the first request; one layout page per test, empty the same request
  object between dispatches, file answers (FileResponse) never reach the test response.
- E-mails render in the frontend area for the default store view (header/footer templates live there).

## Plan 1.3–1.6 (every feature of the paid modules)

Written 2026-10-16 on Iman's request; Notion "15 · Plan 1.3–1.6 · every feature of the paid modules"
(https://app.notion.com/p/3fb563c039fc811082f5e481e52fe233). Status: **approved 2026-10-16** ("go"), all eight
recommendations in §6 accepted (order import as historic as-is, shared connections, no new Composer packages,
merchants register their own OAuth apps, mocks in CI + optional nightly live tests, undo only for update runs,
parallelism only across runs and for exports).

| Release | Slices | Status |
|---|---|---|
| 1.3 Catalog helpers | 1 images from URLs · 2 configurables from simple rows · 3 products missing from the file · 4 add to stock · 5 URL keys and 301s · 6 conditional transforms · 7 several files per run · 8 Shopify/WooCommerce/Magento 1 presets | 1 done (#44), 2 done (#45); 3 in review (#46); 4 `feat/1.3-add-stock`, 5 `feat/1.3-url-keys`, 6 `feat/1.3-conditional` (stacked) |
| 1.4 Connectors | 1 connections · 2 S3 · 3 Google Drive/Sheets · 4 Dropbox, OneDrive · 5 REST API source/destination · 6 archives · 7 ODS, XSLT | not started |
| 1.5 Sales and marketing entities | 1 shipments and tracking · 2 order import · 3 URL rewrites · 4 price rules and coupons · 5 reviews, newsletter, search terms, category positions | not started |
| 1.6 Automation and operations | 1 job chains · 2 triggers · 3 webhook/Slack · 4 reindex · 5 parallel workers · 6 undo · 7 close | not started |

Rule for every slice: one PR, pushed at once; this file updated after each slice (done, exact next step, open
questions, failing/skipped tests, CI link); unit ≥ 85 % on new namespaces, integration on all seven pairs, oracle or
round-trip tests for entities, security tests, E2E with axe/CSP; full matrix before merging schema, runner or sales
code.

## Exact next step

Stack: slice 3 PR #46 (`feat/1.3-missing`) ← slice 4 (`feat/1.3-add-stock`) ← slice 5 (`feat/1.3-url-keys`) ← slice 6
(`feat/1.3-conditional`). Merge #46 when green, rebase 4 onto main and open its PR, merge, then the same for 5 and 6. E2E: dev-env PR #10
(configurables) and #11 (missing + stock, ref `feat/1.3-add-stock`; set back to main after merge). Then slice 7
(several files per run: one child run per matching file) on `feat/1.3-multi-file`.

Local store: after a schema change, double-click `claude-upgrade.command` in the dev-env folder (runs
`setup:upgrade`); the cloud thread cannot run Warden commands.

### 1.3 slice 1 · pictures from web addresses (what was built)

- `Model/Http/SafeDownloader` (+ `DownloadResult`): the URL source's safe download, now shared: SSRF guard on every
  hop, pinned address, size cap, timeout, conditional requests (304).
- Row steps: `Model/Pipeline/Step/RowStepInterface`, `RowStepPool` (di.xml), `StepRecord`. `ImportRunner::flush()`
  runs them after behaviour and delta; failed records become row errors (with the source row), incomplete records keep
  no delta hash, step summaries end the run message.
- `Model/Image`: `ImageStep` (columns from `ImageColumns`, custom media_image attributes included), `ImageFetcher`
  (per-run memo, cache freshness, conditional requests, earlier file when the server is away), `ImageSniffer`
  (JPG/PNG/GIF by magic bytes; WebP/AVIF/SVG/HTML refused with reasons), `ImageName`
  (`openimport/<2>/<8>/<name>.<ext>` from the content SHA-1), `ImageCache` (table `openimport_image`),
  `ImageFolder` (core's import images folder; product runs pass it to core as `import_images_file_dir`).
- Profile column `import_options` (JSON) with `images_on_error` (error | skip); form field in step 5 for products.
- Config group `openimport/images` (refresh_hours 24, max_mb 10, timeout 30, keep_days 30); housekeeping prunes.
- Tests: unit (sniffer, names, freshness, step, runner with a fake step, pool); integration `ImageImportTest` with
  PHP's built-in server (`Test/Integration/_files/image-server.php`): download, 304, no request inside the refresh
  time, 404/HTML/WebP errors, skip option, SSRF refusal, file names from var/import/images, housekeeping.
- Docs `docs/images.md`; changelog Unreleased (Added + Changed: file names now read from `var/import/images`).
- Merged as #44 (CI green on all seven pairs), E2E dev-env #9 (`pictures.spec.ts`) green.
- Lesson: Magento's object manager leaves optional constructor arguments at their default (null) unless di.xml sets
  them; new collaborators must be required arguments.

### 1.3 slice 2 · configurable products from simple rows (what was built)

- Row step hooks grew: `columns()` (add/drop core columns), `observe()` (every valid record, before behaviour and
  delta), `finish()` (records added after the last batch, which pass behaviour, delta and preview), `AbstractRowStep`
  with no-op defaults, `StepRecord::absorb()` (a step takes a record over).
- `Model/Configurable`: `ConfigurableStep` (groups, problems, own parent rows, conflicts, hidden variants, URL keys for
  new variants), `ParentBuilder` (parent rows, `configurable_variations`, common-prefix name), `ConfigurableAttributes`
  (global user-defined dropdowns), `UrlKey`; source `ConfigurableAttribute`; mapping targets
  `configurable:parent_sku` / `configurable:parent_name` (FieldList); form fields in step 5; validator checks.
- Tests: unit (builder, step, runner with the real step and delta), validator; integration `ConfigurableImportTest`
  (attributes created by OpenImport's attribute import, parent + links, delta, added variant keeps old links, own
  parent row, duplicate/empty variants).
- Docs `docs/configurable.md`. Variant values must be options (checked against admin labels, row error otherwise).
- Merged as #45 (all seven pairs green); E2E dev-env #10 (`configurable.spec.ts`).

### 1.3 slice 3 · products missing from the file (what was built)

- `PostImportStepInterface` (+ `PostImportPlan`, `PostImportOutcome`): plan after the last batch (before core),
  execute after core imported (dry run: validate). Runner: no plans when rows had own errors, plan safety joins the
  behaviour's safety (typed word from the plan), runs even when the file itself changes nothing.
- `observeIdentifiers()` hook: filtered rows (mapped for their identifier only when a post-import step takes part),
  rows with mapping errors and records other steps added (configurable parents) count as named.
- `Model/Execution/ConfirmationWord`: the word comes from the run message ("Type DISABLE to confirm."), fallback by
  behaviour; used by the run page and the Confirm controller.
- `Model/Missing`: `MissingProductsStep` (disable / out of stock / delete via core import), `MissingScope` (all,
  website, attribute value through the product collection; disable counts enabled, out of stock counts in-stock).
  Form fields in step 5, validator checks, source `WebsiteCode`.
- Tests: unit (step, confirmation word, runner: disable, safety + dry run + confirmed delete, row errors, nothing
  changed in the file, parents not missing); integration `MissingProductsImportTest` (supplier scope per test,
  dry run = live, out of stock with the limit, delete, row errors stop it).
- Docs `docs/missing-products.md`.

### 1.3 slice 4 · quantities as changes (what was built)

- `Model/Stock`: `StockChangeStep` (+5/-3/4 for products, Stock & prices, MSI sources; running totals per SKU or
  SKU|source; refuses "skip unchanged rows"), `QuantityReader` (default stock, inventory_source_item), `QuantityChange`
  (parse, format). Form field *Quantities in the file* (step 5), validator check.
- Runner: the dry-run preview now compares the step rows (what Magento receives). RunProcessor locks
  `entity:<Magento entity>` (Stock & prices shares the products lock).
- Tests: unit (parse/format, step incl. sources and repeated SKUs, validator); integration `StockChangeImportTest`
  (preview 10 → 15/17, live 17 and 7, row error, second file adds again, MSI source +4).
- Docs `docs/stock-changes.md`.

### 1.3 slice 5 · URL keys and 301 redirects (what was built)

- `Model/UrlKey`: `UrlKeyStep` (pattern for rows with an empty URL key, store views with own values, duplicates in
  the run or in the shop fail the row naming the other SKU; `save_rewrites_history = 1` on every row when redirects
  are on: Magento's import then keeps the old URL as a 301), `UrlKeyPattern`, `UrlKeyOwners` (collection read).
  Runs before the configurable step (which then leaves URL keys alone). Form fields, validator check.
- Tests: unit (pattern, step), validator; integration `UrlKeyImportTest` (key from pattern, renamed product → old URL
  301 to the new one, duplicate key error).
- Docs `docs/url-keys.md`.

### 1.3 slice 6 · conditional transforms (what was built)

- `TransformPool`: a definition may have `when` (expression, parsed once); the step runs only where it is true, other
  rows keep the value; parse errors name the step, evaluation errors are row errors.
- Mapping editor: *Only when* input in front of the transform picker (typed before choosing, so transforms without
  settings that are added at once take it too); the chip shows "· only when …".
- Tests: unit (pool: conditions on columns and `value`, blank condition, errors). E2E in the dev-env pending.
- Docs: "Only when" section in `docs/transforms.md`.

## Open questions

- D3 (01 · Decisions First): own XLSX reader/writer (recommended) or openspout. Default until answered: own reader.
- Repository settings (squash only, auto-merge, delete branch on merge, `main` protected with `ci-ok`) cannot be
  written from cloud threads (proxy refuses settings writes): Iman sets them once, or the repo-factory workflow does.

## Failing or skipped tests

None.
