# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and versions follow [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added
- Categories import and export (`openimport_category`): paths by names, parents created on the way, store-view
  values, dry-run preview, delete with children; own importers plug into the pipeline through `ImporterInterface`
  and `ImporterPool` (core's import engine stays the default). See docs/categories.md.
- Product attributes and attribute sets import and export (`openimport_attribute`): create attributes with input
  type, options, flags and scope, assign to sets and groups, store-view labels and option labels; Delete only
  unassigns. See docs/attributes.md.

## [1.0.0] - 2026-10-15

First release.

### Added
- Module skeleton on `iranimij/module-base`: settings under Stores › Configuration › iranimij › OpenImport, ACL
  under System › Data Transfer, English and German translations, CI.
- Core bridge: prepared rows go into Magento's own import engine without a temporary file (`MappedSource`,
  `CoreImporter`): validate-only dry runs that leave nothing behind, live runs recorded in core's import history,
  core's shared error aggregator cleared per run. Proven equal to core's own CSV import on Magento 2.4.7, 2.4.8 and
  2.4.9.
- File readers for CSV/TSV (delimiter and encoding detection, UTF-16, Windows-1252), Excel .xlsx (own streaming
  reader, dates, sheet choice), JSON (record path, nested fields as dotted columns) and XML (streaming, record path,
  attributes, no DOCTYPE); format detection from the file name or content. See docs/file-formats.md.
- Transforms for mapped fields (text, regex, HTML stripping, numbers with decimal commas, math, rounding with price
  endings, price adjustment, dates, split/join, value maps) and a safe expression language with columns, arithmetic,
  comparisons and 14 functions, parsed once per run without eval. Own transforms can be added through di.xml. See
  docs/transforms.md.
- Mapping engine: source columns, fixed values, transform chains, required fields, identifier checks, store-view
  values as extra rows (with SKU, attribute set and type carried), auto-match with German and English synonyms and
  store-view column names; row filters (include/exclude, all/any, 13 operators or expressions). See docs/mapping.md.
- Database schema (`openimport_profile`, `_mapping`, `_value_map`, `_run`, `_run_error`, `_run_preview`, `_row_hash`,
  `_lock`, `_export_mark`), profile and run repositories (`Api/`), profile validation listing every problem, run
  queue claimed with conditional updates, batched error, preview and delta-hash storage, stale-aware locks, and a
  5-field cron expression parser.
- Import pipeline: queued runs executed by a run processor with profile and entity locks; read → filter → map →
  only-add/only-update → delta → Magento validation and import; prepared rows spooled to the run folder (flat memory);
  errors (OpenImport's and Magento's) mapped to the rows of the source file with the original row kept for the
  error report; dry runs with a field-level preview of products and customers; safety threshold with confirmation
  for Delete and Replace; delta hashes saved only for rows Magento imported. See docs/runs.md.
- Stock & prices entity (`openimport_stock_price`): qty, stock status, price, special price and dates, cost of
  existing products through Magento's product import; unknown SKUs are skipped and counted, never created.
- CLI: `openimport:run` (dry run, file, full, confirm, queue only; exit code for scripts), `openimport:run:status`,
  `openimport:profile:list`. See docs/cli.md.
- Admin: profile and run grids under System › Data Transfer, the six-step profile form (upload with server-side
  sample, mapping editor with auto-match, transform chips, value maps and a live preview of the first rows, rules,
  schedule), run page with counts, change preview, typed confirmation, cancel, error report (original rows,
  formula-protected) and file download; English and German. See docs/admin.md.
- Schedules: own cron group `openimport` in a separate process; every minute due profiles are queued (store time
  zone, each due minute exactly once even with overlapping or late cron, up to 10 minutes of catch-up) and queued
  runs are processed within the time budget; hourly housekeeping removes old run files, unused uploads and schedule
  records and fails runs that died. See docs/schedules.md.
- Sources: server folder (sandboxed to var/openimport and allowed folders), web address (http/https, basic auth or
  bearer token, Google Sheets links read as CSV, SSRF guard on every redirect hop, 256 MB cap), SFTP (password or
  private key, phpseclib 3) and FTP/FTPS; file name patterns and newest/oldest/first/last choice; keep, move or
  delete the file after a successful run; "Test connection" in the form; secrets encrypted with Magento's key and
  never shown again. See docs/sources.md.
- E-mail notifications per profile (never, on failure, always) to the profile's addresses or the creating admin,
  with counts, message and a link to the run; template `openimport_notify_run_finished`.
- Exports: every Magento export entity through core's own export code, streamed through OpenImport's writers
  (CSV/TSV, Excel .xlsx without a library, JSON, XML) with row filters, column mapping (rename, reorder, transforms,
  fixed values), file name placeholders and the formula guard; dry runs write without delivering; download from the
  run page. `number` transform takes a decimal separator. See docs/exports.md.
- Order export (`openimport_order`): one row per order line or per order with totals, addresses, payment, shipments
  and tracking; filters by status, date and store; "only new" and "new or changed" through export marks. Export
  destinations: server folder, SFTP, FTP, e-mail attachment. Mapping editor in export mode (file column ← Magento
  column, "Add every column"); output options (BOM, line ending, JSON root/pretty, XML root/record).
- CLI: `openimport:profiles:export` / `openimport:profiles:import` (JSON without secrets, dry run, all or nothing),
  `openimport:doctor` (cron, indexers, PHP, folders, stuck runs).
- Two sample profiles on first install (schedules off), a first-run notice on the profiles page, and an admin
  system message while runs wait but cron has not run OpenImport's job.
- Uninstall class (`module:uninstall --remove-data` drops every table, setting, cron row and run folder), security
  documentation (docs/security.md), README for 1.0.
