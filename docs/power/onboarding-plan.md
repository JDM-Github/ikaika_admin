# Onboarding — candidate registration with bound links (plan)

Status: **Planned 2026-10-09; decisions taken with the user; nothing built yet.** This replaces the
old WordPress registration page (portal-ikaikabim-site, snippet-338) where a candidate typed their
email, was looked up in Airtable, then typed a password and an OTP — with no admin-side entry and no
bound link. The new flow gives the administrator a Manage page to create a candidate entry, hands
the requirements email out through the existing Email composer, and collects everything on a unique,
bound-email link.

## 1. What this is

An **entry** a Manage / Onboarding administrator creates for one candidate: a link (optional slug, else
generated), a kept-readable generated password, and the candidate's **bound email** — the link only
works for whoever holds the OTP mailed to that address. The admin presses **Send** to open the Email
composer pre-filled (link and password in the body, contract/offer attached there), and the candidate
opens `/register/<code>`, signs in with the password + mailed OTP, fills their personal details,
government numbers and bank details, uploads their requirement documents, and submits. The admin
reviews the entry and presses **Promote to employee**, which creates the `employees` row.

It borrows the Shared Pages machinery (slug, kept password, QR, bound email + OTP, expiry, revoke,
rate caps, the sign-in screen read before the login gate) but is a separate feature: the candidate is
not a guest browsing pages; they are a short-lived registration session that may write only their own
record.

## 2. The experience

### 2.1 An administrator creates an entry (Manage / Onboarding)

1. **New entry** sheet:
   - **Candidate name** — shown on the entry and the sign-in screen ("Truebeck, walkthrough").
   - **Email** — required; the link is bound to it, and every OTP goes there.
   - **Link slug** (optional) — lowercase letters, numbers and dashes, 3-64, unique; blank makes a
     generated 24-hex code. Editable any time; the old link dies with the change.
   - **Expiry** — presets (1 / 7 / 30 days / never), default **30 days** (documents take weeks).
   - The server generates a 12-character password (kept readable, like a share's).
2. **Details sheet** — the link, its QR (candidate may scan it), the password with Copy, and a
   **Send email** button.
3. **Send email** opens the Email composer on a draft: audience **custom**, the bound address, the
   subject and body below with the name, link and password filled in. Contract and offer are attached
   in the composer. `sent_at` is stamped when Send opens the composer (the actual send stays the
   composer's, so it is an approximation).
4. Row actions: **Edit** (name/email/slug/expiry), **New password**, **Revoke**. On a submitted
   entry: document downloads and **Promote to employee**.

Draft wording (the old requirements email, Image 1):

```text
Hi <first name>,

Attached is your Job Offer Letter and Employment Agreement Contract.
Please review and once you accept this, kindly sign and complete the documents and return it to us.
Finally, once you've completed your requirements, submit those in the link below.

IKAIKA Employee Registration
<link>
Pw <password>

Best Regards,
Nathaniel
```

### 2.2 The candidate registers (`/register/<code>`)

The path is read before the login gate, like a share link, and takes precedence over a live session:
a signed-in person gets the same modal — **Sign out and continue** / **Back to the portal**.

1. **Password** — the one the admin sent. Wrong answers are generic, as on a share.
2. **OTP** — mailed to the bound address (6 digits, 10 minutes, 5 tries, resend after 60 s). No
   email-typing step: the OTP is the proof of the mailbox (user decision, 2026-10-09).
3. **Personal details** — first, middle and last name, nickname, bound email (read-only), start
   date, date of birth, address, phone number, emergency contact (person, number, address), and the
   profile photo upload.
4. **Requirements** — TIN, SSS No., PhilHealth No., HDMF No., bank account number, bank name, bank
   SWIFT code.
5. **Documents** — profile photo, TIN ID, SSS ID, PhilHealth ID, HDMF ID, bank certificate (one or
   more files), each up to 5 MB.
6. **Finish** — a thank-you screen; `submitted_at` is stamped and the admin's entry shows Submitted.

Each step autosaves (details on continue, files as each upload finishes), so a dropped connection
resumes where the candidate left off. The session token lasts the link's remaining life.

### 2.3 The administrator promotes

On a submitted entry, **Promote to employee**: ID No. (pre-filled with the next one), department,
role (default User) and job title (pre-filled). Promotion creates the `employees` row with the
private columns filled from the registration (section 7) and links the entry. HR adds them in
Airtable later — the sync never creates people there, the same rule the portal's Add user follows.

## 3. Decisions (2026-10-09, with the defaults filled in)

| # | Decision |
|---|---|
| 1 | **Password then OTP**, no email-typing step: the bound address plus the mailed code is the second factor. The old "type your email" step and its Airtable lookup are dropped. |
| 2 | **The admin promotes**; submitting does not create an employee record by itself. |
| 3 | Submitted details land in **`new_employee_data`** (the existing portal table, Airtable-synced, 10 rows today); link/entry state lives in a new `registrations` table. |
| 4 | Documents are the **old set** (photo, TIN, SSS, PhilHealth, HDMF, bank certificate), **5 MB** each, on the **private disk**, admin-only download. No cropper; the photo uploads as-is. |
| 5 | The page is **Manage / Onboarding**, an administrator-tier fine-grained power like the 2026-10-09 References/Shared Pages change: `manage.onboarding` reads, `.manage` creates/edits/revokes/promotes, `.password` regenerates. Admins and developers hold all by preset; member never hold any; executives none by default. |
| 6 | The link follows the share rules: optional slug, kept password, QR, expiry, revoke, rate caps. |
| 7 | **Send** goes through the Email composer, so the sender sees and may change everything and attaches the contract/offer there (`email.send` applies). |

Defaults the user has not objected to (change here, not in code):

- password: 12 characters from the unambiguous alphabet (no `0/O/1/l/I`);
- OTP: 6 digits, 10 minutes, 5 tries, resend after 60 seconds, stored hashed;
- sign-in attempts: 5 per 10 minutes per registration + IP; per-registration cap 120 requests/minute;
- expiry: 30 days; slug: 3-64, `^[a-z0-9][a-z0-9-]*[a-z0-9]$`, `session`-style reserved words not needed (no path collision);
- session token: same TTL rule as the share guest (the link's remaining life).

## 4. Data (`ikaika_admin/sql/portal/separate.sql` + a migration)

```sql
CREATE TABLE IF NOT EXISTS registrations (
    id                   INT AUTO_INCREMENT PRIMARY KEY,
    candidate_name       VARCHAR(150) NOT NULL,
    email                VARCHAR(255) NOT NULL,     -- the bound address; link, OTP, and the draft go here
    code                 VARCHAR(64)  NOT NULL,     -- a typed slug, or a generated 24-hex code
    password_hash        VARCHAR(255) NOT NULL,
    password_value       VARCHAR(32)  NULL,         -- readable, so the admin can send it again
    expires_at           TIMESTAMP    NULL,
    revoked_at           TIMESTAMP    NULL,
    created_by           INT          NOT NULL,     -- employees.id, the administrator
    created_at           TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    sent_at              TIMESTAMP    NULL,         -- stamped when Send opens the composer
    opened_at            TIMESTAMP    NULL,         -- first verified sign-in
    submitted_at         TIMESTAMP    NULL,
    promoted_at          TIMESTAMP    NULL,
    promoted_employee_id INT          NULL,         -- employees.id after promotion
    employee_data_id     INT          NULL,         -- new_employee_data.id after submit
    UNIQUE KEY registrations_code (code),
    INDEX idx_registrations_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS register_otps (          -- the same shape as shared_page_otps
    id              INT AUTO_INCREMENT PRIMARY KEY,
    registration_id INT          NOT NULL,
    code_hash       VARCHAR(255) NOT NULL,
    expires_at      TIMESTAMP    NOT NULL,
    attempts        TINYINT      NOT NULL DEFAULT 0,
    created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (registration_id) REFERENCES registrations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS register_documents (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    registration_id INT          NOT NULL,
    kind            VARCHAR(24)  NOT NULL,          -- photo|tin|sss|philhealth|hdmf|bank
    name            VARCHAR(191) NOT NULL,          -- the candidate's file name
    mime            VARCHAR(127) NOT NULL,
    size_bytes      INT          NOT NULL,
    path            VARCHAR(255) NOT NULL,          -- private disk, registrations/{id}/
    uploaded_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_register_documents_registration (registration_id, kind),
    FOREIGN KEY (registration_id) REFERENCES registrations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

Catalogue rows (mirroring the References/Shared Pages pattern):

```sql
INSERT INTO access_permissions
    (permission_key, parent_key, label, description, supports_reach, can_toggle, min_tier, sort_order)
VALUES
    ('manage.onboarding', 'manage', 'Onboarding',
     'Guest-free candidate registration: send a bound link, collect details and requirements.', 0, 1, 'admin', 870),
    ('manage.onboarding.manage', 'manage.onboarding', 'Create and revoke',
     'Create a candidate entry, change it, revoke it, and promote a submitted one.', 0, 1, 'admin', 871),
    ('manage.onboarding.password', 'manage.onboarding', 'New passwords',
     'Give a candidate link a new password, whose old one stops working at once.', 0, 1, 'admin', 872);
```

Presets for `admin` and `developer`; a migration
(`2026_10_XX_..._add_portal_onboarding_rules.php`) inserts the rows and presets for existing
databases, mirrored in `separate.sql`, exactly like
`2026_10_09_090000_open_portal_references_and_shared_pages_rules.php`.

## 5. Server (`ikaika_admin`)

### 5.1 Files

| File | Role |
|---|---|
| `App\Support\Portal\PortalRegistrations` | Entries, slug/password rules, OTP, access/verify/resend, session tokens, promotion |
| `App\Support\Portal\PortalRegistrationDocuments` | The document kinds, required set, store/stream |
| `App\Support\Portal\PortalPrivateFiles` | Extracted once from `PortalEmailAttachments`: the private disk store, stream, and the extension/content-type allow-list. Both attachments and registration documents use it — one owner for the rules. |
| `App\Http\Middleware\AuthenticateRegistrationJwt` | Resolves the session token to the entry, re-checks revoke/expiry each request, stamps opened/last-used, rate-caps |
| `routes/portal/register.php` | Public sign-in + session routes |
| `Http\Controllers\Api\Portal\Register\RegisterController` | The public side |
| `Http\Controllers\Api\Portal\Manage\OnboardingController` | The admin side |
| `database/migrations/2026_10_XX_..._add_portal_onboarding_rules.php` | Rules + presets for existing databases |

### 5.2 Endpoints

Every answer rides in `data`, the portal's envelope. Public:

```text
GET  register/{code}                    -> { name, hasEmail }   // never echoes the email itself
POST register/{code}/access  { password } -> { otpRequired: true }  // always mails the OTP (email is bound)
POST register/{code}/verify  { password, otp } -> { token, expiresAt, name, saved: {...}, documents: [...] }
POST register/{code}/resend  { password } -> { sent: true }      // 60-second cooldown
```

Session (registration token; the only routes that middleware guards):

```text
GET    register/session          -> { name, status, saved, documents }
PATCH  register/details          { firstName?, ..., bankSwiftCode? }   // per-step autosave
POST   register/documents        multipart { kind, file }              // staged per file
DELETE register/documents/{id}
POST   register/submit           -> { submitted: true }                // validates required fields + documents
```

Private (`portal.rule:manage.onboarding` and its keys, like the rest of Manage):

```text
GET    manage/registrations                     -> { registrations: [...], }
POST   manage/registrations                     { candidateName, email, slug?, expiresInDays?|neverExpires? }
PATCH  manage/registrations/{id}
DELETE manage/registrations/{id}                -> revoked
POST   manage/registrations/{id}/password       -> { password }
POST   manage/registrations/{id}/sent           -> { sentAt }
GET    manage/registrations/{id}/qr             -> { svg }
GET    manage/registrations/{id}                -> detail: candidate, saved data, documents, status
GET    manage/registrations/{id}/documents/{documentId} -> the file (logged)
POST   manage/registrations/{id}/promote        { idNo, department, role, jobTitle } -> { employee }
```

### 5.3 Registration auth

`AuthenticateRegistrationJwt` mirrors the share guest branch but scoped: the token claim carries
`registration` (the entry id); the middleware runs only on the `register/*` session routes, so the
other rules do not apply. A revoked or expired link answers 401 on every call. Unlike a guest, the
session may write — but only PATCH/POST/DELETE on its own record, with the entry id taken from the
token, never from the request.

### 5.4 Rate, attempts and OTP

Mirror the share limiters: `portal-register-auth` (5 per 10 minutes per registration + IP on
access/verify/resend), `portal-register` (per-registration 120 requests/minute for the session, so
uploads are not throttled by the sign-in cap), OTP 6 digits / 10 minutes / 5 tries.

### 5.5 Emails

Only the OTP is mailed by this feature (the requirements email goes through the composer). OTP bodies
follow the share OTP mail; the requirements draft is built in the portal (section 6.3).

### 5.6 QR

`bacon/bacon-qr-code` as a share's QR: `GET manage/registrations/{id}/qr` renders the link.

## 6. Portal (`ikaika-portal`)

### 6.1 Wiring

- `ShellRoute.onboarding: 'onboarding'` under Manage; `RouteRuleConstant` maps it to
  `manage.onboarding`; the sidebar shows it under Manage when held.
- `ShellRoutePathUtility.registrationCodeFromPath` reads `/register/<code>`; `RootLayout` renders
  the Registration screen for it **before** the authenticated branch (like a share link), with the
  same signed-in modal. The modal is extracted once into `SignedInGateSharedModal` (props: title,
  message maker, busy label, confirm/cancel labels, callbacks) and used by both.

### 6.2 Manage / Onboarding

Files mirror the Shared Pages page: `administration.onboarding.screen.tsx`, controller, constant,
`onboarding.model.ts`, endpoints under `src/apis/endpoint/manage/onboarding/`. A `DataTableShared`
with Candidate, Email, Status (Created / Sent / Opened / Submitted / Promoted / Revoked / Expired),
Sent, Last used, Completed; the New/Edit sheet, the details sheet (link, QR, kept password, Send
email), and the row actions of section 2.1.

### 6.3 Composer handoff

`SendEmailScreenController` gains `startEmailToAddresses({ emails, subject, body })`: audience
`custom`, the typed addresses, subject and body set — the same pattern as `startEmailTo` for Missing
Reports (which only takes members; a candidate has no employee row). The Onboarding screen's Send
button builds the draft from the entry (section 2.1) and opens `ShellRoute.sendEmail`.

### 6.4 The registration screen

`registration.screen.tsx` + controller + constant, outside the shell, following `ShareAccessScreen`:
phases password → otp → steps, then the step forms (2.2). One controller owns phase, step, the saved
draft, per-file upload state, and validation; components live under
`src/components/screens/register/`. Uploads use the composer's multipart pattern (FormData through
`ApiManager.post` with a long timeout); the picker reuses `FilePickerManager`.

## 7. Data destination and promotion

The submitted details insert one `new_employee_data` row (portal-side, `airtable_record_id` null;
core ledger written). Mapping:

| Form | `new_employee_data` / `employees` column |
|---|---|
| First / middle / last name | `first_name`, `middle_name`, `last_name` |
| Nickname | `nickname` |
| Bound email | `email` (the identity; `personal_email` stays for HR) |
| Start date | `start_date` |
| Date of birth | `date_of_birth` |
| Address | `address` |
| Phone number | `phone_number` |
| Emergency contact (person / number / address) | `emergency_contact_person_name`, `emergency_contact_number`, `emergency_contact_address` |
| TIN / SSS / PhilHealth / HDMF | `tax_identification_no`, `sss_no`, `philhealth_no`, `hdmf_no` |
| Bank account / name / SWIFT | `bank_account_number`, `bank_name`, `bank_swift_code` |
| Status on submit | `status` = the vocabulary the seeded rows use; `data_approval` = the table's pending value (mirror the existing values) |

Promotion repeats the Add user rules (`PortalManageUsers::addUser`): ID No pattern
`^\d{6}-\d{4}$` pre-filled with the next, duplicate id/email checks, `status` Active, `role` User,
plus the private columns above copied from the registration. The documents stay on the registration
(`register_documents`); surfing them on the employee profile is parked.

## 8. Security

- The bound email is the second factor: the password alone reaches nothing without the mailed OTP;
  the public `info` endpoint never echoes the email.
- Wrong password and wrong OTP answer identically; attempts and resends are capped.
- Documents live on the private disk (`local`, `storage/app/private/registrations/{id}`), content
  sniffed against the same allow-list as email attachments (pdf/jpg/png and the office types), 5 MB
  each; downloaded only through the authenticated admin route, and the download is logged.
- Never Cloudinary or the public disk: government IDs and bank certificates are not public links.
- The session token is scoped to `register/*`; revoke and expiry are re-checked every request, so a
  revoked link ends a live session at once.
- The password is kept readable (`password_value`) for the administrator, matching the share
  decision; it never travels on a public endpoint.

## 9. Differences from the old WordPress flow (snippet-338)

| Old | New |
|---|---|
| Candidate typed their email first; a REST call checked it against Airtable (`checkEmail`) and prefilled existing data | The admin's entry *is* the record; no lookup, no email step |
| Password + typed email + OTP, with the password sent by hand ("Pw ..." in the email) | Admin-generated password, kept in the entry, plus OTP to the bound address |
| Writes went to Airtable (New Users) with base64 attachments | `new_employee_data` + private-disk documents; HR adds them in Airtable later |
| Profile photo cropped in-browser (Cropper.js) | Plain upload (parked) |
| Documents had per-file checkboxes to skip | All required in v1 (parked: the checkbox option) |
| No admin-side entry, no QR, no revoke | Manage / Onboarding owns entries, QR, revoke, expiry |

## 10. Tests

**Admin (`php artisan test`)**

- `tests/Feature/PortalOnboardingTest.php` — create (slug/blank slug/password kept/bound email),
  duplicate and malformed slug, access/verify/resend (OTP to the bound address only), session save
  per step, document upload (type refusal like attachments, size limit, private path), submit
  validation, the `new_employee_data` row, revoke/expiry ending a session, promote (id pattern,
  duplicates, private columns copied, ledger), downloads refused without the rule, member 403s.
- `PortalAccessTest` / `PortalRulesTest` — `manage.onboarding` + keys in the admin preset, executive
  left-out, developer preset.
- Upload allow-list parity: one test proving attachments and registration documents share the
  `PortalPrivateFiles` rules.

**Portal (`npm test`)**

- Onboarding controller/screen: list, New/Edit sheet, details sheet (link, QR, password, Send),
  split-key gating (read/manage/password), Send draft contents.
- Registration controller/screen: phases, step autosave payloads, per-file upload states, submit,
  dead-link and wrong-password answers, the signed-in modal.
- Composer: `startEmailToAddresses` sets audience custom + addresses + subject/body.
- Path/routing: `/register/<code>` picked up before the login gate, precedence over a session.

## 11. Build order

1. **Admin data + rules** — tables, catalogue rows, presets, migration, `separate.sql` mirror, rule
   tests. (One commit.)
2. **Admin flow** — `PortalPrivateFiles` extraction, `PortalRegistrations`,
   `PortalRegistrationDocuments`, `AuthenticateRegistrationJwt`, routes, `PortalOnboardingTest`.
   (One commit.)
3. **Portal candidate side** — composer `startEmailToAddresses`, `SignedInGateSharedModal`
   extraction, `/register` routing, `RegistrationScreen` + tests. (One commit.)
4. **Portal admin side** — Manage / Onboarding screen, Send handoff, tests. (One commit.)
5. **Docs** — `FINE_GRAINED_RULES.md` enforced list, context topics, graphify. (One commit.)

## 12. Open / parked

- Profile photo cropping (old flow squared it) — plain upload for now.
- Surfacing registration documents on the promoted employee's profile.
- A reusable "Employee registration" template block in the composer library (the draft prefill
  covers it for now).
- Per-document "I don't have this yet" checkboxes as the old form allowed.
- `sent_at` is stamped when Send opens the composer, not when the mail actually leaves.
- The old Airtable "New Users" lookup is gone; if HR ever needs the entry visible in Airtable before
  promotion, that is a sync decision, not a registration one.

## 13. References

- Old registration JS: `C:\IKAIKA\portal-ikaikabim-site\snippet-338.txt` (fields, steps, 5 MB limit);
  the HTML/CSS snippets are empty files.
- Existing destination schema: `sql/portal/schema.sql` (`employees`, `new_employee_data`).
- Sibling plan to copy from: `docs/power/shared-pages-plan.md` (link machinery, sign-in screen,
  readiness discipline). Rules tier pattern: `2026_10_09_090000_open_portal_references_and_shared_pages_rules.php`.
- Private uploads precedent: `App\Support\Portal\PortalEmailAttachments` and the composer's staged
  attachments; composer handoff precedent: Missing Reports' `emailDraftFor` / `startEmailTo`.
