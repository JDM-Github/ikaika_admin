#!/usr/bin/env bash
# Run once on the Bluehost server: bash ~/apps/test-server/scripts/tidy-server.sh
# Moves the first-day layout (~/deploy*, ~/backups, ~/apps/central-platform-test*) into ~/apps/test-server. Safe to rerun.
set -euo pipefail

HOME_DIR=${HOME_DIR:-/home2/eoxvhumy}
ROOT=$HOME_DIR/apps/test-server
OLD_APP=$HOME_DIR/apps/central-platform-test
PUBLIC_INDEX=$HOME_DIR/public_html/website_a85fbf18/central-api/index.php

mkdir -p "$ROOT/scripts" "$ROOT/upload" "$ROOT/backups"

move() {
    if [ -e "$1" ] && [ ! -e "$2" ]; then
        mv "$1" "$2"
        echo "moved   $1 -> $2"
    fi
}

move "$OLD_APP.env" "$ROOT/.env"
move "$OLD_APP.db.env" "$ROOT/db.env"

if [ -d "$OLD_APP" ] && [ ! -e "$ROOT/app" ]; then
    move "$OLD_APP" "$ROOT/app"
    # The public index.php and the cached config both hold the old absolute path.
    sed -i "s#$OLD_APP/#$ROOT/app/#g" "$PUBLIC_INDEX"
    rm -f "$ROOT"/app/bootstrap/cache/*.php
    # No route:cache: cached routes answer 405 for the folder URL (/central-api/) when Laravel sits in a subfolder.
    (cd "$ROOT/app" && php artisan config:cache && php artisan event:cache && php artisan view:cache) >/dev/null
    echo "updated $PUBLIC_INDEX and the config cache"
fi

for dir in "$OLD_APP"-backup-*; do
    [ -d "$dir" ] && move "$dir" "$ROOT/backups/admin-app-${dir##*-backup-}"
done
for dir in "$HOME_DIR"/backups/central-api-*; do
    [ -d "$dir" ] && move "$dir" "$ROOT/backups/admin-public-${dir##*/central-api-}"
done
for dir in "$HOME_DIR"/backups/portal-*; do
    [ -d "$dir" ] && move "$dir" "$ROOT/backups/portal-${dir##*/portal-}"
done
rm -rf "$OLD_APP-new"

# A route cache made by an earlier deploy breaks the folder URL; drop it wherever the app is now.
rm -f "$ROOT"/app/bootstrap/cache/routes-*.php
rmdir "$HOME_DIR/backups" 2>/dev/null && echo "removed $HOME_DIR/backups" || true

# These only ever held uploaded zips, dumps and copies of these scripts.
for dir in "$HOME_DIR/deploy" "$HOME_DIR/deploy-db" "$HOME_DIR/deploy-portal"; do
    if [ -d "$dir" ]; then
        rm -rf "$dir"
        echo "removed $dir"
    fi
done

echo
echo "Layout now:"
ls -la "$ROOT"
echo
curl -s https://eox.vhu.mybluehost.me/website_a85fbf18/central-api/api/staging/portal/health || true
echo
