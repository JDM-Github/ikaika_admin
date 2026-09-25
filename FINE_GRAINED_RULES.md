# Fine-Grained Rules (access permissions) — plan draft

Status: IN PROGRESS. Edit freely; lines marked `?` are open questions.

Done so far (display only, nothing enforced):
- Tables in `sql/portal/separate.sql`: `access_permissions` (catalog), `access_presets`,
  `access_toggles`, `access_grants`, `access_limits`.
- `PortalAccessGraph` works out what everyone effectively holds, live: role preset + valid gifts
  - valid limits. A gift or limit counts only while its maker may still manage that person
  (below their role, inside their Access Rules reach) and still holds the power; a gift is capped
  at the giver's current reach. Nothing is ever deleted in a chain.
- **Access** page (everyone, below Event Calendar): your powers as a folding list with search,
  one row per power: name, what it lets you do, where it came from (role, or who gave it), its
  reach, and a switch. `GET /portal/access`, `PATCH /portal/access/switches`.
- **Manage / Users → Rules → Access Rule** sheet (no separate page): for someone you manage,
  their role picker (Member / Administrator, same as the table) and one switch per power you
  hold, with its reach. `GET|PATCH /portal/manage/access/{id}`; the roster marks
  `canEditAccess` per row.
  - Switch **on**: lifts your own limits on it and the pages above it (a developer lifts
    anyone's), then gives it if they still lack it — with every tab under it you hold, and any
    page above it they lack. Someone else's limit is refused by name.
  - Switch **off**: takes back your gift of it (and everything under it), then limits it if
    their role or someone else still gives it.
  - Reach: re-gives your gift at another reach; offered only between what they keep without you
    and your own reach.
  - Every change is logged in All Logs.
- **Manage other admins** (`manage.users.access.peers`, under Access Rules): lets an admin manage
  fellow admins (role and Access Rules), and an executive fellow executives. Executives and
  developers hold it by preset; admins only when given. `min_tier` = `admin` on the catalog row,
  so a member can never hold it: their switch is disabled ("Only administrators can hold this"),
  switching it on is refused, and switching on Access Rules for a member skips it.
  - Between equals a gift or limit is judged on the maker's **base** powers (role plus what came
    from above), never on what other equals gave them, so two admins cannot keep each other's
    powers alive in a loop. An admin can therefore pass a fellow admin only what came from their
    role or from above, and a limit set by an equal does not shrink what the limited admin can
    pass on to their own equals.
  - Changing someone's powers refreshes the Users roster cache, so "Access Rule" appears or
    disappears on the right rows at once.
- Project admin is retired in the portal: not offered in either role picker; existing holders
  read as Member.
- Both places show a banner: saved, not in effect yet.
- **Developer mode** replaces the Settings preview picker and the `X-Portal-View` header.
  `is_developer` alone grants nothing: a developer works as their role (Default), bound by their
  own Access Rules, which anyone who manages their role may edit. Settings (developers only) has
  Developer / Default; Developer needs their password and lifts every rule and department scope.
  - Tied to the sign-in: every token carries a `sid`, and `developer_modes.active_session` must
    match it, so a new sign-in always starts in Default.
  - Password: the default `123` works once and must be replaced in the same request (428 until
    it is); at least 8 characters after that. Bcrypt hashes in `developer_modes`, never logged;
    changeable only while in Developer mode; 5 attempts a minute.
  - Graph: a developer's preset, default config and rank as a target come from their role
    (base tier). Only the viewer with the mode on holds everything and acts as a developer. A
    gift or limit a developer made is judged as a developer's (authority), so it survives their
    leaving the mode, and limiting a developer never touches their Developer mode.
  - Admins lost "preview as Member"; nothing replaced it for them.

- **Enforcement has started, one page at a time.**
  - Server: `PortalRules::active()` is what someone may use right now (effective powers minus
    what they switched off for themselves, a page above included); routes follow a rule with the
    `portal.rule:<key>` middleware (plain 403, no security alert, since switching yourself off
    lands there too).
  - Session: `auth/me`, login and the Developer mode switch carry `employee.rules` (rule =>
    reach). The portal re-reads it after saving the Access page and on every mode change.
  - Portal: `RouteRuleConstant.enforced` maps a page to its rule. The sidebar hides a page whose
    rule is not held; sign-in, a typed address, an inbox link or losing the rule all land on the
    first open page in sidebar order (Access always stays on).
  - Switching your own power off blocks it for you (decided: e.g. turn off a delete power until
    needed).
  - **Self reach:** anyone can narrow a reach rule of their own on the Access page (reach picker
    beside the switch, saved with Save Changes), never wider than they hold. Stored in
    `access_self_reach` (no row = as far as held); `PortalRules::active()` applies it, so
    enforcement and the session both see the narrowed reach. `PATCH /portal/access/reach`.
  - **Enforced so far: Home, Track Projects, Reports.** The Access page and Access Rule sheet banners
    name the enforced pages; the banner goes when every page is done.
  - Track Projects: `projects.track` now has a reach (member preset `own`, everyone else
    `all`, exactly today's lists). Its own read, `GET /portal/projects/track`
    (`portal.rule:projects.track`): own = projects they are on, department = those plus their
    department's, all = every project. Export keeps its rule. View Projects is parked in
    `docs/power/view-projects-plan.md`.
  - Reports: Daily and Late filing check their rule by the kind sent; Submitted Reports, Edit
    (PATCH) and Delete (DELETE) follow theirs. The filing forms' shared reads (report projects,
    submitted list, filed days) accept any one of the rules that need them
    (`portal.rule:a|b|c`). In the portal each page is in the route map; Edit, Delete and Export
    buttons and the New report choices follow their own rules. Export is hidden only (the file
    is built in the portal from a list already allowed).
  - Sub-powers (Export, Edit, Delete) are wired with their page from now on, not later.

Next: the next page to enforce, one at a time as the user names them (Missing Reports stays
with the other developer). Templates later.

## Context

Access is decided today by a handful of hard-coded rules repeated per page: `PortalRole::isAdmin`,
`is_developer`, `PortalPreview::departmentScope/inScope`, executive exclusions, and the frontend
`isAdmin` flag. Every new rule means editing several pages. The goal is one permission system:

- Every page, tab and action is a named rule in a permission tree.
- Each employee holds a set of rules, each with a reach.
- Developers, executives and admins can hand their own rules down; taking a rule away from
  someone also removes everything they handed on.
- Server enforces everything; the portal only shows what the server allows.

## Decisions already made

1. Admin can give only to **members**, never to another admin, unless they hold **Manage other
   admins** (see Done so far).
2. A member who received a rule **cannot pass it on**.
3. Department limit **depends on the giver's reach**: you can never give wider than you hold. An
   executive given "all departments" by a developer can pass "all departments" to an admin; an
   admin holding "department" can only give "department".
4. A developer **can limit an executive** (e.g. developer settings are not for executives).
5. Revoking a rule from someone removes everything they gave from it (cascade).
6. **Limits flow down too**: a developer can limit anyone's preset (executives included), an
   executive can limit an admin's preset, an admin can limit a member's baseline. Everyone can
   also take back what they themselves gave.
7. **Project admin role is retired**: it becomes a plain member; admins hand out rules instead.
8. **The rule catalog lives in a table** (`access_permissions`), not in code.
9. **Export is one rule per page** (e.g. `manage.requests.export`).
10. **Calendar event creation is a rule**, not open to everyone.
11. **The Settings preview picker (Developer / Admin / Member) is replaced** by a "My powers"
    panel: every rule the person holds with an on/off switch, plus templates. See "Self toggles
    and templates".
12. **Self toggles persist** across sign-in, stored in separate.sql tables.
13. **A project lead may always edit/delete their own project**; everything else is rule-based.
14. **Calendar event creation** preset: admin and above.
15. **ProjectAdmin rows stay as they are** (Airtable keeps the option); the portal treats the value
    as member and stops offering it in Change role. No data rewrite.
16. **The admin spreadsheet at `/` follows these rules** (`workspace.*`).
17. **A self toggle never revokes**: switching your own power off is only for your convenience;
    whatever you gave others keeps working.
18. **All Recycle stays read-only for everyone**: look, never restore. Restoring someone else's
    item can backfire on them, so there is no restore rule there; people restore their own.
19. **Airtable sync and Backup are rule-based** (preset: developer), not hard-coded.
20. **`employee_hierarchy` becomes a future reach** `team` (my direct reports).
21. **Missing Reports and the raw table API are out of scope here**: documented as a handoff for
    the other developer (see "Handoff"). Do not touch them in this work.

## Who can give to whom

| Giver | Can give to | Can give |
|---|---|---|
| Developer | anyone, including executives | any rule, any reach |
| Executive | admins, members | only rules they hold, never wider reach |
| Admin | members | only rules they hold, never wider reach |
| Member | nobody | — |

Nobody gives upward. Nobody grants themselves anything; the only change a person makes to their
own rules is switching them off and on again (self toggles), which can never add a power.

Limits follow the same table: whoever may give to someone may also limit them, within their own
reach (an MIS admin limits MIS members only).

## Model

### Reach (on every rule that shows other people's data)

- `own` — only my own rows
- `department` — my department (case-insensitive, trimmed)
- `all` — every department
- `team` — my direct reports from `employee_hierarchy` (future, decision 20)
- `executives` flag — may also see executives' rows (default off; today only developers and
  executives, and only on All Logs)

### Where a person's rules come from

1. **Role preset** (in code, not rows): member, admin, executive, developer. Day one reproduces
   exactly today's behaviour.
2. **Grants** (rows): added on top by a developer / executive / admin.
3. **Limits** (rows): take a rule (and its sub-rules) away, even one from the preset. This is how a
   developer limits an executive, or an executive takes Manage/Requests away from an admin.

### Live evaluation, not chain deletes

A grant counts only while its giver still effectively holds that rule at that reach (checked
recursively up to a developer or a preset). So:

- Admin loses Manage/Requests -> every member grant that admin made from it stops working at once.
- Admin gets it back -> those grants work again, nobody redoes anything.
- Two admins gave the same member Leave; one is revoked -> member keeps it through the other.
- Admin demoted or deactivated -> same as losing everything.
- Cycles are impossible by rule 1 and 2 (grants only flow downward).

Results cached per employee on the existing `portal:data:version` generation key.

### Self toggles and templates (replaces the Settings preview picker)

- Settings shows **My powers**: the tree of rules the person effectively holds, each with a switch.
  Switching one off hides that page/tab/action for them until they switch it back on. It can only
  narrow; it never adds a power, and it never touches what others were given from it.
- **Templates** are named sets of rules:
  - A person's own toggle templates ("Member view", "Leave only", "Everything") to flip many
    switches at once. This is how "preview as member" survives.
  - Shared grant templates ("Leave approver", "Timesheet reviewer") an admin applies to a member
    in one click instead of ticking rules one by one.
- Toggles are stored server-side, so every page and the server agree; the `X-Portal-View` header
  and `PortalPreview` are retired.
- Toggles **persist** across sign-in: they are rows in `access_toggles` (separate.sql), the
  person's own saved config.
- A toggled-off power never revokes what you gave others (decision 17).

## Tables (separate.sql, portal-only, never synced to Airtable, grid read-only)

```text
access_permissions         the catalog (decision 8)
  key PK, parent_key, label, description, page, supports_reach TINYINT,
  is_baseline TINYINT, sort_order
access_presets             what each role starts with
  role ENUM('member','admin','executive','developer'), permission_key, reach, include_executives
access_grants
  id, employee_id, permission_key, reach ENUM('own','department','all'),
  include_executives TINYINT, granted_by (employee id), created_at
access_limits
  id, employee_id, permission_key, limited_by (employee id), created_at
access_toggles             self toggles, only ever narrow
  employee_id, permission_key, created_at
access_templates
  id, name, kind ENUM('toggle','grant'), owner_employee_id (NULL = shared), created_by, created_at
access_template_rules
  template_id, permission_key, reach, include_executives
```

Presets are rows too (`access_presets`), so a developer can change what a new admin starts with
without a deploy. A new page still needs its catalog rows added in separate.sql.

## Permission tree (seeded — `sql/portal/separate.sql`, table `access_permissions`)

The SQL is the source of truth; this list is a copy for reading. 88 rules. `[R]` takes a reach.
The letters say which role presets hold it: M member, A admin, E executive, D developer.
Descriptions (what the Access page shows on each row) live in the SQL.

- `home` Home — MAED
- `projects` Projects — MAED
  - `projects.view` View Projects [R] — MAED
    - `projects.view.edit` Edit projects — AED
    - `projects.view.delete` Delete projects — AED
  - `projects.create` Add projects — AED
  - `projects.track` Track Projects — MAED
    - `projects.track.export` Export — MAED
- `reports` Reports — MAED
  - `reports.daily` Daily Report — MAED
  - `reports.late` Late Report — MAED
  - `reports.submitted` Submitted Reports — MAED
    - `reports.submitted.edit` Edit reports — MAED
    - `reports.submitted.delete` Delete reports — MAED
    - `reports.submitted.export` Export — MAED
- `requests` Requests — MAED
  - `requests.own` User Requests — MAED
    - `requests.own.edit` Edit requests — MAED
    - `requests.own.cancel` Cancel requests — MAED
    - `requests.own.export` Export — MAED
  - `requests.leave` Leave Request — MAED
  - `requests.overtime` Overtime Request — MAED
  - `requests.offset` Offset Request — MAED
  - `requests.reimbursement` Reimbursement Request — MAED
- `user` User — MAED
  - `user.logs` Logs — MAED
    - `user.logs.export` Export — MAED
  - `user.recycle` Recycle Bin — MAED
    - `user.recycle.restore` Restore — MAED
    - `user.recycle.export` Export — MAED
- `calendar` Event Calendar — MAED
  - `calendar.create` Add events — AED
  - `calendar.download` Download calendar — MAED
- `access` Access [always on] — MAED
- `manage` Manage [R] — AED
  - `manage.requests` Requests [R] — AED
    - `manage.requests.leave` Leave Requests [R] — AED
      - `manage.requests.leave.decide` Approve or reject — AED
    - `manage.requests.overtime` Overtime Requests [R] — AED
      - `manage.requests.overtime.decide` Approve or reject — AED
    - `manage.requests.offset` Offset Requests [R] — AED
      - `manage.requests.offset.decide` Approve or reject — AED
    - `manage.requests.reimbursement` Reimbursement Requests [R] — AED
      - `manage.requests.reimbursement.decide` Approve or reject — AED
      - `manage.requests.reimbursement.payout` Attach payout receipts — AED
    - `manage.requests.flagged` Flag Reports [R] — AED
      - `manage.requests.flagged.decide` Approve or reject — AED
    - `manage.requests.notified` Request alerts — AED
    - `manage.requests.export` Export — AED
  - `manage.reports` Reports [R] — AED
    - `manage.reports.export` Export — AED
  - `manage.users` Users [R] — AED
    - `manage.users.role` Change roles — AED
    - `manage.users.export` Export — AED
    - `manage.users.access` Access Rules [R] — AED
      - `manage.users.access.give` Give powers — AED
      - `manage.users.access.limit` Limit powers — AED
      - `manage.users.access.peers` Manage other admins — ED (admin and above only)
- `administration` Administration [R] — AED
  - `administration.recycle` All Recycle [R] — AED
    - `administration.recycle.export` Export — AED
  - `administration.logs` All Logs [R] — AED
    - `administration.logs.export` Export — AED
  - `administration.actions` All Actions [R] — AED
    - `administration.actions.sync` Sync to Airtable — D
    - `administration.actions.export` Export — AED
  - `administration.missing` Missing Reports [R] — AED
    - `administration.missing.email` Email reminders — AED
    - `administration.missing.mobile` Mobile reminders — AED
    - `administration.missing.export` Export — AED
  - `administration.email` Send Email — AED
    - `administration.email.send` Send emails — AED
    - `administration.email.library` Templates and footers — AED
    - `administration.email.outbox` Outbox — AED
    - `administration.email.export` Export — AED
  - `administration.backup` Backup — D
    - `administration.backup.create` Create backups — D
    - `administration.backup.download` Download backups — D
    - `administration.backup.restore` Restore backups — D
    - `administration.backup.export` Export — D
  - `administration.alerts` Access alerts — AED
- `workspace` Data Workspace — AED
  - `workspace.private` Private columns — AED
  - `workspace.edit` Edit cells — AED
  - `workspace.views` Shared views — AED
- `developer` Developer — D
  - `developer.rules` Rule catalog — D
  - `developer.flag` Developer flag — D
  - `developer.performance` Performance page — D

## Presets (day one = today)

| Rule group | Member | Admin | Executive | Developer |
|---|---|---|---|---|
| Baseline [B] | yes | yes | yes | yes |
| Manage/* | — | department | department | all |
| Administration/* (non-dev) | — | department | department | all |
| All Logs executives flag | — | off | on | on |
| access (the Access page) | yes | yes | yes | yes |
| developer.* | — | — | — | yes |

## Backend inventory — server check each rule replaces

Today there are only two server levels: signed in (`AuthenticatePortalJwt`) and `portal.admin`
(`RequirePortalAdmin`: developer or `PortalRole::canManageUsers`). Controllers check nothing;
every other rule lives in `app/Support/Portal/*`.

| Rule | Replaces |
|---|---|
| `manage.requests.*` | `portal.admin` on `manage/requests*`; `PortalManageRequests::departmentScope/scoped/assertInScope` |
| `manage.requests.reimbursement.payout` | same, plus `PortalManageRequests::uploadPayoutReceipt` scope check |
| `manage.reports` | `portal.admin` on `manage/reports`; `PortalSubmittedReports::managedSubject/roster` |
| `manage.users`, `.role` | `portal.admin` on `manage/users*`; `PortalRole::roleChangeBlock/isLocked/assignableRoles`; no department scope today |
| `administration.recycle*` | `PortalRecycleBin::canViewAll/applyDepartment/itemForActor` (route has no `portal.admin`) |
| `administration.logs` | `portal.admin`; `PortalUserLogs::visibleEmployeeIds` |
| `administration.actions` | `portal.admin` only; no scope in `PortalAllActions` |
| `administration.email.*` | `portal.admin` on `administration/emails*`, `email-blocks*`; no ownership on block edit/delete |
| `projects.view` reach | `PortalViewProjects` (`PortalRole::isAdmin` sees all, else own; ignores developer) |
| `calendar.create` | nothing today (`PortalCalendarEvents::store` has no role check) |
| `workspace.*` | `WorkspaceAccess` tiers (`ResolvesWorkspaceAccess`, `WorkspaceWriter`, `WorkspaceSchema`, `WorkspaceViews`) |
| `access` (self toggles) | `PortalPreview::actsAsDeveloper` header narrowing, once toggles replace preview |

Helpers retired at the end: `PortalRole::isAdmin/canManageUsers` as access checks (kept only for
display labels), `PortalPreview::departmentScope/inScope`, `RequirePortalAdmin`.

## Gaps found (fix inside this work, or flag)

1. **Raw table API is open to every signed-in person** (security; handed off, see "Handoff"):
   `GET /api/<channel>/portal/employees` (and every other `{product}/{resource}`, including
   `portal/reimbursements`, `core/actions`, `core/recycle`) returns whole tables to anyone with a
   login. `Employee` hides no columns, so a member can read every colleague's TIN, SSS,
   PhilHealth, bank account, address and birthday. The only portal caller is Missing Reports
   (`GetAllEmployeesApi` -> `UsersService.loadEmployees`). Fix: give Missing Reports a proper
   scoped endpoint returning names only, then lock `{product}/{resource}` to developers (or
   remove it).
2. **Admin pages render for a member who types the URL** (portal route guard missing; server still
   refuses the data). Closed in phase 3.
3. `PortalViewProjects` and `WorkspaceAccess` ignore `is_developer`.
4. `notifyManagers` and access alerts ignore department and developers.
5. Email blocks can be edited/deleted by any admin, not only their creator.
6. `employee_hierarchy` (manager -> report) exists in the schema and nothing reads it. It becomes
   the future `team` reach (decision 20).
7. The JWT carries `role` but not `role_level`/`is_developer`; the resolver must read the employee
   row, never the token.

## Implementation phases

1. **Resolver + tables, no behaviour change.** `PortalAccess` (catalog, presets, grants, limits,
   live evaluation, cache). Tests prove every current page answers exactly as today.
2. **Move pages onto the resolver one at a time**, replacing `portal.admin`,
   `PortalPreview::departmentScope/inScope` and role checks with `portal.can:<rule>` middleware
   and `PortalAccess::reach($actor, '<rule>')`. Order: Manage Requests, Manage Reports, Users,
   All Recycle, All Logs, All Actions, Missing Reports, Send Email, Backup, Projects, Calendar.
3. **Session carries resolved rules**; portal sidebar, tabs, buttons and route guards read them
   (also closes today's gap: admin pages render for a member who types the URL).
4. **Grant / limit API** with the giver rules above, every change logged in All Logs.
5. **Fine-Grained Rules editor** screen (tree with checkboxes and reach per rule, showing only
   what the editor holds), grant templates, and the Settings **My powers** panel with toggle
   templates (replaces the preview picker; `X-Portal-View` retired).

## Open questions (collected)

Answered: decisions 6-21.

- `?` **Change role** (`manage.users.role`): with rules doing the fine work, a role now only
  picks the starting preset (member / admin). Proposed:
  - Developer: may set anyone to Member or Admin (and mark Executive `?`).
  - Executive: may set members to Admin and admins back to Member.
  - Admin: may **not** change roles at all. Making a member an Admin would create a peer admin,
    which decision 1 forbids; an admin gives rules instead.
  - Unchanged: nobody changes their own role; executives stay locked except by a developer; the
    last admin cannot be demoted.
- `?` **Developer-only section** (`developer.*`): powers only the people who build the portal need.
  Candidates, each still a rule so it can be given out:
  - `developer.rules` — edit the rule catalog and role presets (`access_permissions`,
    `access_presets`).
  - `developer.flag` — mark/unmark someone as a developer (today only via separate.sql).
  - `developer.performance` — the performance debug page (dev builds only today).
  - `administration.actions.sync` — run/inspect the Airtable sync (same as decision 19).
  - `developer.raw_api` — the raw table API, once the handoff locks it.
  Which of these do you want, and anything missing?

## Handoff — for the other developer (do not implement in this work)

**Missing Reports reads the whole employees table through the raw API, which is open to every
signed-in person.**

- Endpoint: `GET /api/<channel>/{product}/{resource}` and `/{id}`
  (`app/Http/Controllers/Api/ResourceController.php`, routes in `routes/api.php`). Only
  `AuthenticatePortalJwt` protects it; no role check.
- Exposure: `portal/employees` returns every column (the `Employee` model hides none): TIN, SSS,
  PhilHealth, HDMF, bank details, address, birthday. Same for `portal/reimbursements`,
  `core/actions`, `core/recycle` and every other registered resource.
- Only portal caller: Administration / Missing Reports
  (`src/apis/endpoint/employees/get/get-all-employees.employees.api.ts` ->
  `UsersService.loadEmployees` -> `missing-reports.screen.controller.ts:147`).
- Suggested fix: a scoped `GET administration/missing-reports` (names, department, id only,
  department-scoped like the other admin pages), move Missing Reports onto it, then lock
  `{product}/{resource}` to developers (`developer.raw_api`) or remove it.

## Verification

- Phase 1: full backend suite plus a snapshot test per page comparing old and new decisions for
  every seeded employee (member, admin, executive, developer, each department).
- Each later phase: feature tests for grant, limit, cascade (revoke giver -> member loses it,
  restore giver -> member regains it), reach caps, preview, executives flag.
- Portal: typecheck, lint, tests; manual walk-through as each preset.
