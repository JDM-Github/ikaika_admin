# View Projects — power plan

Status: **parked.** Enforce only once View Projects, Add Project and Manage Scopes are finished
and tested. Until then the page keeps opening by role, as it does today.

Lines marked `?` are still open. Everything else was decided on 2026-09-25; the reasoning sits
next to each decision.

## Why this is parked

These rules sit on features that do not exist on the server yet. Enforcing a rule on a
placeholder button would only have to be redone.

| Feature | Today |
|---|---|
| Project list | Real: `GET /portal/projects`. An admin sees every project; anyone else sees only projects they are a member of (`employees_projects`). |
| Add New Project | Portal form only. Submit shows "Saving needs the create project endpoint." |
| Save changes (edit) | Placeholder notice: "Saving needs the projects endpoint." |
| Manage Scopes | Placeholder notice: "The scopes editor is not built yet." |
| Delete | Portal-side only (`ProjectsService.deleteProject`); no server endpoint. |
| Who may edit/delete | Portal only: admin, or the project's lead (matched by **name**, not id or email). |
| Project Manager | Does not exist anywhere yet: no column, no role. |
| Creation approval | Columns exist but nothing uses them: `project_creation_status`, `project_creation_approver`, `project_approver_email`, `project_creation_approver_remarks`. |

## The rules

Reach words map onto the existing reaches:
- "Their department only" → `department`
- "All departments" → `all`
- "Only on their project" → `own`

```text
projects.view                       View Projects
│   reach: department | all         which projects the list shows ("Scope")
├── projects.lead                   Project Lead         reach: department | all
└── projects.manager                Project Manager      reach: department | all
    ├── projects.create             Add New Projects     reach: department | all
    │   └── projects.create.direct  No need for a Project Manager's permission
    ├── projects.edit               Edit Projects        reach: own | department | all
    ├── projects.scopes             Manage Scopes        reach: own | department | all
    └── projects.delete             Delete Projects      reach: own | department | all
```

- **Scope** (`projects.view` reach): the list shows every project they are a member of **plus**
  the projects of their department, or of every department. Membership is never lost.
- **Project Lead** (`projects.lead` reach): who may **be chosen as lead** on a project of their
  department, or of any department. The lead picker in Add / Edit lists only people holding it
  at a reach that covers the project's department.
- **Project Manager** (`projects.manager` reach): the parent of adding, editing, managing
  scopes and deleting, for their department or every department.
  - Approving new projects belongs to it too.
  - Approval will most likely get its own page, **Manage / Projects**. `?` Not decided yet.
- **Add New Projects** (`projects.create` reach, under Project Manager): which departments they
  may create a project for.
- **Edit / Manage Scopes / Delete** (`projects.edit`, `projects.scopes`, `projects.delete`, all
  under Project Manager):
  - `own` = projects where they are the lead;
  - `department` = projects of their department;
  - `all` = every project.

### Two consequences of putting these under Project Manager (decided)

**1. When a new project needs a Project Manager's permission.**

Only someone holding Project Manager can hold Add New Projects at all. So "needs permission"
cannot mean "a member asks a manager". It means a manager adding a project **outside their
own manager reach** waits for a manager who does cover it. How it plays out:

| Adding a project for... | Their Project Manager reach | Holds `create.direct`? | Result |
|---|---|---|---|
| Their own department | department or all | either | Created straight away |
| Another department | department | no | Created as **waiting for approval** by a manager who covers that department |
| Another department | department | yes | Created straight away |
| Any department | all | either | Created straight away |

- Add New Projects has its own reach (department or all). It says which departments they may
  even *start* a project for.
- The Project Manager reach says which of those they can *approve* on their own.
- `create.direct` ("No need for a Project Manager's permission") lets a trusted person skip the
  wait anyway.
- **Why this reading:** it keeps the tree the way it was decided, gives the permission rule a
  real job, and uses the approval columns already in the projects table.
- If members should one day *request* a project, move Add New Projects back beside Project
  Manager. Every other part of this plan stays the same.

**2. A project's lead can always edit, manage scopes on, and delete their own project.**

The standing decision in FINE_GRAINED_RULES.md is kept, even for a lead who does not hold
Project Manager.

- **Why:** today the portal already lets the lead edit their own project. Without this
  exception, leads who are not managers would lose that the day the rules go live.
- A manager can still stop one person with a **limit** on them. A limit removes the lead
  exception too, so "always" means "unless someone above them said no".
- The person can also switch it off for themselves on the Access page, like any other power.

## Everyone can change their own reach ("editable in Access")

Decided: a person may **narrow their own reach** on their Access page, for any reach rule on
every page, not only projects. For example, a Project Manager set to All drops themselves to
their department until they need more.

- It may only ever narrow, never widen past what they hold.
- It is stored in a new separate.sql table (e.g. `access_self_reach`).
- `PortalRules::active()` applies it the way it applies the self on/off switch.
- It lives on the Access page next to the switch. It needs Save Changes like the switches.
- Build it once, as a shared step, before the first reach rule is enforced.

Whoever manages the person already changes their reach from the Access Rule sheet.

## Catalog changes (separate.sql)

- **Keep:** `projects`, `projects.view`, `projects.track`.
- **Move / rework:**
  - `projects.create` moves under `projects.manager` and gets `supports_reach`.
  - `projects.view.edit` → `projects.edit`, with reach, under `projects.manager`.
  - `projects.view.delete` → `projects.delete`, with reach, under `projects.manager`.
- **Add:** `projects.lead`, `projects.manager`, `projects.scopes`, `projects.create.direct`.
- **Presets, chosen to match today's behaviour:**
  - Members: view (membership plus department), and lead at `department`.
  - Admins: `all` everywhere, including manager and every child.
  - Executives and developers: `all`.
- `own` reach support needs a meaning per rule; for projects it is "I am the project lead".
  The graph already carries `own` as a reach value.

## Enforcement points (when the page is ready)

**Server**
- `GET /portal/projects`: `portal.rule:projects.view`. The list filter comes from the reach,
  replacing `PortalRole::isAdmin`.
- Each new endpoint follows its rule and checks the reach against the project's department, or
  against lead = actor:
  - create: `projects.create`
  - update: `projects.edit`
  - scopes: `projects.scopes`
  - delete: `projects.delete`
  - approve: `projects.manager`
- Lead matching by **email or employee id**, never by name (the portal's name match is a bug
  waiting to happen).
- **The lead exception, in code.** Edit, Manage Scopes and Delete are allowed when:
  - the person holds the rule at a reach covering the project, **or**
  - they are the project's lead, with no valid limit on that rule and not switched off by
    themselves.
- So the Access Rule sheet must let a manager limit `projects.edit` / `.scopes` / `.delete`
  on someone who does not hold them. Today a switch-off only limits a power the person
  holds.
  - A small change: allow a limit on a held-or-lead-exception rule.
  - The Access page shows the exception as "Yours as project lead", so the person can switch
    it off.

**Portal**
- `RouteRuleConstant.enforced`: `projects` → `projects.view`. The sidebar hides View Projects
  without it, and landing falls through to Track Projects.
- The Add New Project button, Save changes, Manage Scopes and Delete show only when the session
  holds the rule at a reach that covers the selected project.
- The Add Project form offers only departments inside `projects.create` reach, and the lead
  picker lists only people holding `projects.lead` covering that department.
- A "waiting for approval" state on created projects, and approve / reject for Project
  Managers, most likely on a new **Manage / Projects** page (its own plan when decided).

## Order of work (after the page is finished)

1. Decide where approvals live (most likely a new Manage / Projects page, with its own plan).
2. Self-reach on the Access page (shared, every page).
3. Catalog + presets in separate.sql. Parity test: every role still sees exactly what it sees
   today.
4. Server: rule middleware and reach filters on each project endpoint.
5. Portal: route rule, buttons, form pickers, approval state.
6. Update the Access banners' enforced list and FINE_GRAINED_RULES.md.

## Verification

- Feature tests per endpoint:
  - own / department / all reach
  - membership never lost
  - lead picker filtered by `projects.lead`
  - approval: each row of the "needs permission" table above
  - lead exception: a lead edits, manages scopes on and deletes their own project without
    Project Manager, and loses it under a limit or their own switch
  - executives and developers see all
- Self-reach: narrows only, never widens, and applies to enforcement and to the session.
- Parity test: presets reproduce today's list per role before any rule is changed.
- Portal: sidebar and landing without `projects.view`, buttons hidden per reach, pickers
  filtered.
