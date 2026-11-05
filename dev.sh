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

# Public address of the site (banner images, click links and the embed
# script use absolute URLs). Override with BASE_URL=...; in GitHub
# Codespaces the forwarded https URL is detected automatically.
BIND="127.0.0.1"
if [[ -z "${BASE_URL:-}" && -n "${CODESPACE_NAME:-}" ]]; then
  BASE_URL="https://${CODESPACE_NAME}-${PORT}.${GITHUB_CODESPACES_PORT_FORWARDING_DOMAIN:-app.github.dev}"
  BIND="0.0.0.0"
fi
BASE_URL="${BASE_URL:-http://127.0.0.1:$PORT}"

export GB_TEST_BASE_URL="$BASE_URL"
export GB_TEST_SQLITE_FILE="$PWD/$DIR/dev.sqlite"

cat <<EOF

  Advertiser form : $BASE_URL/advertise.php
  Admin panel     : $BASE_URL/admin.php   (password: local-test-admin-pass)
  Embed demo      : $BASE_URL/demo.html
  E-mails         : $DIR/mail.log
  Test images     : $DIR/sample-banners/  (one PNG per slot size)

EOF

cat > "$DIR/demo.html" <<EOF
<!doctype html><meta charset="utf-8"><title>Embed demo</title>
<h1>Embed demo</h1><p>Leaderboard:</p><div data-gb-spot="leaderboard"></div>
<p>Medium rectangle:</p><div data-gb-spot="medium-rectangle"></div>
<script src="$BASE_URL/embed.js.php" async></script>
EOF

# Ready-made banner images in every preset size, for trying the purchase form.
if php -r 'exit(function_exists("imagecreatetruecolor") ? 0 : 1);'; then
  php tests/make-sample-banners.php "$DIR/sample-banners" >/dev/null
fi

exec php -d display_errors=1 -d error_reporting=-1 \
  -d "sendmail_path=tee -a $PWD/$DIR/mail.log >/dev/null" \
  "${XDEBUG_ARGS[@]}" -S "$BIND:$PORT" -t "$DIR"
