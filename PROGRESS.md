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

## Current milestone

**M4 · Release** on `feat/m4-release` (stacked on M3 S6): uninstall class, `docs/security.md`, README and docs
index for 1.0, changelog 1.0.0. Still to do: merge the chain, tag v1.0.0-rc1 and v1.0.0 (`gh release create`),
Packagist, Notion §10 and the M3/M4 report.

Dev-env: `feat/m3-playwright` carries the M3 scenarios (URL and SFTP sources, schedule, export to XLSX and SFTP,
German locale); 12 of 13 green at the last run, the grid-status scenario found the `?? []` column bug fixed in #15.

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

Watch #15 (`feat/m3-s3-notify`) and the dev-env E2E run; merge and retarget up the chain; then open the M4 PR.

## Open questions

- D3 (01 · Decisions First): own XLSX reader/writer (recommended) or openspout. Default until answered: own reader.
- Repository settings (squash only, auto-merge, delete branch on merge, `main` protected with `ci-ok`) cannot be
  written from cloud threads (proxy refuses settings writes): Iman sets them once, or the repo-factory workflow does.

## Failing or skipped tests

None.
