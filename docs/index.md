# OpenImport

Import and export for Magento 2 and Mage-OS, free and MIT.

OpenImport puts profiles, column mapping, value transforms, a dry run that previews every change, schedules and
remote sources (SFTP, FTP, URL, server folder) in front of Magento's own import engine. Magento does the writing, so
imported data is exactly what core import would produce, and upgrades keep working.

## Install

```bash
composer require iranimij/openimport
bin/magento module:enable Iranimij_Base Iranimij_OpenImport
bin/magento setup:upgrade
```

Magento Open Source 2.4.7 to 2.4.9 or Mage-OS, PHP 8.2 to 8.4. Magento's cron must run for schedules and queued
runs; `bin/magento openimport:doctor` tells you whether it does.

## Where to start

1. [Admin](admin.md): the profile form step by step, the run page, grids and roles.
2. [Mapping and filters](mapping.md): columns, store views, auto-match, row filters.
3. [Transforms and expressions](transforms.md): what you can do with a value on its way in.
4. [Runs, dry runs and safety](runs.md): what the preview shows, delta mode, the safety threshold, error reports.
5. [Sources](sources.md) and [Exports](exports.md): files from somewhere else, files to somewhere else.
6. [Schedules and cron](schedules.md), [Command line](cli.md), [Security](security.md).

Developers: [Core bridge](core-bridge.md) explains how rows reach Magento's importer, [Core facts](core-facts.md)
lists the behaviour of core import that OpenImport relies on, and [Baseline](baseline.md) has the performance numbers.

## Getting help

Open an issue at [github.com/iranimij/openimport](https://github.com/iranimij/openimport/issues) with a 10-row sample
of your file and the run's message. Security problems: see
[SECURITY.md](https://github.com/iranimij/openimport/blob/main/SECURITY.md).
