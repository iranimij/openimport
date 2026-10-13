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

## Current milestone

**M1 · Engine** (started 2026-10-13).

| # | Slice | State |
|---|---|---|
| S1 | Readers: CSV/TSV, XLSX (own), JSON, XML, format detection, `docs/file-formats.md`; 50k baseline | PR #4 |
| S2 | Transforms and the safe expression language, value maps, number parsing, `docs/transforms.md` | PR #5 (stacked on #4) |
| S3 | Mapping compiler, store-view rows, auto-match, row filters, entity registry, `docs/mapping.md` (only add / only update move to S5: they need the database) | PR #6 (stacked on #5) |
| S4 | Schema (9 tables + whitelist), `Api/` profile and run repositories, validator, storage services (errors, preview, hashes, locks), cron expressions | PR #7 (stacked on #6) |
| S5 | Run queue and processor, import pipeline, record readers (products, customers), preview, delta, safety threshold, `docs/runs.md` | PR open: `feat/m1-s5-pipeline` |
| S6 | `openimport_stock_price` entity through core product import (existing products only) | PR open: `feat/m1-s6-stock-price` |
| S7 | CLI: `openimport:run`, `openimport:profile:list`, `openimport:run:status`, `docs/cli.md` | PR open: `feat/m1-s7-cli` |
| S8 | Runner unit tests with fakes (coverage gate now includes Model/Pipeline), pipeline benchmark (one process per pass: 10k vs 50k memory, delta 1 %), close | in progress: `feat/m1-s8-close` |

### M2 · Admin (in progress) and M3 · Automation (started)

| Slice | State |
|---|---|
| M2 S1 admin grids, form, mapping editor, run page; `openimport:queue:run` | PR #12 (stacked on #11) |
| M3 S1 cron group, scheduler (exactly once), housekeeping, `docs/schedules.md` | PR open: `feat/m3-s1-cron` |

## Exact next step

Merge S1, S2, S3 in order when CI is green; S5 (pipeline runner) on `feat/m1-s5-pipeline`.

## Open questions

- D3 (01 · Decisions First): own XLSX reader/writer (recommended) or openspout. Default until answered: own reader.
- Repository settings (squash only, auto-merge, delete branch on merge, `main` protected with `ci-ok`) cannot be
  written from cloud threads (proxy refuses settings writes): Iman sets them once, or the repo-factory workflow does.

## Failing or skipped tests

None.
