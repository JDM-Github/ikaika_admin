#!/usr/bin/env python3
"""
generate_sql_transaction_tracker.py -- Transforms a raw Airtable export (produced
by fetch_airtable.py) into a data.sql file that loads into
sql/transaction_tracker/schema.sql.

--------------------------------------------------------------------------
USAGE
--------------------------------------------------------------------------
  # 1. Fetch the raw export:
  python fetch_airtable.py --base-id appp5MogesHN2eVvG --output-dir ./export

  # 2. (Optional) Upload attachments to Cloudinary:
  python upload_attachments_to_cloudinary.py --export-dir ./export

  # 3. Generate the SQL:
  python generate_sql_transaction_tracker.py --export-dir ./export --output data.sql

--------------------------------------------------------------------------
FIELD MAPPING
--------------------------------------------------------------------------
Every column below is pulled by the Airtable field's display NAME, verified
against this base's real _base_schema.json (fetched via the Meta API, see
NOTE.md's Transaction Tracker section for the full field-by-field
breakdown this was built from). If a field gets renamed in the Airtable UI,
re-run inspect_schema.py against a fresh export and update the COLUMNS list
for the affected table below.

--------------------------------------------------------------------------
WHAT'S DELIBERATELY NOT A JUNCTION TABLE
--------------------------------------------------------------------------
Two fields look like they should be links but are plain text in the real
schema -- verified, not assumed:
  - Transactions."Payor/Payee" is a plain text field, NOT a link to the
    separate Payor/Payee table (which shares the name but nothing else).
  - Payor/Payee."Associated Transactions" is plain text too, not a link
    back to Transactions.
Both are written as ordinary text columns. See schema.sql's design notes
3-4 for the reasoning; if Airtable ever turns these into real links, this
generator and the schema both need updating to match.

--------------------------------------------------------------------------
VALUE CONVERSIONS (same rules as generate_sql_users_projects.py)
--------------------------------------------------------------------------
- Checkbox fields: Airtable omits the key entirely when unchecked; treated
  as FALSE rather than NULL, since "unchecked" is known, not unknown.
- dateTime / createdTime / lastModifiedTime fields: Airtable returns
  '2026-08-06T09:00:43.000Z'. MySQL's strict mode rejects the literal T/Z,
  so these are rewritten to '2026-08-06 09:00:43' at generation time.
- String escaping: MySQL treats backslash as an escape character by
  default, so backslashes are doubled before quotes are escaped.
- Junction rows are de-duplicated per table so a composite PRIMARY KEY
  never collides within a single INSERT.

--------------------------------------------------------------------------
ATTACHMENTS
--------------------------------------------------------------------------
Same convention as generate_sql_users_projects.py: `file_url` holds a real
Cloudinary URL when --cloudinary-cache is given, falling back to the local
downloaded path from fetch_airtable.py otherwise. Never the original
(expiring) Airtable URL.
"""

import argparse
import json
import re
from pathlib import Path


# ============================================================================
# Value helpers -- identical to generate_sql_users_projects.py
# ============================================================================

def esc(v):
    if v is None:
        return "NULL"
    if isinstance(v, bool):
        return "TRUE" if v else "FALSE"
    if isinstance(v, (int, float)):
        return str(v)
    s = str(v).replace("\\", "\\\\").replace("'", "''")
    return f"'{s}'"


def sel(v):
    # singleSelect: the public API returns a plain string, not {"name": ...}
    if isinstance(v, str):
        return v
    if isinstance(v, dict):
        return v.get("name")
    return None


def num(v):
    return v if isinstance(v, (int, float)) else None


def chk(f, name):
    # unchecked checkboxes are simply absent from the record -- that means False, not NULL
    return bool(f.get(name, False))


def dt(v):
    # dateTime/createdTime/lastModifiedTime: '...T...Z' -> MySQL-safe '... ...'
    if not isinstance(v, str):
        return None
    if "T" in v:
        return v.split(".")[0].replace("T", " ").rstrip("Z")
    return v


def collab_email(v):
    return v.get("email") if isinstance(v, dict) else None


def ai_text(v):
    # aiText fields can come back as an {"state": "error", ...} object when generation failed
    return v if isinstance(v, str) else None


def snake(name):
    return re.sub(r"[^a-z0-9]+", "_", name.lower()).strip("_")


def text(name):
    return lambda f: f.get(name)


def select(name):
    return lambda f: sel(f.get(name))


def number(name):
    return lambda f: num(f.get(name))


def checkbox(name):
    return lambda f: chk(f, name)


def when(name):
    return lambda f: dt(f.get(name))


def collaborator(name):
    return lambda f: collab_email(f.get(name))


def ai(name):
    return lambda f: ai_text(f.get(name))


# ============================================================================
# Export loading
# ============================================================================

def load_table(export_dir, table_name):
    path = export_dir / f"{table_name}.json"
    if not path.exists():
        print(f"  WARNING: {path} not found, skipping this table")
        return []
    with open(path, encoding="utf-8") as f:
        data = json.load(f)
    return data["records"]


def load_manifest(export_dir):
    path = export_dir / "attachments_manifest.json"
    if not path.exists():
        return {}
    with open(path, encoding="utf-8") as f:
        entries = json.load(f)
    idx = {}
    for e in entries:
        idx.setdefault((e["record_id"], e["field"]), []).append(e)
    return idx


# ============================================================================
# Generic table + junction builders -- identical to generate_sql_users_projects.py
# ============================================================================

def gen_table(export_dir, airtable_table, columns):
    """columns: list of (sql_column_name, extractor) where extractor(fields) -> value."""
    records = load_table(export_dir, airtable_table)
    id_map = {}
    cols = ["airtable_record_id"] + [c for c, _ in columns]
    rows = []
    for i, r in enumerate(records, start=1):
        id_map[r["id"]] = i
        f = r.get("fields", {})
        rows.append([r["id"]] + [extractor(f) for _, extractor in columns])
    return records, id_map, cols, rows


def gen_junction(records, id_map_self, field_name, id_map_target):
    """Resolves a multipleRecordLinks field into (self_id, target_id) pairs, de-duplicated."""
    seen = set()
    rows = []
    for r in records:
        self_id = id_map_self.get(r["id"])
        if self_id is None:
            continue
        for target_rec_id in (r.get("fields", {}).get(field_name) or []):
            target_id = id_map_target.get(target_rec_id)
            if target_id is None:
                continue
            pair = (self_id, target_id)
            if pair not in seen:
                seen.add(pair)
                rows.append(list(pair))
    return rows


ATTACHMENT_COLUMNS = ["table_name", "record_id", "field_name", "file_url", "file_name",
                       "file_size_bytes", "mime_type"]


def resolve_file_url(att, record_id, field_name, manifest, cloudinary_cache):
    """Prefers a real Cloudinary URL (from upload_attachments_to_cloudinary.py's cache,
    keyed by Airtable's stable attachment id) and falls back to the local downloaded
    path so the generator still works standalone without a Cloudinary pass."""
    att_id = att.get("id")
    cached = cloudinary_cache.get(att_id) if att_id else None
    if cached:
        return cached["secure_url"]
    local_entries = manifest.get((record_id, field_name), [])
    match = next((e for e in local_entries if e["filename"] == att.get("filename")), None)
    return match["local_path"].replace("\\", "/") if match else None


def gen_attachments_for_table(records, table_sql_name, id_map, manifest, cloudinary_cache, attachment_field_names):
    rows = []
    for r in records:
        sid = id_map.get(r["id"])
        if sid is None:
            continue
        f = r.get("fields", {})
        for field_name in attachment_field_names:
            atts = f.get(field_name)
            if not isinstance(atts, list):
                continue
            for att in atts:
                file_url = resolve_file_url(att, r["id"], field_name, manifest, cloudinary_cache)
                rows.append([
                    table_sql_name, sid, snake(field_name),
                    file_url, att.get("filename"), att.get("size"), att.get("type"),
                ])
    return rows


def write_insert(out, table, cols, rows, chunk=500):
    if not rows:
        return
    out.append(f"-- {table} ({len(rows)} rows)")
    for start in range(0, len(rows), chunk):
        batch = rows[start:start + chunk]
        out.append(f"INSERT INTO {table} ({', '.join(cols)}) VALUES")
        out.append(",\n".join("(" + ", ".join(esc(v) for v in row) + ")" for row in batch) + ";")
    out.append("")


# ============================================================================
# Per-table column definitions
# ============================================================================

ACCOUNTS_COLUMNS = [
    ("account_name", text("Account Name")),
    ("account_type", select("Account Type")),
    ("email", text("Email")),
    ("institution", text("Institution")),
    ("active", checkbox("Active")),
    ("creation_status", select("CreationStatus")),
    ("created_at", when("Created Time")),
    ("updated_at", when("Last Modified Time")),
]
ACCOUNTS_ATTACHMENTS = ["QRCode"]

BUDGET_CODES_COLUMNS = [
    ("name", text("Name")),
    ("description", text("Desc")),
    ("transfer_date_received", text("Transfer Date (Received)")),
    ("expected_amount_quotation", number("Expected Amount (Quotation)")),
    ("attachment_summary", ai("Attachment Summary")),
]
BUDGET_CODES_ATTACHMENTS = ["Attachments"]

ENVELOPES_COLUMNS = [
    ("envelope_name", text("Envelope Name")),
    ("budget_amount", number("Budget Amount")),
    ("group_name", select("Group")),
    ("time_period", select("Time Period")),
    ("active", checkbox("Active")),
    ("budget_notes", text("Budget Notes")),
]
# No attachment fields on Envelopes - Budget

PAYOR_PAYEE_COLUMNS = [
    ("name", text("Name")),
    ("type", select("Type")),
    ("contact_name", text("Contact Name")),
    ("email", text("Email")),
    ("phone", text("Phone")),
    ("associated_transactions_text", text("Associated Transactions")),  # plain text, not a link -- see module docstring
    ("company", text("Company")),
    ("notes", text("Notes")),
    ("business_lookup", ai("Business Lookup")),
    ("expense_plan", text("Expense Plan")),
]
# No attachment fields, no relationships at all on Payor/Payee

TRANSACTIONS_COLUMNS = [
    ("transaction_name", text("Transaction Name")),
    ("transaction_date", when("Date")),
    ("amount", number("Amount")),
    ("type", select("Type")),
    ("payor_payee", text("Payor/Payee")),  # plain text, not a link -- see module docstring
    ("description", text("Description")),
    ("reviewed_by_email", collaborator("Reviewed By")),
    ("approval_date", when("Approval Date")),
    ("created_by", text("Created By")),
    ("auto_extracted_details", ai("Auto-Extracted Details")),
    ("created_at", when("Created Time")),
    ("updated_at", when("Last Modified Time")),
]
TRANSACTIONS_ATTACHMENTS = ["Receipt", "Attachments"]


# ============================================================================
# Main
# ============================================================================

def main():
    parser = argparse.ArgumentParser(
        description="Transform a raw Airtable export into SQL for sql/transaction_tracker/schema.sql")
    parser.add_argument("--export-dir", required=True, help="Directory produced by fetch_airtable.py")
    parser.add_argument("--output", required=True, help="Output .sql file path")
    parser.add_argument("--database", default="test_tracker_database",
                         help="Database name for the USE statement (must match schema.sql)")
    parser.add_argument("--cloudinary-cache", default=None,
                         help="Cache produced by upload_attachments_to_cloudinary.py. When given, "
                              "attachment file_url values are real Cloudinary URLs instead of local "
                              "paths (falls back to local paths for anything not yet uploaded).")
    args = parser.parse_args()

    export_dir = Path(args.export_dir)
    manifest = load_manifest(export_dir)

    cloudinary_cache = {}
    if args.cloudinary_cache:
        cache_path = Path(args.cloudinary_cache)
        if cache_path.exists():
            with open(cache_path, encoding="utf-8") as f:
                cloudinary_cache = json.load(f)
            print(f"Loaded {len(cloudinary_cache)} Cloudinary URLs from {cache_path}")
        else:
            print(f"WARNING: --cloudinary-cache {cache_path} not found; attachments will use local paths")

    out = []
    summary = []

    def table(label, airtable_table, sql_table, columns):
        records, id_map, cols, rows = gen_table(export_dir, airtable_table, columns)
        summary.append((label, len(records)))
        print(f"Processing {label} ({len(records)})...")
        write_insert(out, sql_table, cols, rows)
        return records, id_map

    def attachments(sql_table, records, id_map, field_names):
        rows = gen_attachments_for_table(records, sql_table, id_map, manifest, cloudinary_cache, field_names)
        write_insert(out, "attachments", ATTACHMENT_COLUMNS, rows)

    out.append(f"USE {args.database};\n")
    out.append("START TRANSACTION;\n")

    # Order matters here only in the sense that junction tables need both sides'
    # id_maps already built -- Accounts/Budget Code/Payor-Payee have no incoming
    # dependencies of their own, so they go first.
    accounts_records, accounts_id_map = table("Accounts", "Accounts", "accounts", ACCOUNTS_COLUMNS)
    attachments("accounts", accounts_records, accounts_id_map, ACCOUNTS_ATTACHMENTS)

    budget_records, budget_id_map = table("Budget Code", "Budget Code", "budget_codes", BUDGET_CODES_COLUMNS)
    attachments("budget_codes", budget_records, budget_id_map, BUDGET_CODES_ATTACHMENTS)

    table("Payor/Payee", "Payor_Payee", "payor_payee", PAYOR_PAYEE_COLUMNS)
    # ^ Airtable table is named "Payor/Payee"; fetch_airtable.py's safe_filename()
    # replaces "/" with "_" when saving, so the export file on disk is
    # "Payor_Payee.json" -- this must match exactly, not the display name.

    envelopes_records, envelopes_id_map = table(
        "Envelopes - Budget", "Envelopes - Budget", "envelopes_budget", ENVELOPES_COLUMNS)
    write_insert(out, "envelopes_accounts", ["envelope_id", "account_id"],
                 gen_junction(envelopes_records, envelopes_id_map, "Account", accounts_id_map))
    write_insert(out, "envelopes_budget_codes", ["envelope_id", "budget_code_id"],
                 gen_junction(envelopes_records, envelopes_id_map, "Budget Code", budget_id_map))

    transactions_records, transactions_id_map = table(
        "Transactions", "Transactions", "transactions", TRANSACTIONS_COLUMNS)
    attachments("transactions", transactions_records, transactions_id_map, TRANSACTIONS_ATTACHMENTS)
    write_insert(out, "transactions_accounts", ["transaction_id", "account_id"],
                 gen_junction(transactions_records, transactions_id_map, "Account", accounts_id_map))
    write_insert(out, "transactions_envelopes", ["transaction_id", "envelope_id"],
                 gen_junction(transactions_records, transactions_id_map, "Envelope", envelopes_id_map))
    write_insert(out, "transactions_budget_codes", ["transaction_id", "budget_code_id"],
                 gen_junction(transactions_records, transactions_id_map, "Budget Code", budget_id_map))
    write_insert(out, "transactions_transfer_source_accounts", ["transaction_id", "account_id"],
                 gen_junction(transactions_records, transactions_id_map, "Transfer Source Account", accounts_id_map))
    write_insert(out, "transactions_transfer_destination_accounts", ["transaction_id", "account_id"],
                 gen_junction(transactions_records, transactions_id_map, "Transfer Destination Account", accounts_id_map))

    out.append("COMMIT;")

    header = [
        "-- " + "=" * 76,
        f"-- {Path(args.output).name} -- generated by generate_sql_transaction_tracker.py",
        f"-- Source export: {export_dir}",
        f"-- Run this AFTER sql/transaction_tracker/schema.sql has created the `{args.database}` database.",
        "--",
    ]
    for label, count in summary:
        header.append(f"--   {label} ".ljust(45, ".") + f" {count}")
    header.append("-- " + "=" * 76)
    header.append("")

    with open(args.output, "w", encoding="utf-8") as f:
        f.write("\n".join(header + out))

    print(f"\nWrote {args.output}")


if __name__ == "__main__":
    main()