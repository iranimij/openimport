# Command line

```bash
bin/magento openimport:profile:list
bin/magento openimport:run <profile id or name> [--dry-run] [--file=path] [--full] [--confirm] [--queue]
bin/magento openimport:run:status <run id>
bin/magento openimport:queue:run [--limit=10]
```

## openimport:run

Runs a profile now, in the terminal, and prints the result: status, counts, the first changes (dry runs) and the first
errors with the row of the file they come from.

| Option | Effect |
|---|---|
| `--dry-run` | Check and preview only; nothing changes in the shop. |
| `--file=path` | Read this file instead of the profile's source. |
| `--full` | Ignore the delta state: send every row. |
| `--confirm` | Confirm a Delete or Replace run above the safety threshold. |
| `--queue` | Only add the run to the queue; OpenImport's cron runs it. |

The exit code is 0 for *Success* and *Partial*, 1 otherwise, so the command can be used in scripts and deployment
pipelines.

## openimport:run:status

Shows any run (also runs started in the admin or by a schedule) with its counts, changes and errors.

## openimport:profile:list

Lists all profiles with direction, entity, source, schedule, whether the schedule is active, and the last run.

## openimport:queue:run

Runs the queued runs now, oldest first (runs started in the admin wait in the queue). OpenImport's cron does the
same every minute; the command is for stores without cron, deployments and tests.

## `openimport:secrets:reencrypt`

After `bin/magento encryption:key:change`: encrypts every stored source password, private key and token again
with the current key. Run it while the old key is still in `app/etc/env.php`.

## `openimport:profiles:export [profile …]`

Writes profiles as JSON to standard output (all profiles, or the ones named by id or name): every setting,
mapping and value map, never passwords, keys, tokens or upload tokens. Keep the file in version control or move it
to another store: `bin/magento openimport:profiles:export > profiles.json`.

## `openimport:profiles:import <file> [--dry-run]`

Creates the profiles of such a file; a profile with the same name is updated (its stored secrets are kept).
Everything is checked first and listed as "create …" / "update …"; one problem and nothing is saved. `--dry-run`
checks and lists without saving. Enter passwords and keys in the admin afterwards.

## `openimport:doctor`

Checks what makes imports slow or stuck and says what to do: cron (has `openimport_tick` run in the last
10 minutes?), indexers on "Update on Save", PHP extensions and `memory_limit`, `var/openimport` writable, runs
waiting longer than 15 minutes, runs "running" for more than 90 minutes. Exit code 1 when something needs a look.
