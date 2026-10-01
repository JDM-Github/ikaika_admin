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
- **Active:** reported in the last 14 days.
- **Idle:** reported before, nothing in the last 14 days.
- **Done:** the project is Closed or Done.

Shown with its hours, its people, and its last report date. The 14 days is a constant to tune.

### 3. Activity tab (new): the work, by person

- A feed of the project's reports, newest first: date, person, hours, activity codes, lines
  (Engineering), remarks. Filter by person and by activity; paged.
- A people strip: everyone who reported, with hours and last report date.
  - Members who never reported are listed as **No reports yet**.
  - People who report but are not members are marked **Not a member**, so the lead can add them.
- Late reports (`late_submission`) are marked, as Submitted Reports does.
- `?` **Who may see other people's hours and remarks on the Activity tab.** Options: everyone who
  can open the project; or only the lead, Project Managers covering it and admins (others see
  totals only). To decide before building.

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
4. Department-aware form (step 5).
5. Track Projects column swap (step 6).

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

- `?` Who sees other people's hours and remarks on the Activity tab (step 3).
- `?` Whether "Active" should be 14 days or follow the project's own report rhythm.
- `?` Whether Lines should also show for General or MIS projects whose reports carry them (today:
  Engineering only, as the user said MIS does not care about lines).
- Parked: per-scope target lines for Engineering, shown as "x of target y lines", never a percentage.
