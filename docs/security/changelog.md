# Security changelog

Newest first. Record only security-relevant changes, audits, rotations, and decisions. Link to
`findings.md`/`remediation-plan.md` ids. Never paste a secret or real PII.

## 2026-10-23

- **T-002 fixed (F-002):** Microsoft login now matches only the unique
  `employees.microsoft_oid`; all display-name/email matching is removed. Unlinked accounts are
  refused. `portal:link-microsoft-oid <id_no> <oid>` links one account after MIS verifies the Entra
  UPN; `portal:link-microsoft-oids <csv>` bulk-links an Entra export after a dry run. The column is
  read-only in the workspace grid. Migration added.
- **Temporary Microsoft fallback added (F-002 migration aid):** `login.microsoft_fallback` is on by
  default. An unlinked account can claim exactly one active employee by exact signed work
  email/UPN; names are never used. The supervisor turns it off manually after the migration.
- **T-001 fixed (F-001):** removed the raw `GET /api/{channel}/{product}/{resource}` routes and
  `ResourceController`; deleted the portal's unused `/employees` fallback (`GetAllEmployeesApi`);
  route-absence tests now cover member and guest requests. No external consumer was identified.
- **Security audit completed** across `ikaika_admin`, `ikaika-portal`, and deployment scripts.
  Read-only; production configuration was not inspected. Findings F-001 through F-022 recorded.
- **Created this security workspace and linked it from both `CLAUDE.md` files.** No application
  code was changed by the audit.
- **Dependency audit:** Composer reports 4 advisories; npm reports 46 (1 critical, 37 high).
  `npm audit fix` currently fails with ERESOLVE; no forced upgrade was attempted.
- **Confirmed already-present controls:**
  - JWT signature/expiry verification and active-employee re-read.
  - Guest share revocation/expiry re-read on every request.
  - Webhook HMAC-SHA256 with `hash_equals`, fail-closed on empty secret.
  - Workspace `_schema`/`_grid`/`_views` use `WorkspaceAccess` and private-column checks.
  - Registration and email attachments use private local storage and MIME/extension checks.
  - OTP hashing with attempt caps and resend windows.
  - No cookie-authenticated write API; CORS credentials are off.
  - Airtable sync has TEST/PROD profile selection, wrong-base refusal, and a live-test guard that
    refuses PROD bases.
- **Not fixed by this audit (at the time):** the generic resource API, Microsoft name matching,
  shared ID login, guest onboarding/mail access, public Cloudinary documents, committed SQL PII, and
  all other open findings. T-001 and T-002 were fixed later that day; see the top entries.

## 2026-10-22

- Airtable sync production safety tightened:
  - `AIRTABLE_ENV=prod` selects PROD base ids; `--send` refuses the PROD profile outside the
    production app.
  - The live round-trip test refuses every PROD base id and only writes TEST data.
  - `setup-prod-airtable.ps1` reads `AIRTABLE_TOKEN_PROD`, hides input, refuses TEST base ids, and
    deletes its temporary env file.
  - `push-env.ps1` prints key names only; `push-missing-prod-env.ps1` never copies DB/APP_KEY/
    APP_ENV identity keys.
  - Production `.env` was set to `APP_ENV=production` and TEST/PROD profiles were verified.
- Onboarding delete now hard-deletes the registration, its submitted data, and documents, and
  ledgers the Airtable delete; promoted entries stay read-only. This is a data-lifecycle control,
  not a fix for F-004.
- Production report repair changed 78 earn codes to `01 Regular Working Day` and filled Actual Work
  Date; approvals were not changed.

## Earlier controls already in place

- Portal JWT issuance uses HS256, an expiry, a per-sign-in `sid`, and an active-employee check on
  every request.
- Portal rules and own-work scoping cover the main portal pages; guests are read-only except for the
  explicit email send path (which is itself a finding, F-012).
- Webhooks verify the raw-body HMAC before handling the event.
- Private files use the local private disk with a downloaded filename and `nosniff`.
- SQL identifiers in the workspace/reference paths are allow-listed or quoted; values are bound.
- Production deployment scripts create database backups and restrict `.env`/backup permissions.
- Airtable base/token keys are not committed; `.env` is ignored.

## Template for the next entry

```markdown
## YYYY-MM-DD

- **T-### (F-###):** what changed, and the user-visible effect.
  - Files/area: ...
  - Tests: `php artisan test ...`, `npx jest ...`, typecheck/lint.
  - Verification: local/TEST only? production? no secrets exposed?
  - Remaining risk / decision: ...
```
