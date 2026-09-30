# Open items from the nj merge (2026-09-28)

Found while merging Noriel's `nj` branch into `jdm`. Not fixed yet: each needs a decision first.
Both merges were committed on 2026-09-28; nothing below has been changed since.

## 1. Finance (formerly Budget Tracker) and the envelope list are gated by role, not a power

**Resolved 2026-09-29.** Finance has rules of its own: `finance.overview`, `finance.transactions` (+ `.export`)
and `finance.envelopes` (+ `.export`), no reach, held by admins, executives and developers as before. The
ledger and codes reads take any of the three page rules; `manage/budget-envelopes` follows
`manage.requests.reimbursement.decide`, so whoever may approve a claim can load its envelopes. The notes
below are the original finding.

- Routes: `GET manage/budget-envelopes`, `manage/budget-transactions`, `manage/budget-codes`
  (`routes/portal/manage.php`), all behind `portal.admin`.
- Portal: the Finance section (Overview, Transactions, Envelopes; it replaced Manage / Budget
  Tracker on 2026-09-28) opens by admin role (or Developer mode); none of it is in
  `RouteRuleConstant.enforced`.
- Effect:
  - A member given `manage.requests.reimbursement.decide` cannot load envelopes. They get a 403
    and a security alert, and cannot approve a claim while the tracker is on (approval needs an
    envelope).
  - An admin whose reimbursement power is switched off still reads every budget.
- To decide: Finance powers of its own (each page, reach, export), and whether the envelope list
  used by the approval picker follows `manage.requests.reimbursement.decide`.

## 2. Project delete ignores powers and Developer mode

- `DELETE projects/{id}` (`routes/portal/projects.php`) has no rule middleware.
- `PortalProjectDeletion::assertMayManage` allows the admin role or the project's lead only. It
  does not look at powers, and a developer in Developer mode who is neither is refused.
- Restore goes through the recycle bin rules. All Recycle is view only, so only the person who
  deleted a project can restore it.
- To decide: a power for deleting projects (for example under Track Projects or View Projects),
  and whether Developer mode lifts it like everything else.

## 3. Deleted projects are only hidden on View and Track Projects

- Only `PortalViewProjects::projectRows` filters `projects.deleted_at`.
- Still listed: report project picker (`PortalReportProjects`), overtime and offset pickers
  (`PortalOvertimeRequests`, `PortalOffsetRequests`), Home (`PortalHome`), and report validation
  (`PortalSubmittedReports`).
- To decide: hide deleted projects from pickers and Home (past reports keep showing the name).

## 4. Missing Reports still uses the open raw employees API

**Resolved 2026-09-29.** The page now reads its roster from `GET administration/missing-reports`, limited
to the `administration.missing` reach; no portal page calls the raw API any more. The raw API itself stays
handed off. The notes below are the original finding.

- nj added the scoped `GET administration/missing-reports` for filed days, but the page still
  loads names through `UsersService.loadEmployees` (the raw `{product}/{resource}` API, which
  returns every employee column to any signed-in person).
- Handed off: mention only, do not fix (see FINE_GRAINED_RULES.md, "Handoff").

## 5. Late decisions do not reach Airtable

- Decisions are ledgered as `reports.late` and show in All Actions; `AirtableSyncMap` has no entry
  for it, so the sync skips them.
- To decide: whether portal decisions should overwrite Airtable's "LateSubmission Approval".

## 6. For Noriel

- `composer.phar` (3.6 MB) was committed in the project soft delete commit.
- `sql/portal/schema.sql` was edited; the convention is that it never changes (new structure goes
  in `separate.sql` or `indexes.sql`).
- The Airtable token is still in git history (commit 4b6450d). Rotate it in Airtable; purging the
  history needs a coordinated force-push.
