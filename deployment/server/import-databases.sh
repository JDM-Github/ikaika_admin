#!/usr/bin/env bash
# Run on the Bluehost server (copy-databases.ps1 does it): bash import-databases.sh [folder with the .sql dumps]
# Loads each dump present (core, portal, tracker, estimator) into its Test Server database, replacing tables of the same name.
# The database is backed up first, to backups/databases-import-<stamp>/. WIPE=1 drops every table before loading, so the
# server ends up exactly like the dump -- nothing the dump lacks survives (copy-databases.ps1 -Wipe, deploy-all.ps1 -ResetDatabases).
set -euo pipefail

HOME_DIR=${HOME_DIR:-/home2/eoxvhumy}
ROOT=${ROOT:-$HOME_DIR/apps/test-server}
DB_ENV=$ROOT/db.env
DIR=${1:-$ROOT/upload}
WIPE=${WIPE:-0}
BACKUP_DIR=$ROOT/backups/databases-import-$(date +%Y%m%d-%H%M%S)

# The dumps hold employee data, so they never outlive this run, even when it fails.
trap 'rm -f "$DIR"/*.sql' EXIT

[ -f "$DB_ENV" ] || { echo "Missing $DB_ENV. Run create-databases.sh first."; exit 1; }
# shellcheck disable=SC1090
source "$DB_ENV"

imported=0

# Every view and table in the database, one DROP each (a GROUP_CONCAT list would be cut at 1024
# characters), with foreign keys off so the order does not matter.
wipe() {
    local db=$1 sql
    sql=$(MYSQL_PWD=$DB_PASSWORD mysql -u "$DB_USERNAME" -N -e \
        "SELECT CONCAT(IF(table_type = 'VIEW', 'DROP VIEW', 'DROP TABLE'), ' \`', table_name, '\`;') FROM information_schema.tables WHERE table_schema = '$db' ORDER BY table_type = 'VIEW' DESC")
    if [ -n "$sql" ]; then
        printf 'SET FOREIGN_KEY_CHECKS=0;\n%s\nSET FOREIGN_KEY_CHECKS=1;\n' "$sql" | MYSQL_PWD=$DB_PASSWORD mysql -u "$DB_USERNAME" "$db"
        echo "   wiped $(printf '%s\n' "$sql" | wc -l | tr -d ' ') tables and views"
    fi
}
import() {
    local file=$DIR/$1.sql db=$2
    # Only the dumps uploaded this time are loaded, so one database can be refreshed alone.
    [ -f "$file" ] || return 0
    [ -n "$db" ] || { echo "== $1.sql has no database in $DB_ENV; rerun create-databases.sh"; exit 1; }
    echo "== $1.sql -> $db"
    mkdir -p "$BACKUP_DIR" && chmod 700 "$BACKUP_DIR"
    MYSQL_PWD=$DB_PASSWORD mysqldump -u "$DB_USERNAME" --single-transaction --no-tablespaces --default-character-set=utf8mb4 "$db" \
        | gzip > "$BACKUP_DIR/$db.sql.gz"
    echo "   backed up to $BACKUP_DIR/$db.sql.gz"
    if [ "$WIPE" = 1 ]; then
        wipe "$db"
    fi
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
# The newest two import backups are kept, like the release backups.
old=$(ls -1dt "$ROOT"/backups/databases-import-* 2>/dev/null | tail -n +3) || true
[ -z "$old" ] || echo "$old" | xargs rm -rf
echo "Done. Dumps removed from the server; the replaced data is in $BACKUP_DIR."
