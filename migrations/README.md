# Airtable → SQL migration scripts

Four standalone Python scripts, no Claude/Anthropic dependency — run them
yourself, on your own schedule, with your own Airtable and Cloudinary
credentials.

## Setup

```bash
pip install requests
export AIRTABLE_API_KEY="patXXXXXXXXXXXXXX"   # Personal Access Token, see below
or
$env:AIRTABLE_API_KEY="patXXXXXXXXXXXXXX"
```

Create the token at https://airtable.com/create/tokens with:
- Scopes: `data.records:read`, `schema.bases:read`
- Access: the specific base(s) you're exporting

Uploading attachments to Cloudinary (step 3 below) reuses the same
`CLOUDINARY_CLOUD_NAME` / `CLOUDINARY_API_KEY` / `CLOUDINARY_API_SECRET` the
admin app already has in its own `.env` — that script reads them from there
by default, nothing extra to set up.

## Workflow

### 1. Fetch everything from Airtable

```bash
python3 fetch_airtable.py --base-id app8DvnFZErPZT5Az --output-dir ./export_test
```

This is fully generic — works for the TEST base, the PRODUCTION base, or
Project Estimator, just by changing `--base-id`. It:
- Auto-discovers every table and field via Airtable's Meta API (no hardcoded
  table list to maintain)
- Paginates properly (Airtable's real API caps pages at 100 records — this
  loops using the `offset` cursor until everything's fetched)
- **Downloads every attachment's actual file bytes immediately**, because
  Airtable's attachment URLs are signed and expire within a few hours. The
  `file_url` you'd get from the raw API response is useless by the time you
  come back to it later — this script saves you from that trap.
- Self-throttles to Airtable's 5 requests/second limit and retries on 429s
- Skips already-downloaded files if you re-run it (safe to interrupt/resume)

Output:
```
export_test/
  _base_schema.json              <- table/field metadata
  Employees.json
  Projects.json
  ... (one file per table)
  attachments/
    <record_id>/<field_name>/<original_filename>
  attachments_manifest.json      <- maps records to local file paths
```

### 2. See what field names you actually have

```bash
python3 inspect_schema.py --export-dir ./export_test
```

Important: the public Airtable API keys every field by its **display name**,
not the internal field ID the connector I used internally relied on. Field
names can be renamed in the Airtable UI at any time, so always check this
against a fresh export.

### 3. Upload attachments to Cloudinary

```bash
python3 upload_attachments_to_cloudinary.py --export-dir ./export_test
```

Uploads every file `fetch_airtable.py` downloaded and writes
`export_test/cloudinary_uploads.json`, keyed by Airtable's attachment id
(stable across re-exports, so re-running this only uploads what's new).
Reimbursement receipts land in `ikaika-portal/reimbursements` — the same
folder the live app's `PortalCloudinary` uploads real member receipts to —
everything else goes to `ikaika-portal/migrated/<table>`.

Skip this step if you just want a quick local `data.sql` for testing schema
changes — step 4 below falls back to local file paths when there's no cache.
For anything meant to actually run in the app, run this step: the app's own
attachment-serving code (`PortalCloudinary::isOwnedUrl`) requires a real
`res.cloudinary.com` URL and will not accept a local path.

### 4. Transform into SQL

```bash
python3 generate_sql_users_projects.py --export-dir ./export_test --output data.sql \
    --cloudinary-cache ./export_test/cloudinary_uploads.json
```

Loads into `sql/portal/schema.sql`. All 20 tables and every junction table
are fully implemented, verified against a real export's field names (not
guessed field IDs). Drop `--cloudinary-cache` to fall back to local file
paths for a quicker no-upload test run.

Reimbursement receipts get one special case: the live app
(`PortalReimbursementRequests::RECEIPT_FIELD`) reads exactly one receipt per
row under `field_name = 'receipts'`, not the raw Airtable field name — the
generator matches that instead of writing a generic snake-cased field name
nothing would ever query.

## Attachments: real Cloudinary URLs, not base64 or placeholders

Earlier exports built by hand used a placeholder `'image.png'` filename
because that work went through a size-limited connector. Now `file_url` in
the `attachments` table holds either:
- a real `https://res.cloudinary.com/...` URL, if step 3 ran and the file's
  Airtable attachment id is in `cloudinary_uploads.json`, or
- the **local relative path** to the downloaded file (e.g.
  `attachments/rec123/Photo/headshot.jpg`) as a fallback, if it isn't.

Never base64, and never the original Airtable URL (those are signed and
expire within hours). Run step 3 before step 4 for anything that needs to
actually work in the app — a local path renders as a broken link there.

**Status:** already run end to end against the TEST base's `export_test/`.
All 186 attachments uploaded (0 failed), and the current `data.sql` in this
directory was generated with `--cloudinary-cache` — every `file_url` in it
is a real `res.cloudinary.com` link, verified against a real local MySQL
import and a join query shaped like `PortalReimbursementRequests`'s own.

**Before committing anything:** `export_test/attachments/` holds real
downloaded files — SSS/PhilHealth/TIN numbers, bank certificates, employee
photos. Keep that folder (and `export_test/*.json`) out of version control;
`data.sql` itself only carries Cloudinary URLs and Airtable record data, no
raw documents.