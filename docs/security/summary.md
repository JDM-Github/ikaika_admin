# Security summary

**Date:** 2026-10-23  
**Repos:** `ikaika_admin` (Laravel API/admin) and `ikaika-portal` (Expo web/native)  
**Scope:** authentication, authorization, public endpoints, token/session handling, sharing,
uploads, database snapshots, deployment, dependencies, and secret hygiene.  
**Mode:** read-only code audit; production environment values were not inspected.

## Verdict

The API has a working authentication layer, but the current code is **not yet safe to widen**:

1. Deployed ID-number login uses one shared password for every employee; local/test bypass it.
2. Sensitive employee documents and receipts are public Cloudinary URLs.
3. Guest shares can read onboarding documents and sent-mail attachments.
4. Real PII is committed in SQL dumps and document URLs.
5. Test Server runs copies of production data and may share secrets.

The generic model dump (F-001) and Microsoft display-name matching (F-002) were removed on
2026-10-23. The remaining Phase 0 identity and document boundaries are still open.

## Top risks

| ID | Risk | Severity |
|---|---|---|
| F-001 | Generic `/{product}/{resource}` API dumps every model, including employees/warnings/actions | Critical - **fixed 2026-10-23** |
| F-002 | Microsoft identity is matched by mutable display name | High - **fixed 2026-10-23; temporary exact-email fallback on until turned off manually** |
| F-003 | One shared ID-login password signs in as any employee; local/test no password | High |
| F-004 | Guest shares expose onboarding documents, registration passwords, and sent-mail attachments | High |
| F-005 | Sensitive uploads are public Cloudinary URLs | High |
| F-006 | Real PII and financial data are committed in `sql/*/data.sql` | High |
| F-007 | Test Server runs copies of production data and may share secrets | High |

## Controls that already work

- JWT signature/expiry checks, active-employee re-read on every request, and guest/share
  revocation re-read on every request.
- Webhooks use HMAC-SHA256 with `hash_equals` and fail closed when the secret is empty.
- Workspace `_schema`/`_grid`/`_views` use `WorkspaceAccess` and private-column checks.
- Registration and email attachments use private local storage with extension/MIME checks.
- No cookie-authenticated write API, so classic CSRF is not applicable.
- Query identifiers are allow-listed/quoted in the reviewed workspace/references paths.
- OTPs are hashed with attempt caps and resend windows.
- Production Airtable sends are guarded by profile/base checks; live tests refuse PROD bases.

## Immediate priority

Implement **Phase 0** in `remediation-plan.md` before any further production data is exposed:

1. ~~Disable the generic resource routes.~~ Done 2026-10-23 (T-001).
2. ~~Stop Microsoft name matching; bind by immutable oid.~~ Done 2026-10-23 (T-002); link existing
   staff with `portal:link-microsoft-oid`.
3. Disable deployed ID login and remove the local password bypass.
4. Remove onboarding/emails from shareable pages.
5. Move sensitive documents off public URLs and purge the exposed snapshots.
6. Rotate anything that may have been exposed by the repository or Test Server.

## Decisions needed

See `decisions.md`. The blocking ones are which pages may ever be shared and whether sensitive
documents stay in Cloudinary or move to private storage. D-001 (generic API) is decided: remove it.
D-002 (Microsoft) is decided: oid only, unlinked refused, MIS links existing accounts.
