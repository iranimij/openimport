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

**1.3.0 (2026-10-16):** catalog helpers, plan page 15 slices 1–8 (#44–#51): pictures from web addresses, configurables
from simple rows, products missing from the file, quantities as changes, URL keys and 301s, conditional transforms,
several files per run, presets. Comparison table updated.

**1.4.0 (2026-10-21):** connectors, plan page 15 slices 1–7 (#53, #54, #58, #59, #60, #62, #63): saved connections,
S3, Google Drive/Sheets, Dropbox, OneDrive/SharePoint, REST API, archives, ODS and XSLT. Lessons: the GitHub runners'
libxml2 2.12+ refuses core 2.4.7's `esconfig.xsd` (CI patches it); PHPUnit's TeamCity log names failed tests when a
fixture crashes before the summary; the OAuth round trip is E2E-testable with a stand-in consent page that redirects
straight back.

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
| 1.3 Catalog helpers | 1 images from URLs · 2 configurables from simple rows · 3 products missing from the file · 4 add to stock · 5 URL keys and 301s · 6 conditional transforms · 7 several files per run · 8 Shopify/WooCommerce/Magento 1 presets | **released 1.3.0** (#44–#51, release PR #52); E2E dev-env #9–#12 |
| 1.4 Connectors | 1 connections · 2 S3 · 3 Google Drive/Sheets · 4 Dropbox, OneDrive · 5 REST API source/destination · 6 archives · 7 ODS, XSLT | **released 1.4.0** (#53, #54, #58–#60, #62, #63; release PR); E2E dev-env #13–#16 |
| 1.5 Sales and marketing entities | 1 shipments and tracking · 2 order import · 3 URL rewrites · 4 price rules and coupons · 5 reviews, newsletter, search terms, category positions | **released 1.5.0** (#65, #67–#70, release PR); E2E dev-env #17 |
| 1.6 Automation and operations | 1 job chains · 2 triggers · 3 webhook/Slack · 4 reindex · 5 parallel workers · 6 undo · 7 close | not started |

Rule for every slice: one PR, pushed at once; this file updated after each slice (done, exact next step, open
questions, failing/skipped tests, CI link); unit ≥ 85 % on new namespaces, integration on all seven pairs, oracle or
round-trip tests for entities, security tests, E2E with axe/CSP; full matrix before merging schema, runner or sales
code.

## Exact next step

1.4 released (v1.4.0; module #53–#63; E2E dev-env #13–#16). Still open from 1.3: the local browser check on the Mac
(double-click `claude-upgrade.command` first), now also for the 1.4 pages (connections grid and form, Google/Dropbox/
Microsoft sign-in buttons, REST fields, archive and XSLT fields in step 3).

**1.5 · Sales and marketing entities** (plan page 15 §3) **released as 1.5.0 on 2026-10-21**: #65 shipments,
#67 orders, #68 URL rewrites, #69 price rules and coupons, #70 reviews/newsletter/search/positions; E2E dev-env
#17. Next: **1.6 · Automation and operations** (plan page 15 §3): 1 job chains, 2 triggers, 3 webhook/Slack,
4 reindex after a run, 5 parallel workers, 6 undo, 7 close. Each slice: README,
CHANGELOG, docs, en_US + de_DE, unit + integration + E2E. Rule since 2026-10-21: every feature slice updates README.md.

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

### 1.3 slice 7 · several files per run (what was built)

- `MultiFileSourceInterface` (names, fetchNamed) on FolderSource and RemoteSource (SFTP/FTP); `FileSelector::PICK_ALL`
  and `ordered()` (natural name order).
- `FileBatch`: a run of an "all" profile takes the first free file (`remote_file` option), queues one run per other
  file (`batch_of` = first run id; files of open runs are skipped); with `all_on_error = stop` a failed run cancels
  the batch's queued runs. Wired into RunProcessor (note in the run message); SourceResolver fetches `remote_file`.
  `RunResource::openRunOptions()`.
- Form: pick option "All matching files", field *When a file fails*.
- Tests: unit (selector order); integration in `SourcesTest` (3 files → 3 runs, failed file stays, others moved;
  stop cancels the rest and the next run starts at the first file again).
- Docs: `docs/sources.md`.

### 1.3 slice 8 · presets (what was built)

- `presets/*.json` (profile JSON + `preset` block): Shopify (current and classic columns), WooCommerce, Magento 1,
  written from public column lists. `Model/Preset/PresetRepository` (list, create with a unique name, inactive),
  controller `profile/fromPreset`, section on the "Import profiles from JSON" page (`ViewModel/Presets`).
- New transform `fill_down` (`RowTransforms`; group column, keeps only the latest group); in the mapping editor.
- Configurable step: a parent's own row without a price takes its first variant's (WooCommerce variable products).
- Runner: columns with ":" (step fields) never go to Magento.
- Tests: unit `PresetFilesTest` (each preset compiles against documented columns and maps sample rows), fill_down,
  runner; integration `PresetImportTest` (unique names, Shopify/WooCommerce/Magento 1 files imported).
- Docs `docs/presets.md`, `fill_down` in `docs/transforms.md`.

### 1.4 slice 1 · saved connections (what was built)

- Table `openimport_connection` (name unique, type, settings JSON with `secrets` encrypted by `Secrets`), repository
  (`ConnectionRepositoryInterface`: save with validation and unique name, getById, getByName, getAll, delete refused
  while `ConnectionUsage` finds profiles), kinds from di.xml (`ConnectionTypes`: sftp, ftp).
- A profile links a connection with `source_config.connection_id` or `export_options.connection_id` (no new profile
  column: form, duplicate and JSON keep working). `ConnectionConfig::resolve()` swaps the login keys (host, port,
  username, auth_sftp, ssl, passive, timeout, secrets) for the connection's at run time; `RemoteSettingsBuilder` builds
  `RemoteSettings` for sources, destinations and the tester.
- Admin: grid and form (System › Data Transfer › OpenImport Connections), Test connection (`ConnectionTester`, lists
  the login folder), ACL `Iranimij_OpenImport::connections`; profile form *Saved connection* selects (options only of
  the chosen type; login fields hide), step summary names the connection, save problems land at the select.
- Profile JSON: `"connection": "<name>"`, never id or login; unknown name stops the import.
- Fixes on the way: `secrets:reencrypt` also covers export destinations (+ connections), keeps going past a profile
  it cannot save; uninstall drops `openimport_image`.
- Tests: unit (ConnectionConfig, validator, form mapper, tester, settings builder, profile validator, profile JSON),
  integration `Test/Integration/Connection/ConnectionsTest.php` (fake remote client) and
  `Test/Integration/Controller/ConnectionAdminTest.php`; E2E `connections.spec.ts`. Docs: `docs/connections.md`.

### 1.4 slice 2 · Amazon S3 and S3-compatible storage (what was built)

- Connection kind `s3` (bucket, region, endpoint, path_style, access_key, secret `secret_key`); source type and
  export destination `s3` (virtual types `S3Source`, `S3Destination`, client `S3Client`); S3 needs a saved connection
  (`ConnectionTypes` `inline` = sftp, ftp; the "enter the login here" option is hidden for the others).
- `S3Signer`: Signature V4 (header auth, payload hash); unit tests = AWS's three published examples.
- `S3Client`: ListObjectsV2 with delimiter and continuation, folders as key prefixes (deeper keys left out), get to
  file, put from file (SHA-256 of the file signed), move = copy (x-amz-copy-source) + delete, errors from the
  `<Error>` code in plain words. Every request through `UrlGuard` with the checked address pinned (CURLOPT_RESOLVE).
- CI: `.github/ci/s3-server.sh` starts RustFS (MinIO images can no longer be pulled) and exports OPENIMPORT_TEST_S3;
  `Test/Integration/Connection/S3Test.php` runs against it. Lesson: Magento's integration framework sets the
  properties of a finished test to null, so typed test properties must be nullable.

### 1.4 slice 3 · Google Drive and Sheets (what was built)

- `Model/Http/ApiClient` (+`ApiResponse`): API requests through the URL guard, body from a file, answer to a file,
  own header lines (no `Expect: 100-continue`).
- `Model/Google/GoogleAuth`: service account (RS256 JWT, jwt-bearer grant), refresh token grant, code exchange with
  PKCE, consent URL; tokens cached in memory. `GoogleEndpoints` (di; tests point it to a mock).
- `Model/Google/DriveClient` (`RemoteClientInterface`): folders by link, id or path from My Drive (created for
  "processed"), Sheets listed as `<name>.xlsx` and exported as XLSX, other Google types left out, resumable uploads
  (an existing file keeps its id; *Save as a Google Sheet* converts), moves by parents, deletes to the bin.
- `Model/Google/OAuthFlow` + `Connection/OauthStart`, `Connection/OauthCallback` (public action without secret key,
  still ACL): state `<id>.<random>` hashed in the connection, verifier encrypted, 15 minutes; the refresh token is a
  secret; the account e-mail comes from `about`.
- Connection form: *Log in as* service account (key; account shown to share folders with) or Google account (client
  ID/secret, redirect URI, Sign in button). Profile: source/destination `google_drive`.
- Tests: unit (PKCE RFC 7636 vector, consent URL, key check, settings, validator, form mapper); integration with
  the mock Google server (`GoogleDriveTest`, `GoogleOAuthAdminTest`).

### 1.4 slice 4 · Dropbox, OneDrive and SharePoint (what was built)

- OAuth generalised: `Model/OAuth/OAuthFlow` (moved from Model/Google) takes providers by connection type
  (`OAuthProviderInterface`: signsIn, consentUrl, exchange, account); `GoogleOAuthProvider`, `DropboxOAuthProvider`,
  `MicrosoftOAuthProvider`; `OAuthTokens` (form posts, refresh with in-memory cache, PKCE challenge).
- `DropboxClient`: list_folder (+continue), download/upload (Dropbox-API-Arg, overwrite), move_v2 after
  create_folder_v2, delete_v2. `OneDriveClient`: Graph path addressing (`root:/a/b:`), the user's OneDrive or a
  SharePoint site's library (`/sites/{host}:/{path}`), download through Graph's redirect, upload sessions in 10 MB
  parts, moves by parent id (folder made when missing), deletes to the recycle bin. Scopes ask for
  Sites.ReadWrite.All only when a site is set.
- Connection form: client ID/secret, redirect URI and the sign-in button now serve Google (account login), Dropbox
  and Microsoft (`oauth-signin.js`); OneDrive adds tenant and site.
- Tests: unit (settings, validator); integration with `Test/Integration/_files/cloud-server.php` (Dropbox + Graph
  stand-in): sign-in through OAuthFlow, files, parts, redirect download, SharePoint site, a profile, a refused code.

### 1.4 slice 5 · REST API source and destination (what was built)

- Connection type `rest`: base URL plus a login (none, header token, basic, OAuth2 client credentials with a cached
  token). `RestClient`: absolute links must stay on the base's host, retries on 429/502/503/504 (Retry-After, at most
  30 s, 3 times), no redirects (ApiClient and the URL guard as everywhere).
- `RestSource`: records at a path in the answer, paging by page number, offset, cursor, next-URL field or Link
  header, a page limit (stops the run with a message, nothing imported); the records go to `rest-api.json` and run
  through the JSON reader. `RestDestination`: the export file in one request, or JSON batches of N records.
- Tests: unit (paging helpers); integration against `Test/Integration/_files/rest-server.php` (every paging kind,
  every login, retries with recorded waits, the page limit, file and batch exports).

### 1.4 slice 6 · archives (what was built)

- `Model/Archive/Unpacker`: ZIP (ZipArchive), gzip (zlib stream), tar and tar.gz (own ustar reader with GNU long
  names and pax paths); the entry by pattern or the first readable file; fixed target file name (no zip-slip),
  size counted while writing (1 GB), 10,000 entries, links skipped, nested and password-protected archives refused.
  `Packer` writes ZIP, gzip and tar.gz (own ustar header); `PackedWriter` gives destinations the archive's
  extension and content type. `Storage/TempFolder` for short-lived folders under `var/openimport/tmp`.
- Wired into `RunProcessor` (the run names the inner file, the message says "Read x from y."), `FileSample` (form
  previews and Test connection unpack too, `inner` in the sample), `ExportRunner` (export option `compress`),
  uploads accept `.zip .gz .tgz .tar`, validator checks, form fields in step 3 (`format_options.archive`,
  `archive_entry`, `export_options.compress`).
- Tests: unit with real archives (zip-slip, bomb, entries limit, password, damaged, gzip, tar long names, links,
  truncated tar, round trips through the Packer); integration `Execution/ArchivesTest` (ZIP and gzip from a folder
  through a live run, pattern, "read as it is", broken ZIP, packed export). Docs `docs/archives.md`.

### 1.4 slice 7 · ODS and XSLT (what was built)

- `Reader/OdsReader` (content.xml streamed with XMLReader: tables by name or position, repeated columns and rows
  expanded with empty runs dropped, typed values, paragraphs, annotations skipped, no DOCTYPE, 512 MB limit,
  mimetype checked) and `Writer/OdsWriter` (mimetype stored first, manifest, styles, bold frozen header, numbers
  as floats, text:s/text:tab). Format `ods` everywhere (factory, sniffing by the mimetype entry, profile formats,
  uploads, URL content type, sheet field, form sample sheets, strict columns).
- `Xslt/XsltStep`: XSLTProcessor with all security prefs on (no read/write file, no network), no PHP functions,
  no DOCTYPE, include/import refused, 64 MB file limit, `check()` at save; `transform()` before the reader
  (`RunProcessor::prepare`, `FileSample`) and `transformInPlace()` after the XML writer (`ExportRunner`). Form:
  `format_options.xslt`, `export_options.xslt` (textareas).
- Tests: unit (ODS reader with Calc-style content, ODS writer round trip, XSLT with eight hostile stylesheets),
  integration `Execution/OdsXsltTest` (ODS export → edit → ODS import, XSLT in and out through live runs, hostile
  stylesheet refused at save). Docs: file-formats (ODS, XSLT), security, sources, exports; README; CHANGELOG.

### 1.5 slice 1 · shipments and tracking (what was built)

- Entity `openimport_shipment` (import only, Add/Update or Only add): `Import/Shipment/ShipmentImporter` collects
  the rows per order (items by SKU with quantities or everything open, tracking numbers, notify, comment), checks
  every row (order, can ship, open item, quantity left, carrier from `Magento\Shipping\Model\Config`), skips and
  reports orders that already carry a tracking number or have nothing left, and ships through
  `ShipOrderInterface`. `ShipmentRecords` caches orders per run; `ShipmentRecordReader` treats every row as new.
  Registry: `EntityRegistry::SHIPMENT`, `ADD_ONLY` (validator), synonyms, entity source names and hints, field list,
  di importer and reader, `magento/module-shipping` required (core module, for the carrier list).
- Tests: integration `Import/ShipmentImportTest` against the Sales order fixture (partial shipment with tracking,
  file imported twice, the rest without SKU with two tracking rows, bad rows refused before anything ships,
  behaviours refused at save); unit validator rule. Docs `docs/shipments.md`, README, comparison, CHANGELOG.

### 1.5 slice 2 · order import (what was built)

- Entity `openimport_order` in the import direction (Add/Update or Only add, `ADD_ONLY`): `Import/Order/OrderFile`
  groups the rows of a file by `increment_id` (the layout of the order export: order columns on the first row,
  one item per row, `item_parent_sku` for children), checks every column and the totals (subtotal = Σ row totals,
  grand total = subtotal + shipping + tax − discount, missing ones filled). `OrderImporter` skips existing numbers
  (non-critical, run partial), looks up the store, builds `Magento\Sales\Model\Order` like the Sales fixtures
  (state/status, currency with rate 1, customer by e-mail on the website or guest, group, addresses, payment
  recorded, items with parents first), saves through `OrderRepository` in one transaction per order, adds the
  history comment, optionally invoices/ships/refunds through `InvoiceOrderInterface`, `ShipOrderInterface`,
  `RefundOrderInterface` and sets the file's state and totals again, optionally reduces stock via
  `StockRegistry`. Numbers: keep the file's or `SalesSequence` next value. `OrderRecords` does the lookups with
  plain selects. `ImportRequest::$options` carries the profile's import options (`ImportRunner`); profile form
  fields `order_number`, `order_documents`, `order_stock` (shown for the order entity). Requires
  `magento/module-sales-sequence` and `magento/module-catalog-inventory`.
- Tests: integration `Import/OrderImportTest` (round-trip oracle: export the `order_list` fixture per line →
  import with generated numbers → export → same rows; an order with documents, tracking and stock; the same file
  twice; dry-run checks; behaviours refused), unit `OrderFileTest` (grouping, totals, checks, columns, money).
  Docs `docs/orders-import.md`, exports cross-link, README, comparison, CHANGELOG.

### 1.5 slice 3 · URL rewrites (what was built)

- Entity `openimport_url_rewrite` (import Add/Update + Delete, export): `Import/UrlRewrite/UrlRewriteImporter`
  prepares every row (path checks, store view, redirect type incl. words, entity from the filled column
  product_sku/category_path/cms_page checked against entity_type, target from the file or the entity's generated
  URL (`systemPath`) for redirects / canonical path otherwise, self-target, duplicates in the file, conflicts with
  Magento's generated URLs of other entities), counts created/updated/deleted in the dry run, writes through the
  URL rewrite model + resource (`UrlRewriteRecords::save/delete`). `UrlRewriteRecordReader` (identifier
  "request_path|store_code"), `UrlRewriteEntitySource` (every rewrite, ids resolved to SKU/path/identifier).
  Registry identifier `['request_path']` (the store view is a plain column), NO_REPLACE, synonyms; requires `magento/module-url-rewrite`.
- Tests: unit `UrlRewriteImporterTest` with an in-memory `FakeRecords` and `ArraySource` (every kind, counts,
  conflicts and bad rows, delete, replace); integration `Import/UrlRewriteImportTest` (product_simple fixture:
  create, the storefront router answers 301/302/forward, update, export round trip, conflicts, delete, generated
  URL kept, replace refused). Docs `docs/url-rewrites.md`, README, comparison, CHANGELOG.

### 1.5 slice 4 · price rules and coupons (what was built)

- Entities `openimport_cart_rule` and `openimport_catalog_rule` (import Add/Update + Delete, export), one
  `Import/PriceRule/PriceRuleImporter` with a `kind` (di virtual types): rows matched by `rule_id` or name
  (several rules of one name reported), values from the columns (empty columns of an existing rule keep their
  values; new rules get defaults: active, all websites and groups), websites/groups by code or `all`, dates,
  action per kind, numbers, cart extras (coupon type/code/codes, limits, free shipping, labels per store view).
  `ConditionTree` parses Magento's condition arrays from JSON (short types expanded, every type must be an
  `AbstractCondition` subclass), builds trees from the simple columns (subtotal, SKUs, categories; Product\Found
  for cart conditions, bare Product for cart actions and catalog rules) and compacts saved trees for the export.
  `PriceRuleRecords`: lookups, rule models + resources, coupon codes/owners, `addCoupon` (generated type).
  Writes: `addData` + fresh condition/action combine loaded from the tree, resource save; extra coupon codes added
  unless the rule has them, codes of another rule refused. `PriceRuleEntitySource` (codes, labels, JSON),
  `PriceRuleRecordReader`. Requires `magento/module-sales-rule`, `module-catalog-rule`, `module-rule`.
- Tests: unit `ConditionTreeTest`; integration `Import/PriceRuleImportTest` (three cart rules from simple columns
  and JSON, coupon list, labels; the coupon applies in a quote; update by name keeps the rest; export round trip
  imports as updates without doubling coupons; bad rows; delete removes coupons; a catalog rule for one SKU,
  reindex, rule price 9 for guests; round trip; delete; replace refused). Docs `docs/price-rules.md`, README,
  comparison, CHANGELOG.

### 1.5 slice 5 · reviews, newsletter, search terms, synonyms, category positions (what was built)

- `Import/Small/SmallImporter` (abstract: check every row → dry-run counts → write/remove row by row; Replace
  refused; duplicates in the file by a key) with five importers: `ReviewImporter` (review + rating models,
  votes replaced, summary aggregated; identifier product_sku|nickname|title or review_id), `NewsletterImporter`
  (subscriber model, status set directly, customer linked, no e-mails), `SearchTermImporter` (search query
  model per store view, redirect cleared by an empty mapped cell), `SynonymImporter` (synonym group repository,
  merge by shared words and scope all/website:/store:), `CategoryPositionImporter` (category link repository
  save/deleteByIds). `SmallSourceInterface` (export + `find` + `count`) with one source per entity (plain
  selects, codes instead of ids) and one generic `SmallRecordReader` (di virtual types with the identifier
  columns). `SmallRecords` shared lookups. Registry, codes, names/hints, field list, di; requires
  `magento/module-review`, `module-newsletter`, `module-search`.
- Tests: integration `Import/SmallEntitiesImportTest` — each entity imported, exported with a filter, the
  export re-imported as unchanged, updates, bad rows, delete. Docs `docs/small-entities.md`, README,
  comparison, CHANGELOG.

## Open questions

- D3 (01 · Decisions First): own XLSX reader/writer (recommended) or openspout. Default until answered: own reader.
- Repository settings (squash only, auto-merge, delete branch on merge, `main` protected with `ci-ok`) cannot be
  written from cloud threads (proxy refuses settings writes): Iman sets them once, or the repo-factory workflow does.

## Failing or skipped tests

None.
