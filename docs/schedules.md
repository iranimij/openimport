# Schedules and cron

Magento Open Source has no scheduled import or export (that is Adobe Commerce only). OpenImport brings its own,
run by Magento's normal cron.

## What you need

Magento's cron must run every minute, as for any store:

```
* * * * * php /var/www/html/bin/magento cron:run
```

OpenImport adds its own cron group, `openimport`, which runs in a separate process. A long import never holds up
Magento's default jobs (indexers, e-mails), and they never hold up an import.

| Job | When | What it does |
|---|---|---|
| `openimport_tick` | every minute | Queues the runs of profiles whose schedule is due, then runs queued runs until the minute's time budget is used. |
| `openimport_cleanup` | hourly (minute 17) | Removes old run folders, unused uploads and old schedule records; marks runs that stopped without finishing as failed. |

Without cron, queued runs wait. `bin/magento openimport:queue:run` does the same work as the tick by hand
(see [Command line](cli.md)).

## Profile schedules

A profile's schedule is a preset (every 15 or 30 minutes, every hour, every day at a time you choose, every night
at 02:00, weekdays at 06:00) or a 5-field cron expression, and the form shows the next three run times: minute, hour, day of month, month, day of week. Lists (`1,15`), ranges (`8-18`), steps (`*/15`) and
names (`mon-fri`, `jan`) work. Times are read in the store's time zone (Stores › Configuration › General ›
Locale Options › Timezone), so `0 2 * * *` means 02:00 local time, also across daylight-saving changes.

A schedule only fires while the profile's **Automatic runs are on** switch is on (called *Schedule is on* before 1.6;
it also governs [triggers](#triggers)). New and duplicated profiles start with it off: run a dry run first, then
switch it on.

A schedule needs a source that changes by itself: a profile that reads an **uploaded file** cannot be switched on
(it would import the same file again and again; use a server folder, web address, SFTP or FTP), and a scheduled
export needs a destination other than *Download* (a server folder, SFTP, FTP or e-mail).

Scheduled runs are live runs. The safety threshold still applies: a scheduled Delete or Replace run that would
remove more than the threshold stops with "needs confirmation" and waits for someone to confirm it on the run page.

## Exactly once

Each due minute fires once, even when:

- two cron processes overlap (a slow `cron:run` and the next one): the first process records
  `schedule:<profile>:<minute>` in `openimport_lock`; the second finds the record and does nothing;
- cron comes late: a due minute missed by up to 10 minutes still fires, once. Missed by more, it is skipped, and the
  next due minute fires as usual (no flood of catch-up runs after downtime);
- a run of the same profile is still going: the new run waits in the queue until the profile lock is free.

Two runs of one profile never run at the same time, and two live imports of the same entity never run at the same
time (products and Stock & prices share the product entity).

## Triggers

Since 1.6. Step 6 of a profile, **Also run when**: shop events that queue a live run of the profile, besides (or
instead of) its schedule. Cron runs it within a minute.

| Event | Raised by |
|---|---|
| A product is saved | a product saved in the admin, by the REST API, by *Update attributes* on several products, or by Magento's own import |
| Stock changes | a stock item saved (admin, API, an order taking stock) |
| A customer registers | a new account made in the storefront |
| A customer is saved | a customer saved in the admin, the storefront account or the API |
| An order is placed | storefront, admin or API checkout |
| An order changes status | an order saved with another status than it had; pick the statuses under *Only for these order statuses* (empty: any status) |

- Triggers fire only while **Automatic runs are on** is on, like the schedule.
- One run, not a flood: when a run of the profile is already waiting in the queue, a new event adds nothing, and
  the same process queues a profile at most once every 30 seconds. Saving 500 products queues one export.
- What OpenImport writes itself starts nothing: a product import does not trigger the product export it may feed,
  so a profile can never trigger itself in a loop. Use a [job chain](#job-chains) to run one profile after another.
- The rules of a schedule apply: a triggered import cannot read uploaded files, and a triggered export needs a
  destination other than *Download*.
- The run grid shows *Event* as the start and the event under *Requested by* (for example *product saved*).
- The profile grid's *Schedule* column lists the events after the schedule: *Every hour · after: product saved*.
- A failing trigger never breaks the save that raised it; the error goes to `var/log/openimport.log`.

Order exports from 1.1 with **Also run after every order** keep working: the switch acts like the trigger *An order
is placed*, also when it was saved as `true` or `1` by a JSON file or the API. The form shows the old switch only
while it is on; switch it off and choose *An order is placed* instead.

The step 6 header sums it up: *Manual only · after: product saved · switched on · then: Stock update*. The mass
actions of the profile grid switch automatic runs (schedule and triggers) on and off.

## Job chains

Since 1.6. Step 6 of a profile, **Then run**: the profile that runs after this one, as a live run of its own with
this run as its parent (the run grid shows *Job chain* as the start). A nightly chain is one schedule: the first
profile has the schedule, every next one has none.

- **When**: *Only after a successful run* (success or partial — the next step gets the data it expects) or
  *Always, even after a failed run* (cleanup or a report that must run regardless). A cancelled run starts
  nothing; a dry run never does.
- The next profile runs with its own settings. A live run under the safety limit stops at *needs confirmation*
  as always, which also holds the rest of the chain until an admin confirms it.
- Saving refuses a chain that comes back to a profile (*The chain would loop: A → B → A*), a profile that runs
  itself, a next profile that reads uploaded files (nothing would be there to read), and more than 20 links.
- The run page says what happened next: *Then: "Stock update" queued as run 512* (a link to that run), or why
  nothing started; the next run's page links back to the run that started it.
- Deleting a profile that another profile runs next asks with the names of those profiles; their chain stops there.
- The *Then run* list leaves out imports that read uploaded files.
- Profiles exported as JSON carry the chain by profile name; an import links the profiles of the file to each
  other once they are saved.

## Housekeeping

- Run files (the uploaded or fetched source, prepared rows, output) are removed after "Keep run files for" days
  (Stores › Configuration › iranimij › OpenImport). The run, its counts and stored errors stay.
- Uploaded files no profile uses any more are removed after two days.
- A run that has been "running" for more than 90 minutes without a live lock (the PHP process died, the server
  restarted) is marked failed with "The run stopped without finishing", so the profile can run again.

## E-mails

Each profile says when to send an e-mail (step 6): never, on failure (failed, finished with errors, needs
confirmation) or always, to the comma-separated addresses, or, when empty, to the admin who created the profile.
The e-mail carries the result, the counts, the run message, a note on errors and a link to the run page. It uses
the store's e-mail settings (Stores › Configuration › General › Store Email Addresses) and the template *OpenImport: a run finished*, which can be customised under Marketing › Email Templates.

The sender is set under Stores › Configuration › iranimij › OpenImport › Runs › *E-mail sender* (one of the store
e-mail addresses); it also sends exports by e-mail.
