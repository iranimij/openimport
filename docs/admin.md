# Admin

OpenImport lives under **System › Data Transfer**: *OpenImport Profiles* and *OpenImport Runs*. Settings are under
**Stores › Configuration › iranimij › OpenImport**.

## Profiles

The profile list shows each profile's direction, what it imports or exports, its source, schedule and last run.
Row actions: *Edit*, *Dry run*, *Run now*, *Duplicate* (the copy starts with its schedule off) and *Delete* (runs
and error reports are kept).

The profile form has six steps:

1. **What**: name, import or export, and what (Products, Stock & prices, Customers …).
2. **Where**: the source. For *Upload a file*, choose the file; it is read on the server, never in the browser.
3. **File**: format and its options (delimiter, encoding, sheet, record path). The first 20 rows of the file are
   shown and read again when an option changes.
4. **Map**: one row per Magento field: the column of the file (or a fixed value), the store view, transforms
   (add, move up and down, remove), *required*, and a value map. **Auto-match** proposes a mapping from the column
   names, **Add required fields** adds what Magento needs for new records, **Preview the first rows** shows what
   Magento will receive, row by row, with any problems.
5. **Rules**: behaviour, rows with errors, allowed errors, *skip unchanged rows* (delta), safety limit, row filters.
6. **When**: manual or a schedule (presets or a cron expression), schedule on/off, e-mails, what happens to the source
   file after a successful run.

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
- A queued run can be cancelled.

## Permissions

| Resource | Allows |
|---|---|
| View profiles | the profile list and form (read only) |
| Create and edit profiles | saving, duplicating and deleting profiles, uploading files |
| Run profiles | dry runs, live runs, cancelling queued runs |
| Confirm runs above the safety limit | the typed confirmation for Delete and Replace |
| View runs and error reports | the run list, run pages and downloads |
