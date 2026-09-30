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
  - **Developer tools are developer-only** (2026-09-26): `developer.*` carry `min_tier` =
    `developer`, so only a developer in Developer mode holds them. The Access Rule sheet never
    lists them, switching one on or off for anyone is refused (422), and a stored gift of one
    counts for nobody, whoever made it.
  - **Executive preset** (2026-09-26): every department, without the developer tools, a member's
    own pages (Home, Reports, Requests, User) and All Recycle, All Actions and Backup. Executives
    who are developers open those in Developer mode. Developers now also include 220101-0001 (the
    president) and 220202-0002 (the MIS manager).

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
  - Your own switches and self reach are not logged (2026-09-27): your own choice about powers you
    already hold, which can never gain you a new one.
  - **Enforced so far: Home, Track Projects, Reports, Requests, User, Event Calendar, Manage / Requests, Manage / Reports, Manage / Users, Administration / All Recycle, Administration / All Logs, Administration / All Actions.** The Access page and Access Rule sheet banners
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
  - Requests: each own list allows User Requests or its filing form (the forms and the Edit
    pages read them); filing follows its form's rule; PATCH follows Edit and `/cancel` follows
    Cancel; receipt upload allows filing a reimbursement or Edit. In the portal the seven pages
    are in the route map (Edit Overtime and Edit Offset follow Edit). Editing a leave or
    reimbursement reopens its filing form, so it needs that form's power as well as Edit. Edit,
    Cancel, Export and the filing buttons hide per rule; Manage Requests keeps its own until it
    follows its rules.
  - User: `user/logs` follows Logs. The recycle bin's own view follows Recycle Bin; opening
    their own item follows Recycle Bin and restoring it follows Restore (someone else's item
    stays with the admin reach, All Recycle unchanged). Portal: Logs and Recycle Bin in the
    route map; Restore and both Exports hide per rule.
  - Event Calendar: `calendar/events` follows Event Calendar; adding an event and its form's
    audience choices follow Add events (admin and above by default, so members lost adding
    events unless given it, as decided); Download is hidden per rule and builds the .ics in the
    portal from what the filters show. Holidays stay open, since the report forms mark them too.
  - Manage / Requests: the page follows `manage.requests` (`portal.rule`, replacing
    `portal.admin`). Each kind follows its own rule and reach: a kind not held comes back empty,
    and its rows are only those its reach covers (`PortalRules::covers`: all, department, or own;
    executives' rows stay on nobody's queue; an owner nobody matches only with all). Deciding needs
    the kind's `.decide` (the whole batch is checked before anything is written, 403 by kind);
    payout proof follows `manage.requests.reimbursement.payout` on its route. Every row carries its
    owner's `department`, and the portal filters on it (toolbar, kept across tabs). Request alerts
    (`notifyManagers`) go to whoever holds `manage.requests.notified` and the kind's rule with a
    reach covering the filer (`PortalRules::holders`), not to every admin any more. Portal: tabs,
    Approve or reject, Submit Changes, payout proof and Export hide per rule; a member given the
    page by rule sees Manage with only Requests under it (only a rule the session named lets an
    admin page past the role).
  - Manage / Reports: the page follows `manage.reports` (replacing `portal.admin`). The roster is
    whoever the Reports reach covers plus the viewer, never an executive (the viewer included: the
    portal carries no executive's timesheets); an executive opens the first person listed. Anyone
    outside the roster answers 404. Export follows `manage.reports.export` (hidden only).
  - **White and black lists** (2026-09-26, Manage / Reports only for now): a rule marked
    `supports_list` can be narrowed per person by anyone who may edit that power for them
    (`access_lists`: allow = white list, block = black list, one kind per maker). Every black list
    hides a person and every white list must name them; the viewer always keeps their own
    timesheets. A list counts like a limit, only while its maker still manages them and holds the
    rule. The maker lists only people they can see themselves (their own roster, themselves
    included); people already listed whom they can no longer see are kept on save, unless the kind
    changes or the list is cleared. **Ignore white and black lists** (`manage.reports.unlisted`)
    skips every list: executives and developers start with it (Developer mode holds it anyway),
    and it is given only on its own, never as a tab of Reports. Lists do not travel with a gift.
    Access Rule sheet: a list button on the row (kind + people picker, saved with Save Changes after
    the switches); the Access page names who narrowed the power.
  - **Manage other admins from an equal** (2026-09-26): an admin given it by a fellow admin can
    manage every other admin, never the one who gave it, and cannot pass it on (it is not a base
    power). The giver must hold it from their role or from above; taking the gift back ends it.
  - **Developer mode and Default are two makers** (2026-09-26): every grant, limit and list stores
    `as_developer`. Only rows made in Developer mode count as a developer's; rows a developer made
    in Default are judged on their role like anyone's (so two developer-admins in Default are
    equals). Developer mode sees and changes what you made in either mode (switching off takes back
    both gifts, a gift or list replaces your Default one, it lifts your Default limits). In Default
    only Default rows are yours; anything you set in Developer mode on that power or under it, or a
    Developer mode limit on a page above it, is shown read only and locked ("Set by you in
    Developer mode"), and the server refuses changing it (422). A borrowed Manage other admins
    counts only while it survives every limit. `tests/Feature/PortalDeveloperMakersTest.php` walks
    the scenarios. The local DB was reset afterwards, so no row's mode is guessed.
  - **Developer mode acts read as "a developer"** (2026-09-26): to anyone not in Developer mode a
    gift, limit, list, refusal or All Logs row made in Developer mode names "a developer" (All Logs
    also hides its id, IP and location, and keeps it out of the user filter and name search);
    to someone in Developer mode it reads "Name (Developer mode)". `logs.as_developer` records it.
  - **Own work in Developer mode** (2026-09-27): a developer's own work (reports, requests, adding
    an event, restoring their own row) is logged by name while Default holds its rule. Past Default (limited by a boss, or not in the role) it still
    goes through in Developer mode, but each write first answers 428 `developer_confirmation` and
    needs `X-Portal-Developer-Confirmed: 1`; that act is logged as "A developer". Routes use
    `portal.own:<keys>`; filing a report and restoring your own row call `PortalRules::requireOwn`
    in code. Receipt uploads keep `portal.rule` (they write no log). Tests: `PortalOwnActivityTest.php`.
    Alerts and other people's texts name the actor through `PortalActivityCopy::actorName`, so they
    read "a developer" exactly when the log does. Exports are built in the portal, so a Developer
    mode session carries `default_rules` and the portal asks before an own-view export past them.
    Administration / All Actions masks the same way through `core.actions.as_developer` (set by
    `CoreLedger` via `CoreDeveloperActs`), filter and search included. Tests:
    `PortalAllActionsDeveloperTest.php`.
  - **Locked to others, Override for developers** (2026-09-26): a power held on only through
    someone else's gift, or held off by someone else's limit (here or on a page above), is locked
    to every editor (off and reach refused 422, on refused by the limiter's name). Switching off
    your own gift no longer limits someone else's. A developer in Developer mode presses Override:
    the switch unlocks, and saving (even untouched) makes that power and every tab under it theirs:
    earlier gifts and limits on the branch go; on gives it at the chosen or kept reach, off limits
    it where the role would still give it. A tab under a page someone limited is overridden
    through the page. Tests: `PortalOverrideTest.php`.
  - In Developer mode your own Default gift or limit reads "Given/Limited by you in Default" and can be
    overridden into a Developer mode act (2026-09-27). Every `developer.mode` log is written as a
    developer's act, with no record id, so switching Developer mode off never names who did it.
  - **Manage / Users** (2026-09-27): the page follows `manage.users` (replacing `portal.admin`);
    the roster, counts and departments follow its reach (department = same department plus
    yourself). Change roles follows `manage.users.role`, Export `manage.users.export`, the Access
    Rule sheet `manage.users.access`; anyone outside the reach answers 404. Every row opens a
    profile (`GET manage/users/{id}`): who they are and their photo, for anyone who can open Users.
  - **Show employee information** (`manage.users.information`, reach, admin and above, executives
    by default): adds the private details (birthday, address, contact, government and tax numbers,
    bank, emergency contact) for the people its reach covers. An executive's details show only to
    executives and Developer mode. Each view of someone else's details is logged (`VIEW`).
  - **Held only by MIS** (`only_department`): Manage other admins and Change roles are holdable
    only by executives and MIS admins (Developer mode exempt). A power someone can never hold is
    left off their Access Rule sheet unless they already hold it.
  - **Personal defaults** (`access_personal`): per-person starting powers beside the role preset,
    seeded in separate.sql like the developers list: Nathaniel Tanglao (220202-0002) gets Manage
    other admins; he and Steven Gaurano (220302-0003) get Show employee information at every
    department. They read "From their defaults" / "From your defaults", and a limit takes them away
    like a preset. Tests: `PortalManageUsersRulesTest.php`.
  - **All Recycle** (2026-09-27): the "all" view follows `administration.recycle` (replacing the
    admin role gate and the old department helper): whoever its reach covers and its white and
    black lists leave in, plus the viewer, never an executive; former staff's items count, and at
    every-department reach with no list, rows with no known deleter too. It takes lists
    (`supports_list`) with its own ignore power `administration.recycle.unlisted` (developers only
    by preset). View only (decision 18): restoring someone else's item answers 403. Export follows
    `administration.recycle.export`. Tests: `PortalAllRecycleRulesTest.php`.
  - The Access Rule sheet leaves out always-on powers (`can_toggle` 0: the Access page), and
    giving or limiting one answers 422.
  - **All Logs** (2026-09-27): follows `administration.logs` (replacing `portal.admin` and the old
    department helper): its reach, its executives flag (executives start at every department with
    executives; admins at their department without), always the viewer, and at every-department
    reach rows tied to no employee. **See developers' activity** (`administration.logs.developers`,
    min tier admin, MIS only, Nathaniel's personal default, never given as a tab): without it
    nothing a developer did shows, sign-ins included, not even to a developer in Default; with it
    their Default rows are named and their Developer mode rows read "a developer" to anyone not
    in Developer mode. Export follows `administration.logs.export`. Tests: `PortalAllLogsRulesTest.php`.
  - **All Actions** (2026-09-28): follows `administration.actions` (replacing `portal.admin`) the same
    way through `PortalVisibleActors`: reach, executives flag, rows with no actor at every-department
    reach, and developers' changes only with See developers' activity (the same power as All Logs).
    It is MIS only (`only_department`, min tier admin, with Sync and Export), admins start at every
    department. Sync to Airtable stays a power of its own (Nathaniel's personal default); the portal
    button is shown only to holders but still says the sync is not implemented, since the sync runs
    as `php artisan portal:airtable-sync`. Tests: `PortalAllActionsRulesTest.php`.
  - **Late Reports** (2026-09-28, from the nj merge): a Manage / Requests kind like the others,
    `manage.requests.late` (reach) and its `.decide`; the queue, decisions and late-filing alerts
    follow that reach. A decision is ledgered as `reports.late`, which the Airtable sync leaves
    unmapped for now. Tests: `PortalManageRequestsTest`, `PortalLateReportsTest`.
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

1. **Role preset** (`access_presets`): member, admin, executive, developer. Day one reproduces
   exactly today's behaviour. **Personal defaults** (`access_personal`) add per-person starting
   powers on top, filtered like presets by `min_tier` and `only_department`.
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

The SQL is the source of truth; this list is a copy for reading. 102 rules. `[R]` takes a reach.
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
- `manage` Manage — AED
  - `manage.requests` Requests — AED (each kind below has its own reach)
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
    - `manage.requests.late` Late Reports [R] — AED
      - `manage.requests.late.decide` Approve or reject — AED
    - `manage.requests.notified` Request alerts — AED
    - `manage.requests.export` Export — AED
  - `manage.reports` Reports [R] — AED
    - `manage.reports.export` Export — AED
  - `manage.users` Users [R] — AED
    - `manage.users.role` Change roles — AED (executives and MIS admins only)
    - `manage.users.export` Export — AED
    - `manage.users.access` Access Rules [R] — AED
      - `manage.users.access.give` Give powers — AED
      - `manage.users.access.limit` Limit powers — AED
      - `manage.users.access.peers` Manage other admins — ED (executives and MIS admins only)
    - `manage.users.information` Show employee information [R] — E (admin and above only)
- `finance` Finance — AED (no reach: the tracker is the company's)
  - `finance.overview` Overview — AED
  - `finance.transactions` Transactions — AED
    - `finance.transactions.export` Export — AED
  - `finance.envelopes` Envelopes — AED
    - `finance.envelopes.export` Export — AED
- `administration` Administration — AED
  - `administration.recycle` All Recycle [R] — AED
    - `administration.recycle.export` Export — AED
  - `administration.logs` All Logs [R] — AED
    - `administration.logs.export` Export — AED
  - `administration.actions` All Actions [R] — AED
    - `administration.actions.sync` Sync to Airtable — ED
    - `administration.actions.export` Export — AED
  - `administration.missing` Missing Reports [R] — AED
    - `administration.missing.email` Email reminders — AED
    - `administration.missing.mobile` Mobile reminders — AED
    - `administration.missing.export` Export — AED
  - `administration.emails` All Emails — AED (what Email sent)
    - `administration.emails.export` Export — AED
  - `administration.backup` Backup — D (Developer mode only while parked, never given)
    - `administration.backup.create` Create backups — D
    - `administration.backup.download` Download backups — D
    - `administration.backup.restore` Restore backups — D
    - `administration.backup.export` Export — D
  - `administration.alerts` Access alerts — AED
- `email` Email — AED (its own page after Administration, as in the sidebar)
  - `email.send` Send emails — AED
  - `email.library` Templates and footers — AED
  - `email.images.global` Global images — ED (admin and above only; an admin only when given it)
- `workspace` Data Workspace — AED
  - `workspace.private` Private columns — AED
  - `workspace.edit` Edit cells — AED
  - `workspace.views` Shared views — AED
- `developer` Developer — D (Developer mode only, never given)
  - `developer.rules` Rule catalog — D
  - `developer.flag` Developer flag — D
  - `developer.performance` Performance page — D

## Presets (day one = today)

| Rule group | Member | Admin | Executive | Developer |
|---|---|---|---|---|
| Baseline [B] | yes | yes | yes | yes |
| Manage/* | — | department | all | all |
| Finance/* | — | yes | yes | yes |
| Administration/* (non-dev) | — | department | all | all |
| Airtable sync | — | — | yes | yes |
| Backup | — | — | — | Developer mode only |
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
