#!/usr/bin/env bash
# Run on the Bluehost server (copy-databases.ps1 does it): bash import-databases.sh [folder with the .sql dumps]
# Loads each dump present (core, portal, tracker, estimator) into its Test Server database, replacing tables of the same name.
set -euo pipefail

HOME_DIR=${HOME_DIR:-/home2/eoxvhumy}
ROOT=${ROOT:-$HOME_DIR/apps/test-server}
DB_ENV=$ROOT/db.env
DIR=${1:-$ROOT/upload}

# The dumps hold employee data, so they never outlive this run, even when it fails.
trap 'rm -f "$DIR"/*.sql' EXIT

[ -f "$DB_ENV" ] || { echo "Missing $DB_ENV. Run create-databases.sh first."; exit 1; }
# shellcheck disable=SC1090
source "$DB_ENV"

imported=0
import() {
    local file=$DIR/$1.sql db=$2
    # Only the dumps uploaded this time are loaded, so one database can be refreshed alone.
    [ -f "$file" ] || return 0
    [ -n "$db" ] || { echo "== $1.sql has no database in $DB_ENV; rerun create-databases.sh"; exit 1; }
    echo "== $1.sql -> $db"
    MYSQL_PWD=$DB_PASSWORD mysql -u "$DB_USERNAME" --default-character-set=utf8mb4 "$db" < "$file"
    MYSQL_PWD=$DB_PASSWORD mysql -u "$DB_USERNAME" -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '$db'" \
        | xargs -I{} echo "   {} tables"
    imported=$((imported + 1))
}

import core      "${CORE_DB_DATABASE:-}"
import portal    "${PORTAL_DB_DATABASE:-}"
import tracker   "${TRACKER_DB_DATABASE:-}"
import estimator "${ESTIMATOR_DB_DATABASE:-}"

[ "$imported" -gt 0 ] || { echo "No dumps found in $DIR"; exit 1; }
echo "Done. Dumps removed from the server."
