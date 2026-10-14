# How the Airtable -> SQL migration works

Read this before touching `migrate.ps1`, `fetch_airtable.py`, `generate/*.py`, or
`verify_migration.py`. It explains what gets pulled out of Airtable, why the fetch is
exhaustive, how Airtable field names become SQL columns, and how the health check
proves that a `data.sql` matches its export.

---

## 1. The pipeline

```
migrate.ps1
  |
  |-- fetch    fetch_airtable.py --base-id <base> --output-dir outputs/<base>/export
  |              downloads every table + every record + every attachment
  |
  |-- upload   upload_attachments_to_cloudinary.py --export-dir <export>
  |              puts the downloaded files on Cloudinary, caches attachment_id -> URL
  |
  |-- generate generate/generate_sql_<base>.py --export-dir <export> --output data.sql
  |              turns the raw export into INSERT statements (schema NOT generated)
  |
  |-- verify   verify_migration.py --export-dir <export> --data-sql data.sql
  |              re-derives every row from the export and compares cell by cell;
  |              a failure stops the run before the import commands are printed
  |
  |-- schema   assembles schema.sql by concatenating the checked-in sql/<base>/ DDL
  |
  '-- prints (does not run) the two mysql import commands
```

Run it with prompts: `.\migrate.ps1`. Or skip the prompts:
`.\migrate.ps1 -Target users-projects-prod -Produce both`.

Nothing touches MySQL automatically. `schema.sql` starts with `DROP DATABASE`, so the
last step is always a deliberate hand-run import:

```powershell
mysql -u root -pPASSWORD --default-character-set=utf8mb4 < outputs\users_projects_prod\schema.sql
mysql -u root -pPASSWORD --default-character-set=utf8mb4 < outputs\users_projects_prod\data.sql
```

Key split: **schema is copied, data is generated.**

| Piece | Where it comes from | Who edits it |
| --- | --- | --- |
| `schema.sql` | checked-in `sql/<base>/schema.sql` (+ `sql/portal/separate.sql` for Users & Projects), concatenated | humans, by hand |
| `data.sql` | `generate/generate_sql_<base>.py` reading the export | the generator script |
| `export/` | live Airtable, via the public REST API | never edited by hand |

`sql/*/indexes.sql` is deliberately left out of the assembled schema (it only retrofits
indexes onto old databases; a fresh schema already creates them).

---

## 2. What the fetch actually takes (and why it is 100%)

`fetch_airtable.py` is a generic exporter. For a base ID it does:

1. **Discovers the structure** via Airtable's Meta API
   (`/v0/meta/bases/<base>/tables`). This returns *every table and every field* of the
   base, whether the person running it knows about them or not. That list is saved as
   `export/_base_schema.json` and is the ground truth the verifier later checks against.

2. **Paginates every record of every table** via the records endpoint, 100 per page
   (Airtable's maximum), following the `offset` cursor until the API stops returning
   one. There is:
   - no `filterByFormula` (no rows excluded),
   - no `view=` (no view-level filtering),
   - no `maxRecords` (no count cap),
   - no `fields=` list (every field comes back).

   The only records that don't come back are the ones that don't exist. Airtable omits
   *empty* fields from a record's `fields` object entirely — so "field absent" means
   "empty", which the generator and verifier both treat as SQL `NULL` (except
   checkboxes, where absent means unchecked / `FALSE`).

3. **Downloads every attachment's actual bytes immediately.** Airtable attachment URLs
   are signed and expire within hours; saving the URL is saving a future dead link.
   Files land in `export/attachments/<record_id>/<field>/<filename>` and every one is
   recorded in `export/attachments_manifest.json` with a `status`
   (`downloaded` / `already_downloaded` / `failed`). Re-runs skip files already on disk,
   so an interrupted fetch resumes cheaply.

4. **Self-throttles and retries.** ~4.7 requests/second (under Airtable's 5/s cap) with
   backoff on 429/5xx, so big bases don't get cut off mid-pagination.

The result: `export/` is a complete, raw, lossless copy of the base at fetch time —
every table, every record, every non-empty field, every attachment file. It is *raw*:
the JSON is Airtable's own record shape (`{"id": "rec...", "fields": {...}}`), nothing
renamed, nothing interpreted.

Two honest limits:

- "Everything" means everything the token can read. The token needs
  `data.records:read` + `schema.bases:read` on the base, and Airtable hides nothing
  else from that scope.
- A field can be *present in the export* and still be dropped on purpose by the
  generators: computed fields (formula, rollup, lookup, autoNumber, created/modified)
  and the project's convention of not storing every text-mirror field. That is not
  data loss — it's the schema design. The verifier reports every such field so an
  intentional omission is visibly intentional (see §5, "field landing").

---

## 3. From export to data.sql

Each base has its own generator under `generate/`:

| Base | Generator | Schema it targets |
| --- | --- | --- |
| Users & Projects | `generate_sql_users_projects.py` | `sql/portal/schema.sql` |
| Transaction Tracker | `generate_sql_transaction_tracker.py` | `sql/transaction_tracker/schema.sql` |
| Project Estimator | `generate_sql_project_estimator.py` | `sql/project_estimator/schema.sql` |

### 3.1 The column map is a human-maintained list

Airtable keys its fields by **display name** — the literal text in the UI, e.g.
`ID No.`, `Hours Rendered`, `IsSupport`. SQL columns are snake_case. The bridge is a
hand-typed list per table:

```python
EMPLOYEES_COLUMNS = [
    ("id_no", text("ID No.")),          # SQL column  <-  exact Airtable field name
    ("email", text("Email")),
    ("job_title", select("Job Title")),
]
```

The extractor (`text`, `select`, `number`, `percent`, `checkbox`, `when`,
`collaborator`, `ai`) also says how the value is converted:

| Extractor | Airtable type | Conversion |
| --- | --- | --- |
| `text` | text/url/phone/rich text | as-is |
| `select` | singleSelect | the option string |
| `number` | number/currency | as-is |
| `percent` | percent | × 100 (matches the `_pct` columns) |
| `checkbox` | checkbox | absent -> `FALSE` |
| `when` | dateTime/createdTime | `...T...Z` -> `... ...` (MySQL-safe) |
| `collaborator` | collaborator | the email |
| `ai` | aiText | the string, or NULL on generation errors |

Strings are escaped for MySQL (backslashes doubled, quotes doubled — a Windows path
like `H:\Drive` survives the import). Deliberate drops are commented in the generator
(e.g. Project Estimator's `Zip Code`, which the checked-in schema has no column for).

**If a field is renamed in the Airtable UI, the mapping breaks silently**: the
extractor `.get("Old Name")` returns `None` on every record and that column becomes
NULL everywhere. The verification (and `inspect_schema.py`, which prints the current
display names from a fresh export) is how you catch it. Fix = update the COLUMNS list,
regenerate, verify.

### 3.2 Links become junction tables

A `multipleRecordLinks` field is not a column — it's a junction row. The generator
resolves each linked record ID through the target table's import order
(`id_map`: Airtable `rec...` -> the 1..N position of the row in that table's INSERT):

- every distinct pair becomes one junction row: `employees_user_reports(employee_id,
  user_report_id)` etc.;
- duplicates are de-duplicated (some relations are mirrored across two link fields);
- a link whose target is not in the export is **silently skipped** by `gen_junction()`
  — exactly the failure mode the verifier's link check exists to catch;
- junctions with extra meaning carry an extra key: `employees_projects.role_on_project`
  (`pm` / `member` / `support` from three link fields), `holidays_earn_codes_v2.variant`.

Which side of a mirrored relation gets read is generator-specific and noted in each
generator's docstring (e.g. Project Estimator reads Projects <-> Activity Codes from
the Activity Codes side; Users & Projects has a **known, documented** issue here, see §7).

### 3.3 Attachments become rows in `attachments`

One row per attachment: `(table_name, record_id, field_name, file_url, file_name,
size, mime_type)`. `file_url` prefers the Cloudinary URL from the upload step's cache
(keyed by Airtable's stable attachment id, so re-runs never re-upload) and falls back
to the local downloaded path. Reimbursement receipts are special-cased: at most one
row per receipt field, written under the field names the live app queries
(`receipts`, `payout_receipt`).

### 3.4 Ordering and chunking

Tables are written parents-first so foreign keys hold. Rows go in chunks of 500 per
`INSERT`, wrapped in `START TRANSACTION; ... COMMIT;`. The file header carries the
per-table record counts the generator processed.

---

## 4. The health check: does the SQL match Airtable?

```
python verify_migration.py --export-dir outputs\users_projects_prod\export --data-sql outputs\users_projects_prod\data.sql
```

`migrate.ps1` runs this automatically after generating and **stops the run if it
fails** (exit code 1), before any import command is printed.

The verifier never trusts the generator's own summary. It:

1. loads the raw export JSON,
2. reads the generator's COLUMNS lists (importing the module and pulling each field
   name out of the extractor closure, so the verifier can never drift from the
   generator's mapping),
3. parses `data.sql` back into values (a real parser: quoted strings with `''` and
   backslash escapes, `NULL`/`TRUE`/`FALSE`, numbers, statements up to `;`), and
4. compares.

### What each check proves

| Check | Question it answers | Failure means |
| --- | --- | --- |
| **Row counts** | Does every export record land as a row? | the generator dropped records |
| **Cell values** | For **every column of every row**, does the value the extractor produces from the raw record equal the value written to `data.sql`? | renamed field (NULLs), wrong extractor, ordering or escaping bug |
| **Field landing** | Is every Airtable field stored as a column, an attachment, a junction, or listed as computed? | a plain field is stored nowhere — often a rename |
| **Link integrity** | Does every linked record ID exist in the target table's export? | dangling links `gen_junction()` would skip |
| **Junction counts** | Does the number of distinct link pairs in the export equal the rows written? | wrong id_map target, links that go nowhere |
| **Attachments** | Do the `attachments` rows equal the export's attachment count? Any failed downloads / missing files / NULL `file_url`s? | attachments lost or broken |
| **Schema columns** | Does every column the `INSERT`s write exist in the checked-in schema? | the data.sql would fail to import |

So yes — this answers row count, column count, per-row, per-field, and per-value:

- **row count**: check 1 (per table, and per row it pairs record *i* with INSERT row *i*);
- **per field**: check 2 compares every mapped column; check 3 accounts for every
  Airtable field (mapped, computed, link, attachment — or reported);
- **per value**: check 2 is exact, cell by cell — a single changed character in a
  single row fails (verified against a deliberately corrupted file);
- **links**: checks 4 + 5, resolved through Airtable's own `linkedTableId`s from
  `_base_schema.json`, not through the generator's assumptions.

### Reading the output

```
  ok    user_reports: 4309 rows, 16 columns, all values match
  warn  Employees: 7 field(s) not stored as a column -- confirm each is intentional: ...
  KNOWN projects_activity_scope: export has 606 distinct link row(s), data.sql has 0
        -> schema FK + generator use project_scope_t3_activities, Airtable links to Activity Code/s (NOTE.md)
RESULT: PASS -- 0 failure(s), 9 warning(s), 1 known issue(s)
```

- `ok` — checked and clean.
- `FAIL` — real mismatch; the run should not be imported.
- `warn` — informational: fields intentionally not stored, NULL `file_url`s, local
  files missing. Read them; they are the list you re-confirm after every Airtable change.
- `info` — link fields that exist in Airtable but have no junction (mirrors,
  Airtable-internal links). Not data loss unless you expected them.
- `KNOWN` — a documented, accepted problem, downgraded from FAIL so it cannot hide
  the exit code. Currently one exists (see §7).

### Re-checking against the live base

The offline check proves `export == data.sql`. To prove `Airtable == export` as of
*now*, re-run the fetch (`.\migrate.ps1 -Target <base> -Produce data`) — it overwrites
the export with today's records, then regenerates and verifies. If someone changed
Airtable in between, cell or count checks will fail until you regenerate, which is
the point: you never import a `data.sql` that silently misses newer Airtable data.

---

## 5. Fixing the common cases

**Airtable field renamed** (symptom: `FAIL <table>: N cells differ` or a new field in
the `warn ... not stored` list):

1. `python inspect_schema.py --export-dir outputs\<base>\export [--table "<Table>"]`
2. Update the matching `text("Old Name")` line in `generate/generate_sql_<base>.py`.
3. Re-run `migrate.ps1` (or just the generator + verifier).

**Something changed in Airtable and you're not sure what:** re-fetch, re-generate,
re-verify — the three checks will point at the table and column.

**New table/column needed in SQL:** that is a schema decision. Edit
`sql/<base>/schema.sql` (and for portal tables, `sql/portal/separate.sql` is for
existing databases), then add the COLUMNS entry to the generator and verify.
`schema.sql` is never generated or auto-edited by this tooling.

---

## 6. Files at a glance

| File | Role |
| --- | --- |
| `migrate.ps1` | orchestrator: fetch -> upload -> generate -> verify -> assemble schema |
| `fetch_airtable.py` | generic exporter (any base, `--base-id`); downloads attachments |
| `inspect_schema.py` | prints live field display names from an export (`_base_schema.json`) |
| `upload_attachments_to_cloudinary.py` | uploads downloaded files, caches URLs |
| `generate/generate_sql_*.py` | export -> `data.sql` per base |
| `verify_migration.py` | export vs `data.sql` health check (this document, §4) |
| `NOTE.md` | per-base inventory and caveats (estimator quirks, reimbursement notes) |
| `outputs/` | **gitignored** — real personal data (IDs, bank certificates, photos) |

The Airtable token is read from `-Token`, then `migrations/.token.private.txt`, then a
hidden prompt (never written to disk or logs by the scripts). Cloudinary credentials
come from `ikaika_admin/.env`. `pip install requests` is the only dependency.

---

## 7. Known issues (reported as KNOWN, not FAIL)

- **`projects_activity_scope` (Users & Projects)**: Airtable's "Activity Scope" field
  on Projects links to **Activity Code/s**, but `sql/portal/schema.sql` declares the
  junction's `activity_id` FK against `project_scope_t3_activities`, and the generator
  therefore points the field at the T3 id map. Result: the junction is written empty
  and **606 project<->activity links exist in the export but not in `data.sql`**.
  Fixing it means changing both the schema FK and the generator together — a schema
  decision, tracked in `NOTE.md` ("Known schema bug, not yet fixed"). The verifier
  reports it as KNOWN so it stays visible.
