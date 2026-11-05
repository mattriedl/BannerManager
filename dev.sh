#!/usr/bin/env bash
# Run the Banner Manager locally — no MySQL or Apache needed.
#
#   ./dev.sh             start at http://127.0.0.1:8000
#   PORT=9000 ./dev.sh   use another port
#   XDEBUG=1 ./dev.sh    break on every request in VS Code (needs Xdebug)
#   RESET=1 ./dev.sh     wipe the local database and uploads first
#
# Regenerates build/dev from install.php on each start (so your edits
# to install.php take effect) but keeps the SQLite DB between runs.
# Outgoing e-mails are written to build/dev/mail.log instead of sent.
set -euo pipefail
cd "$(dirname "$0")"

PORT="${PORT:-8000}"
DIR="build/dev"

if [[ "${RESET:-0}" == "1" ]]; then rm -rf "$DIR"; fi

php install.php --dir="$DIR" --force >/dev/null
cp tests/config.local.php "$DIR/config.local.php"

if [[ ! -f "$DIR/dev.sqlite" ]]; then
  php -r '$p = new PDO("sqlite:" . $argv[1]); $p->exec(file_get_contents($argv[2]));' \
      "$DIR/dev.sqlite" tests/schema.sqlite.sql
  echo "Created local database $DIR/dev.sqlite (8 preset spots)"
fi

XDEBUG_ARGS=()
if [[ "${XDEBUG:-0}" == "1" ]]; then
  XDEBUG_ARGS=(-d xdebug.mode=debug -d xdebug.start_with_request=yes -d xdebug.client_port=9003)
  echo "Xdebug on: start 'Listen for Xdebug' in VS Code first."
fi

export GB_TEST_BASE_URL="http://127.0.0.1:$PORT"
export GB_TEST_SQLITE_FILE="$PWD/$DIR/dev.sqlite"

cat <<EOF

  Advertiser form : http://127.0.0.1:$PORT/advertise.php
  Admin panel     : http://127.0.0.1:$PORT/admin.php   (password: local-test-admin-pass)
  Demo page       : http://127.0.0.1:$PORT/demo.html
  E-mails         : $DIR/mail.log

EOF

cat > "$DIR/demo.html" <<EOF
<!doctype html><meta charset="utf-8"><title>Embed demo</title>
<h1>Embed demo</h1><p>Leaderboard:</p><div data-gb-spot="leaderboard"></div>
<p>Medium rectangle:</p><div data-gb-spot="medium-rectangle"></div>
<script src="http://127.0.0.1:$PORT/embed.js.php" async></script>
EOF

exec php -d display_errors=1 -d error_reporting=-1 \
  -d "sendmail_path=tee -a $PWD/$DIR/mail.log >/dev/null" \
  "${XDEBUG_ARGS[@]}" -S "127.0.0.1:$PORT" -t "$DIR"
