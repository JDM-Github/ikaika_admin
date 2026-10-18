#!/usr/bin/env python3
"""
livecheck.py -- Is the export still current against live Airtable?

Counts every record in every table straight from the Airtable API and compares it
to the number of records in the export directory. If a table's live count is
higher than its export, the export is STALE -- records were added since it was
fetched -- so re-run the fetch before generating or importing. This is the guard
for "did the fetch really get everything?".

    python livecheck.py --base-id appXXXXXXXXXXXXXX --export-dir outputs/<base>/export

The token comes from the AIRTABLE_API_KEY environment variable. Exit code 0 when
every table matches, 1 when any differs. It reads only ONE field per record, so
it is far cheaper than the fetch.
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
REQUEST_INTERVAL_SECONDS = 0.21  # ~4.7 req/sec, under Airtable's 5/sec cap


def safe_filename(name):
    return re.sub(r'[<>:"/\\|?*\x00-\x1f]', "_", name).strip() or "unnamed"


def live_count(base_id, table, headers):
    url = f"{API_ROOT}/{base_id}/{urllib.parse.quote(table['id'])}"
    count = 0
    offset = None
    while True:
        params = {"pageSize": 100}
        if table["fields"]:
            params["fields[]"] = table["fields"][0]["name"]
        if offset:
            params["offset"] = offset
        time.sleep(REQUEST_INTERVAL_SECONDS)
        resp = requests.get(url, headers=headers, params=params)
        resp.raise_for_status()
        data = resp.json()
        count += len(data.get("records", []))
        offset = data.get("offset")
        if not offset:
            return count


def main():
    parser = argparse.ArgumentParser(description=__doc__.splitlines()[1])
    parser.add_argument("--base-id", required=True)
    parser.add_argument("--export-dir", required=True)
    args = parser.parse_args()

    key = os.environ.get("AIRTABLE_API_KEY")
    if not key:
        sys.exit("ERROR: set AIRTABLE_API_KEY (the Airtable token) first.")

    export_dir = Path(args.export_dir)
    schema_path = export_dir / "_base_schema.json"
    if not schema_path.exists():
        sys.exit(f"ERROR: {schema_path} not found -- is this a fetch_airtable.py export?")
    tables = json.loads(schema_path.read_text(encoding="utf-8"))
    headers = {"Authorization": f"Bearer {key}"}

    print(f"livecheck: base {args.base_id}")
    print(f"  export : {export_dir}")
    print()

    stale = 0
    for table in tables:
        name = table["name"]
        count = live_count(args.base_id, table, headers)
        export_file = export_dir / (safe_filename(name) + ".json")
        if not export_file.exists():
            print(f"  MISSING  {name}: live {count}, no export file")
            stale += 1
            continue
        exported = len(json.loads(export_file.read_text(encoding="utf-8")).get("records", []))
        if count == exported:
            print(f"  ok       {name}: {count}")
        else:
            print(f"  STALE    {name}: live {count}, export {exported} ({count - exported:+d})")
            stale += 1

    print()
    if stale:
        print(f"RESULT: STALE -- {stale} table(s) differ. Re-run the fetch, then generate/verify.")
        return 1
    print("RESULT: PASS -- every table's export matches live Airtable.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
