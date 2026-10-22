# Airtable sync on the Test Server (TEST bases only)

How to turn the portal-to-Airtable sync on safely against the **TEST** bases, and what it does when
something fails. Only tables in `sql/portal/schema.sql` (mirrored from Airtable) are synced; tables from
`sql/portal/separate.sql` are portal-only and never sent.

**Nothing in this workflow writes PROD.** A production turn-on is a separate decision at the bottom.

## Where things stand (2026-10-22)

- **Code:** the four-base sync is built and verified locally: main/Calendar/Client Information/Transaction
  Tracker maps, the tracker product, per-base guards, and `--check` against all four **TEST** bases.
- **Proof so far:** the normal Airtable suite, strict fake-base scenarios, and a live round trip across all
  four TEST bases (main records, Calendar event, Client Information client create/edit/delete, tracker
  transaction create/edit). The live test refuses `AIRTABLE_ENV=prod` and every PROD id, and cleans up
  after itself (100 assertions, 0 leftovers).
- **Test Server (`~/apps/test-server`):** configured 2026-10-22 with the four TEST bases and
  `AIRTABLE_SYNC_SCHEDULED=true`; the first manual send synced 3 recent creates and the next dry run was
  empty. The scheduler cron runs every 18 minutes, so the sync follows that cadence.
- **Production (`~/apps/portal-prod`):** not configured, and not touched by this workflow.

### Bases

| Base | TEST | PROD (never use here) |
|---|---|---|
| Users & Projects | `app8DvnFZErPZT5Az` | `app8jGTzRDQt4yPIa` |
| Calendar | `appCoObE52yP1guFP` | `appxjrrZc99jJlpli` |
| Client Information | `appSNMGACG48i3XFX` | `appLBcQ7leQQnJ7Pj` |
| Transaction Tracker | `appYwnzEVZVXwH1hB` | `appp5MogesHN2eVvG` |

`AIRTABLE_ENV=test` selects the TEST column. The `_PROD` ids are ignored in this workflow; leave them
empty on the Test Server. If a base id is wrong, the sync refuses to send ("names another base").

## The `.env` keys

Use one Airtable PAT with `data.records:read`, `data.records:write` and `schema.bases:read` on all four
**TEST** bases. Put these in the local `.env` (the Test Server gets them in the next step):

```
AIRTABLE_ENV=test
AIRTABLE_TOKEN=<TEST PAT>
AIRTABLE_BASE_ID=app8DvnFZErPZT5Az
AIRTABLE_TRANSACTION_TRACKER=appYwnzEVZVXwH1hB
AIRTABLE_CALENDAR=appCoObE52yP1guFP
AIRTABLE_CLIENT_INFORMATION=appSNMGACG48i3XFX
AIRTABLE_SYNC_SCHEDULED=false
```

The per-base token overrides (`AIRTABLE_CALENDAR_TOKEN`, `AIRTABLE_CLIENT_INFORMATION_TOKEN`,
`AIRTABLE_TRANSACTION_TRACKER_TOKEN`) are optional; each falls back to `AIRTABLE_TOKEN`. Keep
`AIRTABLE_SYNC_SCHEDULED=false` until the first manual send was checked.

To stage a new base, leave its id empty until its turn. Actions for an unconfigured base are `skipped`
and wait; nothing is lost.

## Deploy to the Test Server

1. **Check locally first:** `php artisan portal:airtable-sync --check`. It writes nothing and must exit 0
   with no `!` lines.
2. **The settings step carries them.** `deploy-all.ps1` now pushes the Airtable keys from the local
   `.env` to the Test Server. To push only those keys by hand, run:
   ```powershell
   .\deployment\push-env.ps1 -Keys AIRTABLE_ENV,AIRTABLE_TOKEN,AIRTABLE_BASE_ID,AIRTABLE_TRANSACTION_TRACKER,AIRTABLE_CALENDAR,AIRTABLE_CLIENT_INFORMATION,AIRTABLE_SYNC_SCHEDULED
   ```
3. **Deploy as usual:**
   ```powershell
   cd C:\IKAIKA\ikaika_admin\deployment
   .\deploy-all.ps1
   ```
   Do **not** use `-ResetDatabases`. The deploy backs up the default/core, portal and tracker databases
   before swapping, then runs additive migrations and the safe-to-rerun tracker patches. No reset, no
   backfill.
4. **Check the server's mappings:**
   ```bash
   ssh bluehost
   cd ~/apps/test-server/app
   php artisan portal:airtable-sync --check
   ```
   Every line should be `UPDATE`; `SKIPPED requests.leave` is fine when no leave exists. Stop on any `!`
   line. Unconfigured bases are `SKIPPED`.
5. **Dry run:** `php artisan portal:airtable-sync`. It writes nothing. Each line shows the action number
   (`#123`) and when it was recorded, in Manila time.
   - Rows recorded before the 2026-10-19 import must not be sent; settle them with
     `php artisan portal:airtable-sync --skip-through=123`, then dry-run again.
   - A `DELETE` removes an Airtable record; confirm it is a report/claim someone really deleted.
6. **First send, by hand:** `php artisan portal:airtable-sync --send`. Check a few named records in the
   **TEST** bases. Run it again until no `create`/`update`/`delete` remains (a send stops after 10 minutes).
7. **Turn the schedule on** only after that send is clean (already on for this Test Server): set
   `AIRTABLE_SYNC_SCHEDULED=true` in the local `.env`, rerun the `push-env.ps1` command from step 2, then
   rerun `deploy-all.ps1` or edit the server's `.env` and apply it:
   ```bash
   ROOT=~/apps/test-server bash ~/apps/test-server/scripts/apply-env.sh
   ```
8. **Enable the extra bases one at a time** (optional): leave Calendar/Client Information/Tracker ids
   empty at first, then add one id, push that key, `--check`, dry-run and send. This keeps the first send
   to Users & Projects only.

To stop it at any time, set `AIRTABLE_SYNC_SCHEDULED=false` and re-apply the environment. The portal keeps
recording every change; the next run sends them.

## Production later (separate decision)

Production is not part of this Test Server workflow. When it is approved:

- Use production's own PAT for the four PROD bases, never the TEST token.
- Confirm `APP_ENV=production` on `~/apps/portal-prod` first. The command refuses `AIRTABLE_ENV=prod`
  outside the production app, so a server still set to `APP_ENV=staging` will refuse every `--send`
  (including the scheduled one).
- Put the production token in your local `.env` as `AIRTABLE_TOKEN_PROD` (the TEST token stays in
  `AIRTABLE_TOKEN`), then run:
  ```powershell
  .\deployment\setup-prod-airtable.ps1
  ```
  It uses `AIRTABLE_TOKEN_PROD` when present (otherwise asks for it hidden), writes a temporary
  production env file, refuses TEST ids, sends only `APP_ENV`/`AIRTABLE_*` over SSH without printing
  them, runs production's `apply-env.sh`, and optionally runs `deploy-all-prod.ps1`.
  For a file-driven/manual push, use `deployment/push-prod-airtable.ps1 -EnvFile ...` with
  `deployment/prod-airtable.env.example` as the template.
- With `AIRTABLE_ENV=prod`, the main base is read from `AIRTABLE_BASE_ID_PROD` (not
  `AIRTABLE_BASE_ID`). Leave `AIRTABLE_CALENDAR_PROD` and `AIRTABLE_CLIENT_INFORMATION_PROD` empty for
  the first Users & Projects send.
- **Transaction Tracker sync is off for now** (2026-10-22, supervisor): leave
  `AIRTABLE_TRANSACTION_TRACKER_PROD` empty. `setup-prod-airtable.ps1 -AllBases` deliberately does not
  enable it; use `-Tracker` only when the tracker is approved for syncing.
- Redeploy with `deployment\deploy-all-prod.ps1`, then run `--check` and a dry run on the server before
  the first manual `--send`.
- `--send` refuses `AIRTABLE_ENV=prod` outside the production app, so a local/Test Server run cannot
  write PROD by accident.

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
| A dropdown value the base lacks on a lenient field: project Department or Type of Job, claim Team, employee Department, Location, Job Title, Blood Type or Bank Name, leave Category, any New Employee Data pick, tracker Account Type | That field is left out; the rest of the row is sent, and a note is kept. If nothing else changed, there is nothing to send. | Add the option in Airtable if you want the value there. |
| A dropdown value the base lacks on any other field | The row is **blocked** and retried with the waits above, with the reason kept. | Add the option in Airtable. |
| A link to a record Airtable does not have yet, such as a report by someone not yet created there | Blocked until that record exists. Changes go oldest first, so this usually clears in the same run or the next. | Nothing, unless the linked record itself is failing. |
| A record deleted in Airtable while the portal still has the row | Requests, claims and reports are created again (the portal's row is the truth). Employees, projects and grid edits fail with a reason, since only changed columns are sent and the record cannot be rebuilt; they back off. | Decide whether the record should come back. |
| A wrong base id (`AIRTABLE_BASE_ID`, `AIRTABLE_CALENDAR`, `AIRTABLE_CLIENT_INFORMATION` or `AIRTABLE_TRANSACTION_TRACKER`) | Refuses to send anything ("names another base"). | Fix that base's id. |
| Two sends at once | The second one refuses; only one send runs at a time. A killed send frees the lock within 15 minutes. | Nothing. |
| A cancelled or withdrawn request | Never sent. A copy already in Airtable is deleted. A grid typo fix on a cancelled row never deletes anything. | Nothing. |

**Seeing what is waiting:**
- The dry run (`php artisan portal:airtable-sync`) lists every waiting change with its outcome and reason.
- A scheduled send that did not land everything writes `portal.airtable_sync.not_landed` to
  `~/apps/portal-prod/app/storage/logs/laravel.log`.
- The portal's Administration > All Actions page has a Sync to Airtable button (Developer mode): it checks
  the mappings, previews the next send, and after a second confirmation sends with the same engine and lock
  as `--send`. The check and preview write nothing.

## Not synced, by design

- Portal-only tables (`separate.sql`), such as rules, logs, email and onboarding registrations.
- **Project deletes:** the portal moves the project to its recycle bin, and the Airtable record is kept. The
  action settles at once, with a note.
- **User Reports - Project Scope Progress:** the per-report progress rows stay portal-only. A scope's latest
  reported figure reaches Airtable's Project Scopes "Progress" field (as a fraction); the report table does not.

## Checks you can run any time

- `php artisan portal:airtable-sync --check` tries every mapping against the live base and writes nothing.
- `php artisan portal:airtable-sync` is the dry run: everything waiting, with outcomes. It writes nothing.
- On your PC only:
  - `php artisan test --filter=Airtable` runs the sync's own tests and the end-to-end scenarios.
  - `AIRTABLE_LIVE_WRITES=1 php artisan test --filter=PortalAirtableLiveRoundTripTest` makes the real round
    trip against the **TEST** base. It refuses any other base, and deletes everything it made.
