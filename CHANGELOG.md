# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and versions follow [Semantic Versioning](https://semver.org/).

## [Unreleased]

## [1.5.2] - 2026-10-22

Fixes from the fourth hands-on review (Notion "17 · Browser review 4").

### Fixed
- Profiles and Runs grids: the keyword search finds profiles (and runs) by name. It listed every record: the
  search filter never reached Magento's grid data provider.
- Product pictures: `base_image`, `small_image`, `thumbnail_image`, `swatch_image`, `additional_images`, their
  labels and `hide_from_product_page` are in the field list of step 4, and Auto-match finds them (also from *Bild*,
  *Foto*, *Picture*). Magento 2.4.9's own sample file, which the list was built from, has no picture columns.
- Dry run: when rows with errors are left out, the headline says so ("Magento accepts the other rows; 3 row(s) have
  errors and will be left out") instead of "Magento accepts the rows". Run summaries count rows with errors, not
  error messages.
- Exported files import back unchanged: the apostrophe the formula guard puts in front of `=`, `+`, `-`, `@` is
  taken off on import (phone numbers such as `+49 69 …` kept getting an apostrophe).
- CSV files with CR line breaks (old Excel for Mac, some ERPs) are read line by line; they were one header row.
- A CSV row with filled cells beyond the last column is a row error (and a warning in the first rows of step 3):
  the extra cells used to disappear silently, although they mean shifted values.
- A CSV line longer than 8 MB stops the file with a plain message instead of exhausting PHP's memory.
- An empty file says "The file is empty" in step 3 and in the run, not "The profile has no mapping yet".
- Quantities with decimals are refused for products sold in whole pieces (*Qty Uses Decimals* off), for changes
  (`+1.5`) and plain quantities.
- Orders import: unknown country codes in billing and shipping addresses are reported.
- Picture cells with `file://`, `ftp://`, drives, network paths or `../` are refused with a row error instead of
  Magento's "Wrong URL/path used for attribute image"; Magento's "Value for 'name' attribute contains incorrect
  value" names the column in plain words.
- "Products missing from the file" changes nothing when the file has no rows (a broken feed).
- Import profiles from JSON: a broken file says it is not valid JSON.
- REST source: without a saved connection the field says where to add one, Test connection asks for one, and the
  path shows a REST example.
- "Run again" on an export says it exports again instead of "changes the shop"; the cron status says "just now".

### Changed
- Profiles imported from a JSON file never switch automatic runs on: new profiles start off, updated ones keep
  their state.
- New permission *Download run files* (the imported file and the export of a run, which can hold customer data).
  Roles that could view runs get it on upgrade.
- Step 3 lists the sheets of an Excel or OpenDocument file with several sheets.

## [1.5.1] - 2026-10-22

Fixes from the third hands-on browser review of 1.5.0 (Notion "16 · Browser review 3").

### Fixed
- Reviews: a new review without `store_codes`, and `all`, is saved for every store view. It used to be saved for
  store 0 only, which Magento shows nowhere: the review and its stars stayed invisible in the shop and in the
  admin form. The export writes `all` for a review shown in every store view and leaves `store_codes` empty for
  one shown in none, so importing `all` for such a review repairs it.
- Reviews: a rating that is switched off or not shown in the review's store views (Stores › Rating › Rating
  Visibility) is still imported, with a note on the row that its stars will not appear.
- Orders: an order without shipping columns gets the billing address as its shipping address, as documented.
  Without it Magento's admin order view failed with "An error has happened during application run".
- Profile form: the "Order numbers" option (keep the file's numbers or generate new ones) was missing for order
  imports. A select without a default value no longer disappears from the form, and every field of a step has its
  own position (fields sharing one could swap places).
- Dry run: a record spread over several lines (an order with its items) counts once, and records the importer
  refuses (an invalid e-mail, an unknown product) are listed under the errors only, no longer also as "New".
- Profiles grid: the "Source / destination" filter also finds exports by destination ("Export to: E-mail"…).
- Run page: a queued run no longer shows a bare "0" after the waiting notice.
- Run messages for OpenImport's own importers say "The rows are valid" / "The rows have errors" instead of
  naming Magento's import, which did not check them.
- SFTP: a failed connection names the server once and says why in plain words ("the server name is unknown")
  instead of PHP's network messages.

### Changed
- Profile form: after the first file is read, its columns are matched automatically when nothing is mapped yet.
- Auto-match knows `Beliebtheit`, `Häufigkeit`, `uses` and `searches` (popularity) and `Ergebnisse` / `results`
  for search terms.

## [1.5.0] - 2026-10-21

Sales and marketing entities: orders, shipments, URL rewrites, price rules and coupons, reviews, newsletter,
search terms and synonyms, category positions — import and export through Magento's own models and services
(plan 1.3–1.6, release 1.5).

### Added
- Shipments and tracking numbers (`openimport_shipment`): a file ships orders by order number through Magento's
  own shipment service (all open items or one SKU with a quantity, carrier, tracking number, customer e-mail,
  comment); rows of one order make one shipment; orders already carrying the tracking number are skipped and
  reported. See [Shipments](https://iranimij.github.io/openimport/shipments/).
- Order import (`openimport_order`, import direction): historic orders land as they are, one row per item in the
  layout of the order export (state and status, date, totals, addresses, items with parents and product options,
  payment method recorded). Options: keep the file's order numbers or generate new ones, create invoices, shipments
  (with tracking numbers) and credit memos from the item quantities through Magento's own services, reduce stock.
  Orders are only added; existing numbers are skipped and reported; one transaction per order; the dry run checks
  totals and every column. See [Order import](https://iranimij.github.io/openimport/orders-import/).
- Import profiles carry import options (`ImportRequest::$options`) for the own importers.
- URL rewrites (`openimport_url_rewrite`, import and export): custom rewrites and redirects (301, 302, plain) by
  request path and store view, or the URL of a product (SKU), category (path) or CMS page (identifier); Add/Update,
  Delete; Magento's generated URLs are never changed or deleted and a request path that is one is reported as a
  conflict naming the owner. The export writes every rewrite with SKUs, category paths and page identifiers
  instead of ids. See [URL rewrites](https://iranimij.github.io/openimport/url-rewrites/).
- Cart price rules with coupons (`openimport_cart_rule`) and catalog price rules (`openimport_catalog_rule`),
  import and export, matched by name (or `rule_id`): discount, websites and customer groups by code, dates,
  priority, coupon code or a list of codes, usage limits, free shipping, store labels; conditions from simple
  columns (subtotal, SKUs, categories, the items the discount applies to) or Magento's condition tree as JSON
  with short type names; every type checked. Add/Update, Delete. See
  [Price rules](https://iranimij.github.io/openimport/price-rules/).
- Product reviews with ratings (`openimport_review`), newsletter subscribers (`openimport_newsletter`, no e-mail
  sent), search terms (`openimport_search_term`), search synonyms (`openimport_search_synonym`, groups merged as
  the admin does) and product positions in categories (`openimport_category_position`): import (Add/Update,
  Delete) and export, each a round trip. See
  [Reviews, newsletter, search, positions](https://iranimij.github.io/openimport/small-entities/).

## [1.4.0] - 2026-10-21

Connectors: every file source and destination of the paid import modules (plan 1.3–1.6, release 1.4).

### Added
- Saved connections (System › Data Transfer › OpenImport Connections): SFTP and FTP logins with Test connection, shared
  by profiles as source or export destination; secrets encrypted and never shown again; a connection in use cannot be
  deleted; profile files name the connection instead of carrying a login. New ACL resource *Manage connections*. See
  [Saved connections](https://iranimij.github.io/openimport/connections/).
- Amazon S3 and S3-compatible storage (Cloudflare R2, Wasabi, MinIO, Garage, ...) as import source and export
  destination, through a saved S3 connection: own Signature Version 4 client (no SDK), folders as key prefixes, all
  matching files, move after success; every request through the URL guard. Tested against AWS's published signature
  examples and an S3-compatible server in CI.
- Google Drive and Google Sheets with a login, as import source and export destination: a service account (JSON key,
  signed JWT) or a Google account (*Sign in with Google*: your own OAuth client, state and PKCE, refresh token stored
  encrypted). Folders by link, id or path, Sheets read as Excel files, exports replace a file of the same name or
  become a Google Sheet, moves into `processed`, deletes go to the bin.
- Dropbox and OneDrive / SharePoint as import source and export destination, through *Sign in with Dropbox* /
  *Sign in with Microsoft* with the shop's own app (state, PKCE, refresh token stored encrypted). SharePoint document
  libraries by site address; uploads in 10 MB parts on OneDrive.
- REST APIs as import source and export destination: saved REST connection (header token, Basic, OAuth 2 client
  credentials), paging by page numbers, offsets, cursors, next addresses or the Link header, records path, a page
  limit that stops instead of importing a partial list, retries after 429/503 with Retry-After; exports send the
  file or JSON batches.
- Archives: a `.zip`, `.gz`, `.tar` or `.tar.gz` file from any source is unpacked and the file inside is read
  (*File inside the archive*: a name or pattern, else the first CSV, Excel, ODS, JSON or XML file); exports can be
  packed as ZIP, gzip or tar.gz (*Compress*). Zip-slip-safe (fixed file name), size counted while unpacking (1 GB),
  nested and password-protected archives refused. See [Archives](https://iranimij.github.io/openimport/archives/).
- OpenDocument spreadsheets (`.ods`, LibreOffice Calc) as import format (typed values, repeated cells and rows,
  sheet by name or position, streamed) and export format (own writer, bold header row). Detected by the file's
  `mimetype` entry when the name says nothing.
- XSLT: *XSLT before reading (XML)* turns a supplier's XML into records before the reader sees it, *XSLT after
  writing (XML)* reshapes an XML export before delivery. libxslt runs with no file, network or PHP access
  (`document()`, `xsl:include`, `xsl:import` refused), no DOCTYPE, a 64 MB file limit; stylesheets are checked at
  save. See [File formats](https://iranimij.github.io/openimport/file-formats/#xslt-since-14).

### Fixed
- `openimport:secrets:reencrypt` now also encrypts the secrets of export destinations again, and goes on when one
  profile cannot be saved.
- Uninstalling removes the `openimport_image` table too.

## [1.3.0] - 2026-10-16

Catalog helpers: every product helper of the paid import modules (plan 1.3–1.6, release 1.3).

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
- Quantities as changes: *Quantities in the file → are changes (+5, -3)* for products, Stock & prices and stock per
  source (MSI); current quantities are read per batch, repeated SKUs add up, the dry run shows the result. See
  [Quantities as changes](https://iranimij.github.io/openimport/stock-changes/).
- URL keys from a pattern (`{name}-{sku}`) for rows without a URL key, with duplicate keys reported with the other
  SKU, and *Redirect old URLs (301)* for changed URL keys. See
  [URL keys and redirects](https://iranimij.github.io/openimport/url-keys/).
- Conditional transforms: every transform can have *Only when* (an expression such as `{Brand} = 'Acme'` or
  `value > 20`); other rows keep their value.
- Several files per run: *Which file → All matching files, one run each (by name)* for folder, SFTP and FTP
  sources, each file with its own run, report and move; *When a file fails*: go on or stop.
- Presets: *Or start from a preset* creates a product profile for Shopify (current and classic columns),
  WooCommerce and Magento 1 product files. New transform `fill_down` (*Fill from the row above*). See
  [Presets](https://iranimij.github.io/openimport/presets/).
- Row steps (`RowStepInterface`, `AbstractRowStep`): a seam between mapping and Magento's import with `observe`,
  `columns`, `observeIdentifiers`, `apply` and `finish` hooks, and `PostImportStepInterface` for changes after the
  import; pictures, configurable products and missing products are built on it.

### Changed
- Step fields such as `configurable:parent_sku` are never sent to Magento, also when their step is off.
- The dry-run preview compares what Magento receives (after pictures, variants and quantity changes), not the raw
  mapped values.
- Live imports lock Magento's entity, so products and Stock & prices runs no longer run at the same time.
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
