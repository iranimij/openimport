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

A profile's schedule is a preset (every hour, every day at 02:00, weekdays at 06:00, …) or a 5-field cron
expression: minute, hour, day of month, month, day of week. Lists (`1,15`), ranges (`8-18`), steps (`*/15`) and
names (`mon-fri`, `jan`) work. Times are read in the store's time zone (Stores › Configuration › General ›
Locale Options › Timezone), so `0 2 * * *` means 02:00 local time, also across daylight-saving changes.

A schedule only fires while the profile's **Schedule is on** switch is on. New and duplicated profiles start with it
off: run a dry run first, then switch it on.

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

## Housekeeping

- Run files (the uploaded or fetched source, prepared rows, output) are removed after "Keep run files for" days
  (Stores › Configuration › iranimij › OpenImport). The run, its counts and stored errors stay.
- Uploaded files no profile uses any more are removed after two days.
- A run that has been "running" for more than 90 minutes without a live lock (the PHP process died, the server
  restarted) is marked failed with "The run stopped without finishing", so the profile can run again.
