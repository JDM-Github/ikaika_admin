# Plan: correct earn codes, Actual Work Date, and request activity/earn code links

Status: built 2026-10-22. Decisions: daily/late reports always 01 Regular Working Day; overtime by the
day (weekend/holiday variants), offset always 01; leave by type; Activity Scope from each entry's label;
Actual Work Date = the report's date; old portal lines repaired by `portal:fix-report-lines`.
Repo: `ikaika_admin`
(Laravel). Read `deployment/AIRTABLE-SYNC.md` and `docs/plan/airtable-sync-scopes-creators.md` ("Rules that
always apply") first.

## What an earn code is

An earn code is the pay category for a day's hours. Payroll multiplies the hours by it:

| Earn code | Multiplier |
|---|---|
| 01 Regular Working Day | x1.000 |
| 02 Regular Overtime | x1.250 |
| 05 Rest Day | x1.300 |
| 13 Regular Holiday | x2.000 |
| … | |

- **In Airtable:** they live in the **Earn Codes** table (Description, Did Not Work, Did Work). User Reports and
  Request link to them through an "Earn Code" field.
- **In SQL:** the `earn_codes` table, plus the junctions `user_reports_earn_codes` and `requests_earn_codes`.
- **Access:** the sync only **links** to earn codes, and never writes the Earn Codes table, so access is fine.
  - In the TEST base, Earn Codes (like Clients) is an *externally synced* table: read-only, and its sync shows
    "Last sync failed". Linking to its records works (verified 2026-10-22: User Reports records were created
    with an Earn Code link).
  - Do not try to create or edit earn codes through the API.
- **The holiday calendar** is in the estimator database: `holidays`, `holidays_earn_codes_v2` (variant
  'base'), `earn_codes_v2`. `app/Support/Portal/PortalHolidays.php` reads it, and `classify()` turns a holiday's
  base earn code into a type.

## Bug 1: every new daily and late report gets the wrong earn code

- **Symptom:** in Airtable, User Reports filed by the new portal show "24 Regular Holiday + Rest Day Night
  Differential & Overtime" instead of "01 Regular Working Day".
- **Cause:** `PortalSubmittedReports::regularEarnCodeId()` (around line 2262) takes the **first earn code by
  id whose label contains "regular"**.
  - Ids come from the import order, which is sorted by Airtable record id, not by code number.
  - Locally, id 3 = "24 Regular Holiday + Rest Day ND & OT" matches "regular" before id 12 = "01 Regular
    Working Day". Production has its own ids, so it can pick a different wrong code.
- **Callers:**
  - line ~685: the code set for a whole filing;
  - line ~2121: the fallback when a line has no earn code.
- **Fix:**
  1. Match the code by its **number and name**, never by a substring: the row whose description is exactly
     "01 Regular Working Day", or whose leading number is `01` and whose name contains "Working Day". Ignore
     case and spacing; `PortalSubmittedReportPresenter::earnCodeLabel()` strips the number, so compare on the
     raw `description`. Keep it in one place as a constant, e.g. `REGULAR_WORKING_DAY = '01 Regular Working Day'`.
  2. Then decide the day's real earn code. This is the part to **ask the user first**: what should a filing on
     a holiday or rest day get?
     - **Option A (smallest):** always "01 Regular Working Day" and let payroll adjust it. This matches what
       most old reports carry.
     - **Option B:** pick it from the day.
       - Regular holiday: "13 Regular Holiday".
       - Special holiday: "09 Special Holiday".
       - The employee's rest day: "05 Rest Day".
       - Both: the "+ Rest Day" variants (17, 21, 29).
       - Overtime and night differential only come from overtime requests, never from a daily report.
       - Use `PortalHolidays` for the holiday type. Rest days need a definition: weekends? The employee's
         schedule? Ask.
  3. **Existing wrong rows:** ask the user before rewriting them. To fix them, write a one-off command
     (`portal:fix-report-earn-codes`, dry run unless `--apply`) that finds the portal-filed lines carrying the
     wrong code. Those are lines created after the portal went live, still on the earn code the bug picked.
     - For each line it updates `user_reports_earn_codes`, and ledgers a `reports.submitted` edit with that
       line's id in `lineIds`, so the sync sends the corrected Earn Code link.
     - Never touch lines imported from Airtable.
- **Tests:**
  - a filing gets "01 Regular Working Day" even when earn codes with lower ids contain "Regular";
  - Option B only: a holiday filing gets the holiday code;
  - the fix command: dry run changes nothing, and `--apply` changes only portal lines and ledgers them.

## Bug 2: "Actual Work Date" is empty on new reports

- **Facts:**
  - `user_reports.actual_work_date` exists, and `REPORT_LEVEL_COLUMNS` in `PortalSubmittedReports` names it.
  - New filings leave it NULL (rows 4311–4315 locally).
  - The sync map's `reports.submitted` fields deliberately leave out "Actual Work Date". The comment says the form
    never set it, so sending NULL would erase Airtable's value.
  - Imported reports carry it, e.g. row 4309 has it equal to `report_date`.
- **Ask the user:** what does Actual Work Date mean?
  - The day the work was done, while Date is the day it was filed?
  - Or always the report's date?
  - For a **late report**, is it the late day itself?
- **Fix (once answered):**
  1. Set `actual_work_date` when a report is filed, edited or restored, in every path that inserts
     `user_reports` rows (`create`, `replace`, `insertSnapshotLine`, the late-report path).
  2. Add `'actual_work_date' => 'Actual Work Date'` to the `reports.submitted` and `reports.late` field maps. It
     is safe once the portal always writes it.
  3. Backfill portal-filed lines with NULL the same way as Bug 1: a dry-run-first command that ledgers the edits.
- **Tests:**
  - daily and late filings store the date;
  - the sync sends "Actual Work Date" as `Y-m-d`;
  - an edit keeps it.

## Gap 3: overtime and offset requests carry no Activity Scope or Earn Code in Airtable

- **Facts:**
  - Airtable "Request" has **Activity Scope** (a link to Activity Code/s) and **Earn Code** (a link to Earn
    Codes). Imported requests carry both.
  - The portal only writes `projects_requests`. Nothing in `app/` writes `requests_activity_codes` or
    `requests_earn_codes`, though both junctions exist in `sql/portal/schema.sql`.
  - The sync map for `requests.overtime` and `requests.offset` (`AirtableSyncMap.php`, `REQUEST_PROJECTS`) only
    links Project.
- **Ask the user:**
  - **Which activity:** does the overtime/offset form choose one (does it have an activity picker), or should it
    take the project's main activity?
  - **Which earn code:**
    - overtime: "02 Regular Overtime", or the holiday/rest-day overtime variants (06, 10, 14, …) by the day,
      the same rule as Bug 1 option B;
    - offset: which code did the old portal use?
  - **Leave:** sick and vacation leave show "02 Sick Leave" or "01 Vacation Leave" as their Earn Code in
    Airtable, and the leave form never sets it. Should leave get it too?
- **Fix (once answered):**
  1. In `PortalOvertimeRequests`, `PortalOffsetRequests` (and `PortalLeaveRequests` if the answer is yes),
     write the chosen ids to `requests_activity_codes (request_id, activity_code_id)` and
     `requests_earn_codes (request_id, earn_code_id)` in the same `ledger->within` transaction as the request.
     - Check those junctions' column names in `sql/portal/schema.sql`.
  2. Add to the three request resources' `links`:
     ```php
     'Activity Scope' => ['junction' => 'requests_activity_codes', 'local' => 'request_id', 'foreign' => 'activity_code_id', 'table' => 'activity_codes'],
     'Earn Code' => ['junction' => 'requests_earn_codes', 'local' => 'request_id', 'foreign' => 'earn_code_id', 'table' => 'earn_codes'],
     ```
     These are full-row resources, so the links are sent on every create and update. No `column` key is needed.
  3. **Do not backfill** old portal requests unless the user asks.
- **Tests:** an overtime request creates with Project, Activity Scope and Earn Code linked; an offset the same.

## Order

1. Bug 1, the earn code match. It is a wrong value going to payroll, so fix it first.
2. Bug 2, Actual Work Date.
3. Gap 3, the request links.

Each step: filtered tests (`--filter="Airtable|PortalSubmittedReportsTest|PortalLateReportsTest|PortalOvertimeRequestsTest|PortalOffsetRequestsTest|PortalLeaveRequestsTest"`),
`vendor/bin/pint --dirty`, `graphify update .`. Then run a real check on the **TEST** base only (never PROD):
file one of each type locally, run `php artisan portal:airtable-sync --send`, and look at the records in the
TEST base.
