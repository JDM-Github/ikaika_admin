# ADMIN — What Is Missing

Audit of `ikaika_admin` (Laravel backend + data workspace) against what the portal
calls, what the workspace advertises, and what the supervisor asked for. Every claim
was verified in the source.

Companion document: `PORTAL_MISSING.md` in `ikaika-portal`.

**How to use this:** §1 is red today. §3 is the Airtable sync plan. §4 and §5 are the
supervisor's requirements. §2 unblocks the portal.

---

## 1. Build health, measured today

| Metric | Result |
| --- | --- |
| `php artisan test` | **297 passed, 8 failed** (305 tests, 2960 assertions, 47s) |

### 1.1 Five failures are stale positional assertions

| Test | Expected | Actual |
| --- | --- | --- |
| `PortalViewProjectsTest::test_catalog_lists_view_projects_after_home` | `sections.1.name` = `projects` | `calendar` |
| `PortalManageReportsTest::test_catalog_lists_manage_reports_under_portal_sections` | `manage` | `projects` |
| `PortalManageRequestsTest::test_catalog_lists_manage_requests_under_portal_sections` | `manage` | `projects` |
| `PortalManageUsersTest::test_catalog_lists_manage_users_under_portal_sections` | `manage` | `projects` |
| `PortalSubmittedReportsTest::test_catalog_lists_submitted_reports_under_portal_sections` | `sections.3.name` = `reports` | `manage` |

`PortalModule::sections()` returns `home`, `calendar`, `projects`, `manage`, `reports`,
`requests`, `administration`, `user`. Sections were added and the order shifted.

**Do not renumber them.** Asserting by position means every future section breaks five
tests at once. Change them to look the section up **by name**.

- [ ] Rewrite the five catalog tests to find sections by name.

### 1.2 One real behavioural failure

`PortalApiTest::test_disabled_estimator_is_not_served` — **expected 503, received 200.**

A product marked disabled in `config/products.php` is still served. This is the only
failure reporting a genuine behavioural difference.

- [ ] Determine which side is wrong, then fix it.

### 1.3 Two failures that are test-isolation bugs — and matter anyway

| Test | Assertion | Result |
| --- | --- | --- |
| `PortalSubmittedReportsTest::test_a_member_reads_only_their_own_skinny_grouped_history` | `assertCount(2, ...)` | actual size **17** |
| `PortalSubmittedReportsTest::test_lookups_are_batched_not_per_row` | batched lookups == 5 | actual **16** |

Both insert a few rows and assert an exact count against a database that has
accumulated rows from other tests.

I cannot tell from these failures whether the member is genuinely seeing another
member's rows or the database is merely dirty. The same test's companion assertion —
`assertStringNotContainsString('OTHER-EMPLOYEE-SECRET', $encoded)` — passed, which
points to dirty data rather than a scope leak. **Do not take that as reassurance.** A
scope test that counts rows on a shared database passes on a dirty DB for the wrong
reason and fails on a clean one for no reason. It is currently protecting nothing.

- [ ] Isolate the data (transaction/refresh, or scope the count to inserted rows).
- [ ] Then confirm the ownership filter independently — assert that a *named other
      member's* rows are absent, not that a total equals a number.

---

## 2. The portal's stale paths — corrected

**An earlier draft of this section was wrong.** The endpoints exist. The portal is
calling paths the admin never served, and its Generation 1 APIs are bound to fixtures
that hide the 404. Full portal-side analysis in `PORTAL_MISSING.md` §1 and §3.

| Portal calls | Admin serves | Status |
| --- | --- | --- |
| `/submitted-reports` | `/reports/submitted` | mismatch — **4 portal call sites read fixtures** |
| `/users` | `/manage/users`, `/employees` | mismatch — Missing Reports reads fixtures |
| `/leave-requests` | `/requests/leave` | mismatch — fallback only |
| `/overtime-requests` | `/requests/overtime` | mismatch — portal caller is dead code |
| `/offset-requests` | `/requests/offset` | mismatch — portal caller is dead code |
| `/reimbursements` | `/requests/reimbursement` | mismatch — fallback only |
| `/backups` | **nothing** | genuinely unbuilt |
| `/flagged-reports` | **nothing** | genuinely unbuilt |

So the backend work here is **much smaller than it looked**: only `/backups` and
`/flagged-reports` need building. Everything else is a portal path to correct.

- [ ] `backups` — build it, or delete the portal's Backup screen and `GetBackupsApi`.
- [ ] `flagged-reports` — build it, or delete `GetFlaggedReportsApi` and its permanent
      `source: async () => []` stub.
- [ ] No ICS export route exists (`PORTAL_MISSING.md` §4). Build or drop the button.

---

## 3. Airtable synchronization — the plan

### 3.1 What exists today

The write side is **fully built and correct**. `core.actions` is explicitly designed as
the sync source — `sql/core/schema.sql:41`:

> "Every database write (add / edit / delete) — never a fetch. Airtable (or anything
> else) syncs from this table."

| Piece | State |
| --- | --- |
| `core.actions` table | built |
| `core.recycle` table | built |
| `CoreLedger` (write API) | built |
| `CoreActionType` (`add` / `edit` / `delete`) | built |
| `CoreRecycleKey` (semantic per-entity keys) | built |
| `Action` / `Recycle` models | built |
| `idx_actions_unsynced (synced_at, created_at)` | built — the drain index |
| **Airtable client** | **does not exist** |
| **Airtable credentials in `.env` / `config/services.php`** | **do not exist** |
| **Drain command / job / queue worker** | **do not exist** |
| **Anything that ever sets `synced_at`** | **nothing — it is only ever written as `null`** (`CoreLedger.php:210`) |

`app/Console/Commands/` and `app/Jobs/` do not exist. `routes/console.php` has only the
default `inspire`. So: **the ledger records everything and nothing consumes it.** The
queue is growing silently.

### 3.2 The row shape the sync will read

```
id, product, database_target, action_type, resource, record_id,
recycle_key, actor_id, actor_id_no, parameters (JSON), synced_at, created_at
```

`parameters` is a JSON **envelope** wrapping the payload (`CoreLedger::insertAction`,
lines 190-198): `database_target`, `product`, `action_type`, `resource`, `record_id`,
`recycle_key`, `actor_id`, and the inner `parameters`. The drain reads `parameters`,
not the columns, for the payload.

`product` is the catalog key (`portal`, `project-estimator`) and must never be mixed on
one row. `recycle_key` is the semantic identity — `CoreRecycleKey` produces
`{employeeId}:{date}-{kind}`. For a `delete`, the full snapshot lives in
`core.recycle.payload`, keyed by `(product, recycle_key)`.

### 3.3 THE BLOCKER — the ledger has holes

**A sync can only replay what `core.actions` records. Several writes never record.**
Verified by comparing every write site against every `CoreLedger` call site.

#### Ledgered — will sync correctly

| Write path | Actions recorded | Call sites |
| --- | --- | --- |
| Leave create / update / cancel | add, edit, edit | `PortalLeaveRequests.php:154, 237, 287` |
| Overtime create / update / cancel | add, edit, edit | `PortalOvertimeRequests.php:139, 232, 304` |
| Offset create / update / cancel | add, edit, edit | `PortalOffsetRequests.php:135, 233, 308` |
| Reimbursement create / update / cancel | add, edit, edit | `PortalReimbursementRequests.php:176, 296, 353` |
| Report submit / edit / delete / restore | add x2, edit, delete, add | `PortalSubmittedReports.php:193, 333, 402, 484` |
| Workspace grid cell edit | edit | `WorkspaceWriter.php:113` |

#### NOT ledgered — invisible to Airtable

| Write path | Evidence | Consequence if unbuilt |
| --- | --- | --- |
| **Approve / reject a request** (leave, overtime, offset, reimbursement) | `PortalManageRequests.php:217` and `:261` write `status` + `approver_remarks` by direct `->update()`. The class defines **no `CORE_TARGET` constant** — every ledger-writing class does. | **Airtable shows every request as forever pending.** The single most important business event in the portal never syncs. |
| **Create a calendar event** | `PortalCalendarEvents.php:453` `insertGetId` plus two junction inserts. Defines `CORE_RESOURCE` but **no `CORE_TARGET`**, and passes `CORE_RESOURCE` only to `audit->record()`. | Events never reach Airtable. |
| **Change a user's role** | `PortalManageUsers.php:116` `$target->save()` | Role changes diverge. |
| **Save / delete a workspace view** | `WorkspaceViews.php:114, :122, :160` | Saved views diverge. |
| Mark a notification read | `PortalInbox.php:120` | Ephemeral — **probably correct to skip**. |

The missing `CORE_TARGET` constant on `PortalManageRequests` and
`PortalCalendarEvents` is the tell: every class that ledgers has one, these two do not,
so the ledger call was intended and never written.

- [ ] **P0, and a prerequisite for everything else in §3.** Add ledger calls to
      `PortalManageRequests` (approve and reject), `PortalCalendarEvents` (create),
      `PortalManageUsers` (role change) and `WorkspaceViews` (save/delete), following
      the existing pattern exactly.
- [ ] Approvals need a new action type or a distinguishable resource. `add` / `edit` /
      `delete` does not express "approved". Decide whether `CoreActionType` grows a
      `decision` member or whether the approval rides as an `edit` of
      `requests.leave` carrying the status. **Decide before writing it** — this shapes
      the Airtable contract.

### 3.4 Phase A — close the ledger gaps

Prerequisite. Nothing else works until every write is recorded.

- [ ] Add the five missing ledger call sites (§3.3).
- [ ] Extend `CoreActionType` if approvals need their own kind (§3.3).
- [ ] Add `attempts` and `last_error` columns to `actions`. There is currently only
      `synced_at`, so a permanently failing row is indistinguishable from a pending one.
- [ ] Add an index for the failure query, or fold it into `idx_actions_unsynced`.
- [ ] Add tests asserting each new write path records an action. There are none today —
      which is why these holes went unnoticed.

### 3.5 Phase B — build the drain

Suggested shape, following the repo's existing layer conventions:

- [ ] **`app/Support/Core/AirtableClient.php`** — thin HTTP wrapper. Uses Laravel's
      `Http` facade with `connectTimeout` / `timeout` and a CA bundle, exactly as
      `PortalMicrosoftIdToken::azureHttp()` does (that class is the pattern to copy).
      Never logs the token or the base id.
- [ ] **Config** — `config/services.php` gains an `airtable` block reading
      `AIRTABLE_TOKEN`, `AIRTABLE_BASE_ID`, `AIRTABLE_TABLE_MAP` from `.env`. **Add
      `AIRTABLE_TOKEN` to `.env.example` with an empty value, never a real one.**
      Confirm `.env` is gitignored before anything is written to it.
- [ ] **`app/Modules/Core/Models/Action.php`** gains a scope:
      `unsynced()` → `whereNull('synced_at')->orderBy('id')`.
- [ ] **A queued job** that pushes one action and stamps `synced_at` on success. One
      action per job keeps a failure isolated and retryable.
- [ ] **A command** (`php artisan sync:actions`) that selects unsynced rows in `id`
      order — `id` order, not `created_at`, so an add-then-edit-then-delete sequence on
      one `recycle_key` always applies in the order it happened — and dispatches the
      jobs.
- [ ] **Idempotency.** Airtable's upsert keyed on the merge field. `recycle_key` is the
      natural key (`CoreRecycleKey` already produces one per entity) and the portal
      tables already carry `airtable_record_id` columns, so the mapping exists. Record
      the Airtable record id returned on create — there is nowhere to store it yet, so
      decide: a new column on `actions`, or a `(product, recycle_key) -> airtable_id`
      side table.
- [ ] **Deletes** read `core.recycle.payload` for the snapshot and delete by merge key.
      Do not delete by guessing.
- [ ] **Retry policy.** Exponential backoff, a max attempt count, and a dead-letter
      state that a human can query. Never mark `synced_at` on a failed push.
- [ ] **Rate limits.** Airtable caps requests per second per base. Batch up to 10
      records per call and throttle below the cap.
- [ ] **A scheduled entry** in `routes/console.php` (or `bootstrap/app.php`) so the drain
      runs without a human. `QUEUE_CONNECTION=sync` in `.env` today means the queue runs
      inline — fine for a drain command, wrong for a web request that would then block
      on Airtable. Decide before wiring the job into a request path.

### 3.6 Phase C — reconciliation before cutover

- [ ] **A dry-run mode** (`sync:actions --dry-run`) that reports what would be pushed
      without pushing. Required before the first live run.
- [ ] **A backfill decision.** `actions` currently holds the entire un-synced history.
      Draining it pushes months of writes to Airtable at once. Decide whether to replay
      it all, or mark everything before a cut-off as `synced_at = now()` and sync only
      forward.
- [ ] **A comparison run.** Pull a sample of records from both sides and diff, to prove
      the mapping before trusting it.
- [ ] **Confirm direction.** This plan assumes **local DB is the source of truth and
      Airtable is the mirror**. If anyone still writes to Airtable directly, both sides
      will fight. Confirm this with the supervisor before Phase B lands.

### 3.7 Phase D — steady state

- [ ] A monitor on the unsynced backlog — `count(*) where synced_at is null` and the age
      of the oldest row. A growing backlog with no alert is how this fails silently.
- [ ] A dead-letter view in the workspace so an admin can see what failed and why.

---

## 4. Supervisor requirement #1 — leave visibility

> *"upon requesting leave, vacation etc, may special view or calendar view yung admin to
> see if gano kadalas mag leave tong tao na to, sino sino yun mga naka leave, ilan ang
> matitira na workforce that day, ano ano, yung project na tatamaan."*

| Ask | Status |
| --- | --- |
| How often a person takes leave | **not built** |
| Who is on leave a given day | **partly built** — company-wide overlay exists |
| How much workforce remains | **not built** — no capacity concept anywhere |
| Which projects are affected | **blocked by missing data** |

### 4.1 What already exists

`PortalCalendarEvents.php` reads all requests for a window with **no actor filter**,
classifies each through `PortalSubmittedReportPresenter::occupancy()`, skips cancelled
and refused rows, and `collapseLeave()` merges consecutive leave days per member into
one `leave` event (`'id' => 'leave-'.$day['id']`). Counts come back as
`{events, holidays, leave, requests}`.

That is roughly half of "sino sino yun mga naka leave" and the right foundation.

### 4.2 The blocker — leave has no project link at all

**`PortalLeaveRequests.php` does not contain the word "project" anywhere.**

Meanwhile both sibling classes write the junction:

- `PortalOvertimeRequests.php:223-225`, `:417`, `:954`
- `PortalOffsetRequests.php:224-226`, `:442`, `:1002`

Both delete-then-insert into `projects_requests`. The junction exists
(`(project_id, request_id)`), and `LeaveRequest::projects()` is already declared as a
`belongsToMany` through it (`app/Modules/Portal/Models/LeaveRequest.php:34`).

So "anong project ang tatamaan" is blocked by **one missing write in one class**,
following a pattern two siblings already implement.

- [ ] **P0 for this feature:** make `PortalLeaveRequests::create()` accept project ids
      and write `projects_requests`, mirroring `PortalOvertimeRequests:223-225`.
- [ ] Extend the portal's Leave Request form to send them.
- [ ] Backfill decision: existing leave rows have no link. Backfill from the member's
      roster, or leave history empty?

### 4.3 Then build the view

- [ ] **Per-person frequency** — rollup over `requests` grouped by the composed `name`.
      The table has no employee FK; ownership is matched with
      `whereRaw('LOWER(TRIM(name)) = ?')` throughout. **Reuse that rule — do not invent
      a second one.**
- [ ] **Remaining workforce per day** — needs a denominator. `employees` is the only
      roster; there is no shift table, no per-day availability, no working-calendar
      table. **Get the supervisor to define "workforce that day"** (all active employees
      minus those on leave? excluding holidays and weekends?) before building. This is
      the one part that needs a product decision.
- [ ] **Affected projects** — a one-line join once §4.2 lands.
- [ ] Put it under the existing `manage` workspace section (`requires_admin`).

### 4.4 Leave credits are stored but never read

`employees` carries `sl_credits`, `remaining_sl`, `vl_credits`, `remaining_vl`. In
`app/` these appear **only** as casts (`Employee.php:26-29`). Nothing reads them,
nothing decrements them, no screen shows them.

- [ ] Decide whether the capacity view shows remaining credits. If yes, no new storage
      is needed — but something must start maintaining `remaining_*` on approval, or the
      numbers are decorative.

---

## 5. Supervisor requirement #2 — reimbursement auto-deducts from a budget envelope

> *"upon approving a reimbursement, auto deduct sa budget envelope"*

**There is no budget envelope. Anywhere. In either schema.**

### 5.1 What I checked

| Searched | Result |
| --- | --- |
| `portal` schema `projects` | **no budget column at all** |
| `portal` schema, any table | no budget, no envelope, no allocation table |
| `project_estimator` schema | `projects.budgeted_hrs NUMERIC(12,2)` — an **hours** budget, not money, on a different connection |
| `reimbursements` | `cost NUMERIC(12,2)`, `qty NUMERIC(10,2)` — but **no project FK and no envelope FK** |
| `PortalManageRequests` reimbursement branch (`:241-280`) | writes only `status` and `approver_remarks`; creates no financial record |

The only "budget" in the backend is `budgeted_hrs` in the *estimator* — a different
product, a different database, measured in hours.

### 5.2 What this means

The feature cannot start as a small change. Three product decisions come first:

1. **What is an envelope?** Per project? Per department? Per cost centre? Per quarter?
   Nothing exists to model against.
2. **Where does the money come from?** No table holds a monetary amount for anything.
3. **Which envelope does a claim draw from?** `reimbursements` has a free-text
   `team VARCHAR(100)` and a free-text `employee_name_input`, and no project link. There
   is no reliable key to deduct against.

- [ ] **Take these three questions to the supervisor before writing any code.**
- [ ] Then create the envelope table plus a deductions ledger. **Do not mutate a single
      `remaining` column in place** — approval, reversal and audit all need history, and
      `CoreLedger` already establishes the ledger pattern to follow.
- [ ] Add a nullable envelope/project FK to `reimbursements`. Existing rows have nothing
      to backfill from.
- [ ] Hook the reimbursement branch of `PortalManageRequests` (`:241-280`) to write a
      deduction on approve and a reversing entry on reject or undo. **This must also be
      ledgered (§3.3), or the envelope balance will diverge from Airtable.**
- [ ] Decide behaviour when the envelope is short — block the approval, or allow and
      flag? This changes the approval flow, so it needs an answer first.

### 5.3 One thing to get right from the start

Money must not leak outside what `WorkspaceAccess::canReadPrivate()` gates.
`reimbursements` is a registered workspace resource with
`searchable: ['item', 'employee_name_input', 'status']` — when envelope amounts are
added, confirm they are not swept into search results or the grid for a member-tier
account.

---

## 6. Resources in SQL but not registered

`API.md` records these as ready to add — tables and sample data exist, only the
`PortalModule::resources()` entries are missing:

**Ready to add:** project-scopes, processes (T1), procedures (T2), activities (T3),
tasks (T4), earn-codes, job-type-activities, achievements.

**In SQL but empty or skipped:** onboarding, scope-progress, bim-forms, attachments.

- [ ] Register the ready ones — mechanical, low risk, each widens what the workspace can
      browse.
- [ ] Decide the fate of the empty ones. An empty table in the browse list is a dead end
      for whoever clicks it.

---

## 7. Access model — verified sound

`WorkspaceAccess` has four tiers (executive, admin, project-admin, member):

| Grant | Tiers |
| --- | --- |
| `canBrowse()` | everyone except member |
| `canReadPrivate()` — government IDs, bank details, home contact | executive, admin |
| `canEdit()` | executive, admin |
| `canShareViews()` | executive, admin |

`assertCanBrowse()` 403s a member with *"The data workspace is for administrators. Your
work lives in the portal."* `RequirePortalAdmin` gates admin routes on
`PortalRole::canManageUsers()` and 401s when the `portalEmployee` attribute is absent.

Two things to keep true as §3 through §5 land:

- [ ] The new capacity view reveals who is on leave — personal data. Confirm it sits at
      `canBrowse` (admin+) and never reaches a member-tier account.
- [ ] Envelope balances are financial. `canReadPrivate` at minimum.

---

## 8. Test coverage gaps

- [ ] Nothing asserts that an approval records a ledger action — because it does not
      (§3.3). This is the gap that made the sync hole invisible.
- [ ] Nothing asserts leave writes `projects_requests` — because it does not (§4.2).
- [ ] No test asserts catalog section membership **by name**; only brittle positional
      ones, which is what let five tests rot (§1.1).
- [ ] No test covers a disabled product end to end (§1.2).
- [ ] Nothing covers a budget envelope, because it does not exist.

---

## 9. Suggested order

1. **Today** — the five positional catalog tests (§1.1); the disabled product (§1.2);
   isolate the two count-brittle tests (§1.3).
2. **This week** — §3.3 close the ledger gaps. **This is the prerequisite for the
   Airtable sync and for the budget envelope**, and it is a small, well-defined set of
   five call sites following an existing pattern.
3. **Then** — §3.4 through §3.7 build the drain, dry-run first, and decide the backfill
   cut-off before the first live push.
4. **In parallel** — §4.2 the one-line leave→project write, then §4.3 the capacity view
   once the supervisor defines "workforce that day".
5. **Blocked on the supervisor** — §5.2 the envelope. Take the three questions before
   writing code.
6. **Last** — §6 register the ready resources.
