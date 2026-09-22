#!/usr/bin/env python3
"""
inspect_schema.py — Prints every table and field name in an Airtable export,
produced by fetch_airtable.py.

Use this to see the exact field names to plug into generate_sql_users_projects.py
(or a new script for a different base) — the public Airtable API keys every
field by its display name, which you can rename in the Airtable UI at any
time, so always check this against a fresh export rather than assuming
names haven't changed.

USAGE
  python3 inspect_schema.py --export-dir ./export

  # Just one table:
  python3 inspect_schema.py --export-dir ./export --table "Employees"
"""

import argparse
import json
from pathlib import Path


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--export-dir", required=True)
    parser.add_argument("--table", default=None, help="Only show this table")
    args = parser.parse_args()

    export_dir = Path(args.export_dir)
    with open(export_dir / "_base_schema.json") as f:
        tables = json.load(f)

    for t in tables:
        if args.table and t["name"] != args.table:
            continue
        print(f"\n=== {t['name']}  (id: {t['id']}) ===")
        for field in t["fields"]:
            ftype = field["type"]
            extra = ""
            if ftype == "multipleRecordLinks":
                linked = field.get("options", {}).get("linkedTableId", "?")
                extra = f"  -> links to table {linked}"
            elif ftype in ("singleSelect", "multipleSelects"):
                choices = [c["name"] for c in field.get("options", {}).get("choices", [])]
                extra = f"  choices: {choices}"
            print(f"  {field['name']!r:45s} type={ftype}{extra}")


if __name__ == "__main__":
    main()