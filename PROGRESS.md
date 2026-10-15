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

## Exact next step

Nothing in flight. Next features from Notion "12 · Roadmap 1.x" when Iman picks them. Known flake: the first E2E
test's Auto-match sometimes needs Playwright's retry (more time given in dev-env #8).

## Open questions

- D3 (01 · Decisions First): own XLSX reader/writer (recommended) or openspout. Default until answered: own reader.
- Repository settings (squash only, auto-merge, delete branch on merge, `main` protected with `ci-ok`) cannot be
  written from cloud threads (proxy refuses settings writes): Iman sets them once, or the repo-factory workflow does.

## Failing or skipped tests

None.
