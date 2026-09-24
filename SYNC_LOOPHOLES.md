# SQL to Airtable sync - findings and loopholes

Planning document for the one-directional sync (portal SQL -> Airtable) that replays
`core.actions`. It lists everything that would make that sync skip a real write, push a
row twice, push something wrong, or leak data - with the file, line, impact, and fix.

Nothing here has been implemented. The concurrency lock is deliberately out of scope
(section 9 only lists the facts it must respect).

Companion to `ADMIN_MISSING.md` section 3, which this corrects in two places (L-12, L-13).

## Status - 2026-09-23 pass

| ID | Status | What changed |
| --- | --- | --- |
| L-1 | Fixed | `sql/portal/reset.ps1` step 5 deletes `product='portal'` rows from core `actions` and `recycle`. Reset run: ledger now 0 rows, portal matches the dump manifest. |
| L-2 | Fixed | Compensating `try/catch` + `deleteRequests` removed; `CoreLedger::within()` rolls both sides back. Audit and notifications run after commit. |
| L-3 | Fixed | `CoreLedger::within()` nests the core transaction inside the product one; every ledgered write uses it. `recordEdit` is now transactional. |
| L-4 | Fixed | Compensation-only hard deletes are gone. Items dropped by a reimbursement edit get `recycleDeleted` per item. |
| L-5 | Fixed | `PortalManageRequests::recordDecision` - leave, overtime, offset, reimbursement, flag. Flag payload carries `userReportIds`. |
| L-6 | Fixed | Role change ledgered as `portal.employees` / `employees`, same shape as a grid edit. |
| L-7 | Fixed | Receipts ride in every reimbursement item snapshot; payout receipt ledgered as an edit. |
| L-8 | Fixed | Reimbursement actions carry the full stored row (item, cost, quantity, purpose, team, status, receipt). |
| L-9 | Fixed | One action per reimbursement item; report add/edit/restore carry `lineIds` (edit also `removedLineIds`). |
| L-10 | Fixed | Grid and role edits write `portal.<table>`; nothing writes the env database name any more. |
| L-11 | Fixed | Cancel payloads carry the stored fields plus status; grid edits add a flat `fields` map. Sync must still use PATCH. |
| L-12 | Open | Airtable id write-back belongs to the sync build. |
| L-13 | Fixed | Grid edits only tables with `airtable_record_id`. `ADMIN_MISSING.md` corrected. |
| L-14 | N/A | Guidance for the sync build. |
| L-15 | Fixed | `CorePayloadFilter` (moved out of `PortalAudit`) strips secrets and government/bank ids at any depth, in the ledger and the log. |
| L-16 | Fixed | A repeat reimbursement decision no longer 404s. |
| L-17 - L-20 | Open | Deferred by decision. |

Also found and fixed during the pass: `PortalManageRequestsTest` and
`PortalManageUsersTest` transacted only `portal`, so once those paths ledgered, test runs
leaked action rows into the real core database. Both now transact `core` too.

One consequence to know: an item removed from a reimbursement claim now appears in the
Recycle Bin, but only reports can be restored from there, so its Restore answers
"This item cannot be restored from here."

## How this was produced, and how far to trust it

- 11 of 15 per-file audits completed (ManageUsers, Inbox, ManageRequests,
  WorkspaceWriter, WorkspaceViews, LeaveRequests, ReimbursementRequests, Email, Audit,
  EmailMessages, OvertimeRequests). The run was stopped before its adversarial
  verification pass and before its cross-cutting agents finished.
- SubmittedReports, OffsetRequests, CalendarEvents, CoreLedger, id coverage, orphaned
  actions, and the Airtable API limits were checked directly afterwards.
- Every number below was measured against the local databases on 2026-09-23
  (`ikaika_platform`, `test_portal_database`). Line numbers are as of that date.
- Findings marked **(spot-checked)** were re-read by hand. The rest come from a single
  audit pass - re-read the cited lines before changing them.

## 0. The state today, measured

| Fact | Value |
| --- | --- |
| Rows in `core.actions` | 63, all `synced_at IS NULL` |
| Code that ever sets `synced_at` | none (`CoreLedger.php:210` writes `null`) |
| Rows in `core.recycle` | 0 |
| Request actions whose `record_id` no longer exists in SQL | **24 of 25** (leave 13/13, reimbursement 5/5, overtime 2/2, offset 4/5) |
| `reports.submitted` add actions carrying the SQL line ids | 0 of 22 |
| PHP that writes `airtable_record_id` | none |
| Rows missing `airtable_record_id` | requests 0/258, reimbursements 0/62, employees 0/21, projects 0/136, user_reports 2/4752 |
| Unique constraint on `actions` | none besides `id` |

`requests` max id is 258 and `reimbursements` max id is 62 - exactly the dump. Every
request the portal created since was wiped by a re-import, while `core.actions` (a
different database) kept the actions pointing at them. See L-1.

### Write path -> ledger coverage

| Write path | Table | Ledgered | Ledger inside the data transaction |
| --- | --- | --- | --- |
| `PortalSubmittedReports` create / edit / delete / restore | user_reports + 4 junctions | yes (:347, :499, :577, :665) | no - separate connection |
| `PortalLeaveRequests` create / replace / cancel | requests | yes (:154, :242, :297) | no |
| `PortalLeaveRequests::deleteRequests` | requests | **no** (:754) | - |
| `PortalOvertimeRequests` create / replace / cancel | requests, projects_requests | yes (:139, :238, :316) | no; `cancel` has no transaction at all |
| `PortalOvertimeRequests::deleteRequests` | requests, projects_requests | **no** (:970-971) | - |
| `PortalOffsetRequests` create / replace / cancel | requests, projects_requests | yes (:135, :240, :322) | no |
| `PortalOffsetRequests::deleteRequests` | requests, projects_requests | **no** (:1021-1022) | - |
| `PortalReimbursementRequests` create / replace / cancel | reimbursements, employees_reimbursements | partly - one action per claim, not per row (:178, :308, :375) | no |
| `PortalReimbursementRequests` receipts / payout receipt / removed items | attachments, reimbursements | **no** (:1030-1048, :1149-1162, :1226-1232, :301) | - |
| `PortalManageRequests` approve / reject / flag decision | requests, reimbursements, employees_user_reports | **no** (:252, :294, :368) | - |
| `PortalManageUsers::updateRole` | employees | **no** (:119) | - |
| `WorkspaceWriter::update` (admin grid) | any writable table | yes (:116) | no - data autocommits first (:113) |
| `PortalCalendarEvents`, `PortalEmail`, `PortalEmailMessages`, `PortalInbox`, `PortalAudit`, `WorkspaceViews` | portal-only tables | no - correctly (L-13) | - |

## 1. The ledger no longer matches the data

### L-1 Critical - a portal re-import orphans the ledger (spot-checked)

- **Where:** `core.actions` lives in `ikaika_platform`; the rows it describes live in
  `test_portal_database`. Re-importing `sql/portal/*.sql` resets the portal DB and never
  touches `ikaika_platform`.
- **Measured:** 24 of 25 request actions reference ids above the dump's max id; those
  rows do not exist. (There is no `sql/portal/reset.ps1` in the repo any more; whatever
  performed the last re-import had this effect.)
- **Impact:** the first sync run pushes creates for leave, overtime, offset, and
  reimbursement records SQL never had, and edits it cannot resolve. No delete action
  will ever arrive to archive them.
- **Fix:** any portal reset must also clear (or mark synced) the portal rows in
  `ikaika_platform.actions` and `recycle` - a paired reset step, not a manual chore. The
  sync itself must treat "record_id not found in SQL" as an unresolvable row to report,
  never as a create. Before the first real run, set `synced_at = now()` on everything
  written before the cut-over (ADMIN_MISSING.md 3.6 already proposes this).

### L-2 Critical - rolled-back filings leave committed add actions behind

- **Where:** `PortalLeaveRequests.php:151-173`, `PortalOvertimeRequests.php:136-187`,
  `PortalOffsetRequests.php:132-183`, `PortalReimbursementRequests.php:177-227`,
  `PortalSubmittedReports.php:496-533` and `:664-711`.
- **What:** each `create()` commits the portal rows, then calls `recordAdd` in its own
  core transaction, then runs the audit and notification writes inside the same `try`.
  If anything in that `try` throws, the `catch` hard-deletes the rows but discards the
  action ids `recordAdd` already committed.
- **Impact:** phantom Airtable records for filings that were rolled back, and a retry
  produces a second create for the same logical request.
- **Fix:** capture each `recordAdd` return value and discard those actions in the
  `catch`. `CoreLedger::revert` cannot be reused (an add has no recycle row), so add a
  `CoreLedger::discardAdd(int $actionId)`. Move `audit->record` / `notifyManagers` out of
  the guarded `try`, the way `PortalLeaveRequests::create` already does at :175.
  `PortalSubmittedReports::destroy` (:591) is the one correct example to copy.

### L-3 Critical - no transaction spans the data write and the ledger write

- **Where:** every ledgered path above. The portal connection and the `core` connection
  are separate databases, so no Laravel transaction covers both.
  `CoreLedger::recordEdit` (:29-50) is not even wrapped in a core transaction, unlike
  `recordAdd` (:135), `recycleDeleted` (:74), and `revert` (:166).
  `WorkspaceWriter.php:113` autocommits the cell change before the ledger insert at :116.
  `PortalLeaveRequests::replace/cancel` (:236/:293) and `PortalOvertimeRequests::cancel`
  (:314) open no transaction at all.
- **Impact:** a crash, timeout, or core-DB error between the two statements leaves a
  committed change with no action. The sync never learns of it, and nothing can detect
  it later because the evidence is an absence. Overtime cannot even be refiled - the
  duplicate-date check at `PortalOvertimeRequests.php:112` rejects it.
- **Fix (pick one, this is a design decision):**
  1. Both databases are on the same MySQL server - run the pair in one transaction on
     the portal connection and insert into `ikaika_platform.actions` cross-schema; or
  2. Keep separate connections and add a reconciliation pass to the sync that finds rows
     newer than the last sync with no matching action; or
  3. Write intent first, stamp it after (outbox), so a crash leaves a visible pending row.
  At minimum wrap every update-then-ledger pair in a portal transaction with the ledger
  call last inside it, so a ledger failure rolls the data back.
- **Note:** `WorkspaceWriteTest.php:21-26` transacts both connections itself, which hides
  this in tests.

### L-4 High - hard deletes with no delete action and no recycle snapshot

- **Where:** `PortalLeaveRequests.php:754`, `PortalOvertimeRequests.php:970-971`,
  `PortalOffsetRequests.php:1021-1022`, `PortalReimbursementRequests.php:1226-1232`, and
  `replace()` removing claim items at `PortalReimbursementRequests.php:301`.
- **Impact:** Airtable keeps records SQL deleted, forever. Shrinking a three-item claim to
  one leaves two live Airtable records.
- **Fix:** route real user deletes through `recycleDeleted` per row. Compensation deletes
  (rollback of the create) pair with `discardAdd` from L-2 instead.

## 2. Writes that never reach the ledger

### L-5 Critical - approver decisions are not ledgered (spot-checked)

- **Where:** `PortalManageRequests.php:252` (requests status), `:294` (reimbursement
  line status), `:368` (flag decision on employees_user_reports). The class does not
  inject `CoreLedger` at all; it only writes `portal.logs` via `PortalAudit` (:98, :192).
- **Measured:** 235 decided requests (226 Approved, 9 Rejected); 0 actions carry a
  decision; the approver (employee 12) never appears as an `actor_id`.
- **Impact:** every approval and rejection stays Pending in Airtable. Worse, a member's
  cancel IS ledgered (`PortalLeaveRequests.php:297`), so Airtable shows whichever actor
  happened to be ledgered last.
- **Fix:** inject `CoreLedger`; thread the acting `Employee` into `applyQueuedRequest`,
  `applyClaim`, and `applyFlaggedReport`; call `recordEdit` inside the transaction at
  :88 with the owning class's resource (`resourceForKind` at :409-418 already returns
  it). The flag decision needs its own resource name and its `$groupIds` in the payload,
  because one decision writes many pivot rows (L-9).
- **Also:** move the summary audit at :98 inside the transaction (it runs after commit).

### L-6 Critical - role change on Manage > Users is not ledgered

- **Where:** `PortalManageUsers.php:119` (`$target->save()`). The class injects only
  `PortalAudit`. The earlier claim that this class never writes was wrong.
- **Impact:** a role change never syncs, while the same column edited from the admin
  grid does (`WorkspaceWriter.php:116`).
- **Fix:** inject `CoreLedger`, call `recordEdit` after the save, wrap :114-148 in a
  portal transaction. Use the same `database_target` / `resource` the grid uses (L-10).

### L-7 High - receipts and payout receipts are not ledgered

- **Where:** `PortalReimbursementRequests::writeReceipt` (:1030-1048),
  `::writePayoutReceipt` (:1149-1162), reached from `PortalManageRequests.php:139`.
- **Measured:** 61 receipt and 20 payout_receipt attachment rows; 0 actions name them.
- **Impact:** proof of payment never reaches Airtable; a swapped receipt keeps the old
  URL there forever.
- **Fix:** emit a `recordEdit` for the owning reimbursement line carrying
  file_url / file_name / mime_type / size_bytes. Airtable only accepts attachments by
  public URL, so confirm the Cloudinary URL is publicly reachable (section 8).

## 3. Payloads that cannot build the Airtable record

### L-8 Critical - a reimbursement action cannot rebuild its row

- **Where:** `PortalReimbursementRequests.php:182-186`, `:311-315`, `:378-382`.
- **Measured:** all 5 reimbursement actions carry only `{id, itemCount, requestedFor}`.
- **Impact:** creates arrive in Airtable with no item, cost, qty, purpose, or team.
- **Fix:** pass the per-item array the audit call already builds (:199-208).

### L-9 Critical - one action covers many rows

- **Where:** `PortalReimbursementRequests.php:175`, `:306`, `:369` use
  `min($ids)` as the record id; `PortalManageRequests.php:368-377` flags N pivot rows
  under one id; `reports.submitted` add payloads carry
  `{id, kind, entries, remarks, submittedOn}` - no SQL line ids (0 of 22).
- **Impact:** items 2..N of a claim are never created, updated, or cancelled in Airtable;
  report adds cannot be mapped back to the `user_reports` rows (one per line) Airtable
  holds.
- **Fix:** one action per row (the leave pattern at `PortalLeaveRequests.php:153-170`),
  or carry every row id in the payload. Report adds must carry the inserted line ids -
  the delete payloads already do (`lines[].id`).

### L-10 High - `database_target` has two vocabularies (spot-checked)

- **Where:** `WorkspaceWriter.php:118` passes the env database name
  (`test_portal_database`) and a bare table name as `resource` (`employees`). Every other
  writer passes a logical constant (`portal.requests`, `portal.user_reports`) and a
  dotted resource (`requests.leave`).
- **Impact:** a mapper keyed on `database_target` drops one family, and the env name
  changes on deploy, so a mapping tested locally breaks in production.
- **Fix:** route the sync on `resource` (stable across all 63 rows) and treat
  `database_target` as metadata. Separately make `WorkspaceWriter` emit a logical target
  so the column means one thing.

### L-11 High - three payload shapes for one resource

- **Where:** grid edits (`WorkspaceWriter.php:119`) store `{table, key, changes: {col:
  {from, to}}}`; portal edits store a flat field map; cancels store 3 keys where add and
  replace store 5 (`PortalLeaveRequests.php:300-304` vs `:245-250`,
  `PortalOvertimeRequests.php:319-323` vs `:241-247`); report edits store only
  `{entries, remarks}` with no `id`.
- **Impact:** if the sync sends updates with `PUT`, Airtable clears every field not
  included ("destructive update") - a cancel would blank hours, reason, leave type.
- **Fix:** always push updates with `PATCH` (unspecified fields untouched). Give every
  resource one payload shape and one mapper keyed on `resource`.

### L-12 High - identity: `record_id` is a local id, never an Airtable id

- **Where:** `CoreLedger::insertAction` (:189-212) never records an Airtable id;
  `requests.airtable_record_id` is UNIQUE but no PHP writes it. `reports.submitted` uses
  `2026-09-23-daily` as `record_id`, which is not unique across employees.
- **Impact:** a portal-created row has no Airtable id, so the sync must create it and
  store the returned `recXXX` somewhere, or every later edit becomes a duplicate create.
  Identical `(resource, record_id)` rows repeat (`2026-08-25-daily` has three adds and
  three deletes), and four of the eleven deletes have no earlier add.
- **Fix:** write the returned id back to the SQL row's `airtable_record_id` after a
  successful create (the column already exists and is UNIQUE, so a second write-back
  collides loudly rather than duplicating). Key report identity on
  `(actor_id, record_id)` or `recycle_key`. Deletes with no matching Airtable record are a
  no-op, not an error. Consider Airtable `performUpsert` on a stable merge field as a
  second guard (section 8).

## 4. Things that must stay out of the sync

### L-13 High - portal-only tables must never be ledgered or pushed

- **Where:** `calendar_events`, `email_blocks`, `email_messages`, `logs`,
  `notifications`, `attachments` (as its own table), and `core.workspace_views`. They
  live in `sql/portal/separate.sql` or `core`, have no `airtable_record_id`, and have no
  Airtable table.
- **Correction to ADMIN_MISSING.md:165 and :174:** the P0 there says to add ledger calls
  to `WorkspaceViews`. Do not - an action with no Airtable table fails forever and blocks
  the oldest-first queue. Leave `PortalInbox`, `PortalEmail`, `PortalEmailMessages`,
  `PortalCalendarEvents`, and `WorkspaceViews` unledgered.
- **Loophole:** the admin grid can still ledger them. `WorkspaceSchema::table` (:114)
  marks any non-junction portal table writable, and `config/workspace.php` has no
  `hidden_tables.portal`.
- **Fix:** gate `$writable` at `WorkspaceSchema.php:114` on the table having an
  `airtable_record_id` column. In the sync, skip-and-report any action whose resource has
  no Airtable table.

### L-14 Medium - never point the sync at `portal.logs`

- **Where:** `PortalAudit.php:67`. `portal.logs` shares the action/resource/record_id
  vocabulary with `core.actions` but has no `synced_at`, holds 12 rows against 63, and
  logs things the ledger deliberately does not (logins).
- **Fix:** read only `App\Modules\Core\Models\Action`, as `PortalAllActions` already does.

## 5. Data that must never leave

### L-15 High - the ledger carries PII unfiltered

- **Where:** `PortalAudit::sanitizePayload` (:362-379) strips bank and government-ID
  fields, but only on `portal.logs`, which never syncs. `CoreLedger::insertAction`
  stores payloads unfiltered. `WorkspaceWriter` puts before/after values into the payload
  for any editable column, and `bank_account_number`, `sss_no`, `philhealth_no`,
  `hdmf_no`, `bank_swift_code`, `tax_identification_no` are not in
  `WorkspaceRedactor::SECRET` (:22-25). A grid edit of an email body also lands whole in
  the payload.
- **Measured:** not exercised yet - the 3 employee edits hold only an `id_no` diff.
- **Fix:** move the filter into a shared class applied inside `CoreLedger::insertAction`,
  and make private columns non-editable in the grid. Separately decide per field what
  Airtable may receive; the sync should push an allowlist of fields, never the raw
  payload.

## 6. Smaller defects on the same paths

| ID | Where | Problem | Fix |
| --- | --- | --- | --- |
| L-16 | `PortalManageRequests.php:298` | `update() === 0` read as "not found"; a repeat decision 404s and rolls back the batch | check existence separately, skip unchanged rows (`WorkspaceWriter.php:101-111` pattern) |
| L-17 | `PortalManageRequests.php:420-427` | writes `Approved`; the seeded Airtable column uses `Completed` (58 Completed, 4 Pending, 0 Approved) | settle one vocabulary or map in the sync; a single-select either rejects or grows a duplicate option |
| L-18 | `PortalOvertimeRequests.php:143-149, 241-247` | payload carries project labels, not project ids; label lookup is last-wins | put resolved project ids in the payload; Airtable links need the linked rows' `recXXX`, which the sync resolves from `projects.airtable_record_id` |
| L-19 | `WorkspaceWriter.php:97` | `expect` optional, so a stale grid edit is last-write-wins | require `expect` for every changed column |
| L-20 | `CoreLedger.php:244-249` | `assertProduct` accepts only portal and project-estimator; `core` and `transaction-tracker` are browsable | decide before any tracker writes are ledgered |

## 7. Not yet audited

- `PortalSubmittedReports` and `PortalOffsetRequests` were checked only for the L-2 /
  L-3 / L-9 patterns, not line by line.
- `PortalCalendarEvents` was confirmed portal-only (`separate.sql:57`) and unledgered;
  nothing else was checked.
- The per-table Airtable-owned vs portal-authored split was not completed. What is
  known: 20 tables in `sql/portal/schema.sql` carry `airtable_record_id`; `requests`,
  `reimbursements`, `user_reports`, and `employees` are Airtable-seeded AND written by
  the portal, so they are the tables where the two can conflict.
- Project Estimator writes are out of scope here.

## 8. Airtable API constraints the design must respect

From the Airtable Web API docs (read 2026-09-23):

- **Rate limit:** 5 requests per second per base; 50 per second per token. Exceeding it
  returns `429` and blocks for 30 seconds. The sync needs throttling and backoff.
- **Batch size:** create and update take up to 10 records per request (long-standing
  documented limit - confirm when building).
- **PATCH vs PUT:** PATCH leaves unincluded fields alone; PUT clears them. Use PATCH
  (L-11).
- **Upsert:** `performUpsert` with `fieldsToMergeOn` (1 to 3 fields): 0 matches creates,
  1 updates, more than 1 fails the request. Returns `createdRecords` / `updatedRecords`,
  which is how the sync learns the new `recXXX` to write back (L-12).
- **typecast:** off by default; best-effort string conversion when on. Leaving it off
  makes an unknown single-select value fail loudly instead of creating a new option
  (L-17).
- **Linked records** take the linked rows' Airtable ids, not SQL ids - every junction
  needs the parent's `airtable_record_id` resolved first, which is why FIFO order
  matters (a project must exist in Airtable before a report links to it).
- **Attachments** are written by public URL; Airtable copies the file.
- **Delete** is a hard delete. The agreed design (migrations/NOTE.md:259-265) is an
  archive flag instead.

## 9. Facts the lock will have to respect (design deferred)

- Several admins can press Sync at once; the page button guard in
  `all-actions.screen.controller.ts` only stops one browser double-firing.
- `actions.id` is `int unsigned AUTO_INCREMENT`; `idx_actions_unsynced (synced_at,
  created_at)` exists. The drain should order by `id`, not `created_at`, for strict FIFO.
- There are no `attempts` / `last_error` columns, so a failing row is indistinguishable
  from a pending one (ADMIN_MISSING.md:188).
- The All Actions list is cached for 30 seconds, so progress shown there lags.
- A run of 63 rows at 5 req/s and 10 records per request is seconds; a first run after
  a large backlog is not, so the lock needs the timeout noted in migrations/NOTE.md:248.

## 10. Suggested order

1. Decide L-3 (how the data write and the ledger write become atomic). Everything else
   builds on it.
2. Reset hygiene and cut-over (L-1): clear or stamp the orphaned 63 rows.
3. Close the coverage gaps (L-5, L-6, L-7, L-4) and the rollback leak (L-2).
4. Fix payload shape and identity (L-8, L-9, L-10, L-11, L-12).
5. Fence off portal-only tables and PII (L-13, L-15).
6. Then the lock, then the drain.
