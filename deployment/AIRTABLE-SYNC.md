# Airtable sync on production

How to turn the portal-to-Airtable sync on safely, and what it does when something fails.
Only the tables in `sql/portal/schema.sql` (the ones mirrored from Airtable) are synced. Tables from
`sql/portal/separate.sql` are portal-only and never sent.

## Where things stand (2026-10-20)

- **Code:** ready and verified. Committed in the admin repo; production gets it with the next
  `deploy-all-prod.ps1`. The deploy also runs one new migration: `actions.attempted_at` in the core database.
- **Proof so far:**
  - every mapped field exists in the PROD base with the same type;
  - every value in every mapped column fits PROD's options;
  - 450 feature tests, plus 12 end-to-end scenarios against a strict fake Airtable;
  - a real round trip against the **TEST** base: creates, links, attachments, updates and deletes, read
    back and cleaned up.
- **Production (`~/apps/portal-prod`):** not configured yet.
  - `AIRTABLE_TOKEN` and `AIRTABLE_BASE_ID` are empty.
  - The schedule switch is off.
  - The crontab runs the scheduler every 17 minutes.
- **Bases:**
  - Production's data came from the **PROD** base `app8jGTzRDQt4yPIa`.
  - Your local `.env` points at the **TEST** base `app8DvnFZErPZT5Az`, and its token cannot read PROD.
  - Never copy the local Airtable keys to production, and do not add them to `push-env.ps1`. If the wrong
    base is ever set, the sync refuses to send ("names another base").

## Turn it on safely

Every step after the first runs on the server: `ssh bluehost` from PowerShell.

1. **Make a token.** In Airtable, create a personal access token with the scopes `data.records:read`,
   `data.records:write` and `schema.bases:read`, with access to the base **Users & Projects**
   (`app8jGTzRDQt4yPIa`).
2. **Deploy the admin** from your PC: `.\deployment\deploy-all-prod.ps1`.
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
5. **Dry run:** `php artisan portal:airtable-sync`. It writes nothing anywhere. Each line shows the action's
   number (`#123`) and when it was recorded, in Manila time.
   - **Anything recorded before the 2026-10-19 import must not be sent.** It names rows the import has
     since renumbered. Settle those without sending them, replacing `123` with the last such number:
     `php artisan portal:airtable-sync --skip-through=123`
     Then run the dry run again.
   - `DELETE` lines remove records from Airtable. Make sure each one is a report or claim someone really
     deleted.
   - A large `CREATE` count is expected: rows the portal made since the import.
6. **First send, by hand:** `php artisan portal:airtable-sync --send`. Then open a few of the records it
   names in the PROD base and check them.
   - A send stops itself after 10 minutes ("run it again to send the rest"). Run it again until the last
     line shows no `create`, `update` or `delete`.
7. **Switch the schedule on:** set `AIRTABLE_SYNC_SCHEDULED=true` in `~/apps/portal-prod/.env`, then rerun the
   `apply-env.sh` line from step 3.
8. **Scheduler cadence (optional).** Ask first whether the `*/17` cron was set on purpose. If every minute is
   allowed, run `crontab -l > ~/crontab-backup.txt`, then `crontab -e`, and change the `portal-prod` line from
   `*/17 * * * *` to `* * * * *`. Either way works: the sync follows whatever cadence the cron has.

To stop it at any time, set `AIRTABLE_SYNC_SCHEDULED=false` and rerun `apply-env.sh`. Nothing is lost: the
portal keeps recording every change, and the next run sends them.

## When something fails

Nothing is dropped silently. Each change stays in the ledger (`core.actions`) until it has landed in
Airtable. The reason is kept in `last_error`, and the try count in `attempts`.

There is no "retry 5 times then give up". An outage can outlast 5 tries, and a cap would leave those
changes unsent forever. Instead, the scheduled run waits longer after each try that did not land:
- 1 minute, then 2, 4, 8 … doubling up to 6 hours, counted from that try;
- after that, every 6 hours, forever.

A blip clears in minutes. A change Airtable keeps refusing stops costing calls, and it still lands on its
own once the base is fixed. A send you run by hand (`--send`) tries everything at once.

A scheduled minute with nothing due makes **no** Airtable call, so an idle portal does not spend the base's
API allowance.

| What happens | What the sync does | What you do |
|---|---|---|
| Too many requests (429) | Sends at most ~4.5 requests a second (Airtable allows 5). On a 429 it waits 30 s and tries once more, then backs off as above. | Nothing. |
| Network or Airtable down (5xx), or the host cannot be found or reached | That change fails and is retried with the waits above. If the whole base is unreachable, the run stops with a readable reason and the next run tries again. | Nothing. |
| A change sent while the portal is still saving the row | Changes younger than 10 s wait for the next run. A row still missing is retried; missing for 10 minutes means a later edit replaced it, so it settles. | Nothing. |
| A create whose answer never came back (timeout, a 502/504 from Airtable's gateway, or a send killed mid-call) | That row's create is **held**: neither this change nor any later one on the same row creates it again, because Airtable may already have it. | Look in Airtable. Delete any duplicate, then run `php artisan portal:airtable-sync --send --retry-creates`. |
| Airtable refuses a value (a field or option it lacks) | Retried with the waits above; the refusal is in `last_error`. | Fix the field or option in Airtable. The next retry lands it. |
| A dropdown value the base lacks on a lenient field: project Department or Type of Job, claim Team, employee Department, Location, Job Title, Blood Type or Bank Name, leave Category, any New Employee Data pick | That field is left out; the rest of the row is sent, and a note is kept. If nothing else changed, there is nothing to send. | Add the option in Airtable if you want the value there. |
| A dropdown value the base lacks on any other field | The row is **blocked** and retried with the waits above, with the reason kept. | Add the option in Airtable. |
| A link to a record Airtable does not have yet, such as a report by someone not yet created there | Blocked until that record exists. Changes go oldest first, so this usually clears in the same run or the next. | Nothing, unless the linked record itself is failing. |
| A record deleted in Airtable while the portal still has the row | Requests, claims and reports are created again (the portal's row is the truth). Employees, projects and grid edits fail with a reason, since only changed columns are sent and the record cannot be rebuilt; they back off. | Decide whether the record should come back. |
| The wrong base in `AIRTABLE_BASE_ID` | Refuses to send anything ("names another base"). | Fix `AIRTABLE_BASE_ID`. |
| Two sends at once | The second one refuses; only one send runs at a time. A killed send frees the lock within 15 minutes. | Nothing. |
| A cancelled or withdrawn request | Never sent. A copy already in Airtable is deleted. A grid typo fix on a cancelled row never deletes anything. | Nothing. |

**Seeing what is waiting:**
- The dry run (`php artisan portal:airtable-sync`) lists every waiting change with its outcome and reason.
- A scheduled send that did not land everything writes `portal.airtable_sync.not_landed` to
  `~/apps/portal-prod/app/storage/logs/laravel.log`.
- The portal's Administration > All Actions page has a Sync to Airtable button (Developer mode) that shows
  the dry run.

## Not synced, by design

- Portal-only tables (`separate.sql`), such as rules, logs, email and onboarding registrations.
- **Project deletes:** the portal moves the project to its recycle bin, and the Airtable record is kept. The
  action settles at once, with a note.
- Project scope assignees and scope progress: these live in portal-only tables. Airtable's "Project Scopes"
  and "User Reports - Project Scope Progress" receive nothing.

## Checks you can run any time

- `php artisan portal:airtable-sync --check` tries every mapping against the live base and writes nothing.
- `php artisan portal:airtable-sync` is the dry run: everything waiting, with outcomes. It writes nothing.
- On your PC only:
  - `php artisan test --filter=Airtable` runs the sync's own tests and the end-to-end scenarios.
  - `AIRTABLE_LIVE_WRITES=1 php artisan test --filter=PortalAirtableLiveRoundTripTest` makes the real round
    trip against the **TEST** base. It refuses any other base, and deletes everything it made.
