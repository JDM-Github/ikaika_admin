# Migration Notes — Airtable → SQL (context handoff from claude.ai)

This file summarizes a claude.ai planning session so Claude Code can pick up
without re-deriving everything from scratch. Point Claude Code at this file
first (e.g. "read MIGRATION_NOTES.md before we continue").

## The four Airtable bases involved

| Base | Base ID | Notes |
|---|---|---|
| Users & Projects (TEST) | `app8DvnFZErPZT5Az` | Snapshot/sandbox version, migrated first |
| Users & Projects (PRODUCTION) | `app8jGTzRDQt4yPIa` | Same schema/field IDs as TEST (verified identical), real live data, ahead in record counts |
| Project Estimator | `appgh0Mki2uHDLAQY` | Completely different schema — a separate tool, not a copy of Users & Projects |
| Transaction Tracker | `appp5MogesHN2eVvG` | **Not yet migrated** — separate finance-tracking base, schema pulled but no schema.sql/data.sql built yet. See dedicated section below. |

**Users & Projects TEST vs PRODUCTION share identical table/field IDs** — same
schema works for both. Project Estimator and Transaction Tracker don't share
anything with the other bases or each other; each has its own schema
entirely.

## Deliverables already produced (in claude.ai, attached as files in that
conversation — re-download or recreate if not already saved locally)

### Users & Projects base
- `users_projects_schema.sql` — PostgreSQL schema, 48 tables (20 real
  Airtable tables + junction tables for every multipleRecordLinks field +
  a shared `attachments` table)
- `data.sql` — full data load for the TEST base (verified row-by-row: 20
  employees, 114 projects, 4,309 user reports, etc. — every table at 100%)
- `schema_prod.sql` / `data_prod.sql` — same schema, production base data
  (120 projects, 4,432 user reports, etc.) — also includes an
  **Achievements and Milestones** table (86 rows) that only exists in
  production, not in TEST
- `schema_mysql.sql` / `data_mysql.sql` / `data_prod_mysql.sql` — MySQL-
  compatible versions. Key differences from the Postgres originals:
  - `SERIAL PRIMARY KEY` → `INT AUTO_INCREMENT PRIMARY KEY`
  - **Important**: inline `col INTEGER REFERENCES tbl(id) ON DELETE CASCADE`
    (valid Postgres shorthand) does NOT create an enforced foreign key in
    MySQL — it silently parses but adds no real constraint. All 56 FKs were
    rewritten as explicit table-level `FOREIGN KEY (...) REFERENCES ...`
    clauses for the MySQL version. If hand-editing schema later, remember
    this gotcha.
  - Added `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4` to every table (InnoDB
    required for FK enforcement; utf8mb4 avoids mangling non-ASCII text)

### Project Estimator base
- `project_estimator_schema.sql` — separate schema (10 tables: Employees,
  Projects, Activity Codes, Earn Codes, **Earn Codes V2**, Accounts, Project
  Estimates, Holidays, Bug Reports, User Reports)
- `project_estimator_data.sql` — full data (18 employees, 120 projects,
  4,432 user reports, etc.)

## Gotchas worth knowing before touching this data

1. **Two earn-code tables in Project Estimator that look like duplicates but
   aren't.** `earn_codes` (Airtable table "Earn Codes") and `earn_codes_v2`
   (Airtable table literally named "Grid view 2" in the UI — misleading
   name) are separate tables with different Airtable record IDs. The
   Holidays table's link fields point into `earn_codes_v2`, not
   `earn_codes` — verified by cross-referencing actual Airtable record IDs,
   not by field name similarity. Don't consolidate these into one table.

2. **Accounts table (Project Estimator) contains real bcrypt password
   hashes and invite codes.** Included as-is per explicit instruction, but
   treat this table as sensitive — don't expose it beyond trusted systems.

3. **User Reports exists in both Users & Projects (production) and Project
   Estimator, with the same row count (4,432) at the time of export — this
   is coincidental, not shared data.** Verified: zero overlapping Airtable
   record IDs, and spot-checked entries show genuinely different timesheet
   data. They are two independent tables that happen to be the same size
   right now.

4. **Attachments**: the hand-built exports (data.sql, data_prod.sql,
   project_estimator_data.sql) use a placeholder filename `'image.png'` for
   every attachment — these were built through a connector with response
   size limits that made real attachment handling impractical at the time.
   **This is different from the migration script approach below**, which
   downloads real files and (for the TEST base at least) uploads them to
   Cloudinary for real.

5. **Two ambiguous/orphan links were flagged, one now resolved:**
   - Users & Projects: `projects_activity_scope` junction — schema.sql's
     comment guesses the FK target is `project_scope_t3_activities`. This
     guess has been checked against real TEST-base data and is **wrong** —
     Projects' "Activity Scope" field actually links to **Activity Codes**
     (verified: linked record IDs live in `Activity Code_s.json`, not the
     T3 export). Not yet fixed in the checked-in schema — needs a one-line
     FK change (`REFERENCES activity_codes(id)`) plus pointing the
     generator's junction logic at `activity_id_map` instead of
     `t3_id_map`. See "Known schema bug" below.
   - Project Estimator: Projects table's "Label" field links to records of
     unclear origin (date-stamped, don't match any obvious table) — no FK
     generated, flagged as orphan link in schema comments, still
     unresolved.

## Migration scripts (in `migrations/`)

Four standalone Python scripts, no Anthropic/Claude dependency:

- **`fetch_airtable.py`** — generic, works on any base via `--base-id`.
  Auto-discovers tables/fields via Airtable's Meta API. Real pagination
  (100 records/page via offset cursor — the connector used in claude.ai
  had special pageSize=8000 behavior that the real public API does NOT
  support). **Downloads actual attachment file bytes immediately** because
  Airtable attachment URLs are signed and expire within hours — this is the
  fix for the placeholder-filename problem in gotcha #4 above.
- **`inspect_schema.py`** — prints real field display names per table from
  an export's `_base_schema.json`. Needed because the public API keys
  fields by display NAME, while the internal connector used in claude.ai
  keyed everything by internal field ID — these are different identifiers
  and the mapping between them isn't guessable without inspecting the
  export.
- **`upload_attachments_to_cloudinary.py`** — uploads every file
  `fetch_airtable.py` downloaded to Cloudinary, using the same signed-upload
  scheme as `app/Support/Portal/PortalCloudinary.php` (folder + timestamp
  signature), and caches `attachment_id -> secure_url` in
  `<export-dir>/cloudinary_uploads.json` so re-runs never re-upload. Reads
  `CLOUDINARY_CLOUD_NAME`/`_API_KEY`/`_API_SECRET` from `ikaika_admin/.env`
  by default. Reimbursement receipts go to the live app's own
  `ikaika-portal/reimbursements` folder; everything else to
  `ikaika-portal/migrated/<table>`. **Already run for real** against the
  TEST-base export: 186/186 uploaded, 0 failed.
- **`generate_sql_users_projects.py`** — transforms a fetch_airtable.py
  export into SQL matching `sql/portal/schema.sql`. **Status: all 20 tables
  and every junction table are fully implemented**, verified against a real
  TEST-base export (counts matched the manifest exactly: 20 employees, 114
  projects, 4,309 user reports, etc.) and round-tripped through a real local
  MySQL 8.0 import (schema + data both loaded clean, join queries returned
  real data). Pass `--cloudinary-cache <export-dir>/cloudinary_uploads.json`
  to write real Cloudinary URLs into `file_url`; omit it and attachments
  fall back to local downloaded paths. Reimbursement receipts are a special
  case — written under `field_name = 'receipts'` (matching
  `PortalReimbursementRequests::RECEIPT_FIELD`, the only table the live app
  currently reads attachments from), not the raw Airtable field name. No
  equivalent script exists yet for the Project Estimator or Transaction
  Tracker bases' schemas.
  **`migrations/data.sql` is the real, current output** — generated against
  the TEST base with the Cloudinary cache applied (183 attachment rows: 186
  uploaded minus 3 reimbursements that had both receipt fields set, where
  only the first is kept, matching the live app's one-receipt-per-row model)
  and re-verified end to end (real MySQL import + a join query matching
  `PortalReimbursementRequests`'s own query shape).

**Known schema bug, not yet fixed:** `sql/portal/schema.sql`'s
`projects_activity_scope` junction has a comment guessing its FK target is
`project_scope_t3_activities`. Checked against real TEST-base data — that
guess is wrong. Projects' "Activity Scope" field actually links to
**Activity Codes** (verified: the linked record IDs live in
`Activity Code_s.json`, not the T3 export). The generator leaves this
junction empty rather than insert IDs against the wrong FK target. Fix is a
one-line FK change in `schema.sql` (`REFERENCES activity_codes(id)`) plus
pointing the generator's `projects_activity_scope` junction at
`activity_id_map` instead of `t3_id_map` — not done yet, needs a decision on
whether to touch the checked-in schema.

Setup: `pip install requests`, then `$env:AIRTABLE_API_KEY="..."` (or
`export` on bash/zsh) with a Personal Access Token from
https://airtable.com/create/tokens (`data.records:read` +
`schema.bases:read` scopes). **Note: an earlier token was pasted directly
into the claude.ai chat and should be treated as compromised — confirm a
fresh one was generated and the old one revoked before relying on this.**

## Transaction Tracker — schema pulled, migration not started

Base ID `appp5MogesHN2eVvG`. 5 tables, discovered via `list_tables_for_base`
but not yet exported with `fetch_airtable.py` or converted to SQL:

- **Transactions** (`tblKpuFbcyc6YfmH1`) — Transaction Name, Date, Amount
  (currency), Type (singleSelect), Account (link → Accounts), Envelope
  (link → Envelopes - Budget), Payor/Payee (plain text, NOT a link despite
  the name — different from the Payor/Payee *table* below), Receipt
  (attachment), Description, Reviewed By (collaborator), Approval Date,
  Created By, Created Time, Attachments (second, separate attachment
  field), Auto-Extracted Details (AI-generated text field), Transfer
  Destination Account (link → Accounts), Transfer Source Account (link →
  Accounts), Budget Code (link → Budget Code table), plus several
  rollup/lookup fields (Current Balance, Account Name lookups, Budget Code
  Name) that — per the convention used elsewhere in this project — should
  NOT be stored as columns, just noted as computed/omitted.
- **Envelopes - Budget** (`tblbeCM0N32nUcQe0`) — Envelope Name, Budget
  Amount, Group (singleSelect), Time Period (singleSelect), Active
  (checkbox → BOOLEAN), Budget Notes, links to Transactions/Account/Budget
  Code. Several rollup/formula fields (Total Expense/Income Transaction
  Amount, Remaining Budget) — omit as computed, same convention as above.
- **Accounts** (`tbluWKBN7zWuPMjON`) — Account Name, Account Type
  (singleSelect), Email, QRCode (attachment), Institution, Active
  (checkbox), links to Transactions/Envelopes/Incoming Transfers/Outgoing
  Transfers, CreationStatus (singleSelect). Remaining Funds and several
  Total Income/Expense rollups are computed — omit.
- **Payor/Payee** (`tblt4gtJjG7MtzV7y`) — Name, Type (singleSelect),
  Contact Name, Email, Phone, Associated Transactions (plain text, not a
  real link — worth double-checking against actual data whether this is
  an orphan/free-text field or should resolve to something), Company,
  Notes, Business Lookup (AI text), Expense Plan (plain text).
  **Note**: this is a different thing from the plain-text "Payor/Payee"
  field on the Transactions table above — same name, not the same data
  relationship. Confirm with real data whether Transactions.Payor/Payee
  free text is meant to match rows in this table by name, or is
  genuinely independent.
- **Budget Code** (`tblhCLVztUsaZJpN8`) — Name, Desc, Transfer Date
  (Received), Expected Amount (Quotation), Attachments, Attachment
  Summary (AI text), links to Transactions and Envelopes - Budget. Actual
  Credited and Credit Status are computed — omit.

**Likely connection to existing work**: one of the Reimbursements records
in the Users & Projects TEST base data has the remark *"test reimbursement
for integration to transaction tracker"* — this base may be the intended
destination for reimbursement data going forward. Worth checking whether
`Budget Code` or `Transactions` here is meant to receive rows sourced from
the `reimbursements` table already migrated in the other bases, before
building a schema in isolation.

**Not yet done for this base**: no `fetch_airtable.py` export has been run
against it, no `transaction_tracker_schema.sql` exists, and no data
migration has happened. Next step would follow the same pattern used for
Project Estimator: run `fetch_airtable.py --base-id appp5MogesHN2eVvG`,
inspect real field names with `inspect_schema.py`, then build the schema +
data SQL (and a `generate_sql_transaction_tracker.py` following the same
pattern as the Users & Projects generator, which — unlike this one — is
already fully implemented for all tables).

## Airtable ↔ SQL sync — design discussion (not yet built)

The user's portal writes to SQL as the source of truth and wants Airtable
kept in sync (Airtable is primarily a read/display surface, not something
other systems write into independently — worth confirming this assumption
still holds before building anything two-directional).

Agreed direction from the planning conversation, not yet implemented:

- **One-directional push (SQL → Airtable) only**, not two-way sync — much
  lower risk, especially around deletion.
- **Outbox pattern**: portal writes to SQL and an "outbox"/pending-sync
  table in the same transaction, so the two can't drift from a crash
  mid-write. A worker (or the manual trigger below) drains the outbox.
- **FIFO ordering** matters for relationship integrity (e.g. create a
  project before creating a user report that links to it).
- **Manual trigger over automatic background sync**: an admin clicks
  "Sync" rather than a scheduled job running unattended — chosen
  deliberately as a simpler, more visible failure mode than a silent
  background worker.
- **Locking**: needs a `locked_at` timestamp with a timeout (~10 min) so a
  crashed sync doesn't permanently block all future syncs. A second admin
  clicking "Sync" while one is running should see live progress
  (X of Y items, who started it, when) rather than just a generic
  "locked" message.
- **Partial-failure handling**: leaning toward skip-and-continue (one bad
  record shouldn't block 799 good ones), with a clear end-of-run report of
  what failed and why, rather than stop-on-first-error.
- **Dead-letter handling**: an item that fails repeatedly (not just a
  transient blip) should get pulled aside for manual review instead of
  re-queuing forever and blocking the FIFO queue behind it.
- **Deletion was explicitly called out as the hardest part** and is not
  covered by the design above — detecting "this SQL row is gone, so delete
  it from Airtable" requires a full comparison pass (every Airtable
  `airtable_record_id` against every SQL row), which is risky if the SQL
  snapshot is ever incomplete or stale. Recommended: soft-delete/archive
  flag in Airtable rather than hard delete, treated as a separate,
  deliberately-triggered step rather than automatic.
- **Airtable record IDs are the join key** — every table in the generated
  schemas has an `airtable_record_id VARCHAR(20) UNIQUE` column specifically
  to support this kind of push-back sync later.

None of the sync/outbox infrastructure has been built yet — this section is
a design conversation only, to pick up if/when that becomes the next step.