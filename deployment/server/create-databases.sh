#!/usr/bin/env bash
# Run on the Bluehost server (copy-databases.ps1 does it): bash create-databases.sh
# Creates the Test Server databases and one user through cPanel's uapi, then writes their .env lines.
# Safe to rerun: existing databases are kept and an existing credentials file keeps its password.
set -euo pipefail

HOME_DIR=${HOME_DIR:-/home2/eoxvhumy}
PREFIX=${PREFIX:-eoxvhumy_ts}
ROOT=${ROOT:-$HOME_DIR/apps/test-server}
DB_ENV=$ROOT/db.env

DB_USER=$PREFIX
CORE_DB=${PREFIX}_core
PORTAL_DB=${PREFIX}_portal
TRACKER_DB=${PREFIX}_tracker
ESTIMATOR_DB=${PREFIX}_estimator

uapi_ok() {
    local out
    out=$(uapi --output=json "$@")
    if ! echo "$out" | grep -q '"status":1'; then
        echo "uapi $1 $2 failed:"; echo "$out" | grep -o '"errors":\[[^]]*\]' || echo "$out"
        exit 1
    fi
}

existing=$(uapi --output=json Mysql list_databases)
for db in "$CORE_DB" "$PORTAL_DB" "$TRACKER_DB" "$ESTIMATOR_DB"; do
    if echo "$existing" | grep -q "\"database\":\"$db\""; then
        echo "== $db already exists, keeping it"
    else
        echo "== Creating $db"
        uapi_ok Mysql create_database "name=$db"
    fi
done

if [ -f "$DB_ENV" ]; then
    # A second password would lock the app out of databases already granted to the first.
    # shellcheck disable=SC1090
    DB_PASS=$(source "$DB_ENV" && echo "$DB_PASSWORD")
    echo "== User $DB_USER already set up, keeping its password"
else
    # Read a fixed amount first; cutting an endless tr stream short kills it with SIGPIPE under pipefail.
    DB_PASS=$(head -c 1024 /dev/urandom | LC_ALL=C tr -dc 'A-Za-z0-9')
    DB_PASS=${DB_PASS:0:32}
    [ ${#DB_PASS} -eq 32 ] || { echo "Could not generate a password"; exit 1; }
    echo "== Creating user $DB_USER"
    uapi_ok Mysql create_user "name=$DB_USER" "password=$DB_PASS"
fi

for db in "$CORE_DB" "$PORTAL_DB" "$TRACKER_DB" "$ESTIMATOR_DB"; do
    echo "== Granting $DB_USER on $db"
    uapi_ok Mysql set_privileges_on_database "user=$DB_USER" "database=$db" "privileges=ALL PRIVILEGES"
done

mkdir -p "$(dirname "$DB_ENV")"
umask 077
cat > "$DB_ENV" <<ENV
DB_DATABASE=$CORE_DB
DB_USERNAME=$DB_USER
DB_PASSWORD=$DB_PASS
CORE_DB_DATABASE=$CORE_DB
PORTAL_DB_DATABASE=$PORTAL_DB
PORTAL_DB_USERNAME=$DB_USER
PORTAL_DB_PASSWORD=$DB_PASS
TRACKER_ENABLED=true
TRACKER_DB_DATABASE=$TRACKER_DB
TRACKER_DB_USERNAME=$DB_USER
TRACKER_DB_PASSWORD=$DB_PASS
ESTIMATOR_ENABLED=true
ESTIMATOR_DB_DATABASE=$ESTIMATOR_DB
ESTIMATOR_DB_USERNAME=$DB_USER
ESTIMATOR_DB_PASSWORD=$DB_PASS
ENV
chmod 600 "$DB_ENV"

echo "Done. Databases: $CORE_DB, $PORTAL_DB, $TRACKER_DB, $ESTIMATOR_DB. User: $DB_USER"
echo "Credentials saved to $DB_ENV (password not printed)."
