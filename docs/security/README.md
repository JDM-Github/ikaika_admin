# Security workspace

This folder is the single source of truth for security work on the Ikaika admin/API
(`ikaika_admin`) and the Expo portal (`ikaika-portal`).

Read this file before planning or changing authentication, authorization, routing, sharing,
uploads, tokens, deployments, or anything that exposes employee/candidate data.

## Current status

- Audit date: **2026-10-23**
- Mode: read-only code audit of both repositories and their deployment scripts.
- Production configuration was **not** inspected. Findings are based on repository code and the
  local/deployment configuration that is checked in or documented.
- **T-001 is fixed (2026-10-23):** the raw `{product}/{resource}` model routes and their controller
  are removed; the portal no longer uses the old `/employees` fallback. Member and guest tests
  assert the old route is gone.
- **T-002 is fixed (2026-10-23):** Microsoft sign-in is bound to a unique `employees.microsoft_oid`,
  never the display name. A temporary Advanced fallback is on by default and auto-links by exact
  signed work email/UPN; the supervisor turns it off manually after the migration. While it is on,
  an unlinked Microsoft account with a matching active work email can claim that employee. The
  remaining Phase 0 items are still open, so the portal is **not yet** safe to widen.

## Severity

| Level | Meaning |
|---|---|
| Critical | Any authenticated employee, or any share guest, can read data they must never see. Fix immediately. |
| High | Account impersonation, sensitive documents/PII exposed, or a production-grade privacy breach. |
| Medium | Privilege misuse, cross-user data exposure, or a weak credential/session control. |
| Low | Hardening, information leak, or defence-in-depth gap. |

## Index

| File | Use it when |
|---|---|
| `summary.md` | You need the short verdict, top risks, current phase, and open decisions. |
| `findings.md` | You need every finding with evidence, impact, and the required fix. |
| `remediation-plan.md` | You are implementing or tracking the phased work. |
| `prevention.md` | You are adding a route, share page, upload, token flow, sync, or deployment. |
| `changelog.md` | You want what has already been done, by date. |
| `runbook.md` | A token, share link, document, database, or repository exposure is suspected. |
| `decisions.md` | A security trade-off needs an owner decision. |

## Phase dashboard

| Phase | Scope | Status |
|---|---|---|
| 0 | Containment: close the anonymous/any-employee data dump and impersonation paths | **In progress - T-001/T-002 fixed** |
| 1 | Authorization and privacy: ownership, guest boundaries, roles, developer mode | Open |
| 2 | Sessions and browser: token storage, revocation, CSP, headers, claims | Open |
| 3 | Platform and supply chain: deploy checks, secrets, dependencies, logging | Open |

## Rules for this folder

- Every security change updates `changelog.md` and the task status in `remediation-plan.md`.
- A finding is not closed until its acceptance test exists and passes.
- Do not paste secrets, tokens, passwords, real employee PII, or public document URLs into these
  files. Reference the class or column, never the value.
- If a new risk is found, add it to `findings.md` with the next free `F-###` id and a phase.
- If a decision changes, update `decisions.md` and the affected finding/remediation task.
- Keep the files current rather than appending contradictory notes.
