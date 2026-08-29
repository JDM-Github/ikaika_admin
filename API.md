# IKAIKA Platform API

Give this file to frontend. Live routes are marked **live**. Everything else in the portal database already exists in SQL — if they need it, ask for the resource slug and it can be wired the same way as employees/projects.

Base URL (local): `http://127.0.0.1:8000`

```
/api/{channel}/{product}/{resource}
```

Current channel: **`development`**. Example: `http://127.0.0.1:8000/api/development/portal/employees`

Read-only prototype. `Accept: application/json`. No auth. Playground: `/`.

IDs are numeric SQL `id`. `airtable_record_id` is on most rows for reconciliation. Airtable formulas/rollups were **not** stored (full name, KPI hours, lookup names, etc.) — those have to be computed by the API or the UI.

---

## Products

| Product | Key | Frontend | Notes |
|---|---|---|---|
| Employee Portal | `portal` | **Yes** | `test_portal_database` |
| Core | `core` | Catalog / health only | No users, teams, RBAC yet |
| Project Estimator | `project-estimator` | **No** | Disabled. **503** until a DB exists |

---

## How every resource works (once it is live)

| Method | Path | Behavior |
|---|---|---|
| `GET` | `/api/development` | Catalog of products + resource URLs |
| `GET` | `/api/development/portal` | Portal health + resource counts |
| `GET` | `/api/development/portal/health` | DB ping |
| `GET` | `/api/development/portal/{resource}` | Paginated list |
| `GET` | `/api/development/portal/{resource}/{id}` | One record + named relations |

List query params: `page` (default 1), `per_page` (default 20, max 100), `q` (search listed columns).

**Live today:** list returns the row only. **Show** returns the row plus relations in the “Show loads” column.

**Easy to add on request (same pattern, already in SQL):**

- New resource: `GET .../portal/{slug}` + `GET .../portal/{slug}/{id}`
- Extra `q` columns or filters (`?status=`, `?employee_id=`)
- Extra relations on show (or optionally on list)
- Nested “by parent” routes, e.g. `/projects/{id}/employees`

**Not built for any model yet:** `POST` / `PUT` / `PATCH` / `DELETE`, login, file downloads, Airtable sync.

---

## Portal models

### Live

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

**Errors:** `{ "message": "..." }` — `404` unknown product/resource/id, `503` product off or DB down.

---

## Not a portal table (other products)

| Area | Status | What frontend can expect later |
|---|---|---|
| Core users / teams / roles / permissions / audit | Not modeled | Shared login and RBAC |
| Project Estimator `projects` | Namespace stub only | Same short name `Project`, different DB — no collision |
| Airtable sync | Not built | Admin preview/export, not a frontend source of truth |

---

## Frontend notes

1. Discover live URLs from `GET /api/development` if you want menus to stay in sync.
2. Point the app at `http://127.0.0.1:8000` (or the `artisan serve` port).
3. CORS: default `api/*` allows another origin. If blocked, adjust `config/cors.php`.
4. Keep `{channel}` in one constant (`development` → later `v1`).
5. To request a new endpoint, send the **slug** from this file (e.g. “add `activities` list/show, include `tasks` on show, filter `?project_id=`”). That is enough to implement.
