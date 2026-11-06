# BannerPromote (Banner Manager)

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

## Try it in your browser (GitHub Codespaces)

No install needed:

1. On the repo page click **Code → Codespaces → Create codespace on main**.
2. Wait for setup to finish (about a minute the first time), then in the
   terminal at the bottom run `./dev.sh`.
3. A browser tab opens on port 8000 (or open it from the **Ports** tab).
   Go to `/advertise.php`. The terminal prints all the links.

To walk through the whole flow:

1. Open `/sample-banners/index.html` (the link is printed when `./dev.sh`
   starts) and download an image, e.g. `leaderboard-728x90.png`. The files
   are also in `build/dev/sample-banners/` in the file list.
2. On `/advertise.php`, pick **Leaderboard**, fill in the form and upload it.
   You land on the preview page; note the banner number (`id=` in the URL).
3. Real Stripe/PayPal aren't set up here, so mark it paid: either click
   **Mark paid** in `/admin.php` → **Banners**, or run
   `php tests/fake-payment.php 1` in a second terminal (**+**), which sends
   a properly signed Stripe webhook so the real payment code runs.
4. In `/admin.php` (password `local-test-admin-pass`) → **Banners** → **Approve**.
5. Open `/demo.html` to see it served through the embed snippet. Click it,
   then check **Stats** in the admin panel.

Sent e-mails are in `build/dev/mail.log`. Stop the codespace when you're
done (it stops itself after 30 idle minutes); personal accounts get free
monthly hours.

## Run it on your own computer

Needs PHP 8.1+ with `pdo_sqlite` (on Windows, run it inside WSL).

```bash
./dev.sh
```

Open http://127.0.0.1:8000/advertise.php to buy a slot as an advertiser and
http://127.0.0.1:8000/admin.php (password `local-test-admin-pass`) to approve
it. `demo.html` shows the embed in action. E-mails go to `build/dev/mail.log`.
Use `RESET=1 ./dev.sh` to start from an empty database.

`dev.sh` regenerates `build/dev` from `install.php` each start, so edit
`install.php` and restart.

To mark a test order as paid without Stripe/PayPal, run
`php tests/fake-payment.php <banner id>` while `dev.sh` is running, then
approve it in the admin panel.

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

## Search engine optimization

Public pages (all indexable, listed in `sitemap.xml`):

| Page | Targets |
|---|---|
| `/` (static `index.html`, rendered from `home.php` at install) | buy banner ads, banner advertising |
| `/advertise.php` | buy a banner ad online |
| `/pricing.php` (live prices) | banner ad pricing, banner ad cost |
| `/banner-ad-sizes.php` | banner ad sizes, 728x90, 300x250, 160x600, 320x50 |
| `/how-to-buy-banner-ads.php` | how to buy banner ads |
| `/faq.php` | banner advertising questions |

Every page has a unique title and description, a canonical URL, Open Graph
and Twitter tags, schema.org structured data (Organization, WebSite,
BreadcrumbList, Service/Offer, Article, FAQPage) and links to the other
pages. Every other page is `noindex` by default, and `robots.txt` blocks the
private ones. Each verified deploy notifies Bing through IndexNow (key file
`8896ea600333598d3649af506349872f.txt`). The home page's headline price is
`GB_PRICE_PITCH` in `config.php`.

Your previous "Coming Soon" page is kept in `docs/original-coming-soon-index.html`.

## Analytics and privacy

Google Tag Manager (`GB_GTM_ID` in `config.php`, `''` turns it off) loads on
public pages only, never in the admin console. Consent Mode defaults deny ad
storage everywhere and analytics in the EU/EEA, UK and Switzerland. Visitors
whose browser is set to a European time zone see a cookie popup, and nothing
from Google loads until they accept. Everyone else gets analytics by default
and can decline through "Cookie settings" in the footer. Choices are saved in
the browser (`localStorage` key `bp_consent`). Fonts are hosted in `fonts/`
(SIL Open Font License), so no requests go to Google Fonts.

## No self-ads

A website never shows banners that link back to itself. When the embed code
asks for an ad, it tells the server which website it is on; any banner whose
click destination is that same website is skipped, and another banner is
shown instead (or nothing, if none is left).

- `www.` is ignored and subdomains count as the same website, so a banner for
  `shop.examplewebsite.com` never appears on `blog.examplewebsite.com`.
- Two-part endings such as `.co.uk` and shared hosts such as `github.io` or
  `wordpress.com` are understood, so `alice.github.io` can still advertise on
  `bob.github.io`.
- HTML house banners are checked against every link inside them.
- Older copies of the embed code still work: the server falls back to the
  page address the browser reports.
- Turn it off with `define('GB_BLOCK_SELF_ADS', false);` in `config.php`.

## Changing prices

In the admin console go to **Placements**, click **Edit** on a placement,
change its name, price or run length and click **Save changes**. New and
unpaid orders use the new terms; orders already paid keep the price they
paid. A placement's size and embed name are fixed once created, because
existing banners and website code depend on them.

## Branding

Set your company name with `GB_BRAND_NAME` in `config.php` (default:
"BannerPromote"). All pages share one stylesheet,
`style.css`; the colours are defined at the top as CSS variables
(`--ink` navy, `--brand` emerald) if you want to match your own brand.

## Automatic deploys (GitHub Actions)

`.github/workflows/deploy.yml` uploads the site to your host over SFTP.
Add repository secrets `SFTP_HOST`, `SFTP_USER`, `SFTP_PASSWORD` (optional
`SFTP_PORT`, `SFTP_DIR`), upload your filled-in `config.php` once, then run
**Actions → Deploy → Run workflow** (dry run first). It never uploads
`config.php`, never touches `uploads/` and never deletes server files. Set
the repository variable `AUTO_DEPLOY=true` to deploy after every push that
passes the tests.

## Deploy on SFTP-only hosting (no command line)

1. Run `php install.php --dir=upload` on your computer (or in a Codespace).
2. Fill in every line marked `CHANGE` in `upload/config.php`.
3. Upload the contents of `upload/` to your site folder with SFTP. Nothing
   is named `index.html`, so an existing home page is left alone.
4. Open `https://your-site/setup.php`, enter the admin password and run
   setup. It creates only missing tables and never changes existing data.
   Delete `setup.php` afterwards.

When updating later, don't overwrite `config.php` or delete `uploads/`.

## Deploy (server with a command line)

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
| Admin banner list | Approve on an unpaid banner did nothing but said "approved"; Resume could set an unpaid banner to *active* even though it would never be served | Approve explains why it refused; Resume only works on paused banners; each banner shows *serving* / *not serving (reason)*; new **Mark paid** button for offline payments |
