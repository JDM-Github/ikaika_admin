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

## Timezones — send one on every request

**Every client must send the zone it is standing in.** The app runs on UTC and members do not.
A report date, a leave day, and the seven-day filing window are **calendar dates**, not instants,
so resolving them against the server's clock puts a Manila member a day behind their own morning
and a US member a day ahead of their own evening: the picker offers a day and the write refuses it,
or the other way round.

| Header | Example | Notes |
|---|---|---|
| `X-Portal-Timezone` | `Asia/Manila` | IANA name. **Preferred** — it carries its own daylight-saving rules |
| `X-Portal-Timezone-Offset` | `-480` | Minutes to add to local time to reach UTC, exactly JavaScript's `Date#getTimezoneOffset()`. Manila sends `-480`, New York `300` |
| `X-Portal-Location` | `Parian, Calamba City` | Optional city/area label from the device or IP fallback; never coordinates |
| `X-Portal-Location-Source` | `device` / `ip` | Optional validated source for the location label |

Send both where you can: the name is used when it resolves, the offset stands in for a runtime
whose Intl data cannot name the zone, and an unrecognised or out-of-range value falls back to the
application zone. Read them from the browser or device per request, not once at start-up — a laptop
can cross a zone between one call and the next.

Everything the API decides about a *day* is resolved in that zone: `today`, the daily report's
seven-day strip, the late report's "a previous day", the leave floor and its year-ahead ceiling,
the mutation window on PATCH and DELETE, and the default `to` on every dated list. Stored
`date_created` on a request or report is written as the filer's own wall clock, matching how the
seeded rows read; the audit trail in **core.actions** keeps server time, so ordering across members
is still comparable there.

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
| `POST` | `/api/staging/portal/auth/login/microsoft` | None. Body `{ "code", "code_verifier", "redirect_uri" }` from PKCE. Optional `{ "id_token" }` |
| `GET` | `/api/staging/portal/auth/me` | `Authorization: Bearer {token}` |
| `GET` | `/api/staging/portal/home` | Bearer. Dashboard for the signed-in member: this month's hours/reports, active project mix, recent reports, tracked projects |
| `GET` | `/api/staging/portal/calendar/holidays` | Bearer. Company holiday calendar from the estimator product |
| `GET` | `/api/staging/portal/calendar/events` | Bearer. Event Calendar: holidays, live request dates, and member-created events |
| `POST` | `/api/staging/portal/calendar/events` | Bearer. Creates a company or project event |
| `GET` | `/api/staging/portal/calendar/event-options` | Bearer. Department and member pickers for Add Event |
| `GET` | `/api/staging/portal/user/logs` | Bearer. The signed-in member's own activity logs |
| `GET` | `/api/staging/portal/projects` | Bearer. View Projects board. Admin/Executive see all projects with `isAssigned`; members see only their own |
| `GET` | `/api/staging/portal/manage/users` | Bearer + Admin or Executive |
| `PATCH` | `/api/staging/portal/manage/users/{id}/role` | Bearer + Admin or Executive. Body `{ "role": "Admin" }`, `{ "role": "User" }`, or `{ "role": "ProjectAdmin" }` |
| `GET` | `/api/staging/portal/manage/requests` | Bearer + Admin or Executive. Company-wide leave, overtime, offset, and claims |
| `GET` | `/api/staging/portal/manage/reports` | Bearer + Admin or Executive. One member's submitted timesheets plus the user picker |
| `GET` | `/api/staging/portal/reports/projects` | Bearer. The member's own projects plus the activity codes each job type allows |
| `GET` | `/api/staging/portal/reports/submitted` | Bearer. Own timesheets only. `from` / `to` as YYYY-MM-DD, max 24 months |
| `GET` | `/api/staging/portal/reports/submitted/days` | Bearer. Own filed days `{date, kind}` plus `leaveDays`, `overtimeDays`, and `offsetDays`. Same `from` / `to` window |
| `POST` | `/api/staging/portal/reports/submitted` | Bearer. Files own daily or late report. Body `{ kind, reports[] }`. One report per day |
| `PATCH` | `/api/staging/portal/reports/submitted/{id}` | Bearer. Own timesheet group. `{id}` is `YYYY-MM-DD-daily` or `YYYY-MM-DD-late`. Today or last 7 days |
| `DELETE` | `/api/staging/portal/reports/submitted/{id}` | Bearer. Own timesheet group. Same id and window as PATCH |
| `GET` | `/api/staging/portal/requests/leave` | Bearer. Own leave, the leave-type vocabulary, and the days a report already covers |
| `POST` | `/api/staging/portal/requests/leave` | Bearer. Files own leave. Body `{ leaveType, startDate, endDate, reason }`. One row per day |
| `PATCH` | `/api/staging/portal/requests/leave/{id}` | Bearer. Changes own pending leave day. Body `{ leaveType, requestDate, reason }` |
| `POST` | `/api/staging/portal/requests/leave/{id}/cancel` | Bearer. Withdraws own pending leave day. Status becomes Cancelled |
| `GET` | `/api/staging/portal/requests/overtime` | Bearer. Own overtime |
| `POST` | `/api/staging/portal/requests/overtime` | Bearer. Files own overtime. Body `{ requests[] }`. Only against a day already reported |
| `PATCH` | `/api/staging/portal/requests/overtime/{id}` | Bearer. Rewrites one pending own overtime |
| `POST` | `/api/staging/portal/requests/overtime/{id}/cancel` | Bearer. Withdraws own pending overtime. Status becomes Cancelled |
| `GET` | `/api/staging/portal/requests/offset` | Bearer. Own offset |
| `POST` | `/api/staging/portal/requests/offset` | Bearer. Files own offset. Body `{ requests[] }` |
| `PATCH` | `/api/staging/portal/requests/offset/{id}` | Bearer. Rewrites one pending own offset |
| `POST` | `/api/staging/portal/requests/offset/{id}/cancel` | Bearer. Withdraws own pending offset. Status becomes Cancelled |
| `GET` | `/api/staging/portal/administration/all-logs` | Bearer + Admin or Executive. Every member's activity logs |
| `GET` | `/api/staging/portal/administration/recycle-bin/{id}` | Bearer. One deleted report's generated details. Members: own rows. Admin/Executive: any portal row |
| `POST` | `/api/staging/portal/administration/recycle-bin/{id}/restore` | Bearer. Restore a recycled submitted report. Members: own rows. Admin/Executive: any portal row |
| `GET` | `/api/staging/portal/{resource}` | Bearer |
| `GET` | `/api/staging/portal/{resource}/{id}` | Bearer |

Login only succeeds for an **Active** employee. ID login matches `id_no`. Microsoft login redeems the Azure authorization code on the server (`PORTAL_AZURE_REDIRECT_URI` must match exactly), validates the id_token (tenant JWKS, `aud` = `PORTAL_AZURE_CLIENT_ID`, `iss` = the tenant v2 issuer), and matches `employees.first_name` + `employees.last_name` against Azure `given_name` / `family_name` (or `name`). Both issue the same portal JWT. Response includes `token`, `token_type: Bearer`, `expires_in` (default 28800 seconds), and `employee`.

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

## Workspace browser (`_schema` + `_grid`)

The page at `/` is an Airtable-style workspace over all three databases. It introspects
`information_schema` rather than the 13 Eloquent models, so every one of the 77 tables is
browsable, not just the modelled ones.

| Method | Path | Auth | Behavior |
|---|---|---|---|
| `GET` | `/api/{channel}/{product}/_schema` | Bearer | Browsable tables with exact row counts, plus this actor's `grants` |
| `GET` | `/api/{channel}/{product}/_schema/{table}` | Bearer | Every field, typed, plus column presets |
| `GET` | `/api/{channel}/{product}/_grid/{table}` | Bearer | One page of typed cells |
| `GET` | `/api/{channel}/{product}/_grid/{table}/{id}` | Bearer | One record, every field, every chip |
| `PATCH` | `/api/{channel}/{product}/_grid/{table}/{id}` | Bearer, admin | Correct scalar values on one record |
| `GET` | `/api/{channel}/{product}/_views/{table}` | Bearer | Saved views for this table |
| `POST` | `/api/{channel}/{product}/_views/{table}` | Bearer | Save or replace one of your own views |
| `DELETE` | `/api/{channel}/{product}/_views/{id}` | Bearer | Delete a view you own, or a shared one if admin |

`{product}` is `core`, `portal` or `project-estimator`. Reads are limited to 240 requests a
minute per actor and writes to 30: one browser tab paging a grid is a burst of reads, but a
cell is edited by hand.

**Access is three tiers, not one flag.** The workspace reaches every table in all three
databases, so an ordinary member has no business in it -- the portal is their tool.

| Tier | Browse | Personal columns | Edit | Share a view |
|---|---|---|---|---|
| Executive, Admin | yes | yes | yes | yes |
| ProjectAdmin | yes | locked | no | private views only |
| User | `403` | -- | -- | -- |

`_schema` returns `grants` (`{tier, label, browse, edit, private, share_views}`) so the page
states the boundary up front rather than only refusing on submit. Gating is done in the
controller, not by the `portal.admin` middleware, which emails the whole admin roster on a
refusal -- a mistyped table name is not an incident.

**Relationships are fields, not tables.** These databases were converted from Airtable, so
the shape of a relationship is buried in the table list. Four shapes are recovered:

| Shape in SQL | Becomes | Example |
|---|---|---|
| Junction table (26 of the portal's 48) | `link__{target}` chips on both parents, `kind: junction` | `projects.link__clients` |
| Junction with a qualifier column | one field per qualifier value | `link__employees__pm`, `__member`, `__support` |
| Child table holding one link back | `link__{child}` chips on the parent, `kind: child` | `bim_form.link__bim_form_elements_included` |
| A foreign key column | that same column, typed `reference`, `kind: parent` | `bim_form_elements_included.bim_form_id` |

A junction never appears in `_schema`; `GET .../_schema/employees_projects` is a `404`. A
reference is not a second column beside the integer -- it replaces it, so the column still
sorts and filters as itself. Link targets resolve from declared foreign keys first, then by
matching the junction name against the real table list, then by depluralising each segment,
which is what reaches `earn_codes_v2` from `earn_code_v2_id`.

**Filtering through a relationship.** A link column takes `eq:{target id}`, `contains:{part
of the target title}`, `empty` and `filled`, compiled to a correlated `EXISTS`. That is how
"every project this person is PM of" and "every project with no client" get asked. The chips
say who is linked; without this the grid could show that and still not answer the obvious
next question.

**Grid query params**

| Param | Default | Notes |
|---|---|---|
| `page` | `1` | |
| `per_page` | `50` | Capped at 200 |
| `cols` | `key` | `key`, `all`, or a comma-separated list. A filtered or sorted column is added to a preset |
| `q` | — | Searches the searchable columns of that table |
| `sort` | identity column, ascending | `column:asc` or `column:desc`; an unknown column falls back rather than reaching SQL |
| `filter[column]` | — | `eq:`, `ne:`, `in:a\|b\|c`, `contains:`, `starts:`, `gte:`, `lte:`, `empty`, `filled`. `empty` and `filled` take no argument. On a link column: `eq:`, `contains:`, `empty`, `filled` |

**Cell envelopes.** Every cell is `{"v": value}` so `NULL`, `""` and `0` stay distinguishable
— Airtable renders the first two identically, which is a standing source of "the data is
wrong" reports. A link cell is `{"chips": [{"id", "label"}], "more": 0, "to": "employees"}`;
chips are resolved with one batched join per column, never one per row, and capped at
`workspace.chips_per_cell` (3) on a grid, uncapped on a single record. The cell also carries
`count` (the full number behind a `+N`) and `kind`. A cell the actor may not read is
`{"locked": true}` -- present, so the grid is visibly withholding a column rather than
appearing not to have one, but never carrying a value.

**Redaction.** `password_hash`, `invite_code`, `remember_token` and anything matching
`password` / `secret` / `bcrypt` are absent for everyone, admin included -- not listed as a
field, not selectable, not filterable. Government IDs, bank details, date of birth, home
address and personal contact columns are admin-only: for a ProjectAdmin they are listed as
`locked` fields but dropped from the `SELECT`, so no value, no shape probe and no filter or
sort on them ever reaches SQL.

```json
{
  "product": "project-estimator",
  "table": "projects",
  "label": "Projects",
  "title": "project_name",
  "columns": ["id", "project_name", "link__employees__pm"],
  "data": [
    {
      "id": { "v": 1 },
      "project_name": { "v": "25401 GBC Derick" },
      "link__employees__pm": {
        "chips": [{ "id": 4, "label": "220302-0003" }],
        "more": 0, "count": 1, "to": "employees", "kind": "junction"
      }
    }
  ],
  "meta": {
    "current_page": 1, "per_page": 50, "total": 120, "unfiltered_total": 120,
    "last_page": 3, "filtered": false, "filters": {}, "q": "", "sort": "id:asc"
  },
  "sql": "select `id`, `project_name` from `projects` limit 50 offset 0"
}
```

`meta.total` and `meta.unfiltered_total` are both returned so the footer can say
`18 of 122 projects · filtered` instead of quietly showing a smaller number. `sql` is the
exact statement behind the page, surfaced in the UI under `SQL`.

### Editing a cell

`PATCH .../_grid/{table}/{id}` takes `{"changes": {"status": "Closed"}, "expect": {"status":
"Started"}}` and answers with the changed pairs, the refreshed record, and the `action_id` it
was logged as.

This is deliberately the narrowest edit worth having, because these are live production
databases and the portal already owns the curated write endpoints:

- **Scalars only.** A key, an `airtable_record_id`, a JSON column, a link and a reference are
  all refused with `422`. Repointing a foreign key is a relational change, not a corrected
  value, and belongs to the endpoint that owns the record.
- **Every change is logged.** One `core.actions` row of type `edit` per request, carrying the
  before and after of each column and the actor. An edit made here is as reviewable as one
  made through the portal.
- **`core` is never a write target.** It holds the ledger those writes go to. See
  `workspace.writable_products`.
- **`expect` is optimistic locking.** If the stored value is not what the editor last saw the
  request is refused with `409` and nothing is written.
- **Values are validated before MySQL sees them.** Length against the declared column, dates
  through Carbon, emails and URLs through `filter_var`, and a non-nullable column cannot be
  emptied -- a non-strict MySQL would otherwise truncate silently and show the editor a value
  it never stored.
- A value that did not actually move writes nothing and logs nothing.

### Saved views

A view is the grid query string plus a name, kept in `core.workspace_views`. Only `cols`,
`sort`, `q`, `per_page` and `filter[...]` survive the save -- `page` is a scroll position, not
a question. Nothing is interpreted on the way in: the grid re-validates every column and
operator when the view is opened, exactly as it does for a hand-typed URL. Saving an existing
name replaces it. A shared view is visible to everyone in the workspace and can only be
created by an admin; anyone who can browse can keep 30 private ones per table.

---

## Portal models

### Live

#### Calendar / Holidays — **live** (sectioned)

Sidebar: used by date pickers. Laravel: `app/Http/Controllers/Api/Portal/Calendar/HolidaysController.php`.

`GET /api/development/portal/calendar/holidays`

JWT required. Query `from` / `to` as `YYYY-MM-DD`. Default window is today back five years to five years ahead. Rows: `id`, `date`, `name`, `type` (`regular` / `special`). A missing estimator table degrades to an empty list. Cached 10 minutes. Rate limit 60/min.

#### Calendar / Events — **live** (sectioned)

Sidebar: **Event Calendar**. Laravel: `app/Http/Controllers/Api/Portal/Calendar/EventsController.php`.

`GET /api/development/portal/calendar/events`

JWT required; **not** Admin-only. Same `from` / `to` window as holidays. `data` is the Event Calendar feed: company holidays (`kind` `holiday`), live request dates (`kind` `leave` or `event`), and member-created rows (`kind` `event` or `project`). Consecutive leave days for the same member and type collapse to one row with `endsOn`, so the grid can draw a single bar across those days. Overtime uses `request_date`. Offset emits both `original_work_day` and `offset_work_day`. Reimbursement uses `reimb_date`. Cancelled and refused rows are omitted. Filing, editing, cancelling, or reviewing a request, or creating a calendar event, bumps this cache.

Custom events are visible when `audience` is `everyone`, the signed-in member created the row, their `department` matches a chosen department, or they are named on `members`. The creator always sees their own event.

Each row: `id`, `title`, `startsOn`, optional `endsOn` when a span lasts more than one day, `startsAt` (null for all-day), `kind` (`holiday` / `leave` / `event` / `project`), `detail`. Leave titles are `{name}: {leave type}`. Member-created ids are `calendar-{id}`. Do not return bank, tax, or identity documents. Cached 30s, keyed by member. Rate limit 60/min.

`POST /api/development/portal/calendar/events`

JWT required. Any signed-in member. Body:

```json
{
  "title": "All hands",
  "details": "Optional notes",
  "startsOn": "2026-09-10",
  "startsAt": "16:00",
  "endsOn": "2026-09-10",
  "endsAt": "17:00",
  "category": "company_event",
  "audience": "everyone",
  "departments": [],
  "memberIds": []
}
```

`category` is `company_event` or `project` (maps to calendar `kind` `event` / `project`). `audience` is `everyone`, `department`, or `members`. `startsAt` / `endsAt` are `HH:mm` or omitted for all-day. `endsOn` defaults to `startsOn`. `department` requires at least one `departments` value from the options list. `members` requires at least one active `memberIds`. 201 `{ section, resource, data }` with the presenter row. 422 with `message` when a field is missing or not allowed. Rate limit 20/min. Writes an activity log and bump the events cache.

`GET /api/development/portal/calendar/event-options`

JWT required. Skinny pickers for the Add Event form: `data.departments` as `{value,label}` from active employees' departments, `data.members` as `{id,name}` (composed first/last, no email). Cached 30s. Rate limit 60/min.

#### User / Logs — **live** (sectioned)

`GET /api/development/portal/user/logs`

The signed-in member's own activity stream. A member token is always scoped by the server to its
`employee_id`; query parameters cannot request another person's logs.

The list is paged with `page` (default `1`) and `per_page` (allow-listed `10` / `25` / `50` /
`100`, default `25`). `q` searches every word across `action`, `resource`, `record_id`,
`message`, and `ip_address`. `action` accepts `INSERT`, `PATCH`, `DELETE`, or `POST`.
`resource` is a logged activity key that already exists on this member (for example
`auth.login`). `ip` is an exact match against an IP that already appears on this member's
rows. Date filters use the member timezone: `day` (`YYYY-MM-DD`) wins; otherwise `year`
(`YYYY`) plus `month` (`01`–`12`) is that calendar month; `month` alone is every June (or
whichever month) across years; `year` alone is that year. `sort` accepts `date`, `action`,
or `resource`; `dir` accepts `asc` or `desc`. Default order is newest first.

`filters` lists only values that exist on this member's logs: `actions`, `resources`,
`ipAddresses`, `years`, `months` (`value`/`label`), and `days` (`value`/`label`). Facets
ignore the current page and the other query filters so the toolbar does not offer INSERT
or an IP that never appears.

Each row returns `id`, `action`, `resource`, `recordId`, `message`, `ipAddress`, `deviceLabel`,
`locationLabel`, `locationSource`, `createdAt`, `dateLabel`, `timeLabel`, and `createdAtLabel`. `message` preserves
`{UserName|You}` / `{UserName|Your}` for the frontend to resolve. `dateLabel` uses
`Monday, September 7, 2026`; `createdAt` is UTC ISO 8601. `ipAddress` is the request source
observed by the server, and `deviceLabel` is a readable browser and platform summary derived from
the stored user agent. `locationLabel` is the city/area reported by the device or the approximate
IP lookup, and `locationSource` is `device` or `ip`. The raw user agent, coordinates, and sanitized
audit payloads are never returned. IP-based locations are approximate. If permission is denied, the
request is local/private, or lookup fails, both location fields are `null` and the UI should show
`Location unavailable`.

#### Manage / Users — **live** (sectioned)

Sidebar: **Manage → Users**. Laravel: `app/Http/Controllers/Api/Portal/Manage/UsersController.php`.

`GET /api/development/portal/manage/users`  
`PATCH /api/development/portal/manage/users/{id}/role`

Skinny roster for the Users screen. List query: `page` (default 1), `per_page` (allow-listed **10 / 25 / 50 / 100**, default **25**), `q` (every word must match name, email, id_no, department, job_title, nickname), `sort` (`name` / `email` / `department` / `title` / `status` / `access`) and `dir` (`asc` / `desc`). Unknown sizes fall back to 25. Default order is executive, then admin, then last name. Response includes `counts` (admins / members / active / inactive / total) from one SQL aggregate — do not count the page in the client. `meta` is the current page.

Columns only: `id`, `id_no`, `first_name`, `last_name`, `email`, `department`, `job_title`, `status`, `role`, `role_level`, plus computed `is_admin`, `is_executive`, `is_locked`.

Roles: `role_level` Executive is highest and **cannot be changed**. PATCH body `{ "role": "Admin" }`, `{ "role": "User" }`, or `{ "role": "ProjectAdmin" }`. Refuses self-demote, last admin, and assigning Executive. GET and PATCH require JWT plus Admin or Executive — frontend role edits do not bypass this.

Signed-in users who are not Admin/Executive, and anyone who tries to change an Executive, get `403` with `message`, `warning`, and `notified`. Administrators are emailed (rate-limited 15 min per actor + method + path). Unauthenticated `401`s and policy refusals (self-demote, last admin) do not email. Cached 30s; writes bump the cache version. Rate limit 60/min reads, 20/min writes, keyed by employee id.

#### Manage / Requests — **live** (sectioned)

Sidebar: **Manage → Requests**. Laravel: `app/Http/Controllers/Api/Portal/Manage/RequestsController.php`.

`GET /api/development/portal/manage/requests`

Bearer plus Admin or Executive. Members receive `403`. Optional `from` / `to` as YYYY-MM-DD, same 24-month window as own leave (plus 12 months ahead). Response: `data` (queue rows), `leave`, `overtime`, `offset`, `reimbursements`, and `range`.

Queue rows: `id`, `createdOn`, `requestedOn`, `kind` (`leave` / `overtime` / `offset` / `holiday-work`), `memberName`, `offsetFromOn`, `offsetToOn`, `projectLabel`, `activityLabel`, `earnCodeLabel`, `hours`, `elementChange`, `remarks`, `status`, `approverRemarks`. Typed lists match the own-request payloads plus `memberName`. Claims match own claims (`id`, `referenceCode`, `memberName`, `submittedOn`, `status`, `approverRemarks`, `items`) without receipt URLs. Do not return bank, tax, or identity documents. Cached 30s. Rate limit 60/min.

#### Manage / Reports — **live** (sectioned)

Sidebar: **Manage → Reports**. Laravel: `app/Http/Controllers/Api/Portal/Manage/ReportsController.php`.

`GET /api/development/portal/manage/reports`

Bearer plus Admin or Executive. Members receive `403`. Same calendar envelope as `GET /reports/submitted` (`data`, `counts`, `range`, `leaveDays`, `offsetDays`) for one member, plus `employees` (`value`, `label`) and `employeeId`. Omit `employee_id` to open the signed-in admin. Unknown ids are `404`. Does not file, edit, or delete another person's reports. Cached 30s per subject. Rate limit 60/min.

#### Reports / Projects — **live** (sectioned)

Sidebar: the project and activity pickers on **Daily Report** and **Late Report**. Laravel:
`app/Http/Controllers/Api/Portal/Reports/ProjectsController.php`.

`GET /api/development/portal/reports/projects`

Only the projects the signed-in member is assigned to, through `employees_projects`, whatever
`role_on_project` they hold. `data` is `{ id, label, jobType }` — `label` is the same
`{project_number} {project_name}` string the submitted-report payloads use, so a picked value can
be posted straight back; `jobType` is `projects.type_of_job` and doubles as the discipline filter.

`activityGroups` is `{ jobType, activities[] }`, one group per job type on the member's board
rather than one code list per project: forty projects sharing eight job types would otherwise
repeat the same twenty strings forty times. A job type's codes come from
`job_type_allowed_activities.activity_code_id_initials` — a comma-separated list of leading digits
matched against `activity_codes.id_no`. A job type with no row, or a row with no initials, falls
into the `jobType: null` group, which carries the whole catalogue: a missing rule is not a reason
a member cannot report their day. Activity labels are the same strings POST and PATCH accept.

Three small reads (the pivot join, the job-type rules, the code list), cached 30s per employee.
Filing is scoped to this same board: a POST naming a project the member is not on is `422`. An
edit is not — a report already filed stays editable if the assignment is later removed.

#### Reports / Submitted — **live** (sectioned)

Sidebar: **Reports → Submitted Reports**. Laravel: `app/Http/Controllers/Api/Portal/Reports/SubmittedController.php`. Calendar, not a paged table.

`GET /api/development/portal/reports/submitted`  
`GET /api/development/portal/reports/submitted/days`  
`POST /api/development/portal/reports/submitted`  
`PATCH /api/development/portal/reports/submitted/{id}`  
`DELETE /api/development/portal/reports/submitted/{id}`

The signed-in member's own `user_reports` lines, grouped by `report_date` and daily/late into `{ id, referenceCode, memberName, kind, submittedOn, reason, entries[] }`. JWT required; **not** Admin-only. `employee_id` is ignored — there is no way to read or change someone else's timesheet here.

Query: `from` / `to` as `YYYY-MM-DD`. Default window is today back 24 months. A wider span is clamped to 24 months. Invalid dates are `422`. Search, type filter, and entry paging stay in the client because the calendar needs every day in the window.

Each entry is one timesheet line: `projectLabel`, `activityLabel`, `earnCodeLabel`, `hoursRendered`, `elementChange`. Labels come from batched joins on `projects`, `activity_codes`, and `earn_codes` — never per-row queries. `kind` is `late` when `late_submission` is set to anything other than empty / No / On Time. `user_reports.report_date` is indexed (`idx_user_reports_date`).

`leaveDays` and `offsetDays` are YYYY-MM-DD lists for the signed-in member. Leave is a `requests` row with no offset pair and no hours (Pending / Approved / Rejected all occupy the day; Cancelled does not). Offset occupies both `original_work_day` (the day actually worked, often a weekend or holiday) and `offset_work_day` (the weekday taken off). Overtime rows on the same table are ignored. The calendar uses these so those days are not missing.

`{id}` is `{submittedOn}-{kind}`, e.g. `2026-08-31-daily`. PATCH replaces every line in that group. Body `{ "entries": [{ "projectLabel", "activityLabel", "hoursRendered", "elementChange" }], "remarks": "optional" }`. Project and activity labels must match the same strings GET already returns (or `{id_no} - {name}` for an activity). Hours must be above 0, each line at most 8, the group at most 8. DELETE removes the group. Both writes are the signed-in member's own rows, only for **today or the last seven days** (the daily report date strip). Outside that window is `422`. Missing or someone else's group is `404`. Writes bump the 30s cache. Rate limit 60/min reads, 20/min writes, keyed by employee id.

DELETE also snapshots the group into **core.recycle** (`product = portal`, key `{employeeId}:{id}`) and appends **core.actions** (`action_type = delete`). PATCH appends `action_type = edit`. Restoring from the Recycle Bin re-inserts the snapshot and calls `CoreLedger::recordAdd`, which logs `action_type = add` and drops that recycle row — the original `delete` action stays. A later **add** of the same key (filing a new report for that day) also calls `recordAdd` and drops the trash, because restoring the old filing would overwrite the new one. See `sql/core/README.md`. Every future database write (not fetches) must record an action the same way, with `product` set to `portal` or `project-estimator`.

POST files a new report from the Daily Report and Late Report screens. Body is one `kind`
(`daily` or `late`) plus `reports`, up to **8** day groups of
`{ "reportDate": "YYYY-MM-DD", "entries": [...], "remarks": "optional" }` — the builder can hold
several days, and one request writes them in one transaction so a half-saved filing is not
possible. Entries use the same shape and the same rules as PATCH (project labels from the member's own
board and known activity labels, hours above 0, at most 8 per line and 8 per day, at most 20 lines per day). Daily files
against today or the last seven days; **late is any previous day** — yesterday back to the
24-month read window. Today is still daily-only. **A day holds one report of either kind** — a
second filing for a day the member already has is `422`, so the two forms cannot double the hours
on a day even when yesterday is offered on both. A day the member has **leave** filed for is also `422`: there is no
work to report. Rejected leave does not block — a refused request means they were told to work. New lines are
written with `approval = Pending` and `late_submission = Yes` for late. Each group appends
`action_type = add` and drops any recycle row under the same key, exactly as a Recycle Bin restore
does — a fresh filing must win over a stale snapshot. Response is `201` with the created groups in
the same shape GET returns.

GET `/days` is the slim companion the report-entry forms use to decide which days they may offer.
One grouped read over `report_date` and `late_submission` gives `data` as `{ date, kind }` — no
label joins — and one read of `requests` gives `leaveDays` (days a non-refused leave request
covers), `overtimeDays` (days a non-refused overtime request already claims), and `offsetDays`
(both days of a non-refused offset pair). Two queries, nothing else. Same `from` / `to` clamping
as the list, cached 30s under the same version, so a POST, PATCH or DELETE — of a report, overtime,
or offset — refreshes it. Holidays and weekends are **not** in here: both are workable days, the
forms only tint them, and holidays already have `/calendar/holidays`.

The two report forms and the overtime form read `data` in opposite directions. Daily and late must
**not** offer a day that is already in it. Overtime may offer **only** the days in it: overtime is
hours on top of a day that was worked, so a day with no report has no regular day to extend.
Offset ignores `data` and reads `offsetDays` (and `leaveDays`) instead: it is an exchange, not a
timesheet. A client that cannot reach this endpoint must fall back to offering every day rather
than none — "nothing is filed" and "we could not check" close opposite halves of the picker, and
the writes refuse a bad day either way.

Do not return approval, approver remarks, bank, tax, or identity documents. `counts` (`reports`, `days`, `daily`, `late`, `hours`) match the grouped window. Cached 30s per employee + range. Do not use generic `GET /portal/reports`.

#### Requests / Leave — **live** (sectioned)

Sidebar: **Requests → Leave Request**. Laravel: `app/Http/Controllers/Api/Portal/Requests/LeaveController.php`, `app/Support/Portal/PortalLeaveRequests.php`.

`GET /api/development/portal/requests/leave`
`POST /api/development/portal/requests/leave`

The signed-in member's own leave. JWT required; **not** Admin-only. Ownership is the composed
`first_name last_name`, the same key the occupancy reads match on, because `requests` has no
employee junction.

**One day per row.** A five-day leave in the seeded data is five rows under one reason, so the
form's start and end dates are a range the server expands, not a pair it stores. One transaction
writes them all: a five-day application cannot land as three days and a failure. `leaveToOn` is
therefore absent from these rows — each one is its own day.

`leaveType` goes in `category`, which is otherwise unused. The table has no leave-type column and
the form collects one, so this is where it lands; the list round-trips out again as
`leaveTypeLabel`. Older rows with an empty `category` read back as `Leave`.

Body: `{ "leaveType", "startDate", "endDate", "reason" }`, dates as `YYYY-MM-DD`. `leaveType` is
allow-listed against the company vocabulary the form offers (`01 Vacation Leave` … `05 Maternity or
Paternity Leave`) — move the list on both sides together. `reason` is required, at most 500
characters. The range is inclusive, at most **31 days**, and reaches 24 months back (the timesheet
read window) to **12 months ahead** — leave is booked as well as recorded, which is why this one
picker reaches forward where the report ones do not.

Two rules close a day, and both are enforced here as well as in the picker:

- **Leave already filed for it.** A refused request reopens the day, the same rule the report
  forms and overtime follow.
- **A report already filed against it.** This is the mirror of the report forms' own rule: a day
  with a report on it was worked, so asking to be away from it contradicts the record rather than
  adding to it. Weekends and holidays are **not** closed — they are only tinted, as everywhere else.

An offset's day off is deliberately **not** closed; the two are decided by different people and
overlapping them is a scheduling question rather than a data error.

Rows are classified by the same `PortalSubmittedReportPresenter::occupancy` rule the calendar uses,
so a legacy untyped row carrying `no_of_hours` stays what it is — extra time worked, not a day
away — and never appears in this history. New rows are written `type = Leave`, `status = Pending`.

GET returns `data` (`id`, `createdOn`, `requestedOn`, `leaveTypeLabel`, `remarks`, `status`,
`approverRemarks`), `types` (the vocabulary), and `reportedDays` (the days a report covers, so the
form asks once rather than per day). No `memberName` on an own row — only the approval queue needs
to say whose. `status` is lower-cased (`pending` / `approved` / `rejected` / `cancelled`) the way
every request payload sends it. Same `from` / `to` clamping as the timesheet list. Cached 30s per
employee and range.

Each day appends `action_type = add` to **core.actions** under `resource = requests.leave` and
recycle key `{employeeId}:{date}-leave`, so the Recycle Bin's `type=request` filter picks it up
without further wiring. A ledger failure rolls the inserted rows back. Filing bumps this cache and
the submitted-reports one, because leave is what closes a day to the report forms. Response is
`201` with the created rows in the same shape GET returns. Rate limit 60/min reads, 20/min writes.

`PATCH /requests/leave/{id}` changes one day: body `{ leaveType, requestDate, reason }`, same
allow-list and same conflict rules as filing, and the day it already holds is not a clash with
itself. `POST /requests/leave/{id}/cancel` withdraws one: the row **stays** and its status becomes
`Cancelled`, because the history is a record of what was asked for and a request that vanished
would read as one never filed. A cancelled day is free again, for this form and the report forms
both.

Both refuse anything already decided on (`422` — a decided request is the approver's record), and
answer `404` rather than `403` for somebody else's row: whose request it is is not this member's to
learn. Both append `action_type = edit` to **core.actions**. There is still no DELETE — nothing
here is destroyed.

Leave starts **today or later**: it is asked for, not recorded after the fact. An edit may keep a
day that has since gone by, but cannot move one further into the past. Note that the seeded
Airtable rows include leave filed days or weeks after the fact — that history is preserved and
readable, it just cannot be created through this endpoint any more.

#### Requests / Overtime — **live** (sectioned)

Sidebar: **Requests → Overtime Request**. Laravel: `app/Http/Controllers/Api/Portal/Requests/OvertimeController.php`, `app/Support/Portal/PortalOvertimeRequests.php`.

`GET /api/development/portal/requests/overtime`
`POST /api/development/portal/requests/overtime`
`PATCH /api/development/portal/requests/overtime/{id}`
`POST /api/development/portal/requests/overtime/{id}/cancel`

The signed-in member's own overtime. JWT required; **not** Admin-only. Ownership is the composed
`first_name last_name`, the same key `leaveDays` and `offsetDays` already use.

GET returns `data` (`id`, `createdOn`, `requestedOn`, `hours`, `remarks`, `status`,
`approverRemarks`) and `range`. No `memberName` on an own row. Rows are classified by the same
`PortalSubmittedReportPresenter::occupancy` rule so leave and offset never appear here. Same
`from` / `to` clamping as leave (24 months back, 12 ahead). Cached 30s per employee and range.

`POST /api/development/portal/requests/overtime`

Files the signed-in member's own overtime into `requests` (`type = Overtime`, `status = Pending`).
JWT required; **not** Admin-only. The table has no employee junction — Airtable stored the member
as `name` — so rows are written and matched under the composed `first_name last_name`, the same
key `leaveDays` and `offsetDays` already use. A profile with no name on it is `422` rather than a
row nobody can find again.

Body is `requests`, up to **8** day groups of
`{ "requestDate": "YYYY-MM-DD", "reason": "required", "entries": [...] }` — the builder holds
several days and one request writes them in one transaction. Entries use the report shape
(`projectLabel`, `activityLabel`, `hoursRendered`, `elementChange`), at most 20 per day, hours
above 0, at most 8 per line and 8 per day. Project labels must be on the member's **own board**
(`employees_projects`), the same list `/reports/projects` offers.

Three rules decide the day, and all three are enforced here as well as in the picker:

- **It must already carry the member's own report.** Overtime is extra hours on a day that was
  worked; a day with no report has nothing to extend. No report is `422`.
- **One overtime request per day.** A second would double the claim. A refused request reopens the
  day, the same rule leave follows — being told no is not a claim.
- **Today or the last seven days**, the window the eight-day strip offers.

`requests` holds one `reason` and no line items, so the entry breakdown is appended to it as one
`- project / activity / Nh` line each under the member's own words. Dropping it would leave an
approver eight hours with nothing behind them. Distinct projects are linked through
`projects_requests`; `linked_report_ids` is left null — nothing reads it yet.

Each group appends `action_type = add` to **core.actions** under `resource = requests.overtime` and
recycle key `{employeeId}:{date}-overtime`, so the Recycle Bin's `type=request` filter picks it up
without further wiring. A ledger failure rolls the inserted rows back. Filing bumps the
submitted-reports cache version, which is what `/reports/submitted/days` is cached under, so the
strip greys the day on the next read. Response is `201` with
`{ id, requestedFor, hours, reason, status, type }` per group. Rate limit 20/min, keyed by
employee id.

User Requests reads this list through the GET above, not the generic `GET /portal/requests` dump.

`PATCH /api/development/portal/requests/overtime/{id}`

Rewrites one pending day the member owns. Body is one group
`{ "requestDate", "reason", "entries" }` — the same shape as one item of POST, not a list. A
decided request is `422`. The day's current date is not a clash with itself, so an edit that
keeps it still saves. Response is `200` with the presented row.

`POST /api/development/portal/requests/overtime/{id}/cancel`

Withdraws one: the row **stays** and its status becomes `Cancelled`, because the history is a
record of what was asked for and a request that vanished would read as one never filed. A
cancelled day is free again, for this form and the report forms both. A decided request is `422`.
Somebody else's row is `404` rather than `403`. Appends `action_type = edit` to **core.actions**.

#### Requests / Offset — **live** (sectioned)

Sidebar: **Requests → Offset Request**. Laravel: `app/Http/Controllers/Api/Portal/Requests/OffsetController.php`, `app/Support/Portal/PortalOffsetRequests.php`.

`GET /api/development/portal/requests/offset`
`POST /api/development/portal/requests/offset`
`PATCH /api/development/portal/requests/offset/{id}`
`POST /api/development/portal/requests/offset/{id}/cancel`

The signed-in member's own offset. JWT required; **not** Admin-only. Ownership is the composed
`first_name last_name`, the same key `leaveDays` and `offsetDays` already use.

GET returns `data` (`id`, `createdOn`, `requestedOn` as the work day, `dayOffOn`, `hours`,
`remarks`, `status`, `approverRemarks`, `projectLabel`, `entries`) and `range`. `remarks` is the
member's own words; `entries` are the `- project / activity / Nh` lines that filing appends to
`reason`. No `memberName` on an own row. Occupancy
filters to offset rows only. Same `from` / `to` clamping as leave. Cached 30s per employee and
range.

`POST /api/development/portal/requests/offset`

Files the signed-in member's own offset into `requests` (`type = Offset`, `status = Pending`).
JWT required; **not** Admin-only. Ownership is the composed `first_name last_name`, the same key
`leaveDays` and `offsetDays` already use. A profile with no name on it is `422`.

Body is `requests`, up to **8** pairs of
`{ "workDate": "YYYY-MM-DD", "dayOffDate": "YYYY-MM-DD", "reason": "required", "entries": [...] }`
— the builder holds several exchanges and one request writes them in a single transaction.
Entries use the report shape (`projectLabel`, `activityLabel`, `hoursRendered`, `elementChange`),
at most 20 per pair, hours above 0, at most 8 per line and 8 per day. Project labels must be on
the member's **own board** (`employees_projects`), the same list `/reports/projects` offers.

Offset is an exchange, not extra hours on a reported day, so there is **no** "must already have a
report" rule. Four rules decide the pair, and all four are enforced here as well as in the pickers:

- **Two different dates.** Offsetting a day against itself is not an exchange. `422`.
- **The day worked is today or the last seven days.** The day off may also sit in that window, or
  be booked ahead up to a fortnight.
- **Neither day already carries an offset or leave.** Both the work day and the day off occupy the
  calendar (`offsetDays`). A refused offset reopens both days, the same rule leave and overtime
  follow.
- **One pair cannot reuse a day another pair in the same filing already claimed**, as work or as
  a day off.

`request_date` and `original_work_day` are the day worked; `offset_work_day` is the day off;
`no_of_hours` and `offset_hrs` both hold the hours on the work day. The entry breakdown is
appended to `reason` as one `- project / activity / Nh` line each under the member's own words.
Distinct projects are linked through `projects_requests`.

Each pair appends `action_type = add` to **core.actions** under `resource = requests.offset` and
recycle key `{employeeId}:{workDate}-offset`. A ledger failure rolls the inserted rows back.
Filing bumps the submitted-reports cache version, so `/reports/submitted/days` greys both days
on the next read. Response is `201` with
`{ id, workOn, dayOffOn, hours, reason, status, type }` per pair. Rate limit 20/min, keyed by
employee id.

User Requests reads this list through the GET above, not the generic `GET /portal/requests` dump.

`PATCH /api/development/portal/requests/offset/{id}`

Rewrites one pending pair the member owns. Body is one group
`{ "workDate", "dayOffDate", "reason", "entries" }` — the same shape as one item of POST, not a
list. A decided request is `422`. The pair's current dates are not a clash with themselves, so an
edit that keeps them still saves. Response is `200` with the presented row.

`POST /api/development/portal/requests/offset/{id}/cancel`

Withdraws one pair: the row **stays** and its status becomes `Cancelled`. Both days are free
again, for this form and the report forms both. A decided request is `422`. Somebody else's row
is `404` rather than `403`. Appends `action_type = edit` to **core.actions**.

#### Requests / Reimbursement — **live** (sectioned)

Sidebar: **Requests → Reimbursement Request**. Laravel: `app/Http/Controllers/Api/Portal/Requests/ReimbursementController.php`, `app/Support/Portal/PortalReimbursementRequests.php`.

`GET /api/development/portal/requests/reimbursement`
`POST /api/development/portal/requests/reimbursement`
`POST /api/development/portal/requests/reimbursement/receipts`
`PATCH /api/development/portal/requests/reimbursement/{id}`
`POST /api/development/portal/requests/reimbursement/{id}/cancel`

The signed-in member's own expense claims. JWT required; **not** Admin-only. Ownership is the
`employees_reimbursements` junction, so a row is the member's even when `employee_name_input` is
messy Airtable text.

**One SQL row per item.** The form lets a member add several expenses and submit once, so one
request writes every item in a single transaction under the same `date_created` stamp. GET groups
those rows back into one claim (`id` is the smallest item id). Seeded Airtable rows only carry a
date on `date_created`, so same-day items with the same status still group; a new filing writes a
datetime so two submissions a minute apart stay two claims.

`team` is the **office** the expense belongs to, the same vocabulary the old portal offered:
`Angeles Pampanga Office` and `Cebu Office`. It is **not** a department (`Structural`,
`Architectural`, …) and it is **not** `employees.location` (`Pampanga`, `Cebu`). GET returns that
pair as `teams`; POST allow-lists against it. Historical rows may still carry an older `team`
string; the picker does not.

**Any calendar date is allowed.** Reimbursement records spending rather than booking a day, so
there is no "today or later" rule. The only clamp is ten years back and a year ahead, so a typo
cannot store year 0001. Dates are `YYYY-MM-DD`.

Body: `{ "requestDate", "items": [{ "label", "cost", "quantity", "teamLabel", "purpose", "receiptId", "receiptUrl", "receiptName", "receiptMime" }] }`.
At most **20** items. `label` is required, at most 500 characters. `cost` is above zero.
`quantity` is a whole number from 1 to 9999. `teamLabel` must be one of `teams`. `purpose` is
optional, at most 500 characters. New rows are `status = Pending`.

Receipts are images (JPEG, PNG, WebP, GIF, HEIC) or PDF, at most **8 MB**.
`POST /requests/reimbursement/receipts` is `multipart/form-data` with `file`. It uploads to
Cloudinary (credentials stay on the server) and returns
`{ receiptId, fileName, fileUrl, mimeType, sizeBytes, thumbUrl }`. The id is held for an hour;
file the claim with `receiptId` on the item before it expires. An already-stored receipt on an
edit is sent back as `receiptUrl` + `receiptName` + `receiptMime` — only Cloudinary URLs from
this cloud are accepted. Stored on `attachments` (`table_name = reimbursements`,
`field_name = receipts`).

GET returns `data` (`id`, `referenceCode`, `memberName`, `submittedOn` which is `reimb_date`,
`status`, `approverRemarks`, `items` (`id`, `label`, `cost`, `quantity`, `teamLabel`, `purpose`,
`receiptName`, `receiptUrl`, `receiptMime`, `receiptThumbUrl`)), `teams`, and `range`. Seeded `Completed` claims read back as `approved` — that is
how those rows mark a paid reimbursement, and the rest of the portal speaks approved / pending /
rejected / cancelled. Cached 30s per employee and range. Default window is ten years back to a
year ahead.

Filing appends `action_type = add` to **core.actions** under `resource = requests.reimbursement`
and recycle key `{employeeId}:{date}-reimbursement-{claimId}`. A ledger failure rolls the inserted
rows and junction back. Response is `201` with the grouped claim in the same shape GET returns.
Rate limit 60/min reads, 20/min writes.

A claim nobody has decided on is still the member's to change: `PATCH /requests/reimbursement/{id}`
rewrites every item in one transaction under the stamp the filing already had, so the group stays
one claim (`id` is still the smallest item id). `POST /requests/reimbursement/{id}/cancel` sets
every item to `Cancelled`; the rows stay. A decided claim is the approver's record and returns
`422`. Somebody else's claim is `404`, not `403`. Both writes are `action_type = edit` and bump
the same cache. Rate limit 20/min.

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

#### Administration / All Logs — **live** (sectioned)

Sidebar: **Administration → All Logs**. Laravel: `app/Http/Controllers/Api/Portal/Administration/AllLogsController.php`.

`GET /api/development/portal/administration/all-logs`

Bearer plus Admin or Executive. Members receive `403`. The list is every portal log, paged like User / Logs: `page`, `per_page`, `q`, `action`, `resource`, `ip`, `year`, `month`, `day`, `sort` (`date` / `action` / `resource` / `employee`), `dir`. `employee_id` keeps rows for one member. `q` also matches first and last name.

Each row adds `employeeId` and `employeeName`. `filters.users` is `{value,label}` for members who already have a log row — not the whole roster. Other facets are the same shape as User / Logs and come from every log, not the current page. Cached 30s on the same version as User / Logs. Rate limit 60/min.

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
