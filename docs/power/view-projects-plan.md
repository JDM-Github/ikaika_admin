# View Projects — power plan

Status: **parked.** Enforce only once View Projects, Add Project and Manage Scopes are finished
and tested. Until then the page keeps opening by role, as it does today.

Lines marked `?` are open questions to settle before building.

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

## The rules (as understood)

Reach words map onto the existing reaches:
- "Their department only" → `department`
- "All departments" → `all`
- "Only on their project" → `own`

```text
projects.view                    View Projects
│   reach: department | all      which projects the list shows ("Scope")
├── projects.lead                Project Lead          reach: department | all
├── projects.manager             Project Manager       reach: department | all
├── projects.create              Add New Projects      reach: department | all
│   └── projects.create.direct   No need for a Project Manager's permission
├── projects.edit                Edit Projects         reach: own | department | all
└── projects.scopes              Manage Scopes         reach: own | department | all
```

- **Scope** (`projects.view` reach): the list shows projects of their department, or every
  department.
  - `?` Is "department" on top of membership (their department **plus** any project they are
    a member of), or instead of it? Recommendation: on top of it, so nobody loses a project
    they work on.
- **Project Lead** (`projects.lead` reach): who may **be picked as lead** on a project of their
  department, or of any department. The lead picker in Add / Edit lists only people holding it
  at a reach that covers the project's department.
  - `?` Or does it mean the power to *choose* the lead? That reading changes the whole rule.
- **Project Manager** (`projects.manager` reach): who approves new projects for their department,
  or for all departments.
  - A project created by someone who is not a Project Manager waits for a Project Manager's
    approval.
  - That uses the existing `project_creation_status` / `_approver` / `_approver_email` /
    `_approver_remarks` columns.
- **Add New Projects** (`projects.create` reach): which departments they may create a project
  for.
  - Holding `projects.create.direct` ("No need for a Project Manager's permission") skips
    approval.
  - A Project Manager never needs approval for projects inside their own reach.
- **Edit Projects** (`projects.edit` reach):
  - `own` = projects where they are the lead;
  - `department` = projects of their department;
  - `all` = every project.
- **Manage Scopes** (`projects.scopes` reach): same three reaches as Edit.

Standing decision (FINE_GRAINED_RULES.md): **a project's lead can always edit and delete their
own project**, whatever their rules say.

## "Editable in Access" for Scope and Project Manager

Reach is already changeable by whoever manages the person, from the reach picker on the Access
Rule sheet. That works today for any rule with `supports_reach = 1`.

`?` Does "editable in Access" also mean the person may **narrow their own reach** on their own
Access page? For example, a Project Manager set to All drops themselves to their department.

That would be new: a self-reach, like the self on/off switch, but it may only ever narrow. It
would be stored in a new separate.sql table (e.g. `access_self_reach`). Recommendation: yes,
for any reach rule, built once for every page.

## Catalog changes (separate.sql)

- **Keep:** `projects`, `projects.view`, `projects.track`, `projects.create`.
- **Rework:**
  - `projects.view.edit` → `projects.edit`, with `supports_reach`.
  - A new `projects.scopes`, split out of edit.
  - `projects.create` gets `supports_reach`.
- **Add:** `projects.lead`, `projects.manager`, `projects.create.direct`.
- **`projects.view.delete`:** `?` Keep as its own rule with the same own/department/all reach as
  Edit? It is not in the list above.
- **Presets, chosen to match today's behaviour:**
  - Members: view at their department plus membership, and edit/scopes at `own` (as lead).
  - Admins: `all` everywhere, plus manager.
  - Executives and developers: `all`.
- `?` Tree placement. The list above nests Add / Edit / Manage Scopes under View Projects.
  Should they sit under Project Manager instead? That would make them unreachable without it.
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
  - delete: delete rule
  - approve: `projects.manager`
- Lead matching by **email or employee id**, never by name (the portal's name match is a bug
  waiting to happen).

**Portal**
- `RouteRuleConstant.enforced`: `projects` → `projects.view`. The sidebar hides View Projects
  without it, and landing falls through to Track Projects.
- The Add New Project button, Save changes, Manage Scopes and Delete show only when the session
  holds the rule at a reach that covers the selected project.
- The Add Project form offers only departments inside `projects.create` reach, and the lead
  picker lists only people holding `projects.lead` covering that department.
- A new "waiting for approval" state on created projects, and an approve/reject action for
  Project Managers.
  - `?` Where do managers see pending projects: a filter on View Projects, or Manage /
    Requests?

## Order of work (after the page is finished)

1. Settle the `?` questions above.
2. Catalog + presets in separate.sql. Parity test: every role still sees exactly what it sees
   today.
3. Server: rule middleware and reach filters on each project endpoint.
4. Portal: route rule, buttons, form pickers, approval state.
5. Self-reach on the Access page, if chosen.
6. Update the Access banners' enforced list and FINE_GRAINED_RULES.md.

## Verification

- Feature tests per endpoint:
  - own / department / all reach
  - lead always edits their own project
  - non-manager creation waits for approval; `create.direct` and managers skip it
  - executives and developers see all
- Parity test: presets reproduce today's list per role before any rule is changed.
- Portal: sidebar and landing without `projects.view`, buttons hidden per reach, pickers
  filtered.
