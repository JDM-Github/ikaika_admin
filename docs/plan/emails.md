# Every email the portal sends

The one list of every email the system sends: what triggers it, who gets it, how it looks, what it
says, and where its code lives. **Keep it current:** any change that adds, removes or rewords an email
updates this file in the same commit.

**To change an email:** edit its section here (subject, body, design, who gets it, on or off), add a
line under [Requested changes](#requested-changes), and ask Claude to "apply docs/plan/emails.md". The
section is the spec; the code follows it.

Last checked against the code: 2026-10-13.

---

## How mail leaves

| Piece | What it is |
| --- | --- |
| Address | Always `noreply@ikaikabim.com`. |
| Sender name | **IKAIKA Portal** when the email sets it. One that does not set it uses `MAIL_FROM_NAME`: "IKAIKA Portal" locally, but **"IKAIKA Platform" on the Test Server** (its `.env` has `MAIL_FROM_NAME="${APP_NAME}"`). |
| Transport | `MAIL_MAILER=wprelay`: each email is a POST to the WordPress plugin on ikaikabim.com, which sends it with `wp_mail`. A few seconds per email. See `docs/deploy/wordpress/`. |
| Local | The same relay, or `MAIL_MAILER=log` (emails land in `storage/logs/laravel.log`). |
| HTML builder | `PortalEmailMarkup::document()` -- one builder for every design below, the same one Send Email's preview uses. |
| One sender for system letters | `PortalLetters::send()`: Letter design + Company footer + IKAIKA Portal, through the **mail queue**. A one-time code uses `PortalLetters::sendAfterResponse()` instead: same letter, sent right **after the response** (`defer()`), so it never waits for the queue. A failure is logged as `portal.letter.failed`. |
| Mail queue | The database queue (`jobs` table). The server has no always-on worker: the scheduler's every-minute cron starts `queue:work --stop-when-empty --max-time=55`, so a queued email leaves within about a minute. Each email gets 3 tries (now, +1 min, +5 min); then a system email is logged under its own failure event and a Send Email copy is counted failed on its row. `deploy-admin.sh` sets `QUEUE_CONNECTION=database` on the server; locally `.env` keeps `sync`, which sends inside the request as before. Jobs: `App\Jobs\SendPortalMail` (system emails), `App\Jobs\DeliverPortalEmail` (Send Email copies). |
| Test | `php artisan portal:mail-test [to]` sends a test and prints the relay's answer. |

## The designs

Every email uses one of these. The **Company footer** is the first footer block in Send Email -> Footers
(logo, IKAIKA BIM, ikaikabim.com, info@, "IKAIKA Portal - Automated communication", the office address,
and the confidentiality note).

| Design | What the reader sees | Builder |
| --- | --- | --- |
| **Letter** | A white card with rounded corners on a light grey page, max 640px wide. At the top the **letterhead**: a blue bar, the IKAIKA BIM logo, "IKAIKA BIM" and "ikaikabim.com". The message below, in Inter 15px. The footer, when there is one, inside the card under a thin rule. | `FORMAT_LETTER` -> `PortalEmailMarkup::letter()` |
| **Formatted** | No card, no letterhead: the message as styled text (Inter 15px, bold, headings, links), then the footer under a thin rule when there is one. | `FORMAT_MARKUP` |
| **HTML** | Whatever HTML was written, sent as is; the footer goes before `</body>` (or at a `portal:footer` marker). Send Email only. | `FORMAT_HTML` |
| **Plain** | Unstyled text in a `<pre>` block (keeps line breaks), no letterhead, no footer. | `PortalAccessAlertMail` only |

Message text is written in the portal's markup (`**bold**`, `# heading`, `[label](link)`, `![alt](image)`);
one line break stays a line break.

---

## All emails at a glance

| # | Email | Goes to | Design | Letterhead | Company footer | Sender name | Extras | Sent |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| 1 | Sign-in code | Employee's **work** email | Letter | Yes | Yes | IKAIKA Portal | - | After response |
| 2 | Shared page: link + password | Share's bound email | Letter | Yes | Yes | IKAIKA Portal | **QR** image inline | Queue |
| 3 | Shared page: new password | Share's bound email | Letter | Yes | Yes | IKAIKA Portal | **QR** image inline | Queue |
| 4 | Shared page: sign-in code | Share's bound email | Letter | Yes | Yes | IKAIKA Portal | - | After response |
| 5 | Registration code | The candidate | Letter | Yes | Yes | IKAIKA Portal | - | After response |
| 6 | Notification emails (11 kinds) | Managers or the person concerned | Formatted | **No** | Yes | IKAIKA Portal | Link to the page | Queue |
| 7 | Blocked access alert | Holders of Access alerts | Plain | **No** | **No** | Default (see above) | - | Queue |
| 8 | Send Email (composer) | Whoever the admin picks | Admin's choice | If Letter | Admin's choice | IKAIKA Portal, or "{admin} via IKAIKA Portal" + Reply-To the admin | Images, attachments | Queue, one copy per recipient |
| 9 | Mail test | The address given | Formatted | No | A one-line test footer | Default (see above) | - | Immediately |

**Drafts that open Send Email (#8) pre-filled** -- nothing is sent until the admin presses Send, and
the admin can change everything:

| Draft | Opened from | Design it opens with | Footer | Code (portal) |
| --- | --- | --- | --- | --- |
| Missing report reminder | Manage / Missing Reports -> Email | Whatever the composer last used | Whatever the composer last used | `MissingReportsConstant.emailSubject/emailBody` |
| Onboarding requirements | Manage / Onboarding -> Send | Whatever the composer last used | Whatever the composer last used | `OnboardingConstant.emailSubject/emailBody` |
| Shared page resend | Manage / Shared Pages -> Link and QR -> Resend by email | **Letter**, category Notice | **Company footer** | `SharedPagesConstant.emailSubject/emailBody` |

---

## 1. Sign-in code

| | |
| --- | --- |
| When | "Email me a code" on the sign-in page, or "Send a new code" (60 s between) |
| To | The employee's **work email only** (supervisor, 2026-10-12). A personal email, an unknown one or an inactive account gets no email; the page reads "No active portal account uses that email." |
| Design | Letter, letterhead, Company footer, from IKAIKA Portal, after the response |
| Code rules | 6 digits, 10 minutes, 5 tries |
| Code | `PortalEmailLogin::start` -> `PortalLetters::sendAfterResponse` |

**Subject:** `Your IKAIKA Portal sign-in code`

> Hi {first name},
>
> Your IKAIKA Portal sign-in code is **{code}**. It is good for 10 minutes.
>
> If you did not try to sign in, you can ignore this email; nobody can sign in without the code.
>
> Best Regards,
> IKAIKA Portal

## 2. Shared page: link and password

| | |
| --- | --- |
| When | A shared page is created with an email bound |
| To | The bound email |
| Design | Letter, letterhead, Company footer, from IKAIKA Portal, through the mail queue; the link's **QR code** as an inline PNG (about 300 px), carried in the queue as base64 and embedded when it is sent |
| Code | `PortalSharedPages::create` -> `mailPassword` -> `PortalLetters::send`; QR from `PortalQrPng` |

**Subject:** `Your link and password for {share name}`

> Hi {guest name, else share name},
>
> A read-only shared page is ready for you: **{share name}**.
>
> Link: {link}
> Password: **{password}**
>
> Or scan this code with your phone to open the link:
>
> [QR code of the link]
>
> It is good until {12 Oct 2026, 3:24 pm}. *(Never-expiring: "It does not expire until it is revoked.")* It is read-only: you can look, not change anything.
>
> Best Regards,
> IKAIKA Portal

## 3. Shared page: new password

| | |
| --- | --- |
| When | **New password** on a share that has an email |
| To | The bound email |
| Design | As #2 (Letter, letterhead, Company footer, QR, through the mail queue) |
| Code | `PortalSharedPages::regenerate` -> `mailPassword` |

**Subject:** `Your new password for {share name}`

Body as #2, with the second paragraph:

> The password for your read-only shared page, **{share name}**, was changed. The old one no longer works.

## 4. Shared page: sign-in code

| | |
| --- | --- |
| When | A guest types the right password on a share with an email and "Ask for a one-time code" on |
| To | The bound email |
| Design | Letter, letterhead, Company footer, from IKAIKA Portal, after the response |
| Code rules | 6 digits, 10 minutes, 5 tries, 60 s between resends |
| Code | `PortalSharedPages::sendOtp` -> `PortalLetters::sendAfterResponse` |

**Subject:** `Your code for {share name}`

> Hi {guest name, else share name},
>
> Your code is **{code}**. It is good for 10 minutes.
>
> Open the shared page: {link}
>
> Best Regards,
> IKAIKA Portal

## 5. Registration code

| | |
| --- | --- |
| When | A candidate types the password on their `/register/<code>` link |
| To | The candidate's bound email |
| Design | Letter, letterhead, Company footer, from IKAIKA Portal, after the response |
| Code rules | 6 digits, 10 minutes |
| Code | `PortalRegistrations::sendOtp` -> `PortalLetters::sendAfterResponse` |

**Subject:** `Your code for {candidate name}`

> Hi {candidate name},
>
> Your code is **{code}**. It is good for 10 minutes.
>
> Open your registration: {link}
>
> Best Regards,
> IKAIKA Portal

## 6. Notification emails

Every in-portal notice of the kinds below is also emailed.

| | |
| --- | --- |
| Design | **Formatted, no letterhead**, Company footer, from IKAIKA Portal, through the mail queue (one email per recipient) |
| Body | `# {subject}` as a heading, the message, then the link (when the notice has one) as `[{link label}]({portal}/{page})` |
| On / off | Settings -> Advanced (Developer mode only); default from `PORTAL_NOTIFICATION_EMAILS` |
| Skipped | Nobody is emailed about their own action; an employee with no valid work email gets no email |
| Code | `PortalAudit::notify / notifyEach / notifyManagers / notifyIfOther` -> `PortalNotificationEmails::send` -> `SendPortalMail` job; wording in `PortalActivityCopy`; a failure is logged as `portal.notification.email.failed` |

Dates read like "Monday, October 12, 2026"; a range reads "from {date} to {date}".

| Kind (type key) | Subject | Message | Link | Goes to |
| --- | --- | --- | --- | --- |
| Leave filed (`requests.leave.filed`) | New leave request | {name} filed a leave request {date or range}. | Open queue -> Manage / Requests | The managers who decide it |
| Overtime filed (`requests.overtime.filed`) | New overtime request | {name} filed a overtime request {date}. | Open queue | The managers who decide it |
| Offset filed (`requests.offset.filed`) | New offset request | {name} filed a offset request {date}. | Open queue | The managers who decide it |
| Reimbursement filed (`requests.reimbursement.filed`) | New reimbursement request | {name} filed a reimbursement request {date}. | Open queue | The managers who decide it |
| Request decided (`requests.<kind>.approved` / `.rejected` / `.pending`) | Request approved / Request rejected / Request updated (*Late report ...* for a late report) | Your {kind} for {date} was approved / rejected / set back to pending by {manager}. | Open requests -> User Requests | The employee who filed it |
| Project requested (`projects.requested`) | New project to approve (or Project sent again) | {name} added the project {project} and it waits for approval. (or: {name} sent the project {project} again for approval.) | Open Manage / Projects | Project Approvers whose reach covers it |
| Project members added (`projects.requested.members`) | You were added to a project | {name} added you to the project {project}. | View Projects | The members added |
| Project approved (`projects.approved`) | Project approved | {name} approved the project {project}. | Created Projects | The person who requested it |
| Project modified (`projects.modified`) | Project updated | {name} edited the project {project}. | Open Manage / Projects | Project Approvers |
| Event created (`calendar.event.created`) | New event | {name} added the event {title} {date or range}. | *(none)* | Holders of Manage / Reports, and the invitees |

**Not emailed** (portal notice only): request cancelled, late report filed, role or department
changed, project rejected, recycle bin restore, "email sent".

## 7. Blocked access alert

| | |
| --- | --- |
| When | A signed-in user is refused a page or action (403); at most once per person, method and path every 15 minutes |
| To | Everyone holding **Access alerts** (`administration.alerts`; admins and executives by preset) |
| Design | **Plain** (unstyled `<pre>` text), no letterhead, no footer, sender name not set (Test Server: "IKAIKA Platform"), through the mail queue |
| Code | `PortalAccessAlert::report` -> `SendPortalMail` job -> `App\Mail\PortalAccessAlertMail`; a failure is logged as `portal.access.alert.failed` |

**Subject:** `Blocked portal access attempt`

> An authenticated portal user tried to reach something they cannot access.
>
> Employee id: {id}
> Id no: {ID number}
> Name: {name}
> Role: {role} / {role level}
> Request: {method} {path}
> IP: {ip}
> When: {ISO date and time}
> Reason: {why it was refused}

## 8. Send Email (composer)

| | |
| --- | --- |
| When | An admin presses **Send Email** on the Email page |
| To | Members, departments, roles, everyone, or typed addresses; Cc and Bcc |
| Design | The admin picks: Formatted, Formatted + **Letter design** switch, or HTML; any saved footer or none; images and attachments |
| Sender | IKAIKA Portal, or "{admin} via IKAIKA Portal" with Reply-To the admin |
| Sent | Through the mail queue: the Send button answers at once and the row reads **Sending**. Each member gets their own copy (a `DeliverPortalEmail` job), Cc/Bcc share one more, and typed addresses go as one email. Each copy counts itself onto the row as it lands; the row settles on Sent, Partially sent or Failed once every recipient is counted. The composer's toast says "Email queued for N recipients"; All Emails re-reads itself every 10 s while a row it shows is Sending. If the queue cannot take the send at all, the row is marked Failed and the button shows the error |
| Kept | Every send is stored and listed in All Emails |
| Code | `PortalEmailMessages::deliver / deliverCopy / settleCopy`, `App\Jobs\DeliverPortalEmail` (server); `SendEmailScreenController`, `AllEmailsScreenController` (portal) |

## 9. Mail test

| | |
| --- | --- |
| When | `php artisan portal:mail-test [to] [--message=<id>]` |
| Design | Formatted, no letterhead, a one-line footer "Sent by the portal's mail test."; `--message` resends a stored All Emails message exactly as it was built |
| Code | `routes/console.php`, `PortalEmailMessages::testMail` |

**Subject:** `IKAIKA Portal mail test`

> This is a **test** from the portal.
> If you can read this, sending works.

---

## Known problems

| # | Problem | Effect | Fix |
| --- | --- | --- | --- |
| 7 | No letterhead, no Company footer; unstyled text | It looks unlike every other system email | Move to `PortalLetters::send` with a Letter body |
| 6 | Formatted, not Letter: no letterhead | Notification emails look plainer than the sign-in and share emails | Switch `PortalNotificationEmails` to `FORMAT_LETTER` |
| 7, 9 | Sender name not set | They arrive as **"IKAIKA Platform"** on the Test Server | Set IKAIKA Portal, or fix `MAIL_FROM_NAME` on the server |
| 2, 3, 6, 7, 8 | Queued emails need the server's scheduler cron | Without the every-minute `schedule:run` cron nothing works the queue: those emails wait, and Send Email rows stay Sending | Every admin deploy adds the cron if it is missing; check cPanel -> Cron Jobs if mail stops |
| 6 | "{name} filed a leave request Monday, ..." -- no "for"; "a overtime / a offset" | Reads oddly | Reword `PortalActivityCopy::notifyFiled` |
| 6 | Event created has no link | The reader cannot open the event from the email | Give the notice a link to the Event Calendar |
| Resend draft | No QR code | Weaker than #2 | Send Email can only attach uploaded files; needs a different approach |

---

## Requested changes

Write what you want changed here (which email, what to say, which design, who gets it), then ask
Claude to apply it.

- *(none yet)*

---

## Adding a new email

1. Send it through `PortalLetters::send()` (Letter, Company footer, IKAIKA Portal, through the mail queue);
   a one-time code someone waits on uses `PortalLetters::sendAfterResponse()`. Anything different -- say why here.
2. Add it to [All emails at a glance](#all-emails-at-a-glance) and give it a section with its design,
   subject, body, recipients and trigger.
3. Test it with `Mail::fake()` (recipient, subject, design); the test queue is `sync`, so a queued email is sent
   inside the test. Use `Queue::fake()` to check it was queued instead of sent.
4. Update this file in the same commit.
