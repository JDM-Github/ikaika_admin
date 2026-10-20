# All emails the portal sends

Checked against the admin code on 2026-10-19 (admin `ee05c1b`). Every email goes out as **IKAIKA Portal &lt;noreply@ikaikabim.com&gt;** through the mail bridge, with the Company footer under it.

> **Master switch.** Every email in sections 1 to 4 is skipped while **Settings → Notification emails** is off (core `settings`, key `notifications.email_emails`). The bell notice is still written. On your local machine this switch is **OFF** (`0`), so nothing in sections 1 to 4 is emailed there. Sections 5 to 7 ignore the switch.

"Department head" means the person named for that department in `company_departments` (today: MIS → Nathaniel Tanglao, Engineering → Steven Gaurano). The person who did the action is never emailed about their own action.

---

## 1. Reports and requests a member files

| Sent to | Email (subject) | When |
|---|---|---|
| DEPARTMENT HEAD | New daily report | a member files a Daily Report |
| DEPARTMENT HEAD | New late report | a member files a Late Report |
| DEPARTMENT HEAD | New overtime request | a member files an Overtime request |
| DEPARTMENT HEAD | New offset request | a member files an Offset request |
| DEPARTMENT HEAD | New leave request | a member files a Leave request *(new in `ee05c1b`, see below)* |
| REQUEST APPROVERS: anyone holding **Request alerts** and the **reimbursement** rule whose reach covers the member | New reimbursement request | a member files a Reimbursement request |

Approvers who are not the head still get the **bell notice** for daily, late, overtime, offset and leave, just not the email.

## 2. Decisions on a member's request

| Sent to | Email (subject) | When |
|---|---|---|
| THE MEMBER WHO FILED IT | Request approved | their leave, overtime (incl. holiday work), offset or reimbursement is approved |
| THE MEMBER WHO FILED IT | Request rejected | the same, rejected |
| THE MEMBER WHO FILED IT | Request updated | the same, set back to pending |

A decision on a **late report** or a **flagged report** is a bell notice only, with no email.

## 3. Projects

| Sent to | Email (subject) | When |
|---|---|---|
| PROJECT APPROVERS: holders of **Project Approver** (`projects.manager`) whose reach covers the project | New project to approve | someone creates a project that needs approval (not sent when it is auto-approved) |
| PROJECT APPROVERS (same) | Project sent again | a rejected project is resubmitted |
| MEMBERS ADDED TO THE PROJECT | You were added to a project | a project is **created** with them as members (approved or auto-approved alike) |
| HOLDERS OF **Edit closed projects** whose reach covers the project | Project closed | a project is closed and waits for its close to be approved |
| THE PERSON WHO REQUESTED THE PROJECT | Project approved | an approver approves it |
| THE PERSON WHO REQUESTED THE PROJECT | Project back to waiting | an approver reopens it |
| HOLDERS OF **Request alerts** and **Project Approver** whose reach covers the editor | Project updated | someone edits a project (not a resubmit, not a close) |

**Not emailed:** members are **not** emailed when a project is edited, or when they are added to an existing project later (only on create). A rejected project is a bell notice to the requester only.

## 4. Calendar

| Sent to | Email (subject) | When |
|---|---|---|
| INVITED MEMBERS (an event for chosen members) | New event | someone creates an event |
| HOLDERS OF **Request alerts** and **Manage reports** whose reach covers the creator | New event | the same |

## 5. Scheduled, by the admin (not live until the admin is deployed)

| Sent to | Email (subject) | When |
|---|---|---|
| EACH MEMBER MISSING A REPORT (not executives) | Reminder: N reports not yet filed | weekdays 6:00 PM Manila, for missing work days in the last 5 (weekends, holidays, leave, offset and days outside employment skipped) |
| DEPARTMENT HEAD | Reminder: &lt;Department&gt; team missing report dates | the same run: everyone in their department who is missing a report |

Command: `php artisan portal:remind-missing-reports` (`--dry-run` lists, `--to=you@ikaikabim.com` sends you a sample).

## 6. Sent by a person on purpose

| Sent to | Email | When |
|---|---|---|
| WHOEVER THE SENDER CHOOSES (members, departments, roles, typed addresses) | the sender's own subject | **Administration → Send Email** |
| THE MEMBERS CHOSEN ON THE PAGE | Reminder: report not yet filed for &lt;day&gt; | **Administration → Missing Reports → remind** |
| A GUEST (the address a shared page was made for) | Your link and password for &lt;page&gt; / Your new password for &lt;page&gt; | a page is shared with a guest, or its password is replaced |

## 7. Security and sign-in

| Sent to | Email (subject) | When |
|---|---|---|
| THE PERSON SIGNING IN | Your IKAIKA Portal sign-in code | they choose "Email me a code" on the sign-in page |
| A GUEST OPENING A SHARED PAGE | Your code for &lt;page&gt; | the shared page asks for a one-time code |
| A NEW-HIRE CANDIDATE | Your code for &lt;name&gt; | they open their registration page |
| HOLDERS OF **Access alerts** (admins and executives by default) | Blocked portal access attempt | a signed-in person tries a page or action their rules refuse (at most once per 15 minutes per person and page) |

---

## Bell notice only, never emailed

Cancelled requests (leave, overtime, offset, reimbursement), flagged reports to review, late report decisions, project rejected, bug reports, profile changes to review/approved/rejected, role changed, department changed, "an email was sent to you" notices, restored from the recycle bin.

## Still running on the old WordPress server (cron, reads Airtable)

These are **not** part of the new portal. They live in `~/ikaika_scripts/portaldotikaikabim/` and run from **cPanel → Cron Jobs**. Since v2 no longer writes to Airtable, each one now works from stale data:

| Script | Time | Sends |
|---|---|---|
| `reportsubmission_reminder.php` | weekdays 6:00 PM Manila | missing-report reminder to each member + MIS/Engineering summaries (**turn off** once the admin's reminder is live, or people get two) |
| `notify-admin-for-ot-and-leaverequest.php` | weekdays 6:00 PM Manila | overtime and leave notices to admins |
| `idle-project-reminder.php` | weekdays 6:00 PM Manila | idle project reminders |
| `createCalendarEvents.php` | weekdays 6:00 PM Manila | calendar events |
| `monthly_newsletter.php` | 1st of the month | monthly newsletter |

## Why your leave request reached no head (fixed in `ee05c1b`)

1. **Leave never emailed the head.** Overtime, offset, daily and late reports email the department head directly. Leave only emailed people holding **both** "Request alerts" **and** the leave approval rule with a reach covering you, so a head without those two rules got nothing. Leave now emails the department head like the others, and approvers keep the bell notice.
2. **Locally, all notification emails are switched off** (`notifications.email_emails = 0`), so on your machine no head gets anything until you turn **Settings → Notification emails** on. Check the same toggle on production.
