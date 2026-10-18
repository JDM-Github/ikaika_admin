# Airtable → SQL migration

Run `migrate.ps1`. It asks which base you want and whether you want schema,
data, or both, then writes everything for that base into one folder. After
generating, it verifies `data.sql` against the export (`verify_migration.py`)
and stops before the import commands if anything fails. The full picture is in
**HOW-IT-WORKS.md**.

```powershell
.\migrate.ps1
```

```text
What do you want to migrate?
  1. Users & Projects -- PRODUCTION (real live data)
  2. Users & Projects -- TEST (sandbox snapshot)
  3. Project Estimator
  4. Transaction Tracker -- PRODUCTION (real live data)
  5. Transaction Tracker -- TEST (sandbox app)
  6. Custom -- another base ID

What do you want out of it?
  1. Schema and data
  2. Data only
  3. Schema only
```

Skip the prompts by passing the answers:

```powershell
.\migrate.ps1 -Target users-projects-prod -Produce both
.\migrate.ps1 -Target users-projects-test -Produce data -Step generate
.\migrate.ps1 -Target transaction-tracker-test -Produce both
```

Like Users & Projects, the Transaction Tracker has two bases: PRODUCTION
(`appp5MogesHN2eVvG`, the live books) and TEST (`appYwnzEVZVXwH1hB`, a sandbox
app to try Finance against). Both generate into the same `test_tracker_database`
with the same schema and generator; only the base and the output folder differ.

## Do it all at once, or from a window

Three wrappers drive the whole set, so you do not run `migrate.ps1` per base:

| Script | Does |
| --- | --- |
| `migrate-all.ps1 -Env test\|prod` | fetch → upload → generate → verify for every base of that env (portal, estimator, tracker), stopping on the first failure |
| `verify_migrations.ps1 -Env test\|prod` | re-checks every generated `data.sql` against its export, writing `coverage_report.json` per base, and prints one summary table |
| `check_live.ps1 -Env test\|prod` | counts records in Airtable now and compares to the export — flags a STALE export (records added since the fetch) before you generate or import |
| `copy-to-sql.ps1 -Env test\|prod` | adopts the generated `data.sql` into `sql/<product>/data.sql` (strips the appended portal DDL, points `USE` at the local database) and records the env in `sql/status.txt` |

`reset.ps1` reloads every local database (core, portal, estimator, tracker) from
the committed `sql/` folder in one go — the all-bases version of
`sql/portal/reset.ps1`. It reads `sql/status.txt` and warns loudly if the
snapshot is production data.

For a window instead of remembering the commands — with the Test Server deploy
and database actions alongside — double-click **`devtools\Ikaika Console.bat`**.
Each action runs one at a time with live output, an exit code, and errors in
red; destructive actions ask you to type `yes` first.

## What you get

One folder per base, holding everything an import needs:

```text
outputs/
  users_projects_prod/
    export/       raw Airtable JSON, downloaded attachments, Cloudinary cache
    schema.sql    the checked-in sql/portal/ DDL, assembled into one file
    data.sql      generated from that export
  users_projects_test/
    ...
  project_estimator/
    ...
  transaction_tracker_prod/
    ...
  transaction_tracker_test/
    ...
```

`migrate.ps1` never touches MySQL. It prints the two import commands and stops,
because `schema.sql` runs `DROP DATABASE` first.

```powershell
mysql -u root -pPASSWORD --default-character-set=utf8mb4 < outputs/users_projects_prod/schema.sql
mysql -u root -pPASSWORD --default-character-set=utf8mb4 < outputs/users_projects_prod/data.sql
```

To reload the local portal database instead, use `sql/portal/reset.ps1` (portal
only), or `reset.ps1` here, which reloads all four local databases at once.

## Schema is copied, data is generated

**Schema is never generated.** It is the hand-maintained DDL under `sql/`,
concatenated into a single `schema.sql` so one file does the whole job:

| Base | Assembled from |
| --- | --- |
| Users & Projects (both) | `sql/portal/schema.sql` + `sql/portal/separate.sql` |
| Project Estimator | `sql/project_estimator/schema.sql` |
| Transaction Tracker (both) | `sql/transaction_tracker/schema.sql` |

`sql/*/indexes.sql` is deliberately left out. It only retrofits an index onto
databases that predate it, and the schema already creates that index on a fresh
install — concatenating it would fail with `Duplicate key name` and abort
everything after it in the file.

**Data is pulled live from Airtable** for the bases that have a generator under
`generate/` (`fetch` → Cloudinary `upload` → `generate`). Every base has one
now (Project Estimator included); a base without one would fall back to copying
its checked-in `sql/<base>/data.sql` snapshot and say so. Project Estimator's
schema still uses the Postgres inline `REFERENCES` shorthand, which parses in
MySQL but creates no constraint — it enforces zero foreign keys. Follow
`sql/portal/schema.sql` or `sql/transaction_tracker/schema.sql` instead, which
declare foreign keys as explicit table-level clauses.

## Verification

`verify_migration.py` re-derives every row from the raw export (using the
generator's own COLUMNS lists) and compares `data.sql` cell by cell: row
counts, every mapped value, field coverage, link/junction counts, attachments,
and whether every written column exists in the checked-in schema. Run it
standalone when needed:

```powershell
python verify_migration.py --export-dir outputs\users_projects_prod\export --data-sql outputs\users_projects_prod\data.sql
```

Exit code 0 = pass (warnings are informational), 1 = failure. `migrate.ps1`
runs it automatically and refuses to print the import commands on failure.
Which check catches what, and how to re-check against the live base, is in
HOW-IT-WORKS.md §4. Add `--report <path>` to also write a JSON coverage report
(per-table record/row counts plus every field and link that was not migrated) —
the shareable "is all the data there?" artifact. `migrate.ps1` writes it to
`outputs\<base>\coverage_report.json` on every run.

Fields confirmed intentionally not stored live in each base's `accepted` list in
`verify_migration.py`, so a known-good export verifies with **0 warnings**; only a
new or renamed field is reported. Add a field there once you have confirmed it holds
no real data.

## Credentials

The Airtable token is read in this order: `-Token`, then
`migrations/.token.private.txt`, then a hidden prompt. It only ever lives in the
process environment for the fetch step — never written to disk by this script,
never logged, never passed to another step.

Create the token at https://airtable.com/create/tokens with scopes
`data.records:read` and `schema.bases:read`, and access to the base you are
exporting.

Cloudinary reuses the `CLOUDINARY_CLOUD_NAME` / `CLOUDINARY_API_KEY` /
`CLOUDINARY_API_SECRET` already in `ikaika_admin/.env` — nothing extra to set up.

`pip install requests` is the only dependency.

## Attachments

Airtable's attachment URLs are signed and expire within hours, so `fetch` downloads
the real file bytes immediately and `upload` puts them on Cloudinary, caching
`attachment_id → secure_url` in `export/cloudinary_uploads.json` so re-runs never
re-upload. `file_url` in the `attachments` table then holds either:

- a real `https://res.cloudinary.com/...` URL, when the upload step ran, or
- the local relative path under `export/attachments/`, as a fallback.

Never base64, and never the original Airtable URL. A local path renders as a
broken link in the app — `PortalCloudinary::isOwnedUrl` requires a real
Cloudinary URL — so run the upload step for anything meant to actually work.

Reimbursement receipts are the one special case: they are written under
`field_name = 'receipts'` to match `PortalReimbursementRequests::RECEIPT_FIELD`,
the only attachment field the live app reads, rather than the raw Airtable field
name nothing would query. They also upload to the app's own
`ikaika-portal/reimbursements` folder; everything else goes to
`ikaika-portal/migrated/<table>`.

## `outputs/` is gitignored, and must stay that way

`export/` holds real downloaded documents — bank certificates, SSS/PhilHealth/TIN
scans, employee photos — and `data.sql` holds real employee records. The
`migrations/outputs/` rule in `.gitignore` covers all of it. When a generated
`data.sql` is good enough to keep, `copy-to-sql.ps1` promotes it to
`sql/<product>/data.sql` (committed); `sql/status.txt` records which environment
that snapshot came from and is gitignored (machine state).

## The scripts underneath

`migrate.ps1` orchestrates four standalone Python scripts. Run them directly for
anything it does not cover:

| Script | Does |
| --- | --- |
| `fetch_airtable.py` | Exports any base via `--base-id`. Auto-discovers tables and fields, paginates properly, self-throttles to 5 req/s, downloads attachments, resumable. |
| `inspect_schema.py` | Prints real field display names from an export's `_base_schema.json`. The public API keys fields by display **name**, which can be renamed in the UI at any time — always check against a fresh export. |
| `upload_attachments_to_cloudinary.py` | Uploads what `fetch` downloaded, using the same signed-upload scheme as `PortalCloudinary.php`. |
| `verify_migration.py` | Health check: re-derives every mapped row from the export and compares it cell by cell against `data.sql`; checks junctions, attachments and schema columns. See HOW-IT-WORKS.md. |
| `generate/generate_sql_users_projects.py` | Turns a Users & Projects export into SQL matching `sql/portal/schema.sql`. All 20 tables and every junction table are implemented. |
| `generate/generate_sql_transaction_tracker.py` | Turns a Transaction Tracker export into SQL matching `sql/transaction_tracker/schema.sql`. All 5 tables plus 7 junctions and the shared `attachments` table. |
| `generate/generate_sql_project_estimator.py` | Turns a Project Estimator export into SQL matching `sql/project_estimator/schema.sql`. All 10 tables plus 7 junctions. |

`NOTE.md` has the base inventory, the known schema bug in
`projects_activity_scope`, and the Transaction Tracker table breakdown.
