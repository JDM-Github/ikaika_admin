# Security findings

All findings are **Open** until `remediation-plan.md` records an acceptance test passing. F-001 and
F-002 are the exceptions recorded below.
No secret or real PII values are reproduced here.

---

## F-001 - Generic resource API dumps every model - CRITICAL

**Status: FIXED 2026-10-23.** Routes and `ResourceController` removed; portal fallback removed;
route-absence tests added.

**Evidence**
- `routes/api.php:47-50` protects `GET {product}/{resource}` and `GET {product}/{resource}/{id}`
  with `AuthenticatePortalJwt` only.
- `app/Http/Controllers/Api/ResourceController.php:14-62` calls `Model::query()` and
  `findOrFail($id)` with no rule, reach, ownership, guest, or private-field check.
- `app/Http/Middleware/AuthenticatePortalJwt.php:38-42` lets a guest share token through GET.
- `app/Modules/Portal/PortalModule.php:31-84`, `CoreModule.php:31-55`, and
  `TransactionTrackerModule.php:31-62` register employees, warnings, requests, reimbursements,
  reports, actions, settings, transactions, accounts, and more.
- `app/Modules/Support/ProductModel.php:11` uses `$guarded = []`; `Employee` defines no `$hidden`.
- `API.md:870` documents `GET .../employees` as a live endpoint.

**Exploit**
Any active employee token or any valid share-guest token can call:
`GET /api/{channel}/portal/employees?per_page=100` and page through every employee, or
`GET .../warnings`, `.../core/actions`, `.../transaction-tracker/accounts`, etc.

**Impact**
Mass PII: ID numbers, birth dates, addresses, tax/social/health IDs, bank details, emergency
contacts, leave balances, warnings, audit history, and financial records. No throttle limits the
dump beyond 100 rows per page.

**Fix**
Remove the catch-all routes unless a verified consumer needs them. If they must stay, replace them
with explicit resource allow-lists, field projections, and `WorkspaceAccess`/`portal.rule` checks;
guest tokens must never reach them.

**Acceptance**
A normal member gets 403/404; a guest gets 403/404; no response contains private columns; an
authorised workspace reader only sees its allowed fields.

---

## F-002 - Microsoft sign-in trusts display name - HIGH

**Status: FIXED 2026-10-23.** Microsoft login resolves the unique `employees.microsoft_oid`; name
matching is gone and the oid is read-only in the admin grid. A temporary Advanced switch is on by
default and may claim one active employee by **exact signed work email/UPN**; it never uses names
and must be turned off manually after the migration. While on, this is a deliberate, accepted
weakening of the strict oid-only rule for the linking window.

**Evidence**
- `app/Http/Controllers/Api/PortalAuthController.php:98-139` matches `given_name`/`family_name`
  (and split display names) against `employees.first_name`/`last_name`.
- `app/Support/Portal/PortalMicrosoftIdToken.php:88-100` parses `email`, `emails`, `upn`, and
  `oid`, but the controller ignores them.
- `oid` is copied into the issued JWT (`PortalAuthController.php:205-207`) but never stored or
  checked for later sign-ins.

**Exploit**
Any account in the configured Azure tenant can change its display name to a target employee's
name and receive that person's portal session, including an administrator's.

**Impact**
Account takeover. Token signature, audience, issuer, and tenant checks are correct; the identity
binding is not.

**Fix**
Bind to an immutable claim. Preferred: add a unique `microsoft_oid` to employees, link it after a
verified work-email/UPN match, and sign in only by `oid`. Refuse if the `oid` is linked to another
employee. Remove all name-based matching.

**Acceptance**
Same display name, different `oid` cannot sign in as the target; a changed display name still signs
in as the same employee; unlinked accounts are refused with a safe message.

---

## F-003 --" ID-number login is a shared credential --" HIGH

**Evidence**
- `app/Http/Controllers/Api/PortalAuthController.php:31-63` checks one
  `services.portal_id_login.password` for every employee, then looks up any active `id_no`.
- In `local`/`testing`, `:32-35` skips the password check entirely.
- The public `GET auth/id-login` announces whether the feature is on.

**Exploit**
Anyone who learns the MIS password can sign in as any employee, including admins/executives, with
a predictable ID number. A deployed server left on `APP_ENV=local` allows ID-only login.

**Impact**
Full account takeover of any portal user. Conditional on ID login being enabled, or on a
deployment misconfigured as local/testing.

**Fix**
Disable deployed ID login. If it must remain, require a per-user secret/second factor, remove the
environment bypass, enforce lockout, and hide the endpoint when off. Keep local-only test login
behind an explicit test flag, never `APP_ENV`.

**Acceptance**
Deployed login refuses when disabled; wrong password never signs in; local environment does not
bypass a deployed configuration; repeated failures lock out.

---

## F-004 --" Guest shares expose candidate documents and sent-mail attachments --" HIGH

**Evidence**
- `sql/portal/separate.sql:1266,1276` marks `manage.onboarding` and `administration.emails` ready
  to share.
- `routes/portal/manage.php:99-110` exposes onboarding list/detail/documents to any rule holder.
- `app/Support/Portal/PortalRegistrations.php:299` fetches an entry by id with no creator/reach
  check; `present()` at `:934` returns the readable link password.
- `app/Support/Portal/PortalEmailMessages.php:153-167` downloads any sent-mail attachment by id
  and token with no creator/recipient restriction.

**Exploit**
A guest holding a share that grants onboarding can list every candidate, read plaintext
registration passwords, and download ID/bank documents. A guest granted emails can download all
sent attachments. A leaked share link is enough.

**Fix**
Remove both pages from `shared_page_shareable` unless the business explicitly needs them. If
needed, restrict lists and documents to the creator/reach, never expose `password_value`, and make
guest email send read-only or off.

**Acceptance**
Guest cannot list/download onboarding documents or registration passwords; guest cannot download
mail attachments unless explicitly intended; creator-only access is tested.

---

## F-005 --" Sensitive uploads are public Cloudinary URLs --" HIGH

**Evidence**
- `app/Support/Portal/PortalCloudinary.php:36-102` uploads with `/auto/upload` and stores
  `secure_url`; there is no `type=authenticated` or signed delivery.
- `app/Support/Portal/PortalEmployeeDocuments.php:139-148` copies registration documents to
  Cloudinary on promotion.
- `PortalReimbursementRequests.php:430-464`, `PortalPaymentQrs.php:87`, and
  `PortalBugReports.php:144` use the same public path.
- `sql/portal/data.sql` contains public delivery URLs.

**Impact**
Anyone with a URL can fetch ID cards, bank certificates, receipts, payment QRs, and screenshots
without logging in. URLs leak through browser history, copied links, screenshots, logs, and the
committed SQL snapshot.

**Fix**
Use private/authenticated Cloudinary assets with short-lived signed delivery for sensitive files,
or store them on the private local disk like registration/email attachments. Treat email images
and other public branding as a separate public-only path.

**Acceptance**
An unauthenticated request to a document URL is refused; only an authorised portal request can
obtain a short-lived URL; existing public assets are removed or made private.

---

## F-006 --" Tracked SQL dumps contain real PII --" HIGH

**Evidence**
- `sql/portal/data.sql`, `sql/project_estimator/data.sql`, and
  `sql/transaction_tracker/data.sql` are tracked by git.
- The portal and estimator dumps contain employee identity, contact, government-ID, bank, and
  payroll data; the estimator dump also contains bcrypt password hashes and invite codes.
- The portal dump contains public Cloudinary document URLs.

**Impact**
Anyone with repo, fork, archive, CI, or backup access receives real PII and crackable credentials.
Removing the files now does not remove them from history.

**Fix**
Replace with sanitized/synthetic fixtures or schema-only files; purge the data from git history
with a coordinated rewrite; rotate exposed credentials/assets; notify the data owner.

**Acceptance**
Current HEAD contains no real PII or public document URLs; a fresh clone/history scan finds none;
local/test resets still work with the sanitized data.

---

## F-007 --" Test Server holds production data and may share secrets --" HIGH (operational)

**Evidence**
- `deployment/README.md:185` says the Test Server runs on copies of production's database.
- `deployment/server/setup-env.sh:16` copies staging's `.env`; the README says that file contains
  the real `APP_KEY` and JWT secret.
- `config/products.php:36` falls back to `APP_KEY` for the portal JWT secret.

**Impact**
A Test Server compromise can expose production PII. If keys are shared, tokens/sessions can also
be forged across environments.

**Fix**
Give every environment its own `APP_KEY`, `PORTAL_JWT_SECRET`, DB credentials, and API keys;
anonymize or restrict Test Server data; treat the Test Server as production-adjacent.

**Acceptance**
Key fingerprints differ between Test and PROD; a token minted on Test fails on PROD; Test data
contains no real PII unless explicitly approved and protected.

---

## F-008 --" Request ownership is matched by employee name --" MEDIUM

**Evidence**
- `app/Support/Portal/PortalLeaveRequests.php:379-421`.
- `PortalOvertimeRequests.php:842-909`, `PortalOffsetRequests.php:864-956`.
- `PortalManageRequests.php:149-158,420-421,856-869`.

**Impact**
Employees with the same full name can read, edit, or cancel each other's requests; approval
reach can resolve the wrong employee.

**Fix**
Store the submitting employee id on leave/overtime/offset rows, use it for ownership and approval
resolution, and backfill existing rows with an explicit reconciliation step.

**Acceptance**
Same-name accounts cannot access each other's rows; new rows always carry `employee_id`; queue
reach uses id, not name.

---

## F-009 --" Calendar exposes every member's request details --" MEDIUM

**Evidence**
- `routes/portal/calendar.php:10-12` gives every member the `calendar` rule.
- `app/Support/Portal/PortalCalendarEvents.php:130-260,359-403` returns all leave, overtime,
  offset, and reimbursement details in the range.

**Impact**
Names, leave categories/reasons (possibly medical), overtime/offset reasons, and claim items are
visible to every member.

**Fix**
Decide the intended calendar privacy. At minimum show name/date/category, not free-text reasons;
scope requests by department/reach where appropriate.

**Acceptance**
A member cannot read another department's private reasons; only approved summary fields are
returned.

---

## F-010 --" Onboarding promotion accepts arbitrary roles --" MEDIUM

**Evidence**
- `app/Support/Portal/PortalRegistrations.php:348-354,374-401` accepts `role` as free text and
  passes it to `createEmployee()`.
- `PortalManageUsers` has a role allowlist/lock that this path bypasses.

**Impact**
A department head with `manage.onboarding.promote` can create an Executive/Admin account.

**Fix**
Use `PortalRole::assignableRoles()` (and the executive lock) in promotion; audit every role change.

**Acceptance**
Executive/Admin cannot be selected by a non-head; only allowed roles are accepted; tests cover it.

---

## F-011 --" Developer mode has a known default password --" MEDIUM

**Evidence**
- `app/Support/Portal/PortalDeveloperMode.php:20,47-73` accepts `123` for any developer with no
  stored hash and lets that activation set a new password.
- Developer mode grants every rule (`PortalAccessGraph`).

**Impact**
A stolen developer session/token can be escalated to full developer access if the developer never
set a password.

**Fix**
Remove the fixed default; require a one-time setup flow before activation; refuse activation when
no password exists and rate-limit setup.

**Acceptance**
`123` never activates anything; a developer without a password is told to set one; setup is
rate-limited and audited.

---

## F-012 --" Guest email shares can send arbitrary company HTML --" MEDIUM

**Evidence**
- `route`-level guest email grant in `PortalRules.php` and `PortalSharedPages.php`.
- `PortalEmailMessages.php:247-253` allows up to 50 custom external recipients.
- `PortalEmailMarkup.php` sends the composer body as HTML.

**Impact**
A guest with an email share can send company-branded phishing/BEC mail to external addresses.

**Fix**
Disable guest send (read-only shares) or restrict guests to internal recipients and plain text;
keep the send audited and rate-limited.

**Acceptance**
A guest cannot add external recipients or send arbitrary HTML; internal-only send, if allowed,
is tested.

---

## F-013 --" Link passwords are stored readable --" MEDIUM

**Evidence**
- `shared_pages.password_value` and `registrations.password_value` store plaintext.
- `PortalSharedPages.php:117-174,580` and `PortalRegistrations.php:158-253,934` return them to
  viewers; registration returns them to any `manage.onboarding` holder.

**Impact**
A DB/backup leak gives immediate guest/candidate access; delegated admins can read passwords they
do not own.

**Fix**
Remove readable storage or encrypt it with a dedicated key; return it only to the creator; never
include it in guest/registration list responses.

**Acceptance**
DB values are not plaintext; non-creators cannot retrieve passwords; tests cover both.

---

## F-014 - Web tokens and revocation - CROSS-REPO

Canonical portal finding: **P-001** in `../../ikaika-portal/docs/security/findings.md`. The web token
lives in localStorage, has no server-side revocation, and guest tokens can last 30 days. Fix on
both sides: portal storage/CSP plus server session/revocation.

---

## F-015 - Report drafts survive across users - CROSS-REPO

Canonical portal finding: **P-002** in `../../ikaika-portal/docs/security/findings.md`. Drafts and
edit targets are not keyed by employee and survive sign-out on a shared browser.

---
## F-016 --" Public health/catalog leaks DB errors and runs unthrottled --" MEDIUM/LOW

**Evidence**
- `routes/api.php:17-19` exposes `GET /{product}` and `/{product}/health` publicly.
- `app/Support/ProductRegistry.php:92-111` returns `$e->getMessage()`.
- `ProductController`/`CatalogController` run counts/pings; no throttle.

**Impact**
DB host/user/database details leak when a DB is down; unauthenticated load can exhaust DB
connections.

**Fix**
Return a generic health payload, log details server-side, and throttle/restrict the endpoints.

**Acceptance**
A forced DB failure returns no exception text; rate limit applies to the public routes.

---

## F-017 --" CORS, headers, JWT claims, guest rate key --" LOW hardening

**Evidence**
- No `config/cors.php`; framework default allows `*` origins/methods/headers.
- `.htaccess` has no CSP/HSTS/XFO/nosniff/Referrer-Policy.
- `PortalJwt::parse()` does not verify `iss`/`aud`/`nbf`.
- `PortalLimiterKey` uses an empty guest stand-in; guests share one limiter bucket.

**Fix**
Restrict CORS to the portal origins; add security headers at the web server/middleware; verify
JWT issuer/audience/not-before; key guest limits by share id.

**Acceptance**
Cross-origin requests from a non-portal origin fail CORS; headers present; tampered `iss`/`aud`
tokens rejected; one guest cannot exhaust another's limit.

---

## F-018 --" Enumeration and unthrottled public info --" LOW

**Evidence**
- `routes/portal/register.php:34` `GET register/{code}` has no throttle and returns the candidate
  name.
- `PortalEmailLogin` returns a distinct ---No active portal account--- message.
- `GET auth/id-login` announces whether ID login is on.

**Fix**
Throttle the registration info endpoint; return uniform email-start responses; expose the ID-login
state only when needed.

**Acceptance**
Enumeration responses are indistinguishable; public code lookup is rate-limited.

---

## F-019 --" Dependency advisories --" LOW/MEDIUM

**Evidence**
- `composer audit`: 4 advisories (Laravel framework, CommonMark, Symfony HTTP Foundation).
- `npm audit --omit=dev`: 46 vulnerabilities, including high `axios`/`follow-redirects` and a
  critical transitive package; `npm audit fix` currently hits ERESOLVE.

**Fix**
Upgrade Laravel/CommonMark/Symfony within existing constraints; upgrade axios/follow-redirects;
resolve the NativeWind peer conflict before any npm audit fix; never force a breaking Expo
downgrade.

**Acceptance**
`composer audit` and `npm audit --omit=dev` have no actionable high/critical findings; tests and
typecheck pass.

---

## F-020 --" Workspace secrets and legacy WordPress code --" LOW/conditional HIGH

**Evidence**
- `PORTAL:test.py` (untracked) contains live-looking DB credentials.
- `PORTAL:wpcode-export/` (ignored) contains hardcoded DB credentials and unauthenticated
  `wp_ajax_nopriv` handlers, plus an open redirect and an Airtable key disclosure.
- `deployment`/README references the old WordPress bridge.

**Impact**
If the old widgets are active on the WordPress host, the nopriv handlers are critical. Credentials
in local files are exposed to anyone with the workspace archive.

**Fix**
Verify the legacy snippets are removed/disabled; rotate the DB/API credentials; delete local
secret files; never include the workspace in a share/archive without cleaning it.

**Acceptance**
Old endpoints return 404/403; credentials rotated and absent from the workspace; a secret scan is
clean.

---

## F-021 --" Tracked development artefacts --" LOW

**Evidence**
- Tracked: `.playwright-mcp/*` snapshots, `composer.phar`, `composer.zip`, `env.production`,
  `PORTAL:.expo-web.log`, `PORTAL:.expo-ui.*.log`.
- Some snapshots/logs contain employee/project page content; no tokens found.

**Fix**
Remove them from the repo, add ignore rules, and keep a separate documented build/export process.

**Acceptance**
`git ls-files` contains no logs/snapshots/binaries; `.gitignore` covers them.

---

## F-022 --" Deployment safety gaps --" LOW/MEDIUM

**Evidence**
- `deployment/server/deploy-admin.sh` migrates/installs cron without asserting
  `APP_ENV=production`, `APP_DEBUG=false`, `APP_KEY`, `PORTAL_JWT_SECRET`, or secure cookies.
- `push-missing-prod-env.ps1` does not deny `PORTAL_JWT_SECRET`/Azure secret keys.
- Test Server and production share data/key risks (F-007).
- Database user has broad privileges (per audit; verify on server).

**Fix**
Add a fail-closed production preflight, extend the deny list, use per-environment keys and
least-privilege DB users.

**Acceptance**
A deploy with unsafe/missing values aborts before swapping; the secret push cannot write identity
keys; the app starts with the expected secure config.
