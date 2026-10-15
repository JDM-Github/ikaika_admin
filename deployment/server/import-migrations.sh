#!/usr/bin/env bash
# Run on the Bluehost server (import-airtable-to-prod.ps1 does it): bash import-migrations.sh [folder]
# Loads the migrations/ exporter's schema.sql + data.sql for each product into its production database.
# schema.sql drops and recreates the database it names, so each is dumped to backups/ first; data.sql
# then loads the Airtable rows. The app's migrations run last: they are all guarded (hasTable/hasColumn/
# hasIndex), so they add the tables schema.sql does not carry and record a fresh migrations table.
set -euo pipefail

HOME_DIR=${HOME_DIR:-/home2/eoxvhumy}
# server/deploy-release exports DEPLOY_DIR, the folder it uploaded to, so the two can never name different places.
ROOT=${ROOT:-${DEPLOY_DIR:-$HOME_DIR/apps/test-server}}
DIR=${1:-$ROOT/upload}
STAMP=$(date +%Y%m%d-%H%M%S)
BACKUP=$ROOT/backups/databases-airtable-$STAMP

[ -f "$ROOT/db.env" ] || { echo "Missing $ROOT/db.env. Run create-databases.sh first."; exit 1; }
# shellcheck disable=SC1090
source "$ROOT/db.env"

# The dumps hold employee data, so they never outlive this run, even when it fails.
trap 'rm -f "$DIR"/*-schema.sql "$DIR"/*-data.sql' EXIT

mkdir -p "$BACKUP" && chmod 700 "$BACKUP"

# One product's pair of files into its database, after backing that database up.
load() {
    local db=$1 schema="$DIR/$1-schema.sql" data="$DIR/$1-data.sql"
    [ -f "$schema" ] || { echo "Missing $schema"; exit 1; }
    [ -f "$data" ]   || { echo "Missing $data"; exit 1; }
    echo "== backing up $db"
    MYSQL_PWD=$DB_PASSWORD mysqldump -u "$DB_USERNAME" --single-transaction --no-tablespaces \
        --default-character-set=utf8mb4 "$db" | gzip > "$BACKUP/$db.sql.gz"
    echo "== $db: schema (drops and recreates it)"
    MYSQL_PWD=$DB_PASSWORD mysql -u "$DB_USERNAME" --default-character-set=utf8mb4 < "$schema"
    echo "== $db: data"
    MYSQL_PWD=$DB_PASSWORD mysql -u "$DB_USERNAME" --default-character-set=utf8mb4 < "$data"
}

load "$PORTAL_DB_DATABASE"
load "$ESTIMATOR_DB_DATABASE"
load "$TRACKER_DB_DATABASE"

# Guarded migrations: they skip what schema.sql already made and create the rest (logs, notifications,
# shared pages, onboarding, email, the tracker patches' tables, and a fresh migrations table).
echo "== app migrations"
cd "$ROOT/app" && php artisan migrate --force

echo "Done. Loaded $PORTAL_DB_DATABASE, $ESTIMATOR_DB_DATABASE, $TRACKER_DB_DATABASE."
echo "Previous data kept in $BACKUP (the dumps go on this run's exit)."
