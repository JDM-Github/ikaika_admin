# Airtable sync on production

How to turn the portal-to-Airtable sync on safely, and what it does when something fails.
Only the tables in `sql/portal/schema.sql` (the ones mirrored from Airtable) are synced. Tables from
`sql/portal/separate.sql` are portal-only and never sent.

## Where things stand (2026-10-20)

- **Code:** ready. Admin commits 33b3e20 (wrong-base guard) and 39fafaf (gaps fixed, schedule, backoff).
- **Production (`~/apps/portal-prod`):** not configured. `AIRTABLE_TOKEN` and `AIRTABLE_BASE_ID` are empty,
  the schedule switch is off, and the crontab runs the scheduler every 17 minutes instead of every minute.
- **Bases:** production's data came from the **PROD** base `app8jGTzRDQt4yPIa`. Your local `.env` points
  at the **TEST** base `app8DvnFZErPZT5Az`, and its token cannot read PROD. Never copy the local Airtable
  keys to production (do not add them to `push-env.ps1`).

## Turn it on safely

Every step after the first runs on the server: `ssh bluehost` from PowerShell.

1. **Make a token.** In Airtable, create a personal access token with the scopes `data.records:read`,
   `data.records:write` and `schema.bases:read`, with access to the base **Users & Projects**
   (`app8jGTzRDQt4yPIa`).
2. **Deploy the admin** from your PC: `.\deployment\deploy-all-prod.ps1`. The guard, the fixes and the
   schedule ship with it.
3. **Set the keys.** Run `nano ~/apps/portal-prod/.env` and set these three lines:
   ```
   AIRTABLE_TOKEN=<the token from step 1>
   AIRTABLE_BASE_ID=app8jGTzRDQt4yPIa
   AIRTABLE_SYNC_SCHEDULED=false
   ```
   Then apply them: `ROOT=~/apps/portal-prod bash ~/apps/portal-prod/scripts/apply-env.sh`
4. **Check the mappings:** `cd ~/apps/portal-prod/app && php artisan portal:airtable-sync --check`
   - It must not say `AIRTABLE_BASE_ID names another base`.
   - Every line should read `UPDATE`. `SKIPPED requests.leave` is fine when no leave was ever filed.
   - Stop if any line starts with `!`, other than "No SQL row to check".
5. **Dry run:** `php artisan portal:airtable-sync`. It writes nothing anywhere. Read the last line of counts.
   - `delete` rows remove records from Airtable. Make sure each one is a report or claim someone really deleted.
   - A large `create` count is expected: rows the portal made since the 2026-10-19 import.
   - Anything dated before 2026-10-19 was made before the import. Ask before sending it.
6. **First send, by hand:** `php artisan portal:airtable-sync --send`. Then open a few of the records it
   names in the PROD base and check them.
7. **Switch the schedule on:** set `AIRTABLE_SYNC_SCHEDULED=true` in `~/apps/portal-prod/.env`, then rerun the
   `apply-env.sh` line from step 3.
8. **Make the scheduler run every minute:** run `crontab -e` and change the `portal-prod` line from
   `*/17 * * * *` to `* * * * *`. The sync then runs every minute. With `*/17` it would also run, but only
   every 17 minutes.

To stop it at any time, set `AIRTABLE_SYNC_SCHEDULED=false` and rerun `apply-env.sh`. Nothing is lost: the
portal keeps recording every change, and the next run sends them.

## When something fails

Nothing is dropped silently. Each change stays in the ledger (`core.actions`) until it has landed in
Airtable, and the reason is kept in `last_error`.

There is no fixed "retry 5 times then give up". An Airtable outage can last longer than 5 minutes, and
a fixed cap would leave those changes unsent forever. Instead, a failing change waits longer after each
try:
- the next try comes 1, 3, 7, 15, 31 … 255 minutes after it was written;
- after that, it is tried every 6 hours, forever.

A short blip clears within minutes. A change Airtable keeps refusing stops costing a call every minute,
yet it still lands on its own once someone fixes the base. A send you run by hand (`--send`) tries
everything at once.

| What happens | What the sync does | What you do |
|---|---|---|
| Too many requests (429) | Sends at most ~4.5 requests a second (Airtable allows 5). On a 429 it waits 30 s and tries once more. Still refused: counted as a failure and retried with the waits above. | Nothing. |
| Network down, or Airtable down (5xx) | That change fails and is retried with the waits above. If the whole base is unreachable, the run stops with a readable reason and the next minute tries again. | Nothing. |
| Change sent while the portal is still saving the row | Changes younger than 10 s wait for the next run. A row still missing is retried; missing for 10 minutes means a later edit replaced it, so it settles. | Nothing. |
| A create whose answer never came back | Held and not sent again, because Airtable may already hold it and a resend would duplicate it. | Look in Airtable. If the record is not there, run `--send --retry-creates`. |
| Airtable refuses a value (a field or option it lacks) | Fails and is retried with the waits above; the refusal is in `last_error`. | Fix the field or option in Airtable. The next retry lands it. |
| A dropdown value the base lacks on a lenient field: project Department or Type of Job, claim Team, employee Department, Location, Job Title, Blood Type or Bank Name, leave Category | That field is left out; the rest of the row is sent, and a note is kept. | Add the option in Airtable if you want the value there. |
| A dropdown value the base lacks on any other field | The row is **blocked** and retried every run, with the reason kept. | Add the option in Airtable. |
| A link to a record Airtable does not have yet, such as a report by someone not yet created there | Blocked until that record is created. Changes go oldest first, so this usually clears in the same run. | Nothing, unless the linked record itself is failing. |
| A record deleted in Airtable while the portal still has the row | Requests, claims and reports: created again (the portal's row is the truth). Employees, projects and grid edits: blocked with a reason, because only the changed columns are sent and the record cannot be rebuilt from them. | For the blocked kind, decide whether the record should come back. |
| The wrong base in `AIRTABLE_BASE_ID` | Refuses to send anything ("names another base"). | Fix `AIRTABLE_BASE_ID`. |
| Two sends at once | The second one refuses; only one send runs at a time. | Nothing. |
| A cancelled or withdrawn request | Never sent. A copy already in Airtable is deleted. | Nothing. |

**Seeing what is waiting:** the dry run (`php artisan portal:airtable-sync`) lists every waiting change
with its outcome and reason. The portal's Administration > All Actions page has a Sync to Airtable button
(Developer mode) that shows the same preview.

## Not synced, by design

- Portal-only tables (`separate.sql`), such as rules, logs, email and onboarding registrations.
- **Project deletes:** the portal moves the project to its recycle bin, and the Airtable record is kept.
- Project scope assignees and scope progress: these live in portal-only tables. Airtable's "Project Scopes"
  and "User Reports - Project Scope Progress" receive nothing.

## Checks you can run any time

- `php artisan portal:airtable-sync --check` tries every mapping against the live base and writes nothing.
- `php artisan portal:airtable-sync` is the dry run: everything waiting, with outcomes. It writes nothing.
- `php artisan test --filter=Airtable` runs the sync's tests (local machine only).
