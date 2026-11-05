#!/usr/bin/env bash
# One-time Codespace setup: add the gd extension (used for sample banners
# and the test suite) if the image doesn't already have it.
set -u
if ! php -m | grep -qi '^gd$'; then
  sudo apt-get update -qq \
    && sudo apt-get install -y -qq libpng-dev libjpeg-dev libwebp-dev >/dev/null \
    && sudo docker-php-ext-configure gd --with-jpeg --with-webp >/dev/null \
    && sudo docker-php-ext-install -j"$(nproc)" gd >/dev/null \
    || echo "Note: could not install gd; the app runs fine, but sample banners/tests need it."
fi
php -m | grep -iE '^(pdo_sqlite|gd|curl|mbstring)$' | tr '\n' ' '; echo
chmod +x dev.sh
