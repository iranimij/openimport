# Admin

OpenImport lives under **System › Data Transfer**: *OpenImport Profiles* and *OpenImport Runs*. Settings are under
**Stores › Configuration › iranimij › OpenImport**.

## Profiles

The profile list shows each profile's direction, what it imports or exports, its source, schedule and last run.
Above it, a status line says how the last 24 hours went (failed runs link to the runs), what is waiting, and whether
the cron runs. A click on a row opens the profile. Row actions: *Edit*, *Dry run*, *Run now* (the dialog names the
file it imports and how the last dry run went), *Duplicate* (the copy starts with its schedule off) and *Delete*
(runs and error reports are kept). Mass actions: switch schedules on or off, delete. **Import profiles from JSON**
takes a file from *Download as JSON* or `openimport:profile:export`.

The form has a step navigation at the top; each step header says what the step holds, and its **?** opens the
matching page of this documentation. Fields show only where they matter (an export has no import rules, a CSV no
sheet name, the cron field only with *Custom*), required fields are marked, and a save that fails opens the step with
the problem and shows the message at the field. Leaving the form with unsaved changes asks first. Below the steps,
**Recent runs** lists the last five runs of the profile. **Download as JSON** saves the profile without its secrets.

The profile form has six steps:

1. **What**: name, import or export, and what (Products, Stock & prices, Customers …).
2. **Where**: the source. For *Upload a file*, choose the file; it is read on the server, never in the browser.
3. **File**: format and its options (delimiter, encoding, sheet, record path). The first 20 rows of the file are
   shown and read again when an option changes.
4. **Map**: one row per Magento field (pick it from the list, or *Other field …* to type a code): the column of the
   file (or a fixed value), the store view, transforms (one without settings is added when chosen; move up and down,
   remove), *empty is an error*, and a value map. **Auto-match** proposes a mapping from the column
   names (and adds *Decimal comma → dot* where the file writes numbers with commas), **Add required fields** adds what
   Magento needs for new records with proposed fixed values, **Preview the first rows** shows what
   Magento will receive, row by row, with any problems.
5. **Rules**: behaviour (only those the entity can do), rows with errors, allowed errors, *skip unchanged rows*
   (delta), safety limit (empty = the configured default), row filters. Order exports have their filters here:
   statuses, created from/until (date pickers) and which orders.
6. **When**: manual or a schedule (every 15 or 30 minutes, every hour, every day at a chosen time, presets, or a cron
   expression) with the next three run times, schedule on/off, e-mails, what happens to the source file after a
   successful run.

**Save and dry run** saves and starts a dry run straight away. New profiles have their schedule off; switch it on
after a dry run you are happy with.

## Runs

Runs are queued and run by OpenImport's cron within a minute (or by `bin/magento openimport:run`). The run page
shows the status, the numbers (rows read, new, changed, unchanged, skipped, errors), and refreshes itself while the
run is queued or running.

- **Dry runs** list what would change: for each record the field, the value now and the value after the run.
  **Run for real** imports the very file the dry run checked.
- Runs with *Delete* or *Replace* above the safety limit need the word DELETE or REPLACE typed in, by a role allowed
  to confirm them (*Confirm runs above the safety limit*).
- **Download error report** gives a CSV with the row number, record, field and error, followed by the original row,
  so you can fix the rows and import them again. Cells that a spreadsheet would run as formulas are protected.
- **Download the file** gives the file the run read.
- **Cancel** stops a queued run at once, and a running one at its next checkpoint while it still reads the file
  (before it writes to the shop or sends a file). **Run again** / **Dry run again** start the profile again, also from
  the runs grid.
- An export's run page shows the first ten rows of the file it wrote.

The runs grid can be filtered by what and direction, and searched by profile name. A click on a row opens the run.

## Permissions

| Resource | Allows |
|---|---|
| View profiles | the profile list and form (read only) |
| Create and edit profiles | saving, duplicating and deleting profiles, uploading files |
| Run profiles | dry runs, live runs, cancelling runs |
| Confirm runs above the safety limit | the typed confirmation for Delete and Replace |
| View runs and error reports | the run list, run pages and downloads |
