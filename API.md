# IKAIKA Platform API

Give this file to frontend. Live routes are marked **live**. Everything else in the portal database already exists in SQL — if they need it, ask for the resource slug and it can be wired the same way as employees/projects.

```
{origin}{pathPrefix}/api/{channel}/{product}/{resource}
```

| | Local | Staging (use this for the frontend) |
|---|---|---|
| Origin + prefix | `http://127.0.0.1:3000` | `https://ikaikabim.com/staging/central-api` |
| Channel | `development` | `staging` |
| Portal API root | `http://127.0.0.1:3000/api/development/portal` | `https://ikaikabim.com/staging/central-api/api/staging/portal` |

WordPress owns `https://ikaikabim.com/`. Laravel only owns `/staging/central-api/`. Never call `https://ikaikabim.com/api/...` — that is a WordPress 404.

**Do not use the folder root as an API URL.** `https://ikaikabim.com/staging/central-api/` is the playground (HTML). Opening it as JSON is wrong; until `.htaccess` rewrites the directory to `index.php`, Apache returns **405 Method Not Allowed**. Confirmed working checks:

| URL | Result |
|---|---|
| `https://ikaikabim.com/staging/central-api/up` | App up |
| `https://ikaikabim.com/staging/central-api/api/staging/portal/health` | `{ "ok": true, "database": "eoxvhumy_test_portal" }` |
| `https://ikaikabim.com/staging/central-api/api/staging/core/health` | `{ "ok": true, "database": "eoxvhumy_ikaika_platform" }` |

Send `Accept: application/json`. IDs are numeric SQL `id`. `airtable_record_id` is on most rows. Airtable formulas/rollups were **not** stored (full name, KPI hours, lookup names) — compute those in the API or the UI.

---

## Products

| Product | Key | Frontend | Notes |
|---|---|---|---|
| Employee Portal | `portal` | **Yes** | Staging DB `eoxvhumy_test_portal` |
| Core | `core` | Recycle + actions | Staging DB `eoxvhumy_ikaika_platform`. Writes from portal/estimator land here, tagged by `product` |
| Project Estimator | `project-estimator` | **No** | Disabled. **503** until a DB exists |

---

## Auth

Portal data routes need a Bearer token. Health and catalog do not.

Sectioned portal routes (Manage / Users, …) live **beside** the generic resource dump, not inside it. Screens call the sectioned URL and get only the columns they render.

| Method | Staging path | Auth |
|---|---|---|
| `POST` | `/api/staging/portal/auth/login` | None. Body `{ "id_no": "260701-0020" }` |
| `GET` | `/api/staging/portal/auth/me` | `Authorization: Bearer {token}` |
| `GET` | `/api/staging/portal/manage/users` | Bearer + Admin or Executive |
| `PATCH` | `/api/staging/portal/manage/users/{id}/role` | Bearer + Admin or Executive. Body `{ "role": "Admin" }`, `{ "role": "User" }`, or `{ "role": "ProjectAdmin" }` |
| `GET` | `/api/staging/portal/reports/submitted` | Bearer. Own timesheets only. `from` / `to` as YYYY-MM-DD, max 24 months |
| `PATCH` | `/api/staging/portal/reports/submitted/{id}` | Bearer. Own timesheet group. `{id}` is `YYYY-MM-DD-daily` or `YYYY-MM-DD-late`. Today or last 7 days |
| `DELETE` | `/api/staging/portal/reports/submitted/{id}` | Bearer. Own timesheet group. Same id and window as PATCH |
| `GET` | `/api/staging/portal/administration/recycle-bin` | Bearer. Own bin for members. `type=report\|request\|project`. Admin/Executive may send `scope=all` and `employee_id` |
| `GET` | `/api/staging/portal/administration/recycle-bin/{id}` | Bearer. One deleted report's generated details. Members: own rows. Admin/Executive: any portal row |
| `POST` | `/api/staging/portal/administration/recycle-bin/{id}/restore` | Bearer. Restore a recycled submitted report. Members: own rows. Admin/Executive: any portal row |
| `GET` | `/api/staging/portal/{resource}` | Bearer |
| `GET` | `/api/staging/portal/{resource}/{id}` | Bearer |

Login only succeeds for an **Active** employee `id_no`. Response includes `token`, `token_type: Bearer`, `expires_in` (default 28800 seconds), and `employee`.

The shell header weather is not a portal route. The app reads Open-Meteo for Manila and San Jose.

---

## How every resource works (once it is live)

Paths below are after the staging prefix. Full staging URL = `https://ikaikabim.com/staging/central-api` + path.

| Method | Path | Auth | Behavior |
|---|---|---|---|
| `GET` | `/api/{channel}` | No | Product catalog + resource URLs |
| `GET` | `/api/{channel}/portal` | No | Product info, DB health, resource counts |
| `GET` | `/api/{channel}/portal/health` | No | `{ ok, database, error }`. Optional Bearer adds `session` |
| `GET` | `/api/{channel}/core/health` | No | Core DB ping |
| `GET` | `/up` | No | Process health |
| `GET` | `/api/{channel}/portal/{resource}` | Bearer | Paginated list |
| `GET` | `/api/{channel}/portal/{resource}/{id}` | Bearer | One record + named relations |

`{channel}` is `staging` on Bluehost and `development` locally.

List query params: `page` (default 1), `per_page` (default 20, max 100), `q` (search listed columns).

**Live today:** list returns the row only. **Show** returns the row plus relations in the “Show loads” column.

**Easy to add on request (same pattern, already in SQL):**

- New resource: `GET .../portal/{slug}` + `GET .../portal/{slug}/{id}`
- Extra `q` columns or filters (`?status=`, `?employee_id=`)
- Extra relations on show (or optionally on list)
- Nested “by parent” routes, e.g. `/projects/{id}/employees`

**Not built for any model yet:** `PUT` / `PATCH` / `DELETE` on records, file downloads, Airtable sync.

---

## Portal models

### Live

#### Manage / Users — **live** (sectioned)

Sidebar: **Manage → Users**. Laravel: `app/Http/Controllers/Api/Portal/Manage/UsersController.php`.

`GET /api/development/portal/manage/users`  
`PATCH /api/development/portal/manage/users/{id}/role`

Skinny roster for the Users screen. List query: `page` (default 1), `per_page` (allow-listed **10 / 25 / 50 / 100**, default **25**), `q` (every word must match name, email, id_no, department, job_title, nickname), `sort` (`name` / `email` / `department` / `title` / `status` / `access`) and `dir` (`asc` / `desc`). Unknown sizes fall back to 25. Default order is executive, then admin, then last name. Response includes `counts` (admins / members / active / inactive / total) from one SQL aggregate — do not count the page in the client. `meta` is the current page.

Columns only: `id`, `id_no`, `first_name`, `last_name`, `email`, `department`, `job_title`, `status`, `role`, `role_level`, plus computed `is_admin`, `is_executive`, `is_locked`.

Roles: `role_level` Executive is highest and **cannot be changed**. PATCH body `{ "role": "Admin" }`, `{ "role": "User" }`, or `{ "role": "ProjectAdmin" }`. Refuses self-demote, last admin, and assigning Executive. GET and PATCH require JWT plus Admin or Executive — frontend role edits do not bypass this.

Signed-in users who are not Admin/Executive, and anyone who tries to change an Executive, get `403` with `message`, `warning`, and `notified`. Administrators are emailed (rate-limited 15 min per actor + method + path). Unauthenticated `401`s and policy refusals (self-demote, last admin) do not email. Cached 30s; writes bump the cache version. Rate limit 60/min reads, 20/min writes, keyed by employee id.

#### Reports / Submitted — **live** (sectioned)

Sidebar: **Reports → Submitted Reports**. Laravel: `app/Http/Controllers/Api/Portal/Reports/SubmittedController.php`. Calendar, not a paged table.

`GET /api/development/portal/reports/submitted`  
`PATCH /api/development/portal/reports/submitted/{id}`  
`DELETE /api/development/portal/reports/submitted/{id}`

The signed-in member's own `user_reports` lines, grouped by `report_date` and daily/late into `{ id, referenceCode, memberName, kind, submittedOn, reason, entries[] }`. JWT required; **not** Admin-only. `employee_id` is ignored — there is no way to read or change someone else's timesheet here.

Query: `from` / `to` as `YYYY-MM-DD`. Default window is today back 24 months. A wider span is clamped to 24 months. Invalid dates are `422`. Search, type filter, and entry paging stay in the client because the calendar needs every day in the window.

Each entry is one timesheet line: `projectLabel`, `activityLabel`, `earnCodeLabel`, `hoursRendered`, `elementChange`. Labels come from batched joins on `projects`, `activity_codes`, and `earn_codes` — never per-row queries. `kind` is `late` when `late_submission` is set to anything other than empty / No / On Time. `user_reports.report_date` is indexed (`idx_user_reports_date`).

`leaveDays` and `offsetDays` are YYYY-MM-DD lists for the signed-in member. Leave is a `requests` row with no offset pair and no hours (Pending / Approved / Rejected all occupy the day; Cancelled does not). Offset occupies both `original_work_day` (the day actually worked, often a weekend or holiday) and `offset_work_day` (the weekday taken off). Overtime rows on the same table are ignored. The calendar uses these so those days are not missing.

`{id}` is `{submittedOn}-{kind}`, e.g. `2026-08-31-daily`. PATCH replaces every line in that group. Body `{ "entries": [{ "projectLabel", "activityLabel", "hoursRendered", "elementChange" }], "remarks": "optional" }`. Project and activity labels must match the same strings GET already returns (or `{id_no} - {name}` for an activity). Hours must be above 0, each line at most 8, the group at most 8. DELETE removes the group. Both writes are the signed-in member's own rows, only for **today or the last seven days** (the daily report date strip). Outside that window is `422`. Missing or someone else's group is `404`. Writes bump the 30s cache. Rate limit 60/min reads, 20/min writes, keyed by employee id.

DELETE also snapshots the group into **core.recycle** (`product = portal`, key `{employeeId}:{id}`) and appends **core.actions** (`action_type = delete`). PATCH appends `action_type = edit`. Restoring from the Recycle Bin re-inserts the snapshot and calls `CoreLedger::recordAdd`, which logs `action_type = add` and drops that recycle row — the original `delete` action stays. A later **add** of the same key (filing a new report for that day) also calls `recordAdd` and drops the trash, because restoring the old filing would overwrite the new one. See `sql/core/README.md`. Every future database write (not fetches) must record an action the same way, with `product` set to `portal` or `project-estimator`.

Do not return approval, approver remarks, bank, tax, or identity documents. `counts` (`reports`, `days`, `daily`, `late`, `hours`) match the grouped window. Cached 30s per employee + range. Do not use generic `GET /portal/reports`.

#### Administration / Recycle Bin — **live** (sectioned)

Sidebar: **Recycle Bin** for everyone, plus **All Recycle** under Administration for Admin/Executive. Laravel: `app/Http/Controllers/Api/Portal/Administration/RecycleBinController.php`. Paged table from **core.recycle** (`product = portal`). Restore puts a submitted report back. View reads the generated report stored on delete.

`GET /api/development/portal/administration/recycle-bin`  
`GET /api/development/portal/administration/recycle-bin/{id}`  
`POST /api/development/portal/administration/recycle-bin/{id}/restore`

JWT required; **not** Admin-only. Everyone, including Admin and Executive, defaults to their own rows (`deleted_by` = themselves). `scope` and `employee_id` on a member token are ignored. Admin and Executive may send `scope=all` (All Recycle). `employee_id` is honoured only for Admin/Executive with `scope=all`. `type` is allow-listed `report` / `request` / `project` and is honoured for every token — members still only see their own rows.

List query: `page` (default 1), `per_page` (allow-listed **10 / 25 / 50 / 100**, default **25**), `q` (every word AND across record id, recycle key, resource, employee id_no, payload title/kind/date, and employee name), `sort` (`item` / `deleted` / `employee` / `purges`) and `dir` (`asc` / `desc`). Default order is newest deleted first. Unknown sizes fall back to 25.

Rows: `id`, `recordId`, `kind`, `submittedOn`, `title`, `resource`, `type`, `deletedAt`, `purgesAt`, `employeeId`, `employeeIdNo`, `employeeName`. `type` is `report`, `request`, or `project` from the resource prefix. Do not return `payload`, `lines`, bank, tax, or identity documents. `counts.total` is the in-bin count for the current scope and type, ignoring search. `meta` is the filtered page. `employees` is the skinny All Recycle filter list (people who currently have a row in the bin) and is empty for members. `canViewAll` is true for Admin/Executive.

`{id}` is the recycle row id. GET show returns the list fields plus the stored generated report: `referenceCode`, `memberName`, `reason`, `entries` (`id`, `projectLabel`, `activityLabel`, `earnCodeLabel`, `hoursRendered`, `elementChange`). Do not return `payload` or `lines` on show. DELETE of a submitted report stores that generated report on the snapshot so View still has kind, entries, and remarks after the live row is gone. Older snapshots without `report` fall back to hours from the restore lines and Unassigned labels. Members may show or restore only their own rows; someone else's id is `404`. Admin and Executive may show or restore any portal row.

POST restore re-inserts the snapshot into `user_reports` for the original owner (from the recycle key), records `action_type = add`, and drops the recycle row. The original `delete` action stays. If a live report already occupies that day and kind, restore is `422`. Restore is not limited to the seven-day edit window — anything still in the bin can come back. Writes bump both the recycle-bin and submitted-reports caches. Rate limit 60/min reads, 20/min writes, keyed by employee id.

Estimator recycle rows (`product = project-estimator`) are not included. Cached 30s; a submitted-report DELETE or a restore bumps the version. Do not use generic `GET /core/recycle` from this screen.

#### `employees` — **live**

People. Sample: 20 rows.

`GET /api/development/portal/employees`  
`GET /api/development/portal/employees/{id}`

| | |
|---|---|
| Search `q` | `first_name`, `last_name`, `email`, `id_no`, `nickname` |
| Show loads | `projects` (pivot `role_on_project`: `member` / `support` / `pm`) |
| Fields | `id_no`, `first_name`, `last_name`, `middle_name`, `job_title`, `nickname`, `email`, `start_date`, `end_date`, `status`, `employment_status`, `date_of_birth`, `blood_type`, `address`, `personal_email`, tax/philhealth/sss/hdmf nos, `phone_number`, SL/VL credits, `profile_folder_url`, `role`, `department`, `location`, emergency contact, bank fields, `role_level` |
| Also in SQL | Manager/report links (`employee_hierarchy`); photos in `attachments` (`field_name` = `photo`, `qr_code`, IDs, bank cert, agreement) |
| Easy to add | Show `reports`, `warnings`, `reimbursements`, `managers` / `direct_reports`; filter `?status=` `?department=` `?role=`; `/employees/{id}/projects` |

Not stored: formula `name`, 3rd/5th month evaluation.

#### `projects` — **live**

Jobs. Sample: 100 of 114.

`GET /api/development/portal/projects`  
`GET /api/development/portal/projects/{id}`

| | |
|---|---|
| Search `q` | `project_name`, `project_lead_email`, `status` |
| Show loads | `clients`, `employees` (pivot `role_on_project`) |
| Fields | `project_number`, `project_name`, `department`, ledger flags, `status`, `progress_pct`, `type_of_job`, `area_sqft`, LOD fields (`lod`, `ar_lod`…), element/file size fields, `date_done`, `date_closed`, `due_date`, Forma/Teams/Planner/panoramic/Loop/Drive links, `project_lead_email`, creation/close/done approval fields, `date_modified` |
| Easy to add | Show `reports`, `requests`, `scopes`, `action_history`, `activities`; filter `?status=` `?type_of_job=` `?department=` |

Not stored: rollup hours/NOE, PM name, member names, last report date (derive from reports + employees).

#### `clients` — **live**

Sample: 6 rows.

`GET /api/development/portal/clients`

| | |
|---|---|
| Search `q` | `name`, `client_id` |
| Show loads | `projects` |
| Fields | `name`, `client_id`, `notes`, `assignee_email`, `status` |
| Easy to add | Attachments; filter `?status=` |

#### `reports` — **live** (`user_reports`)

Timesheets. Sample: 100 of 4,309.

`GET /api/development/portal/reports`

| | |
|---|---|
| Search `q` | `remarks`, `approval` |
| Show loads | `employees`, `projects` |
| Fields | `report_date`, `hours_rendered`, `change_in_elements`, `progress_per_activity_pct`, `remarks`, `late_submission`, `late_submission_approval`, `date_created`, `approval`, `approver_remarks`, `actual_work_date`, `is_support`, `end_date`, `project_test_summary`, `project_test_summary_2`, `duration` |
| Also in SQL | Links to `activity_codes`, `earn_codes`, other reports (`user_report_dependencies`) |
| Easy to add | Show `activity_codes`, `earn_codes`, `depends_on`; filter `?approval=` `?report_date=` `?employee_id=` `?project_id=` |

Not stored: employee name/dept lookups, hours multiplier, elements-per-8-hours, client rollup.

#### `requests` — **live** (`requests`)

Leave / offset. Sample: 30 of 247.

`GET /api/development/portal/requests`

| | |
|---|---|
| Search `q` | `name`, `reason`, `status`, `type` |
| Show loads | `projects` |
| Fields | `request_date`, `name`, `no_of_hours`, `original_work_day`, `offset_work_day`, `offset_hrs`, `reason`, `status`, `approver_remarks`, `date_created`, `linked_report_ids`, `type`, `category` |
| Also in SQL | `activity_codes`, `earn_codes` |
| Easy to add | Show those codes; filter `?status=` `?type=` `?category=` |

#### `reimbursements` — **live**

Sample: 50 of 56.

`GET /api/development/portal/reimbursements`

| | |
|---|---|
| Search `q` | `item`, `employee_name_input`, `status` |
| Show loads | `employees` |
| Fields | `reimb_date`, `item`, `cost`, `qty`, `purpose`, `employee_name_input`, `team`, `status`, `approver_remarks`, `date_created` |
| Also in SQL | Receipt files in `attachments` (`receipts`, `reimbursement_receipt`) |
| Easy to add | `total_cost` (`cost * qty`); receipts; filter `?status=` `?team=` |

#### `warnings` — **live**

`GET /api/development/portal/warnings`

| | |
|---|---|
| Search `q` | `description`, `status`, `violation_type` |
| Show loads | `employees` |
| Fields | `warning_date`, `description`, `status`, `violation_type`, `date_created` |
| Easy to add | Filter `?status=` `?violation_type=` |

#### `activity-codes` — **live**

`GET /api/development/portal/activity-codes`

| | |
|---|---|
| Search `q` | `name`, `id_no`, `department` |
| Fields | `name`, `id_no`, `department` |
| Easy to add | Show linked `reports` / `requests`; filter `?department=` |

#### `action-history` — **live** (`project_action_history`)

Sample: 30 of 100.

`GET /api/development/portal/action-history`

| | |
|---|---|
| Search `q` | `action_name`, `created_by`, `remarks` |
| Show loads | `projects` |
| Fields | `name` (auto number), `action_name`, `created_by`, `remarks`, `created_at` |

---

### Ready to add (SQL + sample data already there)

Ask for the slug. Same list/show/pagination/`q` pattern. These will work.

#### `project-scopes` — table `project_scopes`

Thin join record: a project’s WBS slice. Links a project to T3 activities, T4 tasks, and assignees.

| | |
|---|---|
| Suggested URL | `/api/development/portal/project-scopes` |
| Fields | `id_no`, `progress_pct` |
| Relations | `projects`, `employees` (assigned), `activities` (T3), `tasks` (T4) |
| Frontend use | Scope progress on a project; “who is assigned to this scope” |
| Not stored | `project_name`, T3/T4 names (come from related rows) |

#### `processes` — table `project_scope_t1_processes`

WBS T1. **Fully loaded** in sample.

| | |
|---|---|
| Suggested URL | `/api/development/portal/processes` |
| Fields | `wbs_code`, `process_index`, `level`, `type`, `name`, `process_wbs`, `sop_reference`, `time_trackable`, `department`, `assignee_email`, `status` |
| Relations | `procedures` (T2) via `t1_processes_t2_procedures` |
| Frontend use | Scope tree level 1; SOP browser |

#### `procedures` — table `project_scope_t2_procedures`

WBS T2. **Fully loaded** in sample.

| | |
|---|---|
| Suggested URL | `/api/development/portal/procedures` |
| Fields | `wbs_code`, `sequence_index`, `level`, `type`, `name`, `process`, `activity_wbs`, `sop_reference`, `time_trackable`, `department`, `assignee_email`, `status` |
| Relations | `processes` (T1) |
| Frontend use | Scope tree level 2 |

#### `activities` — table `project_scope_t3_activities`

WBS T3. Sample: 30 of 95.

| | |
|---|---|
| Suggested URL | `/api/development/portal/activities` |
| Fields | `wbs_code`, `activity_index`, `level`, `type`, `name`, `procedure_wbs`, `procedure`, `process_wbs`, `process`, `sop_reference`, `time_trackable`, `department`, `assignee_email`, `status` |
| Relations | `tasks` (T4), `project_scopes`, `projects` (via `projects_activity_scope`) |
| Frontend use | Activity list on a project; time-trackable WBS |

#### `tasks` — table `project_scope_t4_tasks`

WBS T4. Sample: 30 of 110.

| | |
|---|---|
| Suggested URL | `/api/development/portal/tasks` |
| Fields | `wbs_code`, `task_index`, `task_name`, `task_notes`, `task`, `activity_name`, `activity`, `procedure_name`, `procedure`, `description`, `assigned_to_email`, `start_date`, `due_date`, `time_spent_hrs`, `status`, `priority` |
| Relations | parent `activities`, `project_scopes`, `depends_on` (other tasks) |
| Frontend use | Task board / dependencies |

#### `earn-codes` — table `earn_codes`

**Fully loaded.**

| | |
|---|---|
| Suggested URL | `/api/development/portal/earn-codes` |
| Fields | `description`, `did_not_work`, `did_work` |
| Relations | `reports`, `requests` |
| Frontend use | Timesheet / leave earn-code picker |

#### `job-type-activities` — table `job_type_allowed_activities`

**Fully loaded.** Which activity codes a job type may use.

| | |
|---|---|
| Suggested URL | `/api/development/portal/job-type-activities` |
| Fields | `job_type`, `activity_code_id_initials` |
| Frontend use | Validate report activity against `projects.type_of_job` |

#### `achievements` — table `achievements_milestones`

| | |
|---|---|
| Suggested URL | `/api/development/portal/achievements` |
| Fields | `achievement_date`, `project_name`, `description`, `links` |
| Also | Attachments possible |
| Frontend use | Wins / milestone feed (not FK-linked to `projects`) |

---

### In SQL but empty or skipped in the sample

The endpoint can still be added; lists will be `data: []` until data is imported.

| Suggested slug | Table | Why empty | Fields / use |
|---|---|---|---|
| `onboarding` | `new_employee_data` | Skipped (mirrors employees) | Same shape as employees + `data_approval`. Hiring/staging queue. |
| `scope-progress` | `user_reports_scope_progress` | 0 records in source | `report_date`, `scope_progress_pct`, `reported_by` + links to project, T3, T4. Daily scope % on a project. |
| `bim-forms` | `bim_form` | 0 real records | Scan-to-BIM intake: address, size, floors, client/POC, LOD per discipline, point-cloud flags, `elements_included` (`bim_form_elements_included`). |
| `attachments` | `attachments` | Placeholder `image.png` only | Polymorphic: `table_name`, `record_id`, `field_name`, `file_url`, `file_name`, `file_size_bytes`, `mime_type`. Real files need object storage later. |

---

### Join tables (not their own resources)

Do not call these as URLs. They show up as nested arrays on **show** once the relation is enabled.

| Junction | Meaning | Pivot extras |
|---|---|---|
| `employees_projects` | Who is on a project | `role_on_project` |
| `employee_hierarchy` | Manager → direct report | — |
| `employees_reimbursements` | Reimburse-to | — |
| `employees_project_scopes` / `project_scopes_assigned_to` | Scope assignees | — |
| `employees_warnings` | Warning → employee | — |
| `employees_user_reports` | Report → employee | — |
| `projects_clients` | Project ↔ client | — |
| `projects_project_scopes` | Project ↔ scope | — |
| `projects_user_reports` | Report ↔ project | — |
| `projects_requests` | Request ↔ project | — |
| `projects_user_reports_scope_progress` | Scope-progress ↔ project | — |
| `projects_action_history` | History ↔ project | — |
| `projects_activity_scope` | Project ↔ T3 activity | — |
| `project_scopes_t3_activities` | Scope ↔ T3 | — |
| `project_scopes_t4_tasks` | Scope ↔ T4 | — |
| `t3_activities_t4_tasks` | T3 ↔ T4 | — |
| `t4_task_dependencies` | Task depends on task | — |
| `t1_processes_t2_procedures` | T1 ↔ T2 | — |
| `user_reports_activity_codes` | Report ↔ activity code | — |
| `user_reports_earn_codes` | Report ↔ earn code | — |
| `user_report_dependencies` | Report depends on report | — |
| `user_reports_scope_progress_t3_activities` | Scope-progress ↔ T3 | — |
| `user_reports_scope_progress_t4_tasks` | Scope-progress ↔ T4 | — |
| `requests_activity_codes` | Request ↔ activity code | — |
| `requests_earn_codes` | Request ↔ earn code | — |

---

## Response shapes

**List**

```json
{
  "product": "portal",
  "resource": "employees",
  "data": [{ "id": 1 }],
  "meta": { "current_page": 1, "per_page": 20, "total": 20, "last_page": 1 }
}
```

**Show** (relations only here unless we add them to list)

```json
{
  "product": "portal",
  "resource": "employees",
  "data": {
    "id": 1,
    "first_name": "Don",
    "projects": [{ "id": 1, "pivot": { "role_on_project": "pm" } }]
  }
}
```

**Errors:** `{ "message": "..." }` — `401` missing/expired token, `404` unknown product/resource/id, `503` product off or DB down.

---

## Not a portal table (other products)

### Core — **live** (recycle + actions)

`GET /api/{channel}/core/health`  
`GET /api/{channel}/core/actions` (Bearer)  
`GET /api/{channel}/core/recycle` (Bearer)  
`GET /api/{channel}/core/settings` (Bearer)

Schema: `sql/core/schema.sql`. Contract: `sql/core/README.md`.

`product` on every row is the type: `portal` or `project-estimator`. Unique recycle key is `(product, recycle_key)`, so the same date can sit in both bins.

| Table | What it stores |
|---|---|
| `actions` | JSON write log for Airtable (or other) sync. `action_type`: `add` / `edit` / `delete`. `synced_at` null until a sync job marks it. |
| `recycle` | Deleted snapshot + `purges_at`. An add with the same product + key deletes this row. |
| `settings` | `recycle.retain_days` per product (default 30). |

Portal submitted-report DELETE/PATCH already write these. New product writes must call `App\Support\Core\CoreLedger` — do not forget, or sync and restore miss the change.

| Area | Status | What frontend can expect later |
|---|---|---|
| Core users / teams / roles / permissions | Not modeled | Shared login and RBAC |
| Project Estimator `projects` | Namespace stub only | Same short name `Project`, different DB — no collision |
| Airtable sync job | Not built | Reads `core.actions` where `synced_at` is null; not a frontend source of truth |

---

## Frontend notes

1. Staging base: `https://ikaikabim.com/staging/central-api`. Local: `http://127.0.0.1:3000`.
2. Expo / app env: `EXPO_PUBLIC_API_PATH_PREFIX=/staging/central-api` and `EXPO_PUBLIC_API_CHANNEL=staging`, or `EXPO_PUBLIC_API_BASE_URL=https://ikaikabim.com/staging/central-api/api/staging/portal`.
3. Discover live URLs from `GET /api/{channel}` if you want menus to stay in sync.
4. CORS: default `api/*` allows another origin. If blocked, adjust `config/cors.php`.
5. Keep `{channel}` in one constant (`development` locally, `staging` on Bluehost, later `v1`).
6. To request a new endpoint, send the **slug** from this file (e.g. “add `activities` list/show, include `tasks` on show, filter `?project_id=`”). That is enough to implement.
