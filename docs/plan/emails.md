# Every email the portal sends

The one list of every email the system sends: what triggers it, who gets it, what it says, and where
its code lives. **Keep it current:** any change that adds, removes or rewords an email updates this
file in the same commit.

**To change an email:** edit its section here (subject, body, who gets it, on or off), add a line under
[Requested changes](#requested-changes), and ask Claude to "apply docs/plan/emails.md". The section is
the spec; the code follows it.

Last checked against the code: 2026-10-12.

---

## How mail leaves

| Piece | What it is |
| --- | --- |
| Sender | `noreply@ikaikabim.com`, display name **IKAIKA Portal** (system emails) |
| Transport | `MAIL_MAILER=wprelay`: each email is a POST to the WordPress plugin on ikaikabim.com, which sends it with `wp_mail`. A few seconds per email. See `docs/deploy/wordpress/`. |
| Local | The same relay, or `MAIL_MAILER=log` (emails land in `storage/logs/laravel.log`). |
| Letter design | `PortalEmailMarkup` builds the HTML: the IKAIKA letterhead, the body, then the **Company footer** (the first footer block in Send Email -> Footers). |
| One sender for system letters | `PortalLetters::send()` -- letter design + Company footer + IKAIKA Portal, sent **after the response** (`defer()`), a failure logged as `portal.letter.failed`. |
| Test | `php artisan portal:mail-test [to]` sends a test and prints the relay's answer. |

---

## All emails at a glance

| # | Email | Triggered by | Goes to | Design | Sent | Code |
| --- | --- | --- | --- | --- | --- | --- |
| 1 | Sign-in code | "Email me a code" on the sign-in page | The employee's **work** email | Letter + footer | After response | `PortalEmailLogin::start` |
| 2 | Shared page: link + password | Creating a shared page **with an email** | The share's bound email | Letter + footer + **QR** | After response | `PortalSharedPages::mailPassword` (from `create`) |
| 3 | Shared page: new password | **New password** on a share with an email | The share's bound email | Letter + footer + **QR** | After response | `PortalSharedPages::mailPassword` (from `regenerate`) |
| 4 | Shared page: sign-in code | A guest signs in to a share with an email and "Ask for a code" on | The share's bound email | Letter + footer | After response | `PortalSharedPages::sendOtp` |
| 5 | Registration code | A candidate opens their registration link | The candidate's email | **Plain text, no footer** | **During the request** | `PortalRegistrations::sendOtp` |
| 6 | Notification emails (11 kinds) | Requests, projects, calendar events -- see [#6](#6-notification-emails) | Managers or the person concerned | Letter-style + footer | **During the request** | `PortalNotificationEmails::send` via `PortalAudit` |
| 7 | Blocked access alert | A signed-in user hits a page or action they may not use | Everyone holding **Access alerts** (`administration.alerts`) | **Plain text, no footer** | **During the request** | `PortalAccessAlert::report` + `App\Mail\PortalAccessAlertMail` |
| 8 | Send Email (composer) | An admin presses **Send Email** | Whoever the admin picks | The admin's choice (Formatted / Letter / HTML, any footer) | During the request | `PortalEmailMessages` |
| 9 | Mail test | `php artisan portal:mail-test` | The address given (default jdmpega@) | Plain | Immediately | `routes/console.php` |

**Drafts that open Send Email (#8) pre-filled** -- nothing is sent until the admin presses Send:

| Draft | Opened from | Code (portal) |
| --- | --- | --- |
| Missing report reminder | Manage / Missing Reports -> Email | `MissingReportsConstant.emailSubject/emailBody` |
| Onboarding requirements | Manage / Onboarding -> Send | `OnboardingConstant.emailSubject/emailBody` |
| Shared page resend | Manage / Shared Pages -> Link and QR -> Resend by email | `SharedPagesConstant.emailSubject/emailBody` |

---

## 1. Sign-in code

- **When:** "Email me a code" on the sign-in page, or "Send a new code" (60-second wait between).
- **To:** the employee's **work email only** (supervisor, 2026-10-12). A personal email, an unknown one
  or an inactive account gets no email and reads "No active portal account uses that email."
- **Code rules:** 6 digits, 10 minutes, 5 tries.
- **Subject:** `Your IKAIKA Portal sign-in code`
- **Body:**
  > Hi {first name},
  >
  > Your IKAIKA Portal sign-in code is **{code}**. It is good for 10 minutes.
  >
  > If you did not try to sign in, you can ignore this email; nobody can sign in without the code.
  >
  > Best Regards,
  > IKAIKA Portal

## 2. Shared page: link and password

- **When:** a shared page is created with an email bound.
- **To:** the bound email.
- **Subject:** `Your link and password for {share name}`
- **Body:**
  > Hi {guest name, else share name},
  >
  > A read-only shared page is ready for you: **{share name}**.
  >
  > Link: {link}
  > Password: **{password}**
  >
  > Or scan this code with your phone to open the link:
  > [QR code of the link]
  >
  > It is good until {date, time}. *(or: It does not expire until it is revoked.)* It is read-only: you can look, not change anything.
  >
  > Best Regards,
  > IKAIKA Portal

## 3. Shared page: new password

- **When:** **New password** on a share that has an email.
- **To:** the bound email.
- **Subject:** `Your new password for {share name}`
- **Body:** as #2, but the second line reads:
  > The password for your read-only shared page, **{share name}**, was changed. The old one no longer works.

## 4. Shared page: sign-in code

- **When:** a guest types the right password on a share with an email and "Ask for a one-time code" on.
- **To:** the bound email.
- **Code rules:** 6 digits, 10 minutes, 5 tries, 60 seconds between resends.
- **Subject:** `Your code for {share name}`
- **Body:**
  > Hi {guest name, else share name},
  >
  > Your code is **{code}**. It is good for 10 minutes.
  >
  > Open the shared page: {link}
  >
  > Best Regards,
  > IKAIKA Portal

## 5. Registration code

- **When:** a candidate types the password on their `/register/<code>` link.
- **To:** the candidate's bound email.
- **Subject:** `Your code for {candidate name}`
- **Body (plain text):**
  > Your code is {code}. It is good for 10 minutes.
  >
  > Open your registration: {link}

## 6. Notification emails

Every in-portal notice of these kinds is also emailed, as a letter: the notice's **title** as a heading,
its **message**, then a link to the page. Subject = the notice title. The wording comes from
`PortalActivityCopy` (one sentence per kind).

**On/off:** Settings -> Advanced (Developer mode only) -> notification emails; default from
`PORTAL_NOTIFICATION_EMAILS`. Kinds not listed below stay in the portal only.

| Kind (type key) | Subject (the notice title) | Triggered by | Goes to |
| --- | --- | --- | --- |
| Leave / overtime / offset / reimbursement **filed** (`requests.<kind>.filed`) | per kind, from `PortalActivityCopy` | An employee files the request | The managers who decide it |
| Request **approved / rejected / set back to pending** (`requests.<kind>.approved` / `.rejected` / `.pending`) | per decision, from `PortalActivityCopy::notifyDecided` | A manager decides it on Manage / Requests | The employee who filed it (not when they decided it themselves) |
| Project **requested** (`projects.requested`) | `New project to approve` (or `Project sent again`) | A project that needs approval is added or resubmitted | Project Approvers whose reach covers it |
| Project **members added** (`projects.requested.members`) | `You were added to a project` | A project is created with members | The members added |
| Project **approved** (`projects.approved`) | `Project approved` | An approver approves it | The person who requested it |
| Project **modified** (`projects.modified`) | `Project updated` | A project is edited | Project Approvers (managers holding the approval rule) |
| Calendar **event created** (`calendar.event.created`) | from `PortalActivityCopy` | An event is created | Managers and the invitees |

**Not emailed** (portal notice only): request cancelled, late report filed, role or department
changed, project rejected, recycle bin restore, "email sent".

## 7. Blocked access alert

- **When:** a signed-in user is refused a page or action (403). At most once per person, method and
  path every 15 minutes.
- **To:** everyone who holds **Access alerts** (admins and executives by preset).
- **Subject:** `Blocked portal access attempt`
- **Body (plain text):**
  > An authenticated portal user tried to reach something they cannot access.
  >
  > Employee id / Id no / Name / Role / Request / IP / When / Reason

## 8. Send Email (composer)

Written by an admin on the Email page: audience (members, departments, roles, everyone, or typed
addresses), Cc/Bcc, category, Formatted / Letter design / HTML, a footer, attachments and images.
Sent from IKAIKA Portal, or "{admin} via IKAIKA Portal" with Reply-To the admin. Every send is kept in
All Emails. Code: `PortalEmailMessages` (server), `SendEmailScreenController` (portal).

## 9. Mail test

`php artisan portal:mail-test [to] [--message=<id>]` -- a plain test, or one stored All Emails message
resent to the test address. Writes `storage/logs/mail-test.log`.

---

## Known problems

| # | Problem | Effect | Fix |
| --- | --- | --- | --- |
| 5, 6, 7 | Sent **during the request**, not after it | The action that triggers them waits a few seconds per email (the relay); a notice to several managers waits for each | Send them through `PortalLetters` / `defer()` like #1-4 |
| 5, 7 | **Plain text**, no letter design or Company footer | They look unlike every other system email | Move to `PortalLetters::send` with a letter body |
| 5 | No "Hi {name}" or sign-off | Reads abruptly | Word it like #4 |
| 8 | A big send to many people waits for every email | The Send button can take long | Send in the background (needs a queue or `defer()`) |
| Resend (draft) | The Shared Pages resend letter has **no QR** | Weaker than #2 | Send Email can only attach uploaded files; needs a different approach |

---

## Requested changes

Write what you want changed here (which email, what to say, who gets it), then ask Claude to apply it.

- *(none yet)*

---

## Adding a new email

1. Send it through `PortalLetters::send()` unless it must be different (then say why here).
2. Add it to [All emails at a glance](#all-emails-at-a-glance) and give it a section with subject,
   body, recipients and trigger.
3. Test it with `Mail::fake()` (recipient, subject, letter design).
4. Update this file in the same commit.
