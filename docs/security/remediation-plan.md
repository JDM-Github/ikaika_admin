# Security remediation plan

Work top to bottom. Do not turn on or deploy a wider surface until its phase gate passes.
Every task needs a regression test; the acceptance test is the task's definition of done.

## Phase 0 - Containment (blocking)

**Gate:** no generic model dump, no name-based Microsoft login, no shared ID login, no guest
access to candidate documents or sent attachments, no new public sensitive documents.

### T-001 - Remove or gate the generic resource API

- **Fixes:** F-001
- **Changes:** remove `GET {product}/{resource}` and `GET {product}/{resource}/{id}` from
  `routes/api.php`, or replace with explicit allow-listed endpoints behind `WorkspaceAccess` and
  private-field projections.
- **Verify:** no frontend/external consumer calls them (check logs, API docs, scripts).
- **Tests:** member 404; guest 404 (route absent); authorised reader only sees typed endpoints.
- **Status:** [x] fixed 2026-10-23. `PortalApiTest` and `PortalAuthTest` assert the old route is
  gone; the portal's `/employees` fallback and `GetAllEmployeesApi` were removed.

### T-002 - Bind Microsoft login to an immutable identity

- **Fixes:** F-002
- **Changes:** add a unique `microsoft_oid` column; match by `oid`; link it only after a verified
  work-email/UPN match; remove name matching; refuse a mismatched/duplicate `oid`.
- **Tests:** same display name/different oid refused; changed display name still works; unlinked
  token refused; duplicate link refused by the command and the unique index.
- **Status:** [x] fixed 2026-10-23. Migration adds nullable unique `employees.microsoft_oid`;
  login is oid-only; `portal:link-microsoft-oid <id_no> <oid>` links one account after an operator
  verifies the Entra UPN/work email, and `portal:link-microsoft-oids <csv>` bulk-links a trusted
  Entra export after a dry run. The column is read-only in the workspace grid. A temporary fallback
  (`login.microsoft_fallback`, on by default) can auto-link an unlinked account only by exact
  signed work email/UPN, never name; the supervisor turns it off manually after the migration.
- **Decision:** `decisions.md` D-002.

### T-003 - Disable deployed ID login and the local bypass

- **Fixes:** F-003
- **Changes:** remove the `local`/`testing` bypass; disable ID login in deployed environments unless
  a per-user factor exists; hide `auth/id-login` when off; add lockout.
- **Tests:** deployed disabled/refused; wrong password refused; local cannot ignore a deployed
  setting; lockout works.
- **Status:** [ ] open
- **Decision:** `decisions.md` D-003.

### T-004 - Restrict shareable pages and guest downloads

- **Fixes:** F-004, F-012, F-013
- **Changes:** remove `manage.onboarding`/`administration.emails` from `shared_page_shareable` unless
  explicitly approved; never return `password_value` to non-creators; restrict registration
  documents and mail attachments to creator/reach; disable guest send.
- **Tests:** guest cannot list/download onboarding documents or mail attachments; creator-only
  access; guest cannot send external mail.
- **Status:** [ ] open
- **Decision:** `decisions.md` D-004.

### T-005 - Make sensitive documents private

- **Fixes:** F-005
- **Changes:** use private local storage or Cloudinary authenticated/signed delivery for employee
  documents, receipts, payment QRs, and bug screenshots; separate public branding/email images.
- **Migration:** download/protect existing sensitive assets, replace public URLs, remove/restrict
  the old assets. Do not delete without a recovery plan.
- **Tests:** unauthenticated URL fails; authorised request gets a short-lived URL; public images
  remain public.
- **Status:** [ ] open
- **Decision:** `decisions.md` D-005.

### T-006 - Remove PII from the repository

- **Fixes:** F-006
- **Changes:** replace `sql/*/data.sql` with sanitized/synthetic fixtures or schema-only; purge
  history with a coordinated rewrite; remove public document URLs.
- **Tests:** history/secret scan clean; local reset still works; no real PII in tracked files.
- **Status:** [ ] open
- **Decision:** `decisions.md` D-006.

### T-007 - Separate Test Server from production

- **Fixes:** F-007, F-022
- **Changes:** distinct `APP_KEY`, `PORTAL_JWT_SECRET`, DB users, and API keys; sanitize/anonymize
  Test data; extend production secret deny-list; add a fail-closed deploy preflight.
- **Tests:** cross-environment token fails; unsafe env aborts deploy; Test data scan clean.
- **Status:** [ ] open

### T-008 - Remove workspace secrets and legacy WordPress risk

- **Fixes:** F-020
- **Changes:** delete `test.py`/local secret files; verify `wpcode-export` handlers are not active;
  rotate the involved DB/API credentials; remove tracked logs/snapshots/binaries (F-021).
- **Tests:** legacy endpoints 404/403; secret scan clean; `git ls-files` audit clean.
- **Status:** [ ] open

## Phase 1 - Authorization and privacy

**Gate:** each affected endpoint has an ownership/reach test and no private field leaks.

### T-101 - Request ownership by employee id

- **Fixes:** F-008
- **Changes:** add/employee-reference the submitting employee on leave, overtime, offset; use it
  for read/edit/cancel and approval reach; backfill/reconcile old rows explicitly.
- **Tests:** same-name employees cannot access each other; queue reach uses id.
- **Status:** [ ] open

### T-102 - Calendar privacy policy

- **Fixes:** F-009
- **Changes:** decide fields/scope; remove free-text reasons from non-owner views; apply
  department/reach where needed.
- **Tests:** non-owner sees only allowed summary fields.
- **Status:** [ ] open
- **Decision:** `decisions.md` D-007.

### T-103 - Restrict onboarding promotion roles

- **Fixes:** F-010
- **Changes:** validate against `PortalRole::assignableRoles()` and the executive lock; audit.
- **Tests:** Executive/Admin refused for non-head; allowed roles pass.
- **Status:** [ ] open

### T-104 - Remove the default developer password

- **Fixes:** F-011
- **Changes:** refuse activation without a stored hash; force one-time setup; rate-limit setup.
- **Tests:** `123` never activates; setup required; rate limit works.
- **Status:** [ ] open

### T-105 - Plaintext link passwords

- **Fixes:** F-013
- **Changes:** stop storing readable passwords or encrypt them; return only to creator; remove from
  list responses.
- **Tests:** DB not plaintext; non-creator cannot retrieve.
- **Status:** [ ] open

## Phase 2 - Sessions and browser

**Gate:** revocation works server-side; XSS impact is materially reduced.

### T-201 - Add server-side session revocation and logout

- **Fixes:** F-014
- **Changes:** session/jti table or employee token version; check on every request; add logout;
  rotate/revoke on password change and account disable; shorten TTL; bound guest TTL.
- **Tests:** logout revokes; old token fails; guest expiry; developer sid behavior preserved.
- **Status:** [ ] open

### T-202 - Web token storage and CSP

- **Fixes:** F-014, F-017
- **Changes:** add strict CSP/HSTS/XFO/nosniff/Referrer-Policy; decide HttpOnly cookie migration
  (with CSRF) or keep bearer plus CSP/short TTL. Update `PORTAL/CLAUDE.md`'s outdated web-storage
  note.
- **Tests:** CSP blocks inline script; cookies (if adopted) HttpOnly/Secure/SameSite; token not
  readable by script.
- **Status:** [ ] open

### T-203 - Clear per-user browser state

- **Fixes:** F-015
- **Changes:** key report drafts/edit targets by employee id; clear drafts/preferences on sign-out;
  add a storage event/multi-tab decision.
- **Tests:** second employee sees no prior draft; sign-out clears.
- **Status:** [ ] open

### T-204 - JWT claim validation and limiter keys

- **Fixes:** F-017
- **Changes:** validate `iss`/`aud`/`nbf`; key guest limits by share id; restrict CORS.
- **Tests:** wrong issuer/audience refused; guest limit isolation.
- **Status:** [ ] open

## Phase 3 - Platform and supply chain

### T-301 - Public health/catalog hardening

- **Fixes:** F-016, F-018
- **Changes:** generic errors, server-side logging, throttle public health/catalog, throttle
  registration info, uniform email-start response, hide ID-login state.
- **Tests:** forced DB failure leaks no exception; rate limits apply; enumeration responses equal.
- **Status:** [ ] open

### T-302 - Dependencies

- **Fixes:** F-019
- **Changes:** upgrade Composer packages; upgrade `axios`/`follow-redirects`; resolve NativeWind
  peer conflict before `npm audit fix`; never force an Expo downgrade.
- **Tests:** audit clean; typecheck/lint/tests pass.
- **Status:** [ ] open

### T-303 - Deployment and DB hardening

- **Fixes:** F-022
- **Changes:** preflight assertions; per-product DB users/least privilege; headers/dotfile deny;
  remove tracked binaries/logs.
- **Tests:** unsafe deploy aborts; headers present; secret scan clean.
- **Status:** [ ] open

## Rollout rule

Phase 0 is a **release blocker**. Do not deploy a wider portal or invite more users until T-002
through T-008 pass (T-001 is fixed). Phase 1 can ship in small PRs after Phase 0. Phase 2/3 follow.

## Verification commands

- `php artisan test --compact` for focused feature tests.
- `vendor/bin/pint --dirty --format agent`.
- `npm run typecheck`, `npm run lint`, targeted `npx jest`.
- `composer audit`, `npm audit --omit=dev`.
- A route inventory check for every new route: auth middleware, rule/ownership, throttle,
  response field allow-list.
