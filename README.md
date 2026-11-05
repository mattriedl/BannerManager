# Banner Manager

Self-hosted PHP/MySQL banner ad manager: advertisers buy a slot, upload a
banner and pay with PayPal or Stripe; you approve it in the admin panel; it's
served on any site through a two-line embed snippet, with impression/click
stats.

The whole app ships as one file, **`install.php`**, which writes the project
files into a folder of your choice.

## Requirements

PHP 8.1+ with `pdo_mysql`, `curl`, `mbstring` (and `gd` to run the tests).
MySQL 5.7+/MariaDB 10.3+ in production. Local runs and tests use SQLite, so
no database server is needed for development.

## Run it locally

```bash
./dev.sh
```

Open http://127.0.0.1:8000/advertise.php to buy a slot as an advertiser and
http://127.0.0.1:8000/admin.php (password `local-test-admin-pass`) to approve
it. `demo.html` shows the embed in action. E-mails go to `build/dev/mail.log`.
Use `RESET=1 ./dev.sh` to start from an empty database.

`dev.sh` regenerates `build/dev` from `install.php` each start, so edit
`install.php` and restart.

To mark a test order as paid locally without Stripe/PayPal, approve it from
the admin panel after running:

```bash
sqlite3 build/dev/dev.sqlite "UPDATE gb_banners SET paid=1 WHERE id=1"
```

## Test

```bash
php tests/run.php
```

Runs the installer, boots the app on PHP's built-in server and walks the
whole flow over HTTP: purchase form validation, upload safety, signed
previews, Stripe webhook signatures/replays/duplicates, forged PayPal IPNs,
admin login + CSRF, approval, serving, click redirects, expiry and stats.

GitHub Actions (`.github/workflows/tests.yml`) runs the suite on PHP 8.1–8.4
with SQLite, plus once against a real MySQL 8 using the installer's own
`--db-*` import. To run the MySQL variant yourself:

```bash
GB_TEST_DB=mysql GB_TEST_MYSQL_USER=root GB_TEST_MYSQL_PASS=secret php tests/run.php
```

## Debug

- **Xdebug (VS Code + PHP Debug extension):** start *Listen for Xdebug*,
  then `XDEBUG=1 ./dev.sh` — breakpoints in `build/dev/*.php` hit on every
  request. `.vscode/launch.json` also has configs to step through the
  installer and the test suite.
- **Server log:** errors print in the `dev.sh` terminal
  (`display_errors` is on locally). Test runs keep a log in
  `build/test/server-8765.log`.
- **Schema import:** `php install.php --print-sql` shows exactly which
  statements the `--db-*` import will run.
- **Local config:** `tests/config.local.php` is copied next to
  `config.php` in dev/test builds and replaces it. Never upload
  `config.local.php` to a live server.

## Deploy

```bash
php install.php --dir=/var/www/ads \
  --db-host=localhost --db-user=ads --db-pass='…' --db-name=greenbox
```

Then edit `config.php` (base URL, a strong admin password — the admin panel
refuses to open with the placeholder — DB credentials, PayPal and Stripe
keys) and follow the installer's printed next steps (Stripe webhook for
`checkout.session.completed`, PayPal IPN).

## Fixes made to the original script

| Area | Problem | Fix |
|---|---|---|
| Installer DB import | Leading `--` comments were glued to `CREATE TABLE gb_spots` and the seed `INSERT`, which were then skipped, so the import failed on the foreign key | Strip comment lines before splitting; added `--print-sql` |
| Uploads | `uploads/.htaccess` was only written if the folder didn't exist, but the installer pre-creates it, so it was never written | Written at install time and on upload; also blocks `.php/.phtml/.phar/.html/.svg/.js` |
| CSRF | A POST with no session crashed with a `TypeError` | Safe check |
| Time zones | PHP ran in UTC but MySQL `NOW()` used server time, so `go.php` and `serve.php` could disagree about expiry | MySQL session set to UTC |
| Stripe webhook | No replay protection; only the last `v1` signature checked | 5-minute timestamp tolerance; any valid `v1` accepted |
| Payments | Each Stripe payment left a stale `pending` order plus a new `paid` one; concurrent notifications could double-process | Pending order is converted; atomic paid flag |
| Advertise form | Promised "image size must match the slot" but didn't check; accepted any URL starting with `http` | Exact size check before storing; URL validated |
| Admin | Worked with the shipped `CHANGE-ME-NOW` password; no session regeneration on login; a failed admin upload was an uncaught 500 | Refuses placeholder/short passwords; `session_regenerate_id`; errors shown as a flash message |
| Preview | Always said "awaiting approval" once paid, even when live | Shows the real status |
