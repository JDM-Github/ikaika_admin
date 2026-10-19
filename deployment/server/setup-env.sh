#!/usr/bin/env bash
# Run once on the Bluehost server: bash ~/apps/test-server/scripts/setup-env.sh
# Creates the Test Server .env from staging's, points it at the Test Server paths, and lists keys still missing.
set -euo pipefail

HOME_DIR=${HOME_DIR:-/home2/eoxvhumy}
SOURCE=${SOURCE:-$HOME_DIR/apps/central-platform/.env}
ROOT=${ROOT:-$HOME_DIR/apps/test-server}
TARGET=${TARGET:-$ROOT/.env}
SITE_URL=${SITE_URL:-https://test.ikaikabim.com}
SITE_PATH=${SITE_PATH-}

if [ ! -f "$TARGET" ]; then
    [ -f "$SOURCE" ] || { echo "Staging .env not found at $SOURCE"; exit 1; }
    mkdir -p "$ROOT"
    cp -p "$SOURCE" "$TARGET"
    echo "== Copied $SOURCE"
else
    echo "== $TARGET exists, updating it in place"
fi
chmod 600 "$TARGET"

# Staging's file has no final newline, so an appended key would land on its last line.
[ -z "$(tail -c1 "$TARGET")" ] || echo >> "$TARGET"
# Repairs a key glued onto a quoted value by the earlier version of this script.
sed -i -E 's/"(APP_URL|ASSET_URL|SESSION_PATH|API_PATH_PREFIX|PORTAL_AZURE_[A-Z_]+|MAIL_EHLO_DOMAIN|PORTAL_IP_LOOKUP_PRIVATE)=/"\n\1=/' "$TARGET"

# Replaces every line of the key, or appends it, so the file ends with exactly one value.
set_key() {
    local key=$1 value=$2
    if grep -q "^$key=" "$TARGET"; then
        sed -i "/^$key=/d" "$TARGET"
    fi
    printf '%s=%s\n' "$key" "$value" >> "$TARGET"
    echo "   $key set"
}

echo "== Test Server paths"
set_key APP_URL "$SITE_URL/central-api"
set_key ASSET_URL "$SITE_PATH/central-api"
set_key SESSION_PATH "$SITE_PATH/central-api"
set_key API_PATH_PREFIX ""
set_key PORTAL_AZURE_REDIRECT_URI "${AZURE_REDIRECT_URI:-$SITE_URL/portal}"
set_key PORTAL_PUBLIC_URL "${PORTAL_PUBLIC_URL:-$SITE_URL/portal}"

# Public Azure app IDs, also in the portal's app.json; not secrets.
echo "== Keys the staging .env predates"
grep -q '^PORTAL_AZURE_TENANT_ID=.' "$TARGET" || set_key PORTAL_AZURE_TENANT_ID 70c0daac-fc8d-46de-b3eb-db0ce575ae83
grep -q '^PORTAL_AZURE_CLIENT_ID=.' "$TARGET" || set_key PORTAL_AZURE_CLIENT_ID f995e827-5f8b-49ee-8cd5-b05b99017558
grep -q '^MAIL_EHLO_DOMAIN=.' "$TARGET" || set_key MAIL_EHLO_DOMAIN ikaikabim.com
grep -q '^PORTAL_IP_LOOKUP_PRIVATE=' "$TARGET" || set_key PORTAL_IP_LOOKUP_PRIVATE false

echo "== Still empty or missing (fill in with nano if you need them)"
for key in APP_KEY PORTAL_JWT_SECRET CLOUDINARY_CLOUD_NAME CLOUDINARY_API_KEY CLOUDINARY_API_SECRET \
           AIRTABLE_TOKEN AIRTABLE_BASE_ID MAIL_RELAY_URL MAIL_RELAY_KEY \
           JIRA_BASE_URL JIRA_EMAIL JIRA_API_TOKEN JIRA_PROJECT_KEY JIRA_WEBHOOK_SECRET; do
    grep -q "^$key=." "$TARGET" || echo "   $key"
done
echo "(Database lines come from $ROOT/db.env and need nothing here.)"
echo "Done: $TARGET"
