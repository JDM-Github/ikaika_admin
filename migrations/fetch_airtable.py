#!/usr/bin/env python3
"""
fetch_airtable.py — Generic Airtable base exporter.

Fetches every table in an Airtable base via the real public REST API
(https://airtable.com/developers/web/api/introduction) and saves:

  1. One JSON file per table with every record (all fields, full fidelity).
  2. Every attachment's actual file bytes, downloaded immediately — Airtable's
     attachment URLs are signed and expire a few hours after they're issued,
     so if you don't download promptly, the "file_url" you saved becomes a
     dead link. This script downloads first, records local paths second.

This is a real, standalone script — it does not depend on any Claude/Anthropic
tooling. You run it yourself with your own Airtable credentials.

--------------------------------------------------------------------------
SETUP
--------------------------------------------------------------------------
1. Create an Airtable Personal Access Token:
   https://airtable.com/create/tokens
   Required scopes: data.records:read, schema.bases:read
   Access: the specific base(s) you want to export.

2. pip install requests

3. Set your token as an environment variable (don't hardcode it):
     export AIRTABLE_API_KEY="patXXXXXXXXXXXXXX"

--------------------------------------------------------------------------
USAGE
--------------------------------------------------------------------------
  python3 fetch_airtable.py --base-id appXXXXXXXXXXXXXX --output-dir ./export

  # Skip image downloads (metadata only, much faster):
  python3 fetch_airtable.py --base-id appXXXXXXXXXXXXXX --output-dir ./export --no-images

  # Only fetch specific tables (by name, as shown in the Airtable UI):
  python3 fetch_airtable.py --base-id appXXXXXXXXXXXXXX --output-dir ./export \\
      --tables "Employees" "Projects"

--------------------------------------------------------------------------
OUTPUT LAYOUT
--------------------------------------------------------------------------
  <output-dir>/
    _base_schema.json          <- table/field metadata (names, types, IDs)
    <Table Name>.json          <- every record in that table, raw Airtable JSON
    attachments/
      <record_id>/
        <field_name>/
          <original_filename>  <- actual downloaded file bytes
    attachments_manifest.json  <- record_id/field/filename -> local path mapping

--------------------------------------------------------------------------
RATE LIMITS
--------------------------------------------------------------------------
Airtable allows 5 requests/second per base. This script self-throttles to
stay under that and retries with backoff on HTTP 429.
"""

import argparse
import json
import os
import re
import sys
import time
import urllib.parse
from pathlib import Path

import requests

API_ROOT = "https://api.airtable.com/v0"
META_ROOT = "https://api.airtable.com/v0/meta"
REQUEST_INTERVAL_SECONDS = 0.21  # ~4.7 req/sec, under the 5/sec cap
MAX_RETRIES = 5


def get_api_key():
    key = os.environ.get("AIRTABLE_API_KEY")
    if not key:
        sys.exit(
            "ERROR: Set the AIRTABLE_API_KEY environment variable to your "
            "Airtable Personal Access Token first.\n"
            "  export AIRTABLE_API_KEY=\"patXXXXXXXXXXXXXX\""
        )
    return key


def _sleep_for_rate_limit():
    time.sleep(REQUEST_INTERVAL_SECONDS)


def _request_with_retry(method, url, headers, **kwargs):
    """Wraps requests.<method> with 429/5xx backoff retry."""
    for attempt in range(1, MAX_RETRIES + 1):
        resp = requests.request(method, url, headers=headers, **kwargs)
        if resp.status_code == 429:
            wait = min(2 ** attempt, 30)
            print(f"  [rate limited] waiting {wait}s (attempt {attempt}/{MAX_RETRIES})")
            time.sleep(wait)
            continue
        if resp.status_code >= 500:
            wait = min(2 ** attempt, 30)
            print(f"  [server error {resp.status_code}] retrying in {wait}s")
            time.sleep(wait)
            continue
        return resp
    resp.raise_for_status() # type: ignore
    return resp # type: ignore


def list_tables(base_id, api_key):
    """Uses the Meta API to discover every table and field in the base."""
    headers = {"Authorization": f"Bearer {api_key}"}
    url = f"{META_ROOT}/bases/{base_id}/tables"
    resp = _request_with_retry("GET", url, headers)
    resp.raise_for_status()
    return resp.json()["tables"]


def fetch_all_records(base_id, table_id, api_key):
    """Paginates through every record in a table (100 per page, Airtable's max)."""
    headers = {"Authorization": f"Bearer {api_key}"}
    url = f"{API_ROOT}/{base_id}/{urllib.parse.quote(table_id)}"
    records = []
    offset = None
    page = 0
    while True:
        params = {"pageSize": 100}
        if offset:
            params["offset"] = offset
        _sleep_for_rate_limit()
        resp = _request_with_retry("GET", url, headers, params=params)
        resp.raise_for_status()
        data = resp.json()
        batch = data.get("records", [])
        records.extend(batch)
        page += 1
        print(f"    page {page}: +{len(batch)} records (total so far: {len(records)})")
        offset = data.get("offset")
        if not offset:
            break
    return records


def safe_filename(name):
    """Strips characters that are unsafe in file/directory names."""
    return re.sub(r'[<>:"/\\|?*\x00-\x1f]', "_", name).strip() or "unnamed"


def find_attachments(record):
    """
    Scans a record's fields for Airtable attachment arrays.
    Returns a list of (field_name, attachment_dict) tuples.
    An Airtable attachment dict looks like:
      {"id": "att...", "url": "https://...", "filename": "photo.jpg",
       "size": 12345, "type": "image/jpeg", "thumbnails": {...}}
    """
    found = []
    for field_name, value in record.get("fields", {}).items():
        if isinstance(value, list) and value and isinstance(value[0], dict) and "url" in value[0] and "filename" in value[0]:
            for att in value:
                found.append((field_name, att))
    return found


def download_attachment(url, dest_path, headers, max_retries=3):
    dest_path.parent.mkdir(parents=True, exist_ok=True)
    for attempt in range(1, max_retries + 1):
        try:
            resp = requests.get(url, headers=headers, stream=True, timeout=60)
            resp.raise_for_status()
            with open(dest_path, "wb") as f:
                for chunk in resp.iter_content(chunk_size=65536):
                    f.write(chunk)
            return True
        except requests.RequestException as e:
            print(f"    [attachment retry {attempt}/{max_retries}] {dest_path.name}: {e}")
            time.sleep(2 * attempt)
    print(f"    [FAILED] could not download {dest_path.name}")
    return False


def main():
    parser = argparse.ArgumentParser(description="Export an entire Airtable base to local JSON + files.")
    parser.add_argument("--base-id", required=True, help="Airtable base ID, e.g. appXXXXXXXXXXXXXX")
    parser.add_argument("--output-dir", required=True, help="Directory to write the export into")
    parser.add_argument("--no-images", action="store_true", help="Skip downloading attachment files")
    parser.add_argument("--tables", nargs="*", default=None, help="Only fetch these table names (default: all)")
    args = parser.parse_args()

    api_key = get_api_key()
    out_dir = Path(args.output_dir)
    out_dir.mkdir(parents=True, exist_ok=True)
    attach_dir = out_dir / "attachments"

    print(f"Discovering tables in base {args.base_id} ...")
    tables = list_tables(args.base_id, api_key)
    with open(out_dir / "_base_schema.json", "w") as f:
        json.dump(tables, f, indent=2)
    print(f"Found {len(tables)} tables.")

    if args.tables:
        wanted = set(args.tables)
        tables = [t for t in tables if t["name"] in wanted]
        missing = wanted - {t["name"] for t in tables}
        if missing:
            print(f"WARNING: requested tables not found in base: {missing}")

    manifest = []
    headers = {"Authorization": f"Bearer {api_key}"}

    for table in tables:
        table_name = table["name"]
        table_id = table["id"]
        print(f"\n=== {table_name} ({table_id}) ===")
        records = fetch_all_records(args.base_id, table_id, api_key)

        out_file = out_dir / f"{safe_filename(table_name)}.json"
        with open(out_file, "w") as f:
            json.dump({"table_id": table_id, "table_name": table_name, "records": records}, f, indent=2)
        print(f"  saved {len(records)} records -> {out_file}")

        if not args.no_images:
            att_count = 0
            for record in records:
                for field_name, att in find_attachments(record):
                    rec_id = record["id"]
                    fname = safe_filename(att["filename"])
                    dest = attach_dir / rec_id / safe_filename(field_name) / fname
                    if dest.exists():
                        manifest.append({
                            "table": table_name, "record_id": rec_id, "field": field_name,
                            "filename": att["filename"], "local_path": str(dest.relative_to(out_dir)),
                            "size": att.get("size"), "mime_type": att.get("type"),
                            "airtable_attachment_id": att.get("id"), "status": "already_downloaded",
                        })
                        continue
                    ok = download_attachment(att["url"], dest, headers)
                    att_count += 1
                    manifest.append({
                        "table": table_name, "record_id": rec_id, "field": field_name,
                        "filename": att["filename"], "local_path": str(dest.relative_to(out_dir)) if ok else None,
                        "size": att.get("size"), "mime_type": att.get("type"),
                        "airtable_attachment_id": att.get("id"), "status": "downloaded" if ok else "failed",
                    })
            if att_count:
                print(f"  downloaded {att_count} attachments")

    if manifest:
        with open(out_dir / "attachments_manifest.json", "w") as f:
            json.dump(manifest, f, indent=2)
        failed = [m for m in manifest if m["status"] == "failed"]
        print(f"\nAttachments manifest written: {len(manifest)} total, {len(failed)} failed")
        if failed:
            print("Failed downloads (re-run the script to retry — already-downloaded files are skipped):")
            for m in failed[:20]:
                print(f"  - {m['table']}/{m['record_id']}/{m['field']}/{m['filename']}")

    print(f"\nDone. Export written to: {out_dir.resolve()}")


if __name__ == "__main__":
    main()