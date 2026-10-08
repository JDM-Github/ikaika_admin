#!/usr/bin/env bash
# Run on the Bluehost server (push-env.ps1 does it): KEY=value lines arrive on stdin and replace those keys
# in ~/apps/test-server/.env, so a key sent twice never leaves two lines behind; then apply-env.sh applies them.
# Only key names are printed, never values.
set -euo pipefail

HOME_DIR=${HOME_DIR:-/home2/eoxvhumy}
ROOT=${ROOT:-$HOME_DIR/apps/test-server}
TARGET=$ROOT/.env

[ -f "$TARGET" ] || { echo "Missing $TARGET. Run setup-env.sh once (README one-time setup)."; exit 1; }

umask 077
incoming=$(mktemp)
trap 'rm -f "$incoming" "$TARGET.new" "$TARGET.tmp"' EXIT

# Windows sends CRLF; only well-formed KEY=value lines are kept.
tr -d '\r' | grep -E '^[A-Z0-9_]+=.' > "$incoming" || true
[ -s "$incoming" ] || { echo "Nothing to merge."; exit 0; }

cp "$TARGET" "$TARGET.new"
echo "== Replacing in $TARGET"
while IFS= read -r line; do
    key=${line%%=*}
    grep -v "^$key=" "$TARGET.new" > "$TARGET.tmp" || true
    mv "$TARGET.tmp" "$TARGET.new"
    echo "   $key"
done < "$incoming"

# The file may not end in a newline, which would glue the first new line onto the last old one.
[ -z "$(tail -c1 "$TARGET.new")" ] || echo >> "$TARGET.new"
cat "$incoming" >> "$TARGET.new"
mv "$TARGET.new" "$TARGET"
chmod 600 "$TARGET"

bash "$ROOT/scripts/apply-env.sh"
