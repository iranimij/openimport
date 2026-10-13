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
