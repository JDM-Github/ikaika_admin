# Security incident runbook

Use this when a token, share link, document URL, database, repository, or account may be exposed.
Do not delete evidence. Do not paste secrets into chat or tickets.

## First 15 minutes

1. **Name the incident type:** leaked employee token / leaked share link / public document /
   database or repo exposure / suspicious login / webhook abuse.
2. **Contain:**
   - Employee token: revoke the session (or rotate the JWT secret until server-side revocation
     exists). Shorten active sessions.
   - Share link: revoke the share in Manage / Shared Pages. If the guest path is suspect, revoke
     every share granting the affected page.
   - Public document URL: remove/replace the asset, make it private, or delete it after a safe copy.
   - Database/repo: restrict access, revoke credentials, snapshot evidence.
3. **Preserve evidence:** export relevant audit rows, access logs, share IDs, user IDs, and UTC
   timestamps before cleaning up. Do not copy secrets into the case notes.
4. **Notify the owner:** PII, finance, or candidate documents involve the data owner/management.
   Do not wait for full root cause to notify.

## Employee token or session

- Identify the employee and `sid`; check `auth.login` audit entries and recent access logs.
- Current code has no server-side logout. Until T-201 lands, a stolen token remains valid for its
  TTL (default 8h). **Rotate `PORTAL_JWT_SECRET`** to invalidate all employee tokens, then require
  everyone to sign in again. This is disruptive but safe.
- Disable the account if compromise is confirmed. Check role/developer status and recent writes.
- After T-201: revoke the session id and re-check all active sessions.

## Share link or guest access

- Revoke the share (`shared_pages.revoked_at`) in Manage / Shared Pages; the next request checks it.
- Audit `shared_page_rules` for the affected page:
  - `manage.onboarding`: candidate list, plaintext passwords, documents.
  - `administration.emails`: sent mail and attachments.
  - `manage.users`, `administration.actions/logs`: employee/audit exposure.
- Revoke **all** shares granting the suspicious page until the page is reviewed.
- If a candidate document was downloaded, treat it as a PII incident and follow the data-owner
  process.

## Public Cloudinary document

- Identify the `attachments` rows/URLs; do not open them in a browser with credentials.
- Make a private copy if the business needs it, then delete or restrict the public asset.
- Replace the DB URL with the private/authenticated location.
- Search logs, SQL dumps, and chat for the URL. Rotate any asset that cannot be restricted.

## Database, repo, or secret exposure

- Revoke/rotate the affected credential at its source (DB user, Airtable PAT, Azure secret,
  Cloudinary, Jira, Bitbucket, mail relay).
- Separate environments: a Test Server key must never be a production key.
- If `sql/*/data.sql` or a repo mirror leaked: treat employee PII and bcrypt hashes as exposed;
  force password resets where applicable, rotate invite codes, and start the history-purge process.
- Search CI logs/artifacts for `.env`, dumps, tokens, and build logs.

## Webhook abuse

- Verify the HMAC secret is configured and not empty.
- Rotate the Bitbucket/Jira webhook secret, update both sides, and check whether the endpoint was
  spammed or replayed.
- The handlers are idempotent/read-only for the current integrations; still audit the resulting
  rows.

## Login/account takeover

- Disable the account; inspect `auth.login`, write actions, developer mode, and share creation.
- Microsoft login is oid-only since 2026-10-23; an unexpected link means someone changed
  `microsoft_oid`. Clear the link, disable the Entra account, and investigate the admin grid.
- If the ID-login shared password is the vector, rotate `PORTAL_ID_LOGIN_PASSWORD`, disable ID login
  in production, and force sessions to end.
- Reset the account's developer-mode password and revoke its active session.

## After containment

- Write a dated entry in `changelog.md` with the incident type, scope, actions, and remaining risk.
- Add/update a finding and remediation task with an acceptance test.
- If a control failed, update `prevention.md` with the missing invariant.
- Review similar endpoints/features for the same bug (grep the shared class/middleware, not only
  the reported path).
- Keep the incident record free of secrets and unnecessary PII.
