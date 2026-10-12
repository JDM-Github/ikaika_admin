#!/usr/bin/env bash
# Run on the Bluehost server (deploy.ps1 does it): bash deploy-admin.sh [path/to/central-platform.zip]
# Installs the zip as a new release, swaps it in, migrates, and republishes the public folder.
set -euo pipefail

HOME_DIR=${HOME_DIR:-/home2/eoxvhumy}
# server/deploy-release exports DEPLOY_DIR, the folder it uploaded to, so the two can never name different places.
ROOT=${ROOT:-${DEPLOY_DIR:-$HOME_DIR/apps/test-server}}
SITE_DIR=${SITE_DIR:-website_a85fbf18}
SITE_URL=${SITE_URL:-https://test.ikaikabim.com}
API_CHANNEL=${API_CHANNEL:-staging}

APP_DIR=$ROOT/app
NEW=$ROOT/app-new
BACKUPS=$ROOT/backups
# API_PATH, from the deploy config (deploy.exports), is where this target's API is served from.
case "${API_PATH:-}" in
    /*|*..*) echo "API_PATH must be home-relative, like public_html/site/central-api: $API_PATH"; exit 1 ;;
esac
PUBLIC_DIR=$HOME_DIR/${API_PATH:-public_html/$SITE_DIR/central-api}
# The URL path the API answers at - its folder under the one the domain serves, SITE_ROOT, as test.ikaikabim.com
# serves public_html/website_a85fbf18 - and the scheme and host it answers on.
SITE_ROOT=${SITE_ROOT:-public_html}
API_URL_PATH=/${PUBLIC_DIR#$HOME_DIR/$SITE_ROOT/}
SITE_ORIGIN=$(printf '%s' "$SITE_URL" | sed -E 's#^(https?://[^/]+).*#\1#')
COMPOSER="php $HOME_DIR/bin/composer"
ZIP=${1:-$ROOT/upload/central-platform.zip}
STAMP=$(date +%Y%m%d-%H%M%S)

[ -f "$ZIP" ] || { echo "Zip not found: $ZIP"; exit 1; }
[ -f "$ROOT/.env" ] || { echo "Missing $ROOT/.env. Run setup-env.sh once (README one-time setup)."; exit 1; }

# Keeps the newest two of each backup kind so backups/ never grows without bound.
prune() {
    local old
    old=$(ls -1dt "$BACKUPS/$1"-* 2>/dev/null | tail -n +3) || true
    [ -z "$old" ] || echo "$old" | xargs rm -rf
}

echo "== Unpacking"
rm -rf "$NEW"
mkdir -p "$NEW" "$BACKUPS"
unzip -q "$ZIP" -d "$NEW"

# Laravel keeps the last value of a repeated key, so the generated database lines go last and win.
if [ -f "$ROOT/db.env" ]; then
    cat "$ROOT/.env" <(echo) "$ROOT/db.env" > "$NEW/.env"
else
    cp "$ROOT/.env" "$NEW/.env"
fi

# Files saved under storage/app by the previous release carry over.
if [ -d "$APP_DIR/storage/app" ]; then
    cp -a "$APP_DIR/storage/app/." "$NEW/storage/app/"
fi

echo "== Installing Composer packages"
cd "$NEW"
$COMPOSER install --no-dev --prefer-dist --optimize-autoloader --no-interaction --no-progress

find "$NEW" -type d -exec chmod 755 {} +
find "$NEW" -type f -exec chmod 644 {} +
# Where this target serves the API and the portal, written into the release's .env from API_PATH and PORTAL_PATH every
# deploy, so a folder that moves - production's v2 in website_26e391bd, say - never leaves the server's .env naming the
# old one; the rest of the .env is the server's own.
set_release_key() {
    grep -v "^$1=" "$NEW/.env" > "$NEW/.env.paths" || true
    printf '%s=%s\n' "$1" "$2" >> "$NEW/.env.paths"
    mv "$NEW/.env.paths" "$NEW/.env"
}
set_release_key APP_URL "$SITE_ORIGIN$API_URL_PATH"
set_release_key ASSET_URL "$API_URL_PATH"
set_release_key SESSION_PATH "$API_URL_PATH"
# AZURE_REDIRECT_URI is the address the Azure app registers, the one the portal's build bakes in; without it, the
# portal's own address stands in.
if [ -n "${AZURE_REDIRECT_URI:-}" ]; then
    set_release_key PORTAL_AZURE_REDIRECT_URI "$AZURE_REDIRECT_URI"
elif [ -n "${PORTAL_PATH:-}" ]; then
    set_release_key PORTAL_AZURE_REDIRECT_URI "$SITE_ORIGIN/${PORTAL_PATH#$SITE_ROOT/}"
fi
chmod 600 "$NEW/.env"
chmod -R 775 "$NEW/storage" "$NEW/bootstrap/cache"

echo "== Activating the release"
if [ -d "$APP_DIR" ]; then
    mv "$APP_DIR" "$BACKUPS/admin-app-$STAMP"
    trap 'echo "FAILED. Roll back with: mv $APP_DIR $ROOT/app-failed && mv $BACKUPS/admin-app-$STAMP $APP_DIR"' ERR
fi
mv "$NEW" "$APP_DIR"

cd "$APP_DIR"
php artisan optimize:clear
php artisan migrate --force

# The tracker database is the transaction tracker's own, so no Laravel migration reaches it: its table
# changes ship as patches that are safe to rerun (CREATE TABLE IF NOT EXISTS, and so on).
echo "== Tracker patches"
if [ -f "$ROOT/db.env" ] && compgen -G "$APP_DIR/sql/transaction_tracker/patches/*.sql" > /dev/null; then
    (
        # shellcheck disable=SC1091
        source "$ROOT/db.env"
        for patch in "$APP_DIR"/sql/transaction_tracker/patches/*.sql; do
            echo "   $(basename "$patch")"
            # The USE line names the local database; the server's comes from db.env instead.
            grep -v '^USE ' "$patch" | MYSQL_PWD=$DB_PASSWORD mysql -u "$DB_USERNAME" --default-character-set=utf8mb4 "$TRACKER_DB_DATABASE"
        done
    )
else
    echo "   none"
fi

# The scheduler drives the hourly Bitbucket sync and any later scheduled job; cPanel's Cron Jobs page shows this line too.
echo "== Scheduler cron"
CRON_LINE="* * * * * cd $APP_DIR && $(command -v php) artisan schedule:run >> /dev/null 2>&1"
if crontab -l 2>/dev/null | grep -Fq 'artisan schedule:run'; then
    echo "   already set"
else
    { crontab -l 2>/dev/null || true; echo "$CRON_LINE"; } | crontab -
    echo "   added"
fi

# No route:cache: cached routes answer 405 for the folder URL (/central-api/) when Laravel sits in a subfolder.
php artisan config:cache && php artisan event:cache && php artisan view:cache

echo "== Publishing $PUBLIC_DIR"
if [ -d "$PUBLIC_DIR" ]; then
    mv "$PUBLIC_DIR" "$BACKUPS/admin-public-$STAMP"
fi
mkdir -p "$PUBLIC_DIR"
cp -a "$APP_DIR/public/." "$PUBLIC_DIR/"
cat > "$PUBLIC_DIR/index.php" <<PHP
<?php

use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

if (file_exists(\$maintenance = '$APP_DIR/storage/framework/maintenance.php')) {
    require \$maintenance;
}

require '$APP_DIR/vendor/autoload.php';

(require_once '$APP_DIR/bootstrap/app.php')
    ->handleRequest(Request::capture());
PHP
find "$PUBLIC_DIR" -type d -exec chmod 755 {} +
find "$PUBLIC_DIR" -type f -exec chmod 644 {} +

rm -f "$ZIP"
prune admin-app
prune admin-public

echo "== Health"
curl -s "$SITE_ORIGIN$API_URL_PATH/api/$API_CHANNEL/portal/health" || true
echo
echo "Done. Previous release kept in $BACKUPS"
