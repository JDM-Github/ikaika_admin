# Fine-Grained Rules (access permissions) — plan draft

Status: DRAFT for review. Edit freely; lines marked `?` are open questions.

On approval: this file is copied to `ikaika_admin/FINE_GRAINED_RULES.md` (not committed; the user
commits). Code starts only once the open questions are answered, beginning with phase 1.

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

1. Admin can give only to **members**, never to another admin.
2. A member who received a rule **cannot pass it on**.
3. Department limit **depends on the giver's reach**: you can never give wider than you hold. An
   executive given "all departments" by a developer can pass "all departments" to an admin; an
   admin holding "department" can only give "department".
4. A developer **can limit an executive** (e.g. developer settings are not for executives).
5. Revoking a rule from someone removes everything they gave from it (cascade).

## Who can give to whom

| Giver | Can give to | Can give |
|---|---|---|
| Developer | anyone, including executives | any rule, any reach |
| Executive | admins, members | only rules they hold, never wider reach |
| Admin | members | only rules they hold, never wider reach |
| Member | nobody | — |

Nobody edits their own rules. Nobody gives upward.

## Model

### Reach (on every rule that shows other people's data)

- `own` — only my own rows
- `department` — my department (case-insensitive, trimmed)
- `all` — every department
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

### Preview

Preview = evaluate as the Admin or Member **preset** only, ignoring grants. Only ever narrows
(`X-Portal-View` stays as is).

## Tables (separate.sql, portal-only, never synced to Airtable, grid read-only)

```text
access_grants
  id, employee_id, permission_key, reach ENUM('own','department','all'),
  include_executives TINYINT, granted_by (employee id), created_at
access_limits
  id, employee_id, permission_key, limited_by (employee id), created_at
```

The permission catalog itself lives in code (one constant), so a new page is one line, not a SQL
change. `?` Or a catalog table too, so labels can be edited without a deploy?

## Permission tree (draft — from the frontend inventory)

`[B]` = baseline, every signed-in person has it by preset (listed so a developer can limit it).
`[R]` = takes a reach. Actions under a tab inherit the tab's reach.

### Home
- `home.view` [B]

### Projects
- `projects.view` [B]
  - `projects.edit` — Save/Edit details (stub today), Manage Scopes `?` today: admin or project lead
  - `projects.delete` `?` today: admin or project lead
- `projects.create` — Add New Project (stub today)
- `projects.track.view` [B] — Track Projects

### Reports (own)
- `reports.file.daily` [B]
- `reports.file.late` [B]
- `reports.submitted.view` [B]
  - `reports.submitted.edit` [B] (7-day window still applies)
  - `reports.submitted.delete` [B] (7-day window still applies)

### Requests (own)
- `requests.file.leave` [B]
- `requests.file.overtime` [B]
- `requests.file.offset` [B]
- `requests.file.reimbursement` [B]
- `requests.own.view` [B] — User Requests page
  - `requests.own.edit` [B] (pending only)
  - `requests.own.cancel` [B] (pending only)

### User
- `user.logs.view` [B] — own logs
- `user.recycle.view` [B]
  - `user.recycle.restore` [B]

### Event Calendar
- `calendar.view` [B]
  - `calendar.events.create` [B] `?` everyone can add events today — keep?
  - `calendar.download` [B] (stub)

### Manage (preset: admin, executive, developer)
- `manage.requests` [R]
  - `manage.requests.leave` [R] — view
    - `manage.requests.leave.decide`
  - `manage.requests.overtime` [R]
    - `manage.requests.overtime.decide`
  - `manage.requests.offset` [R]
    - `manage.requests.offset.decide`
  - `manage.requests.reimbursement` [R]
    - `manage.requests.reimbursement.decide`
    - `manage.requests.reimbursement.payout_receipt`
  - `manage.requests.flagged` [R]
    - `manage.requests.flagged.decide`
- `manage.reports` [R] — view other members' timesheets
- `manage.users` [R] — roster
  - `manage.users.change_role` `?` which roles may each giver assign
  - `manage.users.access` — open the Fine-Grained Rules editor for someone

### Administration (preset: admin, executive, developer)
- `administration.all_recycle` [R]
  - `administration.all_recycle.restore` `?` read-only for everyone today
- `administration.all_logs` [R] (+ `executives` flag)
- `administration.all_actions` [R]
  - `administration.all_actions.sync_airtable` (stub today) `?` developer only
- `administration.missing_reports` [R]
  - `administration.missing_reports.notify_email`
  - `administration.missing_reports.notify_mobile` (stub)
- `administration.send_email`
  - `administration.send_email.send`
  - `administration.send_email.library` — templates and footers (create/edit/delete)
  - `administration.send_email.outbox`
- `administration.backup` `?` developer only
  - `administration.backup.create` / `.download` / `.restore` (mostly stubs)

### Settings / Developer
- `settings.preview` — the View-as picker (preset: admin and above)
- `developer.*` `?` developer settings, performance debug, Airtable sync — list to be decided

### Cross-cutting
- Export on every table `?` one rule `export` [B], or per page?

## Presets (day one = today)

| Rule group | Member | Admin | Executive | Developer |
|---|---|---|---|---|
| Baseline [B] | yes | yes | yes | yes |
| Manage/* | — | department | department | all |
| Administration/* (non-dev) | — | department | department | all |
| All Logs executives flag | — | off | on | on |
| settings.preview | — | yes | `?` | yes |
| developer.* | — | — | — | yes |

### Data workspace (the grid at `/`, ikaika_admin) — today its own tiers, ignores `is_developer`
- `workspace.browse` — open the grid (today: executive, admin, project admin)
  - `workspace.private` — read private columns (tax ids, bank, address, ...) (today: executive, admin)
  - `workspace.edit` — edit cells (today: executive, admin)
  - `workspace.share_views` — save/delete shared views (today: executive, admin)

### Notifications (who receives, not who sees)
- `manage.requests.notified` [R] — inbox alert when someone files/changes a request (today: every
  active admin and executive, no department, no developer — `PortalAudit::notifyManagers`)
- `security.access_alerts` — emailed when someone is refused access (today: admins and
  executives — `PortalAccessAlert`)

## Backend inventory — server check each rule replaces

Today there are only two server levels: signed in (`AuthenticatePortalJwt`) and `portal.admin`
(`RequirePortalAdmin`: developer or `PortalRole::canManageUsers`). Controllers check nothing;
every other rule lives in `app/Support/Portal/*`.

| Rule | Replaces |
|---|---|
| `manage.requests.*` | `portal.admin` on `manage/requests*`; `PortalManageRequests::departmentScope/scoped/assertInScope` |
| `manage.requests.reimbursement.payout_receipt` | same, plus `PortalManageRequests::uploadPayoutReceipt` scope check |
| `manage.reports` | `portal.admin` on `manage/reports`; `PortalSubmittedReports::managedSubject/roster` |
| `manage.users`, `.change_role` | `portal.admin` on `manage/users*`; `PortalRole::roleChangeBlock/isLocked/assignableRoles`; no department scope today |
| `administration.all_recycle*` | `PortalRecycleBin::canViewAll/applyDepartment/itemForActor` (route has no `portal.admin`) |
| `administration.all_logs` | `portal.admin`; `PortalUserLogs::visibleEmployeeIds` |
| `administration.all_actions` | `portal.admin` only; no scope in `PortalAllActions` |
| `administration.send_email.*` | `portal.admin` on `administration/emails*`, `email-blocks*`; no ownership on block edit/delete |
| `projects.view` reach | `PortalViewProjects` (`PortalRole::isAdmin` sees all, else own; ignores developer) |
| `calendar.events.create` | nothing today (`PortalCalendarEvents::store` has no role check) |
| `workspace.*` | `WorkspaceAccess` tiers (`ResolvesWorkspaceAccess`, `WorkspaceWriter`, `WorkspaceSchema`, `WorkspaceViews`) |
| `settings.preview` | `PortalPreview::actsAsDeveloper` header narrowing |

Helpers retired at the end: `PortalRole::isAdmin/canManageUsers` as access checks (kept only for
display labels), `PortalPreview::departmentScope/inScope`, `RequirePortalAdmin`.

## Gaps found (fix inside this work, or flag)

1. **Raw resource API is open to every signed-in person**: `GET {product}/{resource}` and
   `/{id}` (`ResourceController`) return `portal/employees`, `portal/reimbursements`,
   `core/actions`, `core/recycle` etc. with only a JWT. Needs a rule (`api.raw_resources`,
   developer only) or removal. **Security — recommend fixing first, independent of this plan.**
2. **Admin pages render for a member who types the URL** (portal route guard missing; server still
   refuses the data). Closed in phase 3.
3. `PortalViewProjects` and `WorkspaceAccess` ignore `is_developer`.
4. `notifyManagers` and access alerts ignore department and developers.
5. Email blocks can be edited/deleted by any admin, not only their creator.
6. `employee_hierarchy` (manager -> report) exists in the schema and nothing reads it. `?` Use it
   as a future reach (`team` = my direct reports)?
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
   what the editor holds).

## Open questions (collected)

- `?` Can an executive limit an admin's preset rules, and can an admin limit a member's baseline
  (e.g. stop someone filing overtime), or only take back what they themselves gave?
- `?` Project admin role: today it does nothing. Fold it into rules (e.g. `projects.edit`) and
  retire the role?
- `?` Catalog in code or in a table.
- `?` Export: one rule or per page.
- `?` Calendar event creation for everyone — keep?
- `?` Data workspace (grid at `/`): put it under these rules too, or leave its tiers alone?
- `?` Raw resource API (gap 1): restrict to developers, or remove?

## Verification

- Phase 1: full backend suite plus a snapshot test per page comparing old and new decisions for
  every seeded employee (member, admin, executive, developer, each department).
- Each later phase: feature tests for grant, limit, cascade (revoke giver -> member loses it,
  restore giver -> member regains it), reach caps, preview, executives flag.
- Portal: typecheck, lint, tests; manual walk-through as each preset.
