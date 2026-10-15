#!/usr/bin/env bash
# Run on the Bluehost server (deploy.ps1 does it): bash deploy-admin.sh [path/to/central-platform.zip]
# Installs the zip as a new release, swaps it in, migrates, and republishes the public folder.
# Migration health: the databases are dumped first, the deploy fails while a migration is still pending, and it
# fails unless /health answers 200 -- each failure prints how to roll the code and the databases back.
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
# Without a deploy config (deployment\deploy.ps1), the Test Server: test.ikaikabim.com serves the site folder itself.
SITE_ROOT=${SITE_ROOT:-public_html/$SITE_DIR}
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
# The portal's own address: from the deploy config's PORTAL_PATH, else the Test Server's, SITE_URL/portal -- what
# deployment\deploy.ps1 builds the portal for. It backs the links and QR codes the API mails (PORTAL_PUBLIC_URL).
if [ -n "${PORTAL_PATH:-}" ]; then
    PORTAL_URL="$SITE_ORIGIN/${PORTAL_PATH#$SITE_ROOT/}"
else
    PORTAL_URL="$SITE_URL/portal"
fi
set_release_key PORTAL_PUBLIC_URL "$PORTAL_URL"
# AZURE_REDIRECT_URI is the address the Azure app registers, the one the portal's build bakes in; without it, the
# portal's own address stands in. Never left to the server's .env: a stale one (the old temporary domain) refuses
# every Microsoft sign-in with a silent 401, since the code is only exchanged for a matching redirect.
set_release_key PORTAL_AZURE_REDIRECT_URI "${AZURE_REDIRECT_URI:-$PORTAL_URL}"
# Mail goes through the database queue, which the scheduler cron below works off every minute; with the sync default
# every notification and Send Email would hold the request for a relay round trip per email.
set_release_key QUEUE_CONNECTION database
# The name every email without a sender of its own goes out under, and what "Send as me" reads "<Name> via ..." with.
set_release_key MAIL_FROM_NAME '"IKAIKA Portal"'
chmod 600 "$NEW/.env"
chmod -R 775 "$NEW/storage" "$NEW/bootstrap/cache"

# A value from the release's .env, last one winning as Laravel reads it, without surrounding quotes. The server's
# .env can carry Windows line endings, so the carriage return goes first or it rides along into the value.
env_value() {
    tr -d '\r' < "$NEW/.env" | grep -E "^$1=" | tail -n 1 | cut -d= -f2- | sed -E 's/[[:space:]]+$//; s/^"(.*)"$/\1/; s/^'"'"'(.*)'"'"'$/\1/' || true
}

# A connection's setting the way config/products.php resolves it: <PREFIX>_DB_<FIELD>, else DB_<FIELD>.
db_value() {
    local value
    value=$(env_value "$1_DB_$2")
    [ -n "$value" ] || value=$(env_value "DB_$2")
    printf '%s' "$value"
}

# The databases a migration or a tracker patch can change: the default (it holds the migrations table), the
# portal and the tracker. Dumped before the swap, so a failed dump stops the deploy with nothing changed yet.
DUMP_DIR=$BACKUPS/databases-$STAMP
DUMPED=()
echo "== Backing up the databases"
mkdir -p "$DUMP_DIR"
chmod 700 "$DUMP_DIR"
for prefix in DEFAULT PORTAL TRACKER; do
    if [ "$prefix" = DEFAULT ]; then
        db=$(env_value DB_DATABASE); user=$(env_value DB_USERNAME); pass=$(env_value DB_PASSWORD)
        host=$(env_value DB_HOST); port=$(env_value DB_PORT)
    else
        db=$(env_value "${prefix}_DB_DATABASE"); user=$(db_value "$prefix" USERNAME); pass=$(db_value "$prefix" PASSWORD)
        host=$(db_value "$prefix" HOST); port=$(db_value "$prefix" PORT)
    fi
    [ -n "$db" ] || { echo "   $prefix: no database named, skipped"; continue; }
    case " ${DUMPED[*]:-} " in *" $db "*) continue ;; esac
    MYSQL_PWD=$pass mysqldump -h "${host:-127.0.0.1}" -P "${port:-3306}" -u "$user" --single-transaction --no-tablespaces \
        --default-character-set=utf8mb4 "$db" | gzip > "$DUMP_DIR/$db.sql.gz"
    DUMPED+=("$db")
    echo "   $db -> $DUMP_DIR/$db.sql.gz"
done

# Everything needed to undo this deploy: the previous code, the previous public folder, and the dumps.
rollback_hint() {
    echo
    echo "FAILED. To roll back:"
    echo "  mv $APP_DIR $ROOT/app-failed && mv $BACKUPS/admin-app-$STAMP $APP_DIR"
    if [ -d "$BACKUPS/admin-public-$STAMP" ]; then
        echo "  rm -rf $PUBLIC_DIR && mv $BACKUPS/admin-public-$STAMP $PUBLIC_DIR"
    fi
    for db in "${DUMPED[@]:-}"; do
        [ -n "$db" ] && echo "  gunzip -c $DUMP_DIR/$db.sql.gz | mysql -u <user> -p $db"
    done
}

echo "== Activating the release"
if [ -d "$APP_DIR" ]; then
    mv "$APP_DIR" "$BACKUPS/admin-app-$STAMP"
    trap rollback_hint ERR
fi
mv "$NEW" "$APP_DIR"

cd "$APP_DIR"
php artisan optimize:clear
php artisan migrate --force

# migrate can return 0 with work left (a migration that skipped itself); --pending exits non-zero while any remain.
echo "== Migration check"
if ! php artisan migrate:status --pending=1; then
    echo "Migrations are still pending after migrate."
    rollback_hint
    exit 1
fi

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

# The scheduler drives the mail queue's worker every minute, the hourly Bitbucket sync and any later scheduled job; without
# it queued email never leaves. cPanel's Cron Jobs page shows this line too.
echo "== Scheduler cron"
CRON_LINE="* * * * * cd $APP_DIR && $(command -v php) artisan schedule:run >> /dev/null 2>&1"
# Matched on this release's own folder: a second target on the same account (production's apps/portal-prod
# beside the Test Server's apps/test-server) has its own line, and matching just 'schedule:run' would let
# the other target's line read as "already set" and leave this one with no scheduler at all.
if crontab -l 2>/dev/null | grep -Fq "cd $APP_DIR &&"; then
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

# 200 only when the portal database answers; anything else -- 503, a 500, no answer -- fails the deploy.
echo "== Health"
HEALTH_URL="$SITE_ORIGIN$API_URL_PATH/api/$API_CHANNEL/portal/health"
HEALTH_BODY=$(mktemp)
HEALTH_CODE=$(curl -sS -o "$HEALTH_BODY" -w '%{http_code}' --max-time 30 "$HEALTH_URL" || true)
cat "$HEALTH_BODY"; echo
rm -f "$HEALTH_BODY"
if [ "$HEALTH_CODE" != "200" ]; then
    echo "Health check answered $HEALTH_CODE at $HEALTH_URL."
    rollback_hint
    exit 1
fi
prune databases
echo "Done. Previous release and the database dumps kept in $BACKUPS"
