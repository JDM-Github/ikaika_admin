# View Projects dashboard: from fake percentages to real report data

Status: **phases 0-2 built (2026-10-01).** Reset baseline done (reset.ps1 fixed: admin 3c880fa);
board `work` from reports (admin 2d6572b, `PortalProjectWork`); the Dashboard tab shows it with no
percentage (portal 62f4fef). **Phases 3-5 built (2026-10-02):**
- Phase 3, Activity tab: `GET projects/{id}/activity`, `PortalProjectActivity` (admin 67c397e;
  portal b2a9e4b). A fourth tab, Activity, sits between Details and Trail.
- Phase 4, Trail: Airtable's "Initial/Last report was on" rows leave it (admin 2b6ff8d). Details
  "Start date" is the earlier of the first change and the first report (portal 9ef8d63).
- Phase 5, department-aware (admin 521d82e, portal 09b89c5):
  - the server clears level of detail, area, Forma and panoramic for a non-Engineering project,
    and the form hides them once a non-Engineering department is chosen;
  - non-Engineering projects scope from their department's and General's activity codes
    (`PortalActivityScopes`; board `scopeOptions`, form options `activityCodes`), shown as flat rows;
  - report projects say `hasLines`, so the report forms hide items and save 0 lines off Engineering;
  - one portal rule lives in `ProjectDepartmentUtility`, one server rule in `PortalProjectWork::isEngineering`.
- Phase 6, no percentage left (2026-10-02, both repos, uncommitted): Track Projects' Progress %
  column became Hours + Last Report; Home dropped the Portfolio Progress KPI and its Project
  Progress list shows hours + last report; Manage Requests' Workforce Capacity projects show hours +
  last report. `scopeSummary` now carries only `tierLabel`/`total` -- `completed`, `inProgress` and
  `overallCompletion` left the server, along with Home's `completionRatio`/`portfolioCompletion` and
  every `progress_pct` read. `PortalHome::activeProjects` adds work via `PortalProjectWork`.

Phases 7 is still planned. Repository links for MIS projects arrive with phase 7, not 5. The power side of View Projects
(who may see, add, edit, scope, delete, lead and approve) is finished; see `view-projects-plan.md`.
This plan is about **what a project page shows**.

## Decisions (2026-10-01, user)

- **No percentage anywhere.** A project changes all the time, so any "% complete" either passes 100%
  or never reaches it. The completion bars, the overall completion figure and the scope progress
  bars all go away. Track Projects' Progress column goes with them.
  - A target can be meaningful (Engineering: a target line count on one scope or activity), but it
    is parked: if it ever comes, it reads "1,500 of 2,000 lines", never as a percentage.
- **Show the data we really have:** status, hours, lines, who reported, which activities, when.
- **Four tabs instead of three:**
  1. **Dashboard:** the summary of the real data.
  2. **Details:** the project's fields (as today, made department-aware).
  3. **Activity:** the work: every report filed on the project, by person.
  4. **Trail:** the changes: who created, edited, approved, closed, scoped, deleted or restored the
     project (admins, project leads, managers). Work never appears here.
- **The project form follows the department.** Engineering fields only for Engineering projects.

### Answered the same day (2026-10-01, user)

- **Activity tab visibility:** other people's hours and remarks only for the project's **Project
  Lead**, a **Project Manager** covering the project, and **Admin or higher**. Everyone else who can
  open the project sees totals only (and their own reports in full).
- **"Active" follows each project's own rhythm**, not a fixed 14 days (see step 2).
- **Lines are Engineering's.** Engineering keeps "change in elements" from its reports. MIS gets its
  own measure from **Bitbucket** (lines added / removed, files, commits; step 7).
- **Lines follow the project's department, not the person's role** (answered 2026-10-01): an
  Engineering project asks for lines from whoever reports on it, admins and project leads included
  (admins still do the work; only executives do not). A General, Admin or MIS project does not ask.
- **Every branch counts** (answered 2026-10-01): all of a person's commits count, on any branch,
  each commit once by its hash (see step 7).
- **Commit author = employee by their ikaikabim email** (answered 2026-10-01): everyone commits with
  it, since Bitbucket is only reachable with an ikaikabim account.
- **Everything new goes in `sql/portal/separate.sql`** (with a migration for existing databases);
  `sql/portal/schema.sql` is the original Airtable data and is never edited.

## What the data says (local copy of real data, 2026-10-01)

| Fact | Number |
|---|---|
| Live projects | 114: Engineering 107, General 3, MIS 3, Admin 1 |
| Project status | Started 30, Closed 70, Done 12, blank 2 |
| Projects with any progress recorded (`projects.progress_pct`) | **0** |
| Airtable scope rows (`project_scopes`) / with progress above 0 | 7 / **0** |
| Reports with `progress_per_activity_pct` filled | **0 of 4,312** |
| Scope-progress tables (`user_reports_scope_progress` and pivots) | **empty** |
| Reports | 4,312, from 2025-04-01 to 2026-10-01 |
| Reports with hours | 4,308, **30,831 hours** in total |
| Reports with activity codes | all 4,312 |
| Reports by the code's department | Engineering 2,862, MIS 774, General 648, HR 20, Admin 8 |
| Reports with lines (`change_in_elements`, numeric) | ~4,255 numeric (3,345 non-empty and non-zero) |
| Reports with remarks | 1,748 |
| Projects with reports (`projects_user_reports`) | 88 of 114, 4,258 links |
| Activity codes | Engineering 40, MIS 8, General 5, HR 1, Admin 1 |
| Trail rows | 100, all project events (edited, creation and closing approvals, checklist) |
| On the 30 ongoing projects | 63 of 155 members never reported; 25 of 117 reporters are not members |

## Why the page looks broken today

- **0% everywhere, even on closed projects.** The dashboard reads progress that nobody records:
  `PortalViewProjects::scopeSummariesByProject` takes `project_scopes.progress_pct` (7 rows, all 0)
  and hard-codes 0 for portal-saved WBS scopes and for scopes found through reports. There is no
  input behind the number, so there is nothing to fix; it has to go.
- **Scopes say Not Started while people work on them.** Status comes from the same empty progress,
  and the people view leans on membership (`employees_projects`), which does not match who actually
  works: many members never report, and some reporters are not members.
- **The trail feels weak.** It only holds admin events, and very few of them. The work itself (30k
  hours of reports) is not visible anywhere on the project.
- **The form is Engineering's.** Level of detail per discipline, area in square feet, Forma and
  panoramic links mean nothing to MIS, General or Admin projects.

## The plan

### 1. Dashboard tab: real numbers, no percentages

Server: the board item (or a per-project summary, see "Server shape") gains, from reports linked
through `projects_user_reports` and `employees_user_reports`:
- `hoursTotal`, `hoursLast30Days`;
- `reporterCount` (distinct people), `lastReportOn`, `firstReportOn`;
- `linesTotal` (sum of numeric `change_in_elements`), sent only when the project is Engineering;
- `hoursByActivity`: the top activity codes with hours and report count.

Portal:
- Status first: project status (Ongoing / Completed / On hold / Cancelled) and approval (Waiting /
  Rejected) as pills, as the board already shows.
- KPI tiles: Hours logged, Last 30 days, People reporting, Last report (date), and Lines for
  Engineering projects only.
- "Hours by activity" list in place of the scope status card.
- Remove: overall completion, completion bars, scope progress bars, the scope "% complete" metrics.

### 2. Scope status from reports, not from progress

Each scope (activity code) on the project gets a status from its reports, never a percentage:
- **Not started:** no report uses it yet.
- **Active:** the last report is within the project's own rhythm.
- **Idle:** reported before, but the gap since the last report is longer than its rhythm.
- **Done:** the project is Closed or Done.

**Rhythm (decided: follow the project):** the typical gap between report days on that project,
taken as the median gap over its last 10 report days, then doubled for slack and held between
3 and 30 days. A project reported daily goes Idle after a few quiet days; one reported weekly does
not go Idle until about two weeks pass. A project with fewer than 3 report days uses 14 days.
Shown with its hours, its people, and its last report date.

### 3. Activity tab (new): the work, by person

- A feed of the project's reports, newest first: date, person, hours, activity codes, lines
  (Engineering), remarks. Filter by person and by activity; paged.
- A people strip: everyone who reported, with hours and last report date.
  - Members who never reported are listed as **No reports yet**.
  - People who report but are not members are marked **Not a member**, so the lead can add them.
- Late reports (`late_submission`) are marked, as Submitted Reports does.
- **Who sees what (decided):** the project's lead, a Project Manager whose reach covers the
  project, and Admin or higher see every report in full. Anyone else who can open the project sees
  the totals and the people strip without hours or remarks, plus their own reports in full. The
  server decides this, not the page: the activity endpoint simply leaves out what the viewer may
  not see.

### 4. Trail tab: changes only

- Keep only project changes: created, edited (with what changed), creation and closing approvals,
  resubmissions, scopes saved, deleted, restored, checklist.
- The imported rows "Initial report was on ..." and "Last Report was on ..." are work, not changes:
  hide them from the trail; the Activity tab and the Dashboard show the same dates for real.
- Each row keeps who did it and when (it already does via `PortalProjectTrail`).

### 5. Department-aware Add / Edit Project

- A field set per department, in one constant:
  - **Engineering:** everything today (level of detail grid, area, Forma, panoramic and the rest).
  - **Other departments:** name, lead, client, department, type, status, members, support members,
    and the general links (MS Teams, Drive, Planner, Loop). No level of detail, no area, no Forma
    or panoramic.
- Manage Scopes offers the project department's activity codes (plus General), so MIS picks from
  its 8 codes instead of Engineering's WBS.
- Switching the department in the form hides the fields that no longer apply. The server ignores
  and clears them for a non-Engineering project, so a hidden field cannot keep a stale value.

### 7. MIS code activity from Bitbucket

An MIS project tracks its work from its Git repositories instead of report lines.

- **Link (decided 2026-10-01: many per project):** a project gets any number of Bitbucket
  repositories (workspace and repository), set on the department-aware form for MIS projects. A
  project can span repositories (the portal and the admin server, other parts, testing repos).
  - Every linked repository counts the same, testing repositories included (decided 2026-10-01:
    "it's still a line").
  - The same repository may belong to more than one project; its commits count on each.
- **Sync:** the server reads each repository's commits on the counted branch through the Bitbucket
  Cloud REST API (`/repositories/{workspace}/{repo}/commits`, then `/diffstat/{hash}` for lines added,
  lines removed and files per commit). A Bitbucket push webhook triggers it, with a scheduled sync as
  a backstop. Stored per commit: hash (unique), repository, author email, matched employee, date,
  message (internal only), lines added, lines removed, files changed.
- **Storage:** the repository links and synced commits are new portal tables in
  `sql/portal/separate.sql` plus a migration; `schema.sql` is never touched.
- **Credentials:** a read-only repository or workspace access token in `ikaika_admin/.env` only,
  like the Airtable token. Never in the portal, a response or a log. (Bitbucket app passwords are
  deprecated; access tokens replace them.)
- **Author = employee by email.** The commit author comes from each developer's Git setting
  (`author.raw` is "Name <email>"); everyone uses their ikaikabim address (decided), so it matches
  `employees.email`. A commit whose email matches nobody is kept and shown as "Unknown author", never
  dropped. (John Dave Pega's work email was missing from `sql/portal/data.sql`; added 2026-10-01.)
- **Honest counts:**
  - Merge commits are skipped (they repeat their parents' lines).
  - Generated and vendored paths are skipped by pattern: lock files, `vendor/`, `node_modules/`,
    `graphify-out/`, build output, generated native folders. Without this one regenerated file
    outweighs a week of work.
  - Every branch counts, each commit **once by its hash**, so a commit on several branches is not
    counted twice.
  - **Merging never moves lines to the person who merged.** A merge commit is skipped, so each
    developer keeps the lines of the commits they wrote. This is why the **merge commit** strategy
    (the Bitbucket default) is the right one for counting.
  - **Squash is the strategy that would get it wrong:** it collapses everyone's commits into one new
    commit with a single author, and once the source branch is deleted the original commits are no
    longer in the repository. Guard for it anyway: commits are stored as they are pushed (webhook),
    so the branch commits are already kept; a pull request's squash commit (its `merge_commit` hash
    from the pull request API) is then skipped, and if it was never seen as branch commits it is
    credited to the pull request's author.
- **Shown:**
  - Dashboard: commits, lines added and removed, files changed, active development days, last commit.
  - Activity tab: a day-by-day list per person, beside their reports.
  - Same visibility rule as the Activity tab: commit messages only for the lead, managers and
    Admin or higher.
- **Reports:** Daily and Late Report ask for lines only when the project is Engineering, whoever
  reports (admins and leads included).
- **Later, a client view:** a client-facing page (on the central platform) can show the same counts
  with curated summaries instead of raw commit messages and no links to Bitbucket. Out of scope here.

### 6. Track Projects follows

- Replace the Progress meter column with Hours and Last report. **Built 2026-10-02.**
- The status filter and department filter stay.

## Server shape

- Board (`GET /portal/projects`): add the cheap summary fields (hours total and last 30 days,
  reporter count, last report date, lines total). One grouped query per board, inside the existing
  30-second cache.
- New `GET /portal/projects/{id}/activity?page=&person=&activity=`: the report feed and people strip
  for one project, checked with the same View Projects reach as the board, paged (25 a page).
- Scope statuses come with the board's scope data, computed from report dates instead of progress.
- Drop `overallCompletion`, `completed`, `inProgress` from `scopeSummary` once the portal no longer
  reads them (same commit as the portal change, so nothing breaks in between). **Done 2026-10-02:**
  the portal's `ProjectScopeSummaryModel` reads only `tierLabel`/`total`, and the server stopped
  sending the rest.

## Safe order of work (so nothing breaks on the way)

The rules every phase follows:
- **Server adds first, portal follows, removal last.** A new field or endpoint ships before the
  portal reads it; an old field goes only once nothing reads it. So the portal never asks for
  something the server does not send.
- **One phase, one pair of commits** (admin, then portal), each green on its own: server tests,
  portal typecheck, lint and tests, then a read-only Playwright check as Steven (admin) and Jamie
  (member). Any rows a check writes are cleaned up by id.
- **New tables and columns go in `sql/portal/separate.sql` and a migration, together**, never
  `schema.sql`. A reset then rebuilds the same database the migrations built.

Phase 0, baseline (before any code):
1. Push ikaika_admin (it was 14 commits ahead of Bitbucket on 2026-10-01) and the portal.
2. Reset the local database: `sql/portal/reset.ps1` (schema, indexes, data, separate, then clears
   the core ledger's portal rows). It wipes everything made only in the portal locally (projects
   added there, Access gifts and limits, notifications, logs).
3. Run both suites. The parity test (`PortalAccessTest`) then proves `separate.sql` rebuilds the
   same presets the migrations made; fix any drift before building.
4. Smoke check View Projects, Manage / Projects and Access in the browser.

Then, one phase at a time:
1. **Board summary fields (server only):** hours, last 30 days, people, last report, lines, hours by
   activity, scope statuses, added next to today's fields. The portal ignores them, so nothing
   changes on screen.
2. **Dashboard tab (portal):** the new tiles and scope statuses; the percentage UI goes. **Built.**
   Tiles: Hours logged (caption: hours in the last 30 days), People reporting, Last report (caption:
   first report), Lines (Engineering only); short dates and bare numbers so a tile never wraps.
   "Hours by activity" replaced the scope donut; the scope tree shows Not Started / Active / Idle /
   Done per row (activity codes mapped onto WBS, a branch takes the strongest status under it, a
   finished project's unreported scopes read Done); the list shows hours; the Details timeline shows
   Last report instead of a progress bar. The old `overallCompletion` / `completed` / `inProgress`
   fields stay on the server until phase 6: Track Projects, Home's project progress and a Manage
   Requests view still read them.
3. **Activity tab:** the new read-only endpoint with the visibility rule enforced server-side, then
   the tab.
4. **Trail cleanup:** report-date rows leave the trail.
5. **Department-aware form and reports:** the server first accepts a non-Engineering project and a
   non-Engineering report without lines (and clears Engineering-only fields), then the portal hides
   them. The repository links for MIS projects arrive here as an empty list.
6. **Track Projects:** Hours and Last report replace the Progress column. Home's project progress
   (`completionRatio`) and the Manage Requests view that reads `overallCompletion` switch too; only
   then do those fields leave the server. **Built 2026-10-02.**
7. **Bitbucket:** tables (separate.sql + migration), then the sync tested against faked HTTP
   responses, then the webhook (with its secret checked), then a real read-only token in `.env`,
   then the UI. No real call to Bitbucket happens until the fake-tested sync is green.

## Verification

- A closed project with reports shows its real hours and people, and no percentage anywhere.
- An ongoing project shows each scope as Not started, Active or Idle from report dates.
- A member who never reported shows "No reports yet"; a non-member reporter shows "Not a member".
- An MIS project's form shows no level of detail, area or Engineering links, and its Manage Scopes
  offers MIS activity codes.
- The trail of a project shows only changes, each with who and when.
- Server tests against the local data copy, transacted; Playwright checks as an admin (Steven) and a
  member (Jamie), read-only.

## Open questions

- Recommended (2026-10-01): keep the **merge commit** strategy, not squash; the squash guard in
  step 7 is only a fallback.
- Decided 2026-10-06 (user): Bitbucket syncs **only the repositories linked to a project**, never the whole workspace.
- Parked: per-scope target lines for Engineering, shown as "x of target y lines", never a percentage.
