# REST API and triggers

Since 1.1.

## Start a profile from another system

```http
POST /rest/V1/openimport/profiles/{profileId}/runs
Authorization: Bearer <integration access token>
Content-Type: application/json

{"mode": "live"}
```

`mode` is `dry_run` (default) or `live`. The run is queued with the trigger *API* and OpenImport's cron runs it
within a minute, exactly like a scheduled run: same source, mapping, delta, safety threshold (a live run above it
waits for a confirmation in the admin) and e-mails. Import profiles need a source other than *Upload* (folder, URL,
SFTP or FTP); exports work with every destination.

Answer:

```json
{"run_id": 42, "status": "queued", "mode": "live", "message": "", "rows_read": 0, "errors": 0}
```

Ask how it went:

```http
GET /rest/V1/openimport/runs/{runId}
```

Access: create an integration (System › Extensions › Integrations) with the resources *OpenImport › Run profiles*
(start) and *View runs and error reports* (status). Typical use: a PIM or ERP drops a file on the SFTP server and then
calls the endpoint, so the import starts at once instead of at the next schedule.

## Export right after an order is placed

Order export profiles from before 1.6 have the setting **Also run after every order** (since 1.6 the form shows it only while it is on; new profiles use step 6 › *Also run when* › *An order is placed*). When an order is placed (storefront, admin or
API), the profile is queued; cron runs it within a minute. Ten orders in that minute make one run, not ten. Combine
it with *Which orders: Only orders not exported before by this profile* so every file holds just the new orders.

Since 1.6 any profile can do this, and react to more events (a product or customer saved, stock changes, an order
changing status): see [Schedules › Triggers](schedules.md#triggers).
