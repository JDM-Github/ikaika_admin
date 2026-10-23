# Security decisions

Open decisions are blocking the related remediation task. Record the chosen option, date, and who
decided; do not leave a "temporary" decision undocumented.

## D-001 - Does anything consume the generic resource API?

- **Why:** F-001/T-001. Removing the routes is the cleanest fix, but external MIS scripts may use
  `GET /api/{channel}/{product}/{resource}`.
- **Options:** remove them; or keep a small explicit allow-list with DTOs and rule/reach checks.
- **Recommendation:** remove. The portal UI uses its own endpoints; the generic API is a raw model
  dump with no safe field boundary.
- **Status:** **decided 2026-10-23 - remove.** No external consumer identified; portal `/employees`
  fallback deleted. Implemented as T-001.
- **Owner:** product/API owner

## D-002 - Microsoft identity binding

- **Why:** F-002/T-002.
- **Options:** match `oid` only; or verified work email/UPN first, then bind `oid`; or disable
  Microsoft login until accounts are linked.
- **Recommendation:** `oid` as the durable key, with work-email/UPN only to make the first link.
  Refuse if the oid is already linked to a different employee.
- **Status:** **decided 2026-10-23 - oid first, temporary exact-email fallback.** The fallback is
  on by default and only links one active employee by exact signed work email/UPN; names are never
  used. The supervisor turns it off manually after the migration. MIS may still use
  `portal:link-microsoft-oid` or the bulk CSV command for mismatches.
- **Needs:** the Entra oid export only for accounts whose Microsoft email does not match the portal
  email; MIS owns those links.

## D-003 - ID-number login

- **Why:** F-003/T-003.
- **Options:** remove in production; keep for local/test only; keep with per-user password/OTP and
  lockout.
- **Recommendation:** remove in production. Keep local/test access behind an explicit test flag,
  never `APP_ENV`.
- **Status:** open
- **Owner:** MIS/operations

## D-004 - Which pages may ever be shared?

- **Why:** F-004/T-004. `manage.onboarding`, `administration.emails`, `manage.users`,
  `administration.logs/actions`, and finance pages are currently shareable.
- **Recommendation:** onboarding and All Emails are not shareable. Users/logs/actions/finance only
  after a data-classification review; restrict to creator/reach and private fields.
- **Status:** open
- **Owner:** product/security owner

## D-005 - Sensitive document delivery

- **Why:** F-005/T-005.
- **Options:** private local disk; Cloudinary authenticated delivery with short-lived signed URLs;
  encrypted object storage.
- **Recommendation:** private local disk for candidate/employee/finance documents unless Cloudinary
  authenticated delivery is proven operationally. Keep public Cloudinary for branding only.
- **Status:** open
- **Needs:** volume, retention, backup, and preview requirements.

## D-006 - SQL snapshots in git

- **Why:** F-006/T-006.
- **Options:** sanitized fixtures; schema-only; encrypted private dump outside git.
- **Recommendation:** schema-only in the repo, synthetic/sanitized seed data for tests, real dumps
  outside git. Purge history in a coordinated window.
- **Status:** open
- **Owner:** data owner/team lead
- **Impact:** local reset and Test Server setup must be updated at the same time.

## D-007 - Calendar privacy

- **Why:** F-009/T-102.
- **Options:** keep all details for every member; show name/date/category only; scope by
  department/reach.
- **Recommendation:** no free-text reasons to non-owners; scope by department where the calendar is
  not a company-wide notice.
- **Status:** open
- **Owner:** HR/management

## D-008 - Web token storage

- **Why:** F-014/T-202. Web currently uses `localStorage`; the portal `CLAUDE.md` says web storage
  is in-memory, which is stale.
- **Options:** HttpOnly Secure SameSite cookie + CSRF; or keep bearer + strict CSP + short TTL +
  server-side revocation.
- **Recommendation:** move to HttpOnly cookies if the hosting/CSRF design allows; otherwise CSP +
  revocation is the minimum. Do not leave the current "localStorage + no CSP + no revocation".
- **Status:** open
- **Owner:** portal/security owner

## D-009 - Guest email send

- **Why:** F-012. Guests can send company-branded HTML to external addresses.
- **Recommendation:** disable guest send; shares stay read-only.
- **Status:** open
- **Owner:** product owner

## Accepted risks

| Risk | Why accepted | Expiry / review |
|---|---|---|
| None recorded | - | - |
