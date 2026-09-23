#!/usr/bin/env python3
"""
upload_attachments_to_cloudinary.py -- Uploads every downloaded attachment from a
fetch_airtable.py export to Cloudinary and records a local id -> URL cache that
generate_sql_users_projects.py reads (via --cloudinary-cache) to write real
Cloudinary URLs into the `attachments` table instead of local file paths.

Uses the same signed-upload scheme as the live app's Cloudinary integration
(app/Support/Portal/PortalCloudinary.php in ikaika_admin), so migrated files and
future in-app uploads are indistinguishable to anything that reads the
`attachments` table -- same request shape, same signature formula.

--------------------------------------------------------------------------
SETUP
--------------------------------------------------------------------------
  pip install requests

Needs the same three Cloudinary credentials the admin app uses:
  CLOUDINARY_CLOUD_NAME, CLOUDINARY_API_KEY, CLOUDINARY_API_SECRET
Either export them yourself, or point --env-file at ikaika_admin/.env (default:
the .env next to this script's parent directory, i.e. the ikaika_admin root) and
they're read from there directly -- nothing is written back to that file.

--------------------------------------------------------------------------
USAGE
--------------------------------------------------------------------------
  # 1. Export from Airtable first (downloads real files):
  python fetch_airtable.py --base-id appXXXXXXXXXXXXXX --output-dir ./export

  # 2. Upload every downloaded file to Cloudinary:
  python upload_attachments_to_cloudinary.py --export-dir ./export

  # 3. Generate SQL -- pass the cache so it writes real Cloudinary URLs:
  python generate_sql_users_projects.py --export-dir ./export --output data.sql \\
      --cloudinary-cache ./export/cloudinary_uploads.json

--------------------------------------------------------------------------
TRACKING / RESUMABILITY
--------------------------------------------------------------------------
Airtable's attachment id (the "att..." id on each attachment object, distinct
from the record id) never changes for a given file, so results are cached in
<export-dir>/cloudinary_uploads.json keyed by that id. Re-running this script
only uploads attachments that aren't in the cache yet -- safe to interrupt and
resume, and safe to re-run after a fresh fetch_airtable.py export of the same
base (already-uploaded files are recognized and skipped, never duplicated).

--------------------------------------------------------------------------
FOLDERS
--------------------------------------------------------------------------
Reimbursement receipts go to 'ikaika-portal/reimbursements' -- the exact folder
PortalCloudinary::FOLDER uploads live receipts to, so migrated and future
receipts sit together in Cloudinary. Everything else goes to
'ikaika-portal/migrated/<table-slug>', kept apart as a historical bulk import.
"""

import argparse
import hashlib
import json
import os
import sys
import time
from pathlib import Path

import requests

UPLOAD_URL = "https://api.cloudinary.com/v1_1/{cloud}/auto/upload"
LIVE_RECEIPTS_FOLDER = "ikaika-portal/reimbursements"  # must match PortalCloudinary::FOLDER
MIGRATED_FOLDER_PREFIX = "ikaika-portal/migrated"
MAX_RETRIES = 3
SAVE_EVERY = 20  # periodic cache flush, so a crash mid-run doesn't lose earlier uploads


def load_env_file(path):
    env = {}
    if not path.exists():
        return env
    for line in path.read_text(encoding="utf-8").splitlines():
        line = line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, _, value = line.partition("=")
        env[key.strip()] = value.strip().strip('"').strip("'")
    return env


def get_credentials(env_file):
    env = load_env_file(env_file) if env_file else {}

    def pick(name):
        return os.environ.get(name) or env.get(name)

    cloud, key, secret = pick("CLOUDINARY_CLOUD_NAME"), pick("CLOUDINARY_API_KEY"), pick("CLOUDINARY_API_SECRET")
    if not cloud or not key or not secret:
        sys.exit(
            "ERROR: Cloudinary credentials not found. Set CLOUDINARY_CLOUD_NAME, "
            "CLOUDINARY_API_KEY, CLOUDINARY_API_SECRET as environment variables, "
            "or pass --env-file pointing at ikaika_admin/.env."
        )
    return cloud, key, secret


def folder_for(table_name):
    if table_name == "Reimbursements":
        return LIVE_RECEIPTS_FOLDER
    slug = table_name.lower().replace(" - ", "-").replace(" ", "-")
    return f"{MIGRATED_FOLDER_PREFIX}/{slug}"


def upload_one(cloud, key, secret, file_bytes, upload_name, folder):
    """Signed upload -- same folder+timestamp signature formula as PortalCloudinary::uploadReceipt."""
    timestamp = int(time.time())
    signature = hashlib.sha1(f"folder={folder}&timestamp={timestamp}{secret}".encode()).hexdigest()
    url = UPLOAD_URL.format(cloud=cloud)
    data = {"api_key": key, "timestamp": timestamp, "signature": signature, "folder": folder}

    for attempt in range(1, MAX_RETRIES + 1):
        try:
            resp = requests.post(url, files={"file": (upload_name, file_bytes)}, data=data, timeout=120)
        except requests.RequestException as e:
            print(f"    [retry {attempt}/{MAX_RETRIES}] {upload_name}: {e}")
            time.sleep(2 * attempt)
            continue
        if resp.status_code >= 500:
            print(f"    [server error {resp.status_code}, retry {attempt}/{MAX_RETRIES}] {upload_name}")
            time.sleep(2 * attempt)
            continue
        if not resp.ok:
            print(f"    [FAILED {resp.status_code}] {upload_name}: {resp.text[:200]}")
            return None
        secure_url = resp.json().get("secure_url")
        if not secure_url:
            print(f"    [FAILED] {upload_name}: no secure_url in response")
            return None
        return secure_url
    print(f"    [FAILED] {upload_name}: exhausted retries")
    return None


def main():
    parser = argparse.ArgumentParser(
        description="Upload every downloaded attachment from a fetch_airtable.py export to Cloudinary")
    parser.add_argument("--export-dir", required=True, help="Directory produced by fetch_airtable.py")
    parser.add_argument("--cache", default=None,
                         help="Cache file path (default: <export-dir>/cloudinary_uploads.json)")
    parser.add_argument("--env-file", default=None,
                         help="Path to a .env file holding CLOUDINARY_* vars (default: ikaika_admin/.env)")
    args = parser.parse_args()

    export_dir = Path(args.export_dir)
    manifest_path = export_dir / "attachments_manifest.json"
    if not manifest_path.exists():
        sys.exit(f"ERROR: {manifest_path} not found -- run fetch_airtable.py first.")
    with open(manifest_path, encoding="utf-8") as f:
        manifest = json.load(f)

    cache_path = Path(args.cache) if args.cache else export_dir / "cloudinary_uploads.json"
    cache = {}
    if cache_path.exists():
        with open(cache_path, encoding="utf-8") as f:
            cache = json.load(f)

    env_file = Path(args.env_file) if args.env_file else Path(__file__).resolve().parent.parent / ".env"
    cloud, key, secret = get_credentials(env_file)

    to_upload = [m for m in manifest if m.get("status") in ("downloaded", "already_downloaded")
                 and m.get("airtable_attachment_id") and m.get("local_path")]
    print(f"{len(to_upload)} attachments in manifest, {len(cache)} already cached")

    uploaded = 0
    skipped = 0
    failed = []
    for i, entry in enumerate(to_upload, start=1):
        att_id = entry["airtable_attachment_id"]
        if att_id in cache:
            skipped += 1
            continue
        local_path = export_dir / entry["local_path"]
        if not local_path.exists():
            print(f"  [{i}/{len(to_upload)}] MISSING FILE: {local_path}")
            failed.append(entry)
            continue

        folder = folder_for(entry["table"])
        print(f"  [{i}/{len(to_upload)}] uploading {entry['filename']} -> {folder}")
        secure_url = upload_one(cloud, key, secret, local_path.read_bytes(), entry["filename"], folder)
        if secure_url is None:
            failed.append(entry)
            continue

        cache[att_id] = {
            "secure_url": secure_url,
            "folder": folder,
            "table": entry["table"],
            "record_id": entry["record_id"],
            "field": entry["field"],
            "filename": entry["filename"],
        }
        uploaded += 1
        if uploaded % SAVE_EVERY == 0:
            with open(cache_path, "w", encoding="utf-8") as f:
                json.dump(cache, f, indent=2)

    with open(cache_path, "w", encoding="utf-8") as f:
        json.dump(cache, f, indent=2)

    print(f"\nUploaded {uploaded}, already cached {skipped}, failed {len(failed)}")
    if failed:
        print("Failed (re-run this script to retry -- already-uploaded files are skipped):")
        for entry in failed[:20]:
            print(f"  - {entry['table']}/{entry['record_id']}/{entry['field']}/{entry['filename']}")
    print(f"\nCache written to {cache_path}")


if __name__ == "__main__":
    main()
