# Preventing the next security bug

Security is a design constraint, not a final checklist. Every new endpoint, page, share, upload,
sync, or deployment must pass the rules below before it ships.

## Non-negotiable invariants

1. **Every route is authenticated unless it is deliberately public.**
   Public routes are only: auth start/verify, share sign-in/session, registration sign-in/session,
   health, and webhooks. A new public route requires an explicit decision in `decisions.md`.
2. **Every authenticated route is authorised.**
   Authentication answers "who"; it never answers "what may they see". Add `portal.rule`,
   `portal.own`, `WorkspaceAccess`, or a service-level ownership/reach check.
3. **No raw model dumping.**
   Never return `Model::query()` directly. Use explicit resources/DTOs, allow-listed fields, and
   presenters. `$guarded = []` is not a security boundary.
4. **Guest is not a weak employee.**
   A share guest is read-only, must not reach employee/admin models, and may only see the pages the
   share explicitly grants. Never pass a guest token to a generic API.
5. **Identity comes from an immutable provider claim.**
   Match Microsoft by `oid` (preferred) or verified work email/UPN. Never by display name, nickname,
   or a request-supplied name.
6. **No shared passwords for accounts.**
   A login factor is per person. Shared team passwords, default passwords, or an environment-based
   bypass are not acceptable in production.
7. **Sensitive files have private delivery.**
   Employee/candidate documents, receipts, payment QRs, and private screenshots use private local
   storage or authenticated/signed Cloudinary delivery. Public URLs are only for public branding.
8. **Secrets never enter the repository.**
   `.env`, tokens, DB passwords, JWTs, private keys, invite codes, and PII are not committed.
   Generated dumps are sanitized or schema-only.
9. **Tokens are short-lived and revocable.**
   Server-side session/version check, logout/revocation, short TTL, no localStorage on web unless a
   CSP and XSS review explicitly accepts it.
10. **Least privilege in data and deployment.**
    Per-environment keys, per-product DB users, no Test Server copy of production PII, no broad
    database grants.
11. **Every security decision is tested.**
    A happy-path test is not enough. Include the negative case: wrong user, guest, no rule, expired
    token, revoked share, tampered payload.

## New route checklist

Before merging a route:

- [ ] Is it public? If yes, record why in `decisions.md` and add throttle + uniform errors.
- [ ] Does it have `AuthenticatePortalJwt` or the correct registration/share middleware?
- [ ] Does it have a `portal.rule` / `portal.own` / `WorkspaceAccess` check?
- [ ] Does the service re-check ownership/reach for an id taken from the URL?
- [ ] Is the response a DTO/allow-list, not a whole model?
- [ ] Are private fields omitted for non-owners?
- [ ] Is there a write throttle and server-side validation?
- [ ] Is the action audited without secrets/PII in the payload?
- [ ] Are guest and same-name/other-department cases tested?
- [ ] Is the route in `php artisan route:list` reviewed by another person?

## New shareable page checklist

- [ ] Is this page safe to expose to an unauthenticated guest with only a password/OTP?
- [ ] Does the page avoid employee PII, candidate documents, mail attachments, passwords, and
  private reasons?
- [ ] Is the list scoped to one creator/department instead of "all"?
- [ ] Are downloads creator/reach scoped and audited?
- [ ] Can a guest write? If yes, why, and is the write limited to an explicit action?
- [ ] Is the page removed from `shared_page_shareable` when it no longer qualifies?

## New upload checklist

- [ ] File type allow-list by extension and detected MIME.
- [ ] Size cap and clear error.
- [ ] Random server filename; never trust the client name.
- [ ] Private disk or authenticated delivery for sensitive files.
- [ ] Download authorization checked by owning record/creator.
- [ ] No public CDN URL returned for employee/candidate/finance documents.
- [ ] Files cleaned up on entry deletion and failed transactions.
- [ ] No secrets/PII in logs or file names.

## New sync/export/import checklist

- [ ] Which environment/base does it target? Assert TEST/PROD explicitly.
- [ ] Does it fail closed on missing/ambiguous config?
- [ ] Are credentials scoped to the minimum base/table/action?
- [ ] Are writes idempotent and locked?
- [ ] Are dry-run and real-send separated with a confirmation?
- [ ] Does an error expose a token, SQL, file path, or PII?
- [ ] Is the resulting file/report stored privately and access-controlled?
- [ ] Is the destination checked for the correct environment before any write?

## Authentication checklist

- [ ] No default or shared password.
- [ ] No environment-based bypass (`APP_ENV=local` must not unlock production).
- [ ] OTP hashed, short TTL, attempt cap, resend window, rate limit.
- [ ] SSO matches an immutable claim; display names are display only.
- [ ] First-login linking cannot claim an already-linked/unverified account.
- [ ] Logout and password/status changes revoke sessions.
- [ ] Login errors do not enumerate accounts.
- [ ] Tokens are not returned in URLs or logs.

## Data classification

| Class | Examples | Handling |
|---|---|---|
| Secret | PATs, OAuth secrets, DB passwords, JWT secret, APP_KEY | `.env`/secret store only; never logs/repo; rotate on exposure |
| Restricted | Employee/candidate PII, government IDs, bank details, receipts, warning reasons | private disk/authenticated delivery; owner/reach checks; no public links |
| Internal | Reports, projects, finance totals, audit logs | authenticated + rule/reach; no guest unless explicitly required |
| Public | Branding images, public calendar summaries, marketing | public CDN okay; no PII |

## Threat-model prompts for every feature

- Who is the least-privileged person who can reach this? What should they see?
- What happens with a guest/share token, an expired token, a disabled employee, a token for another
  department, and a same-named employee?
- Can an id be changed to another person's record (IDOR)?
- Does the response contain more fields than the screen needs?
- Can this be replayed, spammed, or used for enumeration?
- What happens when a download URL is copied out of the browser?
- What happens if the database/repo/Test Server is leaked?
- Does a failed request still write a row or a file?

## Security tests every feature should consider

- Unauthenticated: 401.
- Authenticated but no rule: 403.
- Guest: 403/404 for non-granted pages, read-only behavior, revoked/expired share.
- Owner vs same-name/other-department: 404/403.
- IDOR: another id returns 404/403, not data.
- Private fields: absent for non-owner; present for owner/admin only.
- Upload: wrong MIME/extension/size refused; private URL not public.
- Auth: wrong password/code, lockout, expired token, revoked logout.
- Error path: no token/SQL/PII in response/log.

## Review culture

- Security review is part of "definition of done", not a separate audit.
- Any new raw query, file path, token, upload, share, webhook, or public route gets a second pair of
  eyes.
- Prefer deleting a risky feature over bolting on a check. The safest endpoint is the one that does
  not exist.
- If a shortcut accepts a known ceiling, mark it with a `ponytail:` comment and a security task in
  `remediation-plan.md`; never leave it silent.
- When a security fix changes behavior, document the user-visible effect in `changelog.md` and the
  deployment guide.

## Source of truth

The detailed, dated work lives in this folder. `CLAUDE.md` requires reading `README.md` before
security-sensitive work; this file is the checklist that keeps the next change from reopening the
same class of bug.
