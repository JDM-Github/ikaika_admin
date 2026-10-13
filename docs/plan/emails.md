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
| Sender name | **IKAIKA Portal**. Every system email sets it; Send Email's "Portal" sender uses `MAIL_FROM_NAME`, which `deploy-admin.sh` writes as `"IKAIKA Portal"` on every deploy (it used to be "IKAIKA Platform" on the Test Server). |
| Transport | `MAIL_MAILER=wprelay`: each email is a POST to the WordPress plugin on ikaikabim.com, which sends it with `wp_mail`. A few seconds per email. See `docs/deploy/wordpress/`. |
| Local | The same relay, or `MAIL_MAILER=log` (emails land in `storage/logs/laravel.log`). |
| HTML builder | `PortalEmailMarkup::document()` -- one builder for every design below, the same one Send Email's preview uses. |
| One sender for system letters | `PortalLetters::send()`: Letter design + Company footer + IKAIKA Portal, through the **mail queue**. Every system email's words go through `PortalLetters::body($firstName, $message)`: "Hi {first name}," (or "Hi there,"), the message, then "Best Regards, / IKAIKA Portal" -- so no two can drift apart. People's own text set in a letter (names, titles, reasons) goes through `PortalEmailMarkup::escape()` so a `*` or `[` stays text. A one-time code uses `PortalLetters::sendAfterResponse()` instead: same letter, sent right **after the response** (`defer()`), so it never waits for the queue. A failure is logged as `portal.letter.failed`. |
| Mail queue | The database queue (`jobs` table). The server has no always-on worker: the scheduler's every-minute cron starts `queue:work --stop-when-empty --max-time=55`, so a queued email leaves within about a minute. Each email gets 3 tries (now, +1 min, +5 min); then a system email is logged under its own failure event and a Send Email copy is counted failed on its row. `deploy-admin.sh` sets `QUEUE_CONNECTION=database` on the server; locally `.env` keeps `sync`, which sends inside the request as before. Jobs: `App\Jobs\SendPortalMail` (system emails), `App\Jobs\DeliverPortalEmail` (Send Email copies). |
| Test | `php artisan portal:mail-test [to]` sends a test and prints the relay's answer. |

## The designs

**Every email the system writes by itself is a Letter with the Company footer.** Formatted and HTML are
only for Send Email. The **Company footer** is the first footer block in Send Email -> Footers (logo,
IKAIKA BIM, ikaikabim.com, info@, "IKAIKA Portal - Automated communication", the office address, and the
confidentiality note).

Links are **never underlined**: they read by their blue colour alone. Anything a mail client would find
by itself and underline (the letterhead's ikaikabim.com, the footer's office address, which links to the
map) is a link of our own, styled as plain text, and every letter asks phones not to detect addresses,
dates or numbers (`format-detection` meta + an `x-apple-data-detectors` rule). Message text is **14px**
(headings 20 / 17 / 15px).

**Category.** Every email says what it is about, twice: in front of its subject (`[Security] Your
IKAIKA Portal sign-in code`) and, in a letter, as a coloured pill above the title (the subject, in
20px bold). `PortalEmailMarkup::subject()` / `header()`; colours in `PortalEmailMarkup::CATEGORIES`.

| Category | Colour | Used by |
| --- | --- | --- |
| **Security** | indigo | sign-in code, shared-page letters and code, registration code |
| **Warning** | amber | blocked access alert |
| **Request** | teal | request filed / decided notifications |
| **Project** | purple | project notifications |
| **Event** | green | new-event notifications; Send Email's Event |
| **Notice** | brand blue | mail test, Missing Reports reminder; Send Email's Notice and the three drafts |
| **Complaint** | red | Send Email's Complaint |
| Other | -- | Send Email's Other: no pill, no prefix |

**Blocks.** Two lines of markup become blocks of their own in any markup body (letters and Formatted):

| Line | Becomes |
| --- | --- |
| `::code 655991` | The code (or password) in a box: monospace, bold, brand blue, spaced wide (30px for 8 characters or fewer, 22px for longer) |
| `::button[Open the shared page](https://...)` | A brand-blue button with that label; only an `http(s)://` or `/` link -- anything else stays text |
| Lines starting `> ` | A tinted details panel (light grey, brand-blue left edge, rounded) |

A link is a button, never a long address to read or copy. The footer sits 16px under the sign-off.

| Design | What the reader sees | Builder |
| --- | --- | --- |
| **Letter** | A white card with rounded corners on a light grey page, max 640px wide. At the top the **letterhead**: a blue bar, the IKAIKA BIM logo, "IKAIKA BIM" and "ikaikabim.com". The message below, in Inter 14px. The footer, when there is one, inside the card under a thin rule. | `FORMAT_LETTER` -> `PortalEmailMarkup::letter()` |
| **Formatted** | No card, no letterhead: the message as styled text (Inter 14px, bold, headings, links), then the footer under a thin rule when there is one. Send Email only. | `FORMAT_MARKUP` |
| **HTML** | Whatever HTML was written, sent as is; the footer goes before `</body>` (or at a `portal:footer` marker). Send Email only. | `FORMAT_HTML` |

Message text is written in the portal's markup (`**bold**`, `# heading`, `[label](link)`, `![alt](image)`);
one line break stays a line break.

---

## All emails at a glance

| # | Email | Category | Goes to | Design | Company footer | Sender name | Blocks | Sent |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| 1 | Sign-in code | Security | Employee's **work** email | Letter | Yes | IKAIKA Portal | code box | After response |
| 2 | Shared page: link + password | Security | Share's bound email | Letter | Yes | IKAIKA Portal | button, password box, **QR** inline | Queue |
| 3 | Shared page: new password | Security | Share's bound email | Letter | Yes | IKAIKA Portal | button, password box, **QR** inline | Queue |
| 4 | Shared page: sign-in code | Security | Share's bound email | Letter | Yes | IKAIKA Portal | code box, button | After response |
| 5 | Registration code | Security | The candidate | Letter | Yes | IKAIKA Portal | code box, button | After response |
| 6 | Notification emails (11 kinds) | Request / Project / Event | Managers or the person concerned | Letter | Yes | IKAIKA Portal | button to the page | Queue |
| 7 | Blocked access alert | Warning | Holders of Access alerts, one letter each | Letter | Yes | IKAIKA Portal | details panel | Queue |
| 8 | Send Email (composer) | The admin's pick | Whoever the admin picks | Admin's choice | Admin's choice | IKAIKA Portal, or "{admin} via IKAIKA Portal" + Reply-To the admin | any; images, attachments | Queue, one copy per recipient |
| 9 | Mail test | Notice | The address given | Letter | Yes | IKAIKA Portal | - | Immediately |

**Drafts that open Send Email (#8) pre-filled** -- nothing is sent until the admin presses Send, and
the admin can change everything. All three open as the same letter as the system emails: **Letter**
design, category Notice, the **Company footer** (by name, `SendEmailConstant.companyFooterName`), a
greeting by first name, the link as a `::button`, a password in a `::code` box, signed "Best Regards," +
the signed-in admin's name. The composer's preview shows the category pill and the subject as the title.

| Draft | Opened from | Greets | Code (portal) |
| --- | --- | --- | --- |
| Missing report reminder | Manage / Missing Reports -> Email | The member's first name, or "everyone" for several | `MissingReportsConstant.emailSubject/emailBody` |
| Onboarding requirements | Manage / Onboarding -> Send | The candidate's first name | `OnboardingConstant.emailSubject/emailBody` |
| Shared page resend | Manage / Shared Pages -> Link and QR -> Resend by email | The guest name, else the share name | `SharedPagesConstant.emailSubject/emailBody` |

Missing report reminder:

> Hi {first name | everyone},
>
> We have no report from you for {day}. Please file it as a Late Report when you can.
>
> Best Regards,
> {admin}

Onboarding requirements:

> Hi {first name},
>
> Attached are your Job Offer Letter and Employment Agreement Contract. Please review them, and once you accept, sign and complete the documents and send them back to us.
>
> When your requirements are complete, submit them on your registration page.
>
> [ Open your registration ]   *(button)*
>
> Your password:
>
> [ {password} ]   *(password box)*
>
> Best Regards,
> {admin}

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
> Use this code to sign in to the IKAIKA Portal. It is good for 10 minutes.
>
> [ {code} ]   *(code box)*
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
> [ Open the shared page ]   *(button)*
>
> Your password:
>
> [ {password} ]   *(password box)*
>
> Or scan this code with your phone to open it:
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
> Use this code to open **{share name}**. It is good for 10 minutes.
>
> [ {code} ]   *(code box)*
>
> [ Open the shared page ]   *(button)*
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
> Use this code to open your registration. It is good for 10 minutes.
>
> [ {code} ]   *(code box)*
>
> [ Open your registration ]   *(button)*
>
> Best Regards,
> IKAIKA Portal

## 6. Notification emails

Every in-portal notice of the kinds below is also emailed.

| | |
| --- | --- |
| Design | Letter, letterhead, Company footer, from IKAIKA Portal, through the mail queue (one email per recipient) |
| Body | "Hi {first name},", the message, then the link (when the notice has one) as a button `::button[{link label}]({portal}/{page})`, then "Best Regards, IKAIKA Portal". Category: Request for `requests.*`, Project for `projects.*`, Event for `calendar.*` |
| On / off | Settings -> Advanced (Developer mode only); default from `PORTAL_NOTIFICATION_EMAILS` |
| Skipped | Nobody is emailed about their own action; an employee with no valid work email gets no email |
| Code | `PortalAudit::notify / notifyEach / notifyManagers / notifyIfOther` -> `PortalNotificationEmails::send` -> `SendPortalMail` job; wording in `PortalActivityCopy`; a failure is logged as `portal.notification.email.failed` |

Dates read like "Monday, October 12, 2026"; one day reads "for {date}", a range "from {date} to {date}".

> Hi {first name},
>
> Juan Dela Cruz filed a leave request for Monday, October 12, 2026.
>
> [ Open queue ]   *(button to {portal}/manage/manage-requests)*
>
> Best Regards,
> IKAIKA Portal

| Kind (type key) | Subject | Message | Link | Goes to |
| --- | --- | --- | --- | --- |
| Leave filed (`requests.leave.filed`) | New leave request | {name} filed a leave request for {date} (or from {date} to {date}). | Open queue -> Manage / Requests | The managers who decide it |
| Overtime filed (`requests.overtime.filed`) | New overtime request | {name} filed an overtime request for {date}. | Open queue | The managers who decide it |
| Offset filed (`requests.offset.filed`) | New offset request | {name} filed an offset request for {date}. | Open queue | The managers who decide it |
| Reimbursement filed (`requests.reimbursement.filed`) | New reimbursement request | {name} filed a reimbursement request for {date}. | Open queue | The managers who decide it |
| Request decided (`requests.<kind>.approved` / `.rejected` / `.pending`) | Request approved / Request rejected / Request updated (*Late report ...* for a late report) | Your {kind} for {date} was approved / rejected / set back to pending by {manager}. | Open requests -> User Requests | The employee who filed it |
| Project requested (`projects.requested`) | New project to approve (or Project sent again) | {name} added the project {project} and it waits for approval. (or: {name} sent the project {project} again for approval.) | Open Manage / Projects | Project Approvers whose reach covers it |
| Project members added (`projects.requested.members`) | You were added to a project | {name} added you to the project {project}. | View Projects | The members added |
| Project approved (`projects.approved`) | Project approved | {name} approved the project {project}. | Created Projects | The person who requested it |
| Project modified (`projects.modified`) | Project updated | {name} edited the project {project}. | Open Manage / Projects | Project Approvers |
| Event created (`calendar.event.created`) | New event | {name} added the event {title} {date or range}. | Open the calendar -> Event Calendar | Holders of Manage / Reports, and the invitees |

**Not emailed** (portal notice only): request cancelled, late report filed, role or department
changed, project rejected, recycle bin restore, "email sent".

## 7. Blocked access alert

| | |
| --- | --- |
| When | A signed-in user is refused a page or action (403); at most once per person, method and path every 15 minutes |
| To | Everyone holding **Access alerts** (`administration.alerts`; admins and executives by preset), each in a letter of their own |
| Design | Letter, letterhead, Company footer, from IKAIKA Portal, through the mail queue |
| Code | `PortalAccessAlert::report` -> `PortalLetters::send`; a failure is logged as `portal.access.alert.failed` |

**Subject:** `Blocked portal access attempt`

> Hi {first name},
>
> A signed-in portal user tried to reach something they are not allowed to.
>
> *(details panel)*
> **Who:** {name} (ID no. {ID number})
> **Role:** {role} / {role level}
> **Tried:** `{METHOD} /{path}`
> **Refused because:** {why it was refused}
> **From IP:** {ip}
> **When:** {Tuesday, October 13, 2026 at 3:24 pm} (portal time)
>
> You get this because you hold Access alerts. The same attempt by the same person is reported at most once every 15 minutes.
>
> Best Regards,
> IKAIKA Portal

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
| Design | Letter, letterhead, Company footer, from IKAIKA Portal; `--message` resends a stored All Emails message exactly as it was built |
| Code | `routes/console.php`, `PortalEmailMessages::testMail` -> `PortalLetters::letter` |

**Subject:** `IKAIKA Portal mail test`

> Hi there,
>
> This is a **test** from the portal.
> If you can read this, sending works.
>
> Best Regards,
> IKAIKA Portal

---

## Known problems

| # | Problem | Effect | Fix |
| --- | --- | --- | --- |
| 2, 3, 6, 7, 8 | Queued emails need the server's scheduler cron | Without the every-minute `schedule:run` cron nothing works the queue: those emails wait, and Send Email rows stay Sending | Every admin deploy adds the cron if it is missing; check cPanel -> Cron Jobs if mail stops |
| 2, 3 | The QR shows inside the letter only once the current mail bridge is uploaded | Until then the live bridge attaches the QR as a loose file and the letter shows a broken image (before 2026-10-13 it refused these two letters outright, so they never arrived) | Upload `docs/deploy/wordpress/ikaika-portal-mail.php` to `public_html/wp-content/mu-plugins/` (see that README's Inline images) |
| Resend draft | No QR code | Weaker than #2 | Send Email can only attach uploaded files; needs a different approach |

---

## Requested changes

Write what you want changed here (which email, what to say, which design, who gets it), then ask
Claude to apply it.

- *(none yet)*

---

## Adding a new email

1. Send it through `PortalLetters::send()` (Letter, Company footer, IKAIKA Portal, through the mail queue);
   a one-time code someone waits on uses `PortalLetters::sendAfterResponse()`. Give it a category
   (`PortalEmailMarkup::CATEGORY_*`), write its words with `PortalLetters::body($firstName, $message)`, put
   a code on a `::code` line and a link on a `::button` line, and pass people's own text through
   `PortalEmailMarkup::escape()`.
   Anything different -- say why here.
2. Add it to [All emails at a glance](#all-emails-at-a-glance) and give it a section with its design,
   subject, body, recipients and trigger.
3. Test it with `Mail::fake()` (recipient, subject, design); the test queue is `sync`, so a queued email is sent
   inside the test. Use `Queue::fake()` to check it was queued instead of sent.
4. Update this file in the same commit.
