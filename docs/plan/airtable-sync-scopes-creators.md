# Plan: sync project Activity Scope, Project Scopes, and createdBy/lastModifiedBy to Airtable

Status: built 2026-10-22. Decisions: Activity Scope = allowed activities minus removals; the portal owns
Project Scopes records; lastModifiedBy = the actor of every project action.

## Goal

Fill three things in Airtable that the portal never sends today:
1. **Project/s → "Activity Scope"**: the activity codes a project uses.
2. **Project Scopes table**: one record per assigned scope task, with its project, T3 activity, T4 task,
   assignees and progress.
3. **Project/s → "createdBy" and "lastModifiedBy"**: links to the Employees who created the project and who
   last changed it. Airtable fills "creatorName" and "lastModifiedByName" itself, because they are lookups.

## Read first

- `deployment/AIRTABLE-SYNC.md`: how the sync behaves.
- `app/Support/Airtable/AirtableSyncMap.php`: the `projects` resource, link specs (`junction`, `local`, `foreign`,
  `table`, `where`, `column`, `matches` + `from`), and `changed_only` / `create_full`.
- `app/Support/Airtable/PortalAirtableSync.php`: `row()`, `links()`, `changedColumns()`.
- `app/Support/Portal/PortalProjectEditor.php`: `create`, `update`, `updatePeople`, `replaceScopes`,
  `changedFields()` (line ~868). This is where project actions are ledgered.
- `tests/Feature/PortalAirtableSyncTest.php` and `tests/Feature/PortalAirtableSyncScenariosTest.php` show the
  test patterns. Use the stateful fake there for end-to-end checks.

## Facts (checked 2026-10-22)

**Airtable fields (PROD `app8jGTzRDQt4yPIa`, same names and types in TEST)**

| Table | Field | Type |
|---|---|---|
| Project/s | Activity Scope | multipleRecordLinks to Activity Code/s; shows e.g. "8302- STRUCTURAL REVIT MODELING" |
| Project/s | createdBy, lastModifiedBy | multipleRecordLinks to Employees |
| Project/s | creatorName, lastModifiedByName | lookups (computed); never send them |
| Project/s | Project Scopes | multipleRecordLinks to Project Scopes (the reverse side) |
| Project Scopes | IDNo. | autoNumber (computed); never send it |
| Project Scopes | Project | multipleRecordLinks to Project/s |
| Project Scopes | Scope - T3 Activities | multipleRecordLinks to "Project Scope Source - T3 Activities" |
| Project Scopes | Scope - T4 Tasks | multipleRecordLinks to "Project Scope Source - T4 Tasks" |
| Project Scopes | AssignedTo | multipleRecordLinks to Employees |
| Project Scopes | Progress | percent: send a fraction 0–1, not 0–100 |

**Portal SQL (local `test_portal_database`)**

| Table | Columns | Notes |
|---|---|---|
| `projects_wbs_scopes` | project_id, wbs_code | the scopes a project picked (any tier, by WBS code) |
| `projects_scope_assignees` | project_id, wbs_code, employee_id | who is assigned to a scope |
| `projects_scope_progress` | project_id, wbs_code, employee_id, user_report_id, report_date, percent | progress per report |
| `projects_removed_activities` | | activities removed from a project |
| `projects_activity_scope` | project_id, activity_id | schema.sql junction, empty: the exporter never filled it (`migrations/NOTE.md`) |
| `project_scopes`, `project_scopes_t3_activities`, `project_scopes_t4_tasks`, `project_scopes_assigned_to`, `projects_project_scopes` | | the Airtable-mirrored Project Scopes rows (7 locally); the portal never writes them |
| `projects` | `requested_by`, `date_modified` | `requested_by` is the creator; no "last modified by" column exists |

- WBS codes map to the scope tiers through `project_scope_t3_activities.wbs_code` and
  `project_scope_t4_tasks.wbs_code`. Those rows carry `airtable_record_id` from the import.
- The ledger keeps `actor_id` (an employees.id) on every action. That is the "last modified by" person.

## Decisions to make first (ask the user)

1. **Activity Scope source:** what does a project's "activity scope" mean in the new portal? Either the project
   type's allowed activities minus `projects_removed_activities`, or the activity codes reported against it.
   Pick one before mapping.
2. **Project Scopes ownership:** should the portal own those Airtable records (create, update and delete them as
   assignments change), or only create new ones? Owning them means deleting records that HR made by hand.
3. **lastModifiedBy:** the ledger's actor on each project edit, or the approver on approval? Recommended: the
   actor of every project action.

## Work, in order

### 1. createdBy / lastModifiedBy (smallest; do first)

- **createdBy:** add a link `'createdBy' => ['table' => 'employees', 'matches' => <id-no column>, 'from' => <column>]`.
  - First check which column `requested_by` holds: an employee id, id_no or email. Match `matches` accordingly.
  - Send it only on create (`create_full`). On a changed-only update, send it only when the payload names it.
- **lastModifiedBy:** comes from the action, not the row.
  - Add support in `PortalAirtableSync::links()` for a link sourced from `$action->actor_id`, e.g. a map option
    `'from_actor' => true`. Look up that employee's `airtable_record_id` and send `[id]`.
  - Send it on every project create or update that sends anything. A blank actor (system, console) sends nothing.
- **Never send the computed fields:** creatorName and lastModifiedByName. `problems()` already refuses computed fields.
- **Tests:**
  - a project create sends createdBy and lastModifiedBy;
  - an edit sends lastModifiedBy only;
  - an actor with no Airtable id blocks with a reason, like the PM ID link does.

### 2. Activity Scope (after decision 1)

- If the source is allowed-minus-removed: compute the activity code ids in SQL and add a link resolved by a
  query rather than a junction. Either:
  - add a generic `'query'` link kind to `links()` that returns target row ids, or
  - keep `projects_activity_scope` filled by `PortalProjectEditor` whenever activities change and map it as a
    junction. This is simpler, and the table already exists in schema.sql.
- Ledger the change: `PortalProjectEditor` must put an `activityIds` key in the edit's `fields` when the scope's
  activities change. The link's `'column' => 'activityIds'`, so changed-only updates send it.
- **Tests:** create with activities, then an edit that removes one, and the Activity Scope link follows.

### 3. Project Scopes (after decision 2; largest)

- **New resource** `projects.scopes`: `sql_table` = `project_scopes`, `airtable_table` = 'Project Scopes',
  `create_full` true. Fields: `progress_pct` → Progress, sent as a fraction (add coercion if `progress_pct` is
  0–100). Links:
  - Project via `projects_project_scopes`;
  - Scope - T3 Activities via `project_scopes_t3_activities`;
  - Scope - T4 Tasks via `project_scopes_t4_tasks`;
  - AssignedTo via `project_scopes_assigned_to`.
- **Writer:** where the portal assigns scopes (`PortalProjectEditor::replaceScopes`, the Manage Scopes assignee
  code, and the progress written from reports):
  - upsert a `project_scopes` row per (project, T4 task, or T3 activity when there is no task);
  - fill its junction rows;
  - ledger `recordAdd` / `recordEdit` under `projects.scopes`, inside `ledger->within(...)` with the write;
  - on removal, `recordDelete` with `airtableRecordId` (add `'deleted_id' => 'airtableRecordId'` to the map).
- **Progress:** take the latest `projects_scope_progress.percent` per scope.
  - Throttle it: one edit per report, not one per line.
- **Existing Airtable rows:** the 7 imported ones keep their ids. Match a portal assignment to an existing row by
  (project, T3, T4) before creating a new one, so they are never duplicated.
- **Tests:**
  - assign a scope, which creates a record with its 4 links;
  - change the assignee: changed-only, only AssignedTo is sent;
  - report progress: Progress is sent as a fraction;
  - remove the scope: the record is deleted;
  - an imported row is matched and never duplicated.

## Rules that always apply

- Every write that changes a mirrored table goes through `CoreLedger` inside `ledger->within($connection, ...)`.
- A changed-only resource needs its payload to name every changed column or link (`fields` keys, or a
  `columns` list).
- Run `php artisan test --filter="Airtable|PortalProjectEditorTest|PortalManageProjectsTest"`, never the whole
  suite. Then run `vendor/bin/pint --dirty` and `graphify update .`.
- Prove the result on the TEST base: run
  `AIRTABLE_LIVE_WRITES=1 php artisan test --filter=PortalAirtableLiveRoundTripTest` after extending it. It
  refuses any base but TEST, and **never PROD**.
- Update `deployment/AIRTABLE-SYNC.md` ("Not synced, by design") once these are built.
