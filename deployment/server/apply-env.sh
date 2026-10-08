#!/usr/bin/env bash
# Run on the Bluehost server: bash ~/apps/test-server/scripts/apply-env.sh
# Rebuilds the running release's .env from the two server files and refreshes Laravel's config cache.
set -euo pipefail

HOME_DIR=${HOME_DIR:-/home2/eoxvhumy}
ROOT=${ROOT:-$HOME_DIR/apps/test-server}
APP_DIR=$ROOT/app

[ -d "$APP_DIR" ] || { echo "No release at $APP_DIR; deploy first."; exit 1; }
[ -f "$ROOT/.env" ] || { echo "Missing $ROOT/.env"; exit 1; }

# Same order as deploy-admin.sh: database lines last, because Laravel keeps the last value of a repeated key.
umask 077
if [ -f "$ROOT/db.env" ]; then
    cat "$ROOT/.env" <(echo) "$ROOT/db.env" > "$APP_DIR/.env"
else
    cp "$ROOT/.env" "$APP_DIR/.env"
fi
chmod 600 "$APP_DIR/.env"

cd "$APP_DIR"
php artisan optimize:clear >/dev/null
# No route:cache: cached routes answer 405 for the folder URL (/central-api/) when Laravel sits in a subfolder.
(php artisan config:cache && php artisan event:cache && php artisan view:cache) >/dev/null
echo "Applied. Mailer: $(php artisan tinker --execute='echo config("mail.default");' 2>/dev/null | tail -n1)"
