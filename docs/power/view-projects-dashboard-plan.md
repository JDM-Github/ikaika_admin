# View Projects dashboard: from fake percentages to real report data

Status: **planned (2026-10-01).** Nothing here is built yet. The power side of View Projects
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

- **Link:** a project gets one or more Bitbucket repositories (workspace, repository, branch), set
  on the department-aware form for MIS projects. A project can span repositories (for example the
  portal and the admin server).
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
    counted twice. Caveat: a pull request merged with **squash** creates a new commit holding the
    same lines as the branch's commits, which would then count twice. If the team squash-merges,
    skip squash commits (Bitbucket marks the pull request's merge strategy) or count only the
    branch commits.
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

- Replace the Progress meter column with Hours and Last report.
- The status filter and department filter stay.

## Server shape

- Board (`GET /portal/projects`): add the cheap summary fields (hours total and last 30 days,
  reporter count, last report date, lines total). One grouped query per board, inside the existing
  30-second cache.
- New `GET /portal/projects/{id}/activity?page=&person=&activity=`: the report feed and people strip
  for one project, checked with the same View Projects reach as the board, paged (25 a page).
- Scope statuses come with the board's scope data, computed from report dates instead of progress.
- Drop `overallCompletion`, `completed`, `inProgress` from `scopeSummary` once the portal no longer
  reads them (same commit as the portal change, so nothing breaks in between).

## Order of work

1. Dashboard tab and scope status from reports (steps 1 and 2): the biggest visible win, read-only.
2. Activity tab (step 3), after the visibility question is decided.
3. Trail cleanup (step 4): small.
4. Department-aware form (step 5), with the repository link for MIS projects.
5. Track Projects column swap (step 6).
6. Bitbucket code activity for MIS (step 7), once a read-only access token exists.

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

- `?` Bitbucket: whether the team merges pull requests with squash (decides the squash rule in step 7).
- `?` Bitbucket: the whole workspace, or only the repositories linked to a project.
- Parked: per-scope target lines for Engineering, shown as "x of target y lines", never a percentage.
