# Runs, dry runs and safety

A run reads one file through a profile. Runs are queued (admin buttons, schedules, the CLI) and executed by
OpenImport's own cron group or by `bin/magento openimport:run`; never inside a web request.

## What happens in a run

1. **Read** the file (see [File formats](file-formats.md)).
2. **Row filters** skip rows (counted as *filtered*).
3. **Mapping and transforms** build Magento's import rows; a row that cannot be mapped becomes an error with its row
   number in your file.
4. **Behaviour**: *Only add new* skips records that exist, *Only update existing* skips records that do not (counted
   as *skipped*).
5. **Delta** (when switched on): a record whose prepared rows are exactly what the last successful run of this
   profile sent is skipped (counted as *unchanged*). Changing the profile's source, format, filters, behaviour,
   mapping or value maps forgets the stored state, and a run with *full* ignores it. Each line of the file is
   compared on its own, so a file with one line per store view (or several addresses or tier prices per record)
   only sends the lines that changed.
6. **Magento validates and imports** the rows with its own import engine. Its errors are reported against the rows
   of your file, not against Magento's internal row numbers.

Prepared rows are written to the run folder (`var/openimport/runs/<run id>/`), so memory use does not grow with the
file.

## Dry runs

A dry run does everything except the import: Magento validates every row, and OpenImport compares the prepared rows
with what the shop has now. The run page shows the totals (new, changed, unchanged, deleted) and a sample of up to 200
records per kind with *before → after* for every changed field. Comparison follows Magento's rules: empty cells
change nothing, numbers compare as numbers (12.50 = 12.5), Yes/No equal 1/0. Products and customers are compared field
by field; categories, images and product relations are not compared.

## Errors

- **Stop on error** (default): if any row has an error, nothing is imported.
- **Skip rows with errors**: valid rows are imported as long as the number of errors stays within *allowed errors*.

Every error names the row in your file, the record's identifier, the field and what is wrong. The first 10,000 errors
of a run are stored (setting *Errors kept per run*); the count includes all of them.

## Safety threshold

Runs with the *Delete* or *Replace* behaviour that would affect more than the safety threshold (default 10 % of the
records of that kind in the shop) stop with the status **Needs confirmation** and do nothing. Confirming the run
(typing DELETE or REPLACE in the admin, or `--confirm` on the CLI) runs it.

## Statuses

| Status | Meaning |
|---|---|
| Queued | Waiting for the next cron minute (or the CLI). |
| Running | Being processed. |
| Success | Everything went through. |
| Partial | Valid rows were imported, some rows had errors. |
| Failed | Nothing was imported; the message says why. |
| Needs confirmation | Above the safety threshold; nothing was done. |
| Cancelled | Stopped by an admin: before it started, or while it still read its file (nothing was written or sent). |

**Cancel** stops a queued run at once and a running one within seconds while it still reads its file. Once a live
run writes to Magento (or delivers its export) it cannot stop half-way: the run page says "Writing to Magento",
Cancel is no longer offered, and a cancel that came too late is named in the finished run's message. An export that
is stopped or fails while Magento reads its records delivers nothing (since 1.5.4).

Only one run of a profile runs at a time, and only one live import per kind of record (products, customers …), so
two imports never fight over the same indexers.
