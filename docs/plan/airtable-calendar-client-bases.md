# Plan: Airtable multi-base sync (Calendar, Client Information, Transaction Tracker)

Status: Phase 1 built 2026-10-22; phases 2-8 planned. Written for the developer (or model) who
implements it. Repo: `ikaika_admin` (Laravel). Read `deployment/AIRTABLE-SYNC.md`,
`docs/plan/airtable-earn-codes-work-date.md` and `migrations/NOTE.md` first.

## Goal

Make the portal sync to four Airtable bases, and take holidays/Earn Codes from Calendar:

1. Users & Projects (main) - projects, reports, requests, employees, warnings, achievements.
2. Calendar - holidays source, Events, editable Earn Codes.
3. Client Information - Clients (the writable source; Users & Projects Clients is a synced copy).
4. Transaction Tracker - Accounts, Budget Code, Envelopes - Budget, Transactions.

## Bases (TEST / PROD)

| Base | TEST | PROD |
|---|---|---|
| Users & Projects | `app8DvnFZErPZT5Az` | `app8jGTzRDQt4yPIa` |
| Calendar | `appCoObE52yP1guFP` | `appxjrrZc99jJlpli` |
| Client Information | `appSNMGACG48i3XFX` | `appLBcQ7leQQnJ7Pj` |
| Transaction Tracker | `appYwnzEVZVXwH1hB` | `appp5MogesHN2eVvG` |

The PAT holds read/write on all four TEST bases. One token is enough; per-base token overrides are
supported. `AIRTABLE_ENV=test|prod` picks the TEST or `_PROD` keys.

## What this will and will not touch (production safety)

- The sync is one-way: SQL -> Airtable. It never imports Airtable data into portal business tables.
- The only Airtable -> SQL pull is the Calendar holiday/Earn Code mirror. It writes a new local table,
  never `projects`, `user_reports`, `requests`, `employees`, `clients`, `earn_codes` or the tracker DB.
- Schema changes are additive (new columns/tables). Never run `sql/portal/schema.sql` or a deploy
  `-ResetDatabases` on production.
- Existing ids stay: `clients.airtable_record_id` and `earn_codes.airtable_record_id` are the
  Users & Projects (synced copy) ids used by project/report links. New source-base ids live in their
  own columns.
- No backfill by default. Existing clients/earn codes are mapped by name/description (ids only).
  New source records wait for Airtable to create the Users & Projects copy, then the portal pulls that
  id before the record can be linked.
- Reports/requests already ledgered in production are unaffected; the first main-base send catches
  them up to Users & Projects as before.

## Decisions (2026-10-22, user)

1. PROD ids confirmed as above.
2. New clients/earn codes: block linking until the Users & Projects copy appears.
3. Earn Codes: one Description field (raw `01 Regular Working Day`) + Did Not Work + Did Work.
   Removal refused when any report/request uses it.
4. Events: mirror all 9 fields (Name, Details, Start Date, End Date, Type, Author, Attendees,
   Employee Names, Dept).
5. Tracker: all four tables + attachments.
6. One command (`portal:airtable-sync`) handles both products.

## Phase 1 - multi-base engine (built)

- `config/services.php`: `airtable.env`, `token`, `tokens`, `base` (main), `bases` for main/calendar/
  clients/tracker. Prod keys are `_PROD` and selected by `AIRTABLE_ENV=prod`.
- `.env` / `.env.example`: TEST keys now, `_PROD` keys for deployment.
- `AirtableClient`: every method takes a base key (`main` default); `baseId()` validates the named
  base; `token()` falls back to the main token.
- `AirtableSyncMap`: resources may carry `base`, `product`, `connection`, `record_id_column`, and a
  link may carry `id_column`. Defaults: main/portal/portal/`airtable_record_id`.
- `CoreLedger`: `transaction-tracker` is a known product.
- `PortalAirtableSync`: syncs portal + transaction-tracker actions in one run; per-resource
  connection/base/schema; per-base `wrongBase`; `--check` per base/resource; write-back and link
  resolution use `record_id_column`; an unconfigured base settles as `skipped`.
- `migrations/NOTE.md`: base table + synced-copy note.

## Phase 2 - Holidays from Calendar (pull, built)

- `portal:sync-calendar` (hourly/on demand): fetch Calendar Earn Codes + Holidays; classify the base
  Earn Code into regular/special/double; upsert `calendar_holidays` (date, name, type,
  airtable_record_id); delete rows the base dropped; bump the holiday cache.
- `PortalHolidays` reads `calendar_holidays`; the estimator `Holiday` model is no longer used.
- TEST Calendar fields: Holidays (Date, Name, Earn Code, Earn Code OT/ND/ND+OT, Special Holiday Type);
  Earn Codes (Description, Did Not Work, Did Work).

## Phase 3 - Earn Codes updater on References (built)

- Admin: `PortalReferences` gains `earnCodes` in `index()` and add/update/remove methods;
  `manage/references/earn-codes` routes behind `projects.references.edit`; validates Description +
  numeric multipliers; refuses removal when `user_reports_earn_codes`/`requests_earn_codes` use it;
  ledgers `references.earn_codes` to Calendar with `calendar_airtable_record_id`.
- Portal: new References section (list, add/edit sheet, remove confirm, Usage filter).
- Existing 36 codes mapped to Calendar by exact Description (dry run, ids only).
- New code: create in Calendar, wait for the Users & Projects synced copy, pull its id before reports
  can link it.
- The admin grid `earn_codes` resource also targets Calendar (same source id), so both editors agree.

## Phase 4 - Events to Calendar (built)

- `PortalCalendarEvents::create` writes inside `ledger->within` and ledgers `calendar.events`.
- Map to Calendar Events: Name, Details, Start/End Date (dateTime), Type (`company_event` -> Company
  Event, `project` -> Project), Author (actor name), Attendees (Everyone/Specific Members/Department/
  None), Employee Names (text), Dept (multi-select).
- Store the denormalized author/attendees/member names/departments/datetimes on `calendar_events` (or
  a sibling table) so the sync can send them.
- Only creates exist; no event update/delete endpoint.

## Phase 5 - Clients to Client Information (built)

- Repoint `references.clients` and the grid `clients` to Client Information; keep the Users & Projects
  id for project links; add `client_info_airtable_record_id`.
- Send Name and Notes. Assignee (collaborator) and Status (Todo/In progress/Done) only after a
  controlled test. No client attachments in the portal.
- New client waits for the Users & Projects copy before a project can link it.

## Phase 6 - Transaction Tracker sync (built)

- Core ledger actions on tracker writes:
  - `PortalBudgetAccounts` accounts add (there is no edit/remove endpoint).
  - `PortalBudgetSetup` Budget Code and Envelopes - Budget add/edit (no remove endpoints).
  - `PortalBudgetLedger::record` transactions, including allocations, transfers and reimbursement
    charges; `PortalBudgetTransactions::edit` edits them.
- `tracker.transactions`, `tracker.accounts`, `tracker.envelopes`, `tracker.budget_codes` map to the
  TEST tracker base; product `transaction-tracker`, connection `tracker`, base `tracker`.
- Fields/links: Transactions (Account, Envelope, Budget Code, Transfer Source/Destination; Receipt;
  Type Expense/Credit/Transfer), Accounts (QRCode), Budget Code, Envelopes - Budget. Receipts are sent
  on create or when the edit changed/removed them; an imported list is never resent.
- Skip computed fields (Current Balance, Remaining Budget, rollups, Created/Last Modified Time,
  Auto-Extracted Details). Skip `Reviewed By` (collaborator) in v1.
- No backfill; the local TEST tracker ids already point at the TEST base.
- Verified: unit/scenario tests, `--check` against all four TEST bases, and a live TEST tracker round
  trip (create + edit, 92 assertions, 0 leftovers).

## Phase 7 - Verification (built)

- Fake bases for each base, strict typecast off; per-base `--check`; stateful main-base scenarios and
  tracker/client unit coverage.
- `PortalAirtableLiveRoundTripTest` covers all four TEST bases: main records, a Calendar event, a Client
  Information client (create/edit/delete) and a tracker transaction (create/edit), refusing any PROD id
  and `AIRTABLE_ENV=prod`; it creates/reads/deletes only its own records. Last live run: 100 assertions,
  0 leftovers.
- Targeted tests, `vendor/bin/pint --dirty`, `graphify update .`.

## Phase 8 - Deployment (Test Server built, 2026-10-22)

- `.env.example` lists all four TEST bases, the `_PROD` ids and `AIRTABLE_SYNC_SCHEDULED=false`; local
  `.env` now has the TEST keys and the schedule on.
- `deployment/AIRTABLE-SYNC.md` is written for the Test Server: `push-env.ps1` now carries the Airtable
  keys by default (Test Server only; `-Prod` still needs explicit keys), then `deploy-all.ps1` with no
  reset. The deploy backs up default/core, portal and tracker before swapping.
- Deployed to the Test Server: `AIRTABLE_ENV=test`, all four TEST bases, `AIRTABLE_SYNC_SCHEDULED=true`;
  migrations `portal_clashflow_workflow`, `calendar_holidays`, earn-code id, event fields and client-info
  id ran; tracker patch applied. First manual `--send` synced 3 recent creates (2 leaves, 1 claim), read
  back from TEST; the next dry run was empty. Backups:
  `~/apps/test-server/backups/databases-20261022-053956/`.
- The Test Server cron runs `schedule:run` every 18 minutes, so the sync follows that cadence.
- Production stays separate and untouched: its own PAT + `AIRTABLE_ENV=prod` + `_PROD` ids, deployed
  with `deploy-all-prod.ps1` only after approval. `--send` refuses the PROD profile outside production.

## Open items

- Controlled write test for Client Information `Assignee`/`Status` and Tracker `Reviewed By`.
- Event update/delete: the portal has no endpoints; syncing only creates.
- The transaction tracker's first send on production: inspect the dry run because its DB may hold
  actions from before the reload.
