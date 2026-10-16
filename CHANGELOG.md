# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and versions follow [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added
- Product pictures from web addresses: OpenImport downloads them itself (SSRF check on every redirect, JPG/PNG/GIF
  checked by their first bytes, size and time limits), keeps them in `var/import/images/openimport` and asks the
  server with ETag/Last-Modified before downloading again. Profile option *Pictures that cannot be read*: report the
  row or import it without the picture. Settings under *Pictures from web addresses*; housekeeping removes pictures
  no run used for 30 days. See [Product pictures](https://iranimij.github.io/openimport/images/).
- Configurable products from simple rows: map a column to *Parent SKU (configurable products)*, choose the variation
  attributes; OpenImport builds the parent (name from the variants or a mapped column, values from the first
  variant), hides the variants, gives new variants unique URL keys and lists every variant in the file even with
  *Skip unchanged rows*. A parent's own row (product type `configurable`, as WooCommerce exports) is used. See
  [Configurable products](https://iranimij.github.io/openimport/configurable/).
- Products missing from the file: a product profile can treat its file as the complete list and disable, set out of
  stock or delete the products of a scope (all, a website, an attribute value such as a supplier) that the file does
  not name, after the import, in the dry run, under the safety limit with a typed confirmation (*DISABLE*, *OUT OF
  STOCK*, *DELETE*); a run with row errors changes nothing. See
  [Products missing from the file](https://iranimij.github.io/openimport/missing-products/).
- Row steps (`RowStepInterface`, `AbstractRowStep`): a seam between mapping and Magento's import with `observe`,
  `columns`, `observeIdentifiers`, `apply` and `finish` hooks, and `PostImportStepInterface` for changes after the
  import; pictures, configurable products and missing products are built on it.

### Changed
- Product pictures given as file names are read from `var/import/images` (as in Magento's own import), not from
  `pub/media/import`.

## [1.2.0] - 2026-10-15

The admin, reworked from the second hands-on review (design items D1–D15, product items P1–P10).

### Added
- Profile page: step navigation, a one-line summary in each step header, a **?** per step that opens the docs,
  **Recent runs** (the last five), **Download as JSON**.
- Profiles grid: a status line (runs of the last 24 hours, waiting runs, cron health), mass actions (switch schedules
  on or off, delete), **Import profiles from JSON**, a click on a row opens the profile.
- Runs: **Cancel** also stops a running run while it still reads the file; **Run again** / **Dry run again** on the
  run page and in the runs grid; the runs grid has *What* and *Direction* columns and filters, shows *Exported* for
  exports and no import counts there; a click on a row opens the run.
- Schedules every 15 or 30 minutes and every day at a chosen time; the form shows the next three run times.
- An export's run page shows the first ten rows of the file it wrote.
- Setting *E-mail sender* for run e-mails and exports by e-mail.

### Changed
- Run page: a status chip next to a one-line headline; *What would change* has one row per record with the changed
  fields inline (`field: before → after`), more behind a toggle; tables scroll sideways only.
- One primary action per page (*Download the export* is secondary while *Run for real* is offered).
- Mapping: the field-code box only shows for *Other field …*; *+ Value map* link; column *Empty is an error*;
  transforms without settings are added when chosen; chips stay on one line with their buttons on hover or focus.
- Yes/No settings are switches; port, errors allowed and safety limit are narrow fields with units; the upload is a
  drop zone that shows the current file with *Replace the file*.
- Order exports: statuses from a list, dates with a date picker, the order filters in step 5.
- Grid dates do not wrap.

### Fixed
- *Auto-match* right after an upload could use an older answer without the file's columns and match nothing.

## [1.1.2] - 2026-10-15

Fixes from the second hands-on review (QA items Q1–Q21 of the review page).

### Fixed
- Profiles grid: *Dry run* and *Duplicate* work again (they opened a 404 page); the *What*, *Source / destination*
  and *Started by* filters list their choices; the keyword search finds profiles by name, runs by profile name.
- Deleting categories counts the subcategories that go along, in the dry run and in the safety limit.
- Export profiles no longer show import settings: no *Detect* choices, sheet, record path or first rows in step 3,
  no behaviour, error rules, *skip unchanged* or safety limit in step 5; row filters say *Export only the records
  that match*.
- Fields show only where they matter: delimiter and encoding for CSV, sheet for Excel, record path for JSON/XML,
  the cron field with *Custom*, the schedule switch with a schedule, the sheet gid for
  Google Sheets links, the password unless SFTP logs in with a key, the file handling for folder/SFTP/FTP sources.
- *Behaviour* lists only what the chosen entity can do; *Replace* for categories, attributes and CMS is refused on
  save instead of at run time.
- Private keys go in a multi-line field (hidden while not focused); keys that lost their line breaks are repaired
  before the login.
- A failed save opens the step with the problem and shows the message at the field; a wrong cron expression names
  every wrong part at once.
- The form asks before leaving with unsaved changes.
- *Run for real* with a typed confirmation stays disabled until the word is typed; lower case is accepted.
- *Run now* says which file is imported (or where the export goes) and how the last dry run went; dialog buttons say
  what they do (*Run now*, *Delete profile*).
- Required source and destination fields are marked; the server folder is kept apart from the SFTP/FTP folder when
  switching the source type; help texts per source type.
- Long error messages wrap on the run page; a failed connection test shows as an error.
- Order date filters use the store time zone, like the schedules; a date alone as *until* means the whole day.
- The safety limit field shows the configured default.
- Five texts were missing in German; CI now checks that every phrase has an English and a German line.

### Changed
- A schedule needs a source that changes by itself: uploads cannot be scheduled, and scheduled exports need a
  destination other than *Download*. The sample profiles read from and write to server folders.
- CSV exports can leave out the header row and write ISO-8859-1/-15.

## [1.1.1] - 2026-10-15

Fixes and small improvements from a hands-on review of 1.1.0 in the admin.

### Added
- Auto-match adds *Decimal comma → dot* to number fields whose first rows use decimal commas, and understands
  `store:<code>:<field>` column names.
- *Add required fields* proposes fixed values (default attribute set and website, `simple`, default customer group,
  default source) and says what it added.
- The note under *What* explains the chosen entity; entities are listed in a sensible order.
- Records skipped by the behaviour (for example SKUs that are not in the shop) are named in the run message.

### Fixed
- New products without type or attribute set get a plain message with the column instead of core's "Product Type is
  invalid or not supported", once per row.
- Dry-run totals leave out records Magento refuses, and count the parent categories a category import creates; new
  categories are no longer also counted as updated.
- The profiles grid shows where an export goes (*Source / destination*); the runs grid and the run page show how and
  by whom a run started.
- Mapping step: the "choose what to import" note no longer stays visible, the editor lines up with the other steps,
  transforms show their names, wide first-rows tables show a scrollbar; the detected file format is no longer shown
  as a warning; store-view rows in the preview no longer repeat attribute set and product type.

## [1.1.0] - 2026-10-15

Categories, product attributes and attribute sets, CMS pages and blocks, a REST trigger, and order exports right after every order.

### Added
- Categories import and export (`openimport_category`): paths by names, parents created on the way, store-view
  values, dry-run preview, delete with children; own importers plug into the pipeline through `ImporterInterface`
  and `ImporterPool` (core's import engine stays the default). See docs/categories.md.
- Product attributes and attribute sets import and export (`openimport_attribute`): create attributes with input
  type, options, flags and scope, assign to sets and groups, store-view labels and option labels; Delete only
  unassigns. See docs/attributes.md.
- CMS pages and blocks import and export (`openimport_cms_page`, `openimport_cms_block`) by identifier and store
  views, dry-run preview, delete. See docs/cms.md. New dependency: `magento/module-cms` (core).
- REST API: `POST /V1/openimport/profiles/:profileId/runs` starts a profile (dry run or live),
  `GET /V1/openimport/runs/:runId` reports on it (`RunManagementInterface`). Order exports can run after every
  placed order (debounced: one waiting run per profile). See docs/api.md.

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
