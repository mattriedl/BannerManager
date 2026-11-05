#!/usr/bin/env php
<?php
declare(strict_types=1);

/* =====================================================================
   install.php — Self-contained installer for the Green Box-style
   PHP/MySQL Banner Ads Manager.

   Usage (CLI):   php install.php [options]

   Options:
     --dir=PATH        Target directory for the project files
                       (default: ./banner-manager, created if missing)
     --db-host=HOST    Optional: if given along with db-user/db-name,
                       the installer also creates the database and
                       imports schema.sql automatically.
     --db-user=USER    MySQL username for auto-import
     --db-pass=PASS    MySQL password for auto-import
     --db-name=NAME    Database name for auto-import
     --force           Overwrite existing files without asking
     --print-sql       Debug: print the SQL statements the DB import
                       would run (one per block), then exit

   Afterwards edit config.php (passwords, PayPal/Stripe keys, URL).
   ===================================================================== */

$opts = getopt('', ['dir:', 'db-host:', 'db-user:', 'db-pass:', 'db-name:', 'force', 'print-sql']);
$targetDir = $opts['dir'] ?? getcwd() . DIRECTORY_SEPARATOR . 'banner-manager';
$overwrite = isset($opts['force']);
$doDbImport = isset($opts['db-name'], $opts['db-user']);

/* ---------------------------------------------------------------------
   File manifest: every project file as literal content (nowdoc).
   --------------------------------------------------------------------- */

$files = [];

$files['schema.sql'] = <<<'SCHEMA_FILE'
-- Import with: mysql -u USER -p DBNAME < schema.sql

CREATE TABLE gb_spots (
  id INT AUTO_INCREMENT PRIMARY KEY,
  slug VARCHAR(60) NOT NULL UNIQUE,
  name VARCHAR(120) NOT NULL,
  width SMALLINT UNSIGNED NOT NULL DEFAULT 300,
  height SMALLINT UNSIGNED NOT NULL DEFAULT 250,
  price DECIMAL(10,2) NOT NULL DEFAULT 0,
  period_days SMALLINT UNSIGNED NOT NULL DEFAULT 30,
  is_active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB;

CREATE TABLE gb_banners (
  id INT AUTO_INCREMENT PRIMARY KEY,
  spot_id INT NOT NULL,
  advertiser_name VARCHAR(120) NULL,
  advertiser_email VARCHAR(190) NULL,
  kind ENUM('image','html') NOT NULL DEFAULT 'image',
  image_path VARCHAR(255) NULL,
  html MEDIUMTEXT NULL,
  target_url VARCHAR(500) NULL,
  width SMALLINT UNSIGNED NOT NULL DEFAULT 300,
  height SMALLINT UNSIGNED NOT NULL DEFAULT 250,
  status ENUM('pending','active','paused','rejected','expired') NOT NULL DEFAULT 'pending',
  paid TINYINT(1) NOT NULL DEFAULT 0,
  starts_at DATETIME NULL,
  ends_at DATETIME NULL,
  impressions BIGINT UNSIGNED NOT NULL DEFAULT 0,
  clicks BIGINT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_serve (spot_id, status, ends_at),
  FOREIGN KEY (spot_id) REFERENCES gb_spots(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE gb_orders (
  id INT AUTO_INCREMENT PRIMARY KEY,
  banner_id INT NOT NULL,
  gateway ENUM('paypal','stripe','manual') NOT NULL,
  amount DECIMAL(10,2) NOT NULL,
  currency CHAR(3) NOT NULL DEFAULT 'USD',
  status ENUM('pending','paid','failed','refunded') NOT NULL DEFAULT 'pending',
  txn_ref VARCHAR(190) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (banner_id) REFERENCES gb_banners(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE gb_stats (
  banner_id INT NOT NULL,
  day DATE NOT NULL,
  impressions INT UNSIGNED NOT NULL DEFAULT 0,
  clicks INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (banner_id, day),
  FOREIGN KEY (banner_id) REFERENCES gb_banners(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 8 preset sizes seeded as sellable spots (add custom sizes in admin):
INSERT INTO gb_spots (slug,name,width,height,price,period_days) VALUES
('medium-rectangle','Medium Rectangle',300,250,19.00,30),
('leaderboard','Leaderboard',728,90,29.00,30),
('full-banner','Full Banner',468,60,15.00,30),
('mobile-banner','Mobile Banner',320,50,12.00,30),
('skyscraper','Skyscraper',160,600,24.00,30),
('half-page','Half Page',300,600,34.00,30),
('large-rectangle','Large Rectangle',336,280,22.00,30),
('square','Square',250,250,16.00,30);
SCHEMA_FILE;

$files['config.php'] = <<<'CONFIG_FILE'
<?php
declare(strict_types=1);

/* ================================================================
   Green Box-style Banner Manager — configuration
   Edit the values below, then upload to your web server.
   ================================================================ */

// Local development / testing: if config.local.php exists next to this
// file it replaces this config entirely (never upload it to production).
if (is_file(__DIR__ . '/config.local.php')) {
    require_once __DIR__ . '/config.local.php';
    return;
}

// --- Database ---
define('GB_DB_HOST', 'localhost');
define('GB_DB_NAME', 'greenbox');
define('GB_DB_USER', 'root');
define('GB_DB_PASS', '');

// --- Site ---
define('GB_BASE_URL', 'https://ads.example.com'); // no trailing slash!
define('GB_ADMIN_PASSWORD', 'CHANGE-ME-NOW');   // strong password required
define('GB_ADMIN_EMAIL', 'admin@example.com');   // receives payment alerts

// --- Uploads ---
define('GB_UPLOAD_DIR', __DIR__ . '/uploads');
define('GB_MAX_UPLOAD_BYTES', 5 * 1024 * 1024);  // 5 MB max image

// --- Moderation ---
define('GB_AUTO_APPROVE', false);  // false = banners need admin approval
define('GB_CURRENCY', 'USD');

// --- PayPal ---
define('GB_PAYPAL_BUSINESS', 'seller@example.com');
define('GB_PAYPAL_SANDBOX', true);  // true while testing, false for live

// --- Stripe (raw REST, no SDK needed) ---
define('GB_STRIPE_SECRET', 'sk_test_REPLACE');
define('GB_STRIPE_WEBHOOK_SECRET', 'whsec_REPLACE');

// --- Mail ---
define('GB_MAIL_FROM', 'noreply@example.com');

date_default_timezone_set('UTC');

require_once __DIR__ . '/lib.php';
CONFIG_FILE;

$files['lib.php'] = <<<'LIB_FILE'
<?php
declare(strict_types=1);

/* ================================================================
   Core library: DB connection, helpers, banner engine,
   payments workflow, image upload validation.
   ================================================================ */

function gb_db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        if (function_exists('gb_pdo_factory')) {   // test/dev hook (config.local.php)
            $pdo = gb_pdo_factory();
            return $pdo;
        }
        $pdo = new PDO(
            'mysql:host=' . GB_DB_HOST . ';dbname=' . GB_DB_NAME . ';charset=utf8mb4',
            GB_DB_USER, GB_DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
             PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
        // PHP runs in UTC (config.php); make MySQL's NOW()/CURDATE() match,
        // otherwise go.php's strtotime() checks disagree with the SQL ones.
        $pdo->exec("SET time_zone = '+00:00'");
    }
    return $pdo;
}

function e(?string $s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function gb_session(): void {
    if (session_status() === PHP_SESSION_NONE) session_start();
}

function gb_csrf(): string {
    gb_session();
    if (empty($_SESSION['gb_csrf'])) $_SESSION['gb_csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['gb_csrf'];
}

function gb_csrf_field(): string {
    return '<input type="hidden" name="csrf" value="' . e(gb_csrf()) . '">';
}

function gb_csrf_ok(): bool {
    gb_session();
    $token = $_SESSION['gb_csrf'] ?? '';
    return $token !== '' && is_string($_POST['csrf'] ?? null)
        && hash_equals($token, $_POST['csrf']);
}

function gb_flash(string $msg): void { gb_session(); $_SESSION['gb_flash'][] = $msg; }
function gb_flashes(): array {
    gb_session(); $f = $_SESSION['gb_flash'] ?? [];
    unset($_SESSION['gb_flash']); return $f;
}

function gb_redirect(string $url): void { header('Location: ' . $url); exit; }

/** HMAC-signed preview link (lets advertisers view their banner privately). */
function gb_sig(int $id): string {
    return substr(hash_hmac('sha256', 'gb-preview:' . $id, GB_ADMIN_PASSWORD), 0, 24);
}

function gb_money(float $v): string { return number_format($v, 2) . ' ' . GB_CURRENCY; }

function gb_mail(string $to, string $subject, string $body): bool {
    if ($to === '') return false;
    return @mail($to, $subject, $body,
        "From: " . GB_MAIL_FROM . "\r\nContent-Type: text/plain; charset=UTF-8\r\n");
}

/* ---------------- banner engine ---------------- */

function gb_find_spot_by_slug(string $slug): ?array {
    $st = gb_db()->prepare("SELECT * FROM gb_spots WHERE slug=? AND is_active=1");
    $st->execute([$slug]);
    return $st->fetch() ?: null;
}

function gb_get_banner(int $id): ?array {
    $st = gb_db()->prepare(
        "SELECT b.*, s.name AS spot_name, s.slug AS spot_slug,
                s.price AS spot_price, s.period_days AS spot_period
         FROM gb_banners b JOIN gb_spots s ON s.id = b.spot_id WHERE b.id=?");
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/** Random rotation among eligible banners in a spot. */
function gb_pick_banner(int $spot_id): ?array {
    $st = gb_db()->prepare(
        "SELECT * FROM gb_banners
         WHERE spot_id=? AND status='active' AND paid=1
           AND (starts_at IS NULL OR starts_at<=NOW())
           AND (ends_at   IS NULL OR ends_at>=NOW())
         ORDER BY RAND() LIMIT 1");
    $st->execute([$spot_id]);
    return $st->fetch() ?: null;
}

function gb_banner_html(array $b): string {
    if ($b['kind'] === 'html') return (string)$b['html']; // admin-authored, trusted
    $url  = GB_BASE_URL . '/' . ltrim((string)$b['image_path'], '/');
    $href = GB_BASE_URL . '/go.php?id=' . (int)$b['id'];
    return sprintf(
        '<a href="%s" target="_blank" rel="nofollow sponsored noopener">'
      . '<img src="%s" width="%d" height="%d" alt="Advertisement" '
      . 'loading="lazy" border="0" style="display:block;max-width:100%%;"></a>',
        e($href), e($url), (int)$b['width'], (int)$b['height']);
}

/** Track an event. $col: 'impressions' or 'clicks'. */
function gb_track(string $col, int $banner_id): void {
    if (!in_array($col, ['impressions', 'clicks'], true)) return;
    $db = gb_db();
    $db->prepare("UPDATE gb_banners SET `$col`=`$col`+1 WHERE id=?")->execute([$banner_id]);
    $db->prepare("INSERT INTO gb_stats (banner_id, day, `$col`) VALUES (?,CURDATE(),1)
                  ON DUPLICATE KEY UPDATE `$col`=`$col`+1")->execute([$banner_id]);
}

/** Expire banners whose paid period has ended. */
function gb_expire_check(): void {
    gb_db()->exec("UPDATE gb_banners SET status='expired'
                   WHERE status='active' AND ends_at IS NOT NULL AND ends_at<NOW()");
}

/* ---------------- purchase / approval workflow ---------------- */

function gb_mark_paid(int $banner_id, string $gateway, string $ref, float $amount): void {
    $db = gb_db();
    $b  = gb_get_banner($banner_id);
    if (!$b || (int)$b['paid'] === 1) return; // idempotent
    // Atomic flip: if two notifications race, only one proceeds.
    $flip = $db->prepare("UPDATE gb_banners SET paid=1 WHERE id=? AND paid=0");
    $flip->execute([$banner_id]);
    if ($flip->rowCount() === 0) return;

    // Stripe: pay-stripe.php already recorded a pending order -> mark it paid.
    $upd = $db->prepare("UPDATE gb_orders SET status='paid', amount=?
                         WHERE banner_id=? AND gateway=? AND txn_ref=? AND status='pending'");
    $upd->execute([$amount, $banner_id, $gateway, $ref]);
    if ($upd->rowCount() === 0) {
        $db->prepare("INSERT INTO gb_orders (banner_id,gateway,amount,currency,status,txn_ref)
                      VALUES (?,?,?,?,'paid',?)")
           ->execute([$banner_id, $gateway, $amount, GB_CURRENCY, $ref]);
    }

    // email notifications to admin + buyer
    gb_mail(GB_ADMIN_EMAIL, 'New paid banner #' . $banner_id,
        "Advertiser: {$b['advertiser_name']} <{$b['advertiser_email']}>\n"
      . "Spot: {$b['spot_name']} — " . gb_money((float)$b['spot_price'])
      . " for {$b['spot_period']} days.\n"
      . (GB_AUTO_APPROVE ? "Auto-approval is ON; banner is live."
                         : "Approval required: open the admin panel to publish."));
    gb_mail((string)$b['advertiser_email'], 'Thank you for your purchase',
        "Hi {$b['advertiser_name']},\n\nWe received " . gb_money($amount)
      . " for the '{$b['spot_name']}' ad slot.\n"
      . (GB_AUTO_APPROVE ? "Your banner is now live."
                         : "It will go online after a short review (within 24h)."));

    if (GB_AUTO_APPROVE) gb_approve($banner_id);
}

/** @return string '' on success, otherwise why the banner was not approved. */
function gb_approve(int $banner_id): string {
    $db = gb_db();
    $b  = gb_get_banner($banner_id);
    if (!$b)                      return 'banner not found';
    if ((int)$b['paid'] !== 1)    return 'it is unpaid — use "Mark paid" first if payment was received another way';
    if ($b['status'] === 'active') return 'it is already active';
    $db->prepare("UPDATE gb_banners SET status='active', starts_at=NOW(),
                  ends_at=DATE_ADD(NOW(), INTERVAL ? DAY) WHERE id=?")
       ->execute([(int)$b['spot_period'], $banner_id]);
    gb_mail((string)$b['advertiser_email'], 'Your banner is now live',
        "Your banner in '{$b['spot_name']}' is online for {$b['spot_period']} days.");
    return '';
}

/* ---------------- image uploads ----------------
   Advertisers: images only, content sniffed via getimagesize().
   Admins may additionally use custom HTML banners. */

/** Ensure uploads/ can never execute scripts (written even if dir pre-exists). */
function gb_protect_uploads(string $dir): void {
    $f = $dir . '/.htaccess';
    if (is_file($f)) return;
    @file_put_contents($f,
        "Options -Indexes -ExecCGI\n"
      . "RemoveHandler .php .phtml .phar\nRemoveType .php .phtml .phar\n"
      . "<IfModule mod_php.c>\nphp_flag engine off\n</IfModule>\n"
      . "<FilesMatch \"\\.(?i:php\\d?|phtml|phar|html?|svg|js)$\">\nRequire all denied\n</FilesMatch>\n");
}

/** @return [relative_path, width, height] @throws RuntimeException */
function gb_save_upload(array $file): array {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK)
        throw new RuntimeException('Upload failed, please try again.');
    if ($file['size'] > GB_MAX_UPLOAD_BYTES)
        throw new RuntimeException('Image exceeds the maximum size.');
    $info = @getimagesize($file['tmp_name']);
    if ($info === false) throw new RuntimeException('File is not a valid image.');
    $extMap = ['image/png'=>'png','image/jpeg'=>'jpg','image/gif'=>'gif','image/webp'=>'webp'];
    $mime = $info['mime'] ?? '';
    if (!isset($extMap[$mime]))
        throw new RuntimeException('Only PNG, JPEG, GIF and WebP are allowed.');

    if (!is_dir(GB_UPLOAD_DIR)) mkdir(GB_UPLOAD_DIR, 0755, true);
    gb_protect_uploads(GB_UPLOAD_DIR);
    $name = bin2hex(random_bytes(10)) . '.' . $extMap[$mime];
    if (!move_uploaded_file($file['tmp_name'], GB_UPLOAD_DIR . '/' . $name))
        throw new RuntimeException('Could not store the file.');
    return ['uploads/' . $name, (int)$info[0], (int)$info[1]];
}
LIB_FILE;

$files['serve.php'] = <<<'SERVE_FILE'
<?php
declare(strict_types=1);
require __DIR__ . '/config.php';

/* ================================================================
   AJAX banner delivery. Returns JSON, so banners are pulled in
   AFTER the page renders (keeps PageSpeed happy) and the target
   URL stays hidden from crawlers (SEO-friendly, clean redirects).
   Call from any site:
     GET serve.php?spot=leaderboard
   ================================================================ */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');   // allow remote embedding
header('X-Robots-Tag: noindex, nofollow');

$spot = gb_find_spot_by_slug((string)($_GET['spot'] ?? ''));
gb_expire_check();
$banner = $spot ? gb_pick_banner((int)$spot['id']) : null;

if ($banner) gb_track('impressions', (int)$banner['id']);

echo json_encode([
    'html'   => $banner ? gb_banner_html($banner) : null,
    'width'  => $spot ? (int)$spot['width'] : 0,
    'height' => $spot ? (int)$spot['height'] : 0,
]);
SERVE_FILE;

$files['go.php'] = <<<'GO_FILE'
<?php
declare(strict_types=1);
require __DIR__ . '/config.php';

/* ================================================================
   SEO-friendly click redirect. Banners link here instead of
   directly to the target URL, so:
     - the advertiser's landing URL isn't exposed in your HTML
     - every click is counted in gb_stats
     - the hop counts as a single clean 30x redirect for crawlers
   ================================================================ */

$id = (int)($_GET['id'] ?? 0);
$b  = gb_get_banner($id);

$eligible = $b && $b['status'] === 'active' && (int)$b['paid'] === 1
    && (!$b['starts_at'] || strtotime($b['starts_at']) <= time())
    && (!$b['ends_at']   || strtotime($b['ends_at'])   >= time());

if (!$eligible || !preg_match('~^https?://~i', (string)$b['target_url'])) {
    http_response_code(404); exit('Not found.');
}

gb_track('clicks', $id);
header('X-Robots-Tag: noindex, nofollow');
header('Location: ' . $b['target_url']);
exit;
GO_FILE;

$files['embed.js.php'] = <<<'EMBED_FILE'
<?php
/* ================================================================
   Remote embed loader — works on ANY website (no backend needed).
   Paste these two snippets into any page:

     <div data-gb-spot="leaderboard"></div>
     <script src="https://ads.example.com/embed.js.php" async></script>

   Replace ads.example.com with your GB_BASE_URL. You can place
   multiple <div data-gb-spot="..."> per page (one per slot).
   ================================================================ */

require __DIR__ . '/config.php';
header('Content-Type: application/javascript; charset=utf-8');

$base = json_encode(GB_BASE_URL);
echo <<<JS
(function () {
  "use strict";
  var BASE = $base;
  function boot() {
    var nodes = document.querySelectorAll("[data-gb-spot]");
    Array.prototype.forEach.call(nodes, function (el) {
      if (el.getAttribute("data-gb-loaded")) return;
      el.setAttribute("data-gb-loaded", "1");
      fetch(BASE + "/serve.php?spot=" + encodeURIComponent(el.getAttribute("data-gb-spot")))
        .then(function (r) { return r.json(); })
        .then(function (d) { if (d.html) el.innerHTML = d.html; })
        .catch(function () {});
    });
  }
  if (document.readyState === "loading")
    document.addEventListener("DOMContentLoaded", boot);
  else boot();
})();
JS;
EMBED_FILE;

$files['advertise.php'] = <<<'ADVERTISE_FILE'
<?php
declare(strict_types=1);
require __DIR__ . '/config.php';
gb_session();

/* ================================================================
   Self-service purchase page. Advertisers pick a slot, upload
   their banner image and get redirected to a private preview +
   payment page. Link your visitors here to sell spots directly.
   ================================================================ */

$errors = [];
$spots = gb_db()->query("SELECT * FROM gb_spots WHERE is_active=1 ORDER BY price")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!gb_csrf_ok()) throw new RuntimeException('Session expired, please resubmit.');

        $spot = gb_find_spot_by_slug((string)($_POST['spot'] ?? ''));
        if (!$spot) throw new RuntimeException('Please choose an ad slot.');

        $name  = trim(is_string($_POST['name']  ?? null) ? $_POST['name']  : '');
        $email = trim(is_string($_POST['email'] ?? null) ? $_POST['email'] : '');
        $url   = trim(is_string($_POST['url']   ?? null) ? $_POST['url']   : '');

        if ($name === '' || mb_strlen($name) > 120)     throw new RuntimeException('Please enter your name.');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Please enter a valid e-mail.');
        if (!preg_match('~^https?://~i', $url) || !filter_var($url, FILTER_VALIDATE_URL) || strlen($url) > 500)
            throw new RuntimeException('URL must be a valid http:// or https:// address.');

        // Check dimensions BEFORE storing, so rejected uploads leave no files behind.
        $tmp  = $_FILES['image']['tmp_name'] ?? '';
        $dims = ($tmp !== '' && is_file($tmp)) ? @getimagesize($tmp) : false;
        if ($dims && ((int)$dims[0] !== (int)$spot['width'] || (int)$dims[1] !== (int)$spot['height']))
            throw new RuntimeException(sprintf('Image is %d×%d but the %s slot needs exactly %d×%d.',
                $dims[0], $dims[1], $spot['name'], $spot['width'], $spot['height']));

        [$path, $w, $h] = gb_save_upload($_FILES['image'] ?? []);

        gb_db()->prepare(
            "INSERT INTO gb_banners (spot_id, advertiser_name, advertiser_email,
             kind, image_path, target_url, width, height, status, paid)
             VALUES (?,?,?,'image',?,?,?,?, 'pending', 0)")
            ->execute([(int)$spot['id'], $name, $email, $path, $url, $w, $h]);

        $bid = (int)gb_db()->lastInsertId();
        gb_redirect('preview.php?id=' . $bid . '&sig=' . gb_sig($bid));
    } catch (RuntimeException $ex) {
        $errors[] = $ex->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><title>Purchase an ad slot</title>
<style>
 body{font-family:system-ui,Arial,sans-serif;background:#f4f5f7;margin:0;padding:40px 16px;display:flex;justify-content:center}
 form{background:#fff;padding:28px;border-radius:10px;box-shadow:0 2px 12px rgba(0,0,0,.08);max-width:520px;width:100%;box-sizing:border-box}
 h1{margin-top:0;font-size:22px}label{display:block;margin:14px 0 4px;font-weight:600;font-size:14px}
 input,select,button{width:100%;padding:10px;font-size:15px;box-sizing:border-box;border:1px solid #ccc;border-radius:6px}
 button{background:#2fa855;color:#fff;border:0;font-weight:bold;cursor:pointer;margin-top:18px}
 .err{color:#b00020;font-size:14px}
</style></head><body>
<form method="post" enctype="multipart/form-data">
  <h1>Purchase an ad slot</h1>
  <?php foreach ($errors as $er): ?><p class="err"><?= e($er) ?></p><?php endforeach; ?>
  <?= gb_csrf_field() ?>
  <label for="spot">Ad slot</label>
  <select name="spot" id="spot" required>
    <?php foreach ($spots as $s): ?>
      <option value="<?= e($s['slug']) ?>"><?= e($s['name']) ?> —
        <?= (int)$s['width'] ?>&times;<?= (int)$s['height'] ?>,
        <?= gb_money((float)$s['price']) ?> / <?= (int)$s['period_days'] ?> days</option>
    <?php endforeach; ?>
  </select>
  <label for="name">Your name</label>
  <input id="name" name="name" maxlength="120" required value="<?= e($_POST['name'] ?? '') ?>">
  <label for="email">E-mail</label>
  <input id="email" name="email" type="email" maxlength="190" required value="<?= e($_POST['email'] ?? '') ?>">
  <label for="url">Target URL (where clicks land)</label>
  <input id="url" name="url" type="url" maxlength="500" placeholder="https://" required value="<?= e($_POST['url'] ?? '') ?>">
  <label for="image">Banner image (PNG/JPG/GIF/WebP, max <?= (int)(GB_MAX_UPLOAD_BYTES/1048576) ?> MB)</label>
  <input id="image" name="image" type="file" accept="image/*" required>
  <button type="submit">Continue to preview</button>
  <p class="err" style="font-size:12px">Image size must match the selected slot. Banners go online after admin approval.</p>
</form>
</body></html>
ADVERTISE_FILE;

$files['preview.php'] = <<<'PREVIEW_FILE'
<?php
declare(strict_types=1);
require __DIR__ . '/config.php';
gb_session();

/* ================================================================
   Private preview page (signed link, unguessable). Shows the
   advertiser exactly how their banner looks on a page BEFORE
   publishing — with no impression tracking, so previews don't
   pollute stats. Also hosts the PayPal / Stripe payment buttons.
   ================================================================ */

$id = (int)($_GET['id'] ?? 0);
$b  = gb_get_banner($id);

if (!$b || !hash_equals(gb_sig($id), (string)($_GET['sig'] ?? ''))) {
    http_response_code(404); exit('Not found.');
}

$ppBase = GB_PAYPAL_SANDBOX ? 'https://www.sandbox.paypal.com' : 'https://www.paypal.com';
$price  = (float)$b['spot_price'];
?>
<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><title>Preview your banner</title>
<style>
 body{font-family:system-ui,Arial,sans-serif;margin:0;color:#222}
 header{background:#24325f;color:#fff;padding:14px 24px;font-weight:bold}
 main{max-width:720px;margin:24px auto;padding:0 16px;box-sizing:border-box}
 .site-demo{border:2px dashed #aab;padding:20px;text-align:center;background:#fafafa}
 .banner-slot{pointer-events:none;display:inline-block} /* preview = no tracking */
 .pay{display:flex;gap:12px;margin-top:20px;flex-wrap:wrap}
 form.paybtn{flex:1;min-width:220px}
 button{width:100%;padding:14px;font-size:16px;border:0;border-radius:6px;color:#fff;font-weight:bold;cursor:pointer}
 .pp{background:#0070ba}.st{background:#635bff}
 table{border-collapse:collapse;margin-top:16px;width:100%}td,th{border:1px solid #ddd;padding:6px 10px;text-align:left}
</style></head><body>
<header>ExampleSite.com — your ad will look like this:</header>
<main>
  <div class="site-demo">
    <p>Some example page content above the banner…</p>
    <div class="banner-slot"><?= gb_banner_html($b) ?></div>
    <p>…and below it.</p>
  </div>
  <table>
    <tr><th>Slot</th><td><?= e($b['spot_name']) ?> (<?= (int)$b['width'] ?>&times;<?= (int)$b['height'] ?>)</td></tr>
    <tr><th>Period</th><td><?= (int)$b['spot_period'] ?> days</td></tr>
    <tr><th>Price</th><td><?= gb_money($price) ?></td></tr>
  </table>

  <?php if ((int)$b['paid'] === 1): ?>
    <?php if ($b['status'] === 'active'): ?>
      <p>✅ Payment received — your banner is live until <?= e($b['ends_at']) ?> UTC.</p>
    <?php elseif ($b['status'] === 'pending'): ?>
      <p>✅ Payment received — your banner is awaiting approval.</p>
    <?php else: ?>
      <p>Payment received — banner status: <?= e($b['status']) ?>.</p>
    <?php endif; ?>
  <?php else: ?>
    <div class="pay">
      <!-- PayPal -->
      <form class="paybtn" method="post" action="<?= e($ppBase) ?>/cgi-bin/webscr" target="_top">
        <input type="hidden" name="cmd" value="_xclick">
        <input type="hidden" name="business" value="<?= e(GB_PAYPAL_BUSINESS) ?>">
        <input type="hidden" name="item_name" value="Ad slot: <?= e($b['spot_name']) ?>">
        <input type="hidden" name="amount" value="<?= e(number_format($price, 2, '.', '')) ?>">
        <input type="hidden" name="currency_code" value="<?= e(GB_CURRENCY) ?>">
        <input type="hidden" name="no_shipping" value="1">
        <input type="hidden" name="rm" value="2">
        <input type="hidden" name="custom" value="<?= (int)$b['id'] ?>">
        <input type="hidden" name="return" value="<?= e(GB_BASE_URL) ?>/preview.php?id=<?= (int)$b['id'] ?>&sig=<?= e(gb_sig((int)$b['id'])) ?>">
        <input type="hidden" name="notify_url" value="<?= e(GB_BASE_URL) ?>/ipn-paypal.php">
        <button class="pp" type="submit">Pay with PayPal</button>
      </form>
      <!-- Stripe -->
      <form class="paybtn" method="post" action="pay-stripe.php">
        <?= gb_csrf_field() ?>
        <input type="hidden" name="banner_id" value="<?= (int)$b['id'] ?>">
        <button class="st" type="submit">Pay by card (Stripe)</button>
      </form>
    </div>
  <?php endif; ?>
</main>
</body></html>
PREVIEW_FILE;

$files['pay-stripe.php'] = <<<'PAYSTRIPE_FILE'
<?php
declare(strict_types=1);
require __DIR__ . '/config.php';
gb_session();

/* ================================================================
   Starts a Stripe Checkout session using raw REST calls via
   cURL — no Composer / SDK needed. On success the advertiser is
   redirected to Stripe's hosted payment page; when they finish,
   webhook-stripe.php receives the event and marks the banner
   as paid.
   ================================================================ */

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !gb_csrf_ok()) {
    http_response_code(400); exit('Bad request.');
}

$bid = (int)($_POST['banner_id'] ?? 0);
$b   = gb_get_banner($bid);
if (!$b || (int)$b['paid'] === 1) { http_response_code(404); exit('Not found.'); }

$price  = (float)$b['spot_price'];

$fields = [
    'mode'                                    => 'payment',
    'success_url'                             => GB_BASE_URL . '/preview.php?id=' . $bid . '&sig=' . gb_sig($bid),
    'cancel_url'                              => GB_BASE_URL . '/preview.php?id=' . $bid . '&sig=' . gb_sig($bid),
    'client_reference_id'                     => (string)$bid,
    'metadata[banner_id]'                     => (string)$bid,
    'line_items[0][quantity]'                 => '1',
    'line_items[0][price_data][currency]'     => strtolower(GB_CURRENCY),
    'line_items[0][price_data][unit_amount]'  => (string)((int)round($price * 100)),
    'line_items[0][price_data][product_data][name]'
                                             => 'Ad slot: ' . $b['spot_name'] . ' (' . $b['spot_period'] . ' days)',
];

$ch = curl_init('https://api.stripe.com/v1/checkout/sessions');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => http_build_query($fields),
    CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . GB_STRIPE_SECRET],
]);
$res = json_decode((string)curl_exec($ch), true);
curl_close($ch);

if (empty($res['url']) || empty($res['id'])) {
    http_response_code(502);
    exit('Stripe error: ' . e($res['error']['message'] ?? 'could not start checkout.'));
}

// Record a pending order; marked paid by webhook-stripe.php.
gb_db()->prepare("INSERT INTO gb_orders (banner_id,gateway,amount,currency,status,txn_ref)
                  VALUES (?,'stripe',?,?,'pending',?)")
       ->execute([$bid, $price, GB_CURRENCY, $res['id']]);

gb_redirect($res['url']);
PAYSTRIPE_FILE;

$files['ipn-paypal.php'] = <<<'IPN_FILE'
<?php
declare(strict_types=1);
require __DIR__ . '/config.php';

/* ================================================================
   PayPal IPN listener. Every completed payment triggers a
   server-to-server notification here. We validate it with
   PayPal (post-back verification) before trusting anything,
   then mark the matching banner as paid.
   ================================================================ */

$raw  = file_get_contents('php://input');
$host = GB_PAYPAL_SANDBOX ? 'https://ipnpb.sandbox.paypal.com' : 'https://ipnpb.paypal.com';

// Post the exact payload back to PayPal prefixed with cmd=_notify-validate
$ch = curl_init($host . '/cgi-bin/webscr');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => 'cmd=_notify-validate&' . $raw,
    CURLOPT_TIMEOUT        => 30,
]);
$answer = trim((string)curl_exec($ch));
curl_close($ch);

if ($answer !== 'VERIFIED') { http_response_code(400); exit; }

$bid   = (int)($_POST['custom'] ?? 0);       // we set this in preview.php
$b     = $bid ? gb_get_banner($bid) : null;
$gross = (float)($_POST['mc_gross'] ?? 0);

if ($b
    && ($_POST['payment_status'] ?? '') === 'Completed'
    && ($_POST['mc_currency'] ?? '') === GB_CURRENCY
    && $gross + 0.001 >= (float)$b['spot_price']
    && strcasecmp((string)($_POST['receiver_email'] ?? ''), GB_PAYPAL_BUSINESS) === 0) {
    gb_mark_paid($bid, 'paypal', (string)($_POST['txn_id'] ?? ''), $gross);
}
http_response_code(200);
IPN_FILE;

$files['webhook-stripe.php'] = <<<'WEBHOOK_FILE'
<?php
declare(strict_types=1);
require __DIR__ . '/config.php';

/* ================================================================
   Stripe webhook listener with manual signature verification
   (no SDK). Stripe POSTs the checkout.session.completed event
   here after a successful card payment; we verify the signature,
   match the amount against the spot price, and mark the banner
   as paid.
   ================================================================ */

$payload = file_get_contents('php://input');
$sig     = (string)($_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '');

// Parse the Stripe-Signature header: "t=timestamp,v1=sig[,v1=sig2...]"
// (Stripe sends several v1 entries while a secret is being rolled.)
$t = ''; $v1s = [];
foreach (explode(',', $sig) as $kv) {
    [$k, $v] = array_pad(explode('=', $kv, 2), 2, '');
    $k = trim($k); $v = trim($v);
    if ($k === 't')  $t = $v;
    if ($k === 'v1') $v1s[] = $v;
}

$expected = hash_hmac('sha256', $t . '.' . $payload, GB_STRIPE_WEBHOOK_SECRET);
$valid = false;
foreach ($v1s as $v1) if (hash_equals($expected, $v1)) $valid = true;

// Reject replays: signature timestamp must be within 5 minutes.
if ($t === '' || !ctype_digit($t) || abs(time() - (int)$t) > 300 || !$valid) {
    http_response_code(400); exit('Bad signature.');
}

$event = json_decode($payload, true);

if (($event['type'] ?? '') === 'checkout.session.completed') {
    $s   = $event['data']['object'] ?? [];
    $bid = (int)($s['metadata']['banner_id'] ?? 0);
    $b   = $bid ? gb_get_banner($bid) : null;

    if ($b
        && ($s['payment_status'] ?? '') === 'paid'
        && ((int)($s['amount_total'] ?? 0)) >= (int)round((float)$b['spot_price'] * 100)) {
        gb_mark_paid($bid, 'stripe', (string)($s['id'] ?? ''), ((int)$s['amount_total']) / 100);
    }
}
http_response_code(200);
WEBHOOK_FILE;

$files['admin.php'] = <<<'ADMIN_FILE'
<?php
declare(strict_types=1);
require __DIR__ . '/config.php';
gb_session();
gb_expire_check();

/* ================================================================
   Admin panel: approve/reject/pause banners, manage spots and
   custom sizes, create admin banners (image OR custom HTML),
   and view statistics filtered by date range.
   ================================================================ */

/* ---- refuse to run with the shipped placeholder password ---- */
if (GB_ADMIN_PASSWORD === 'CHANGE-ME-NOW' || strlen(GB_ADMIN_PASSWORD) < 10) {
    http_response_code(503);
    exit('Admin disabled: set a strong GB_ADMIN_PASSWORD (10+ chars) in config.php first.');
}

/* ---- login ---- */
if (empty($_SESSION['gb_admin'])) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (hash_equals(GB_ADMIN_PASSWORD, is_string($_POST['password'] ?? null) ? $_POST['password'] : '')) {
            session_regenerate_id(true);        // prevent session fixation
            $_SESSION['gb_admin'] = true;
            gb_redirect('admin.php');
        }
        usleep(500000);                         // slow down password guessing
    }
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Admin login</title></head>'
       . '<body style="font-family:system-ui,sans-serif;display:flex;justify-content:center;padding-top:80px">'
       . '<form method="post"><h2>Admin login</h2>'
       . '<p><input type="password" name="password" placeholder="Password" autofocus></p>'
       . '<button>Log in</button></form></body></html>';
    exit;
}

if (($_GET['a'] ?? '') === 'logout') { unset($_SESSION['gb_admin']); gb_redirect('admin.php'); }

/* ---- actions ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!gb_csrf_ok()) { gb_flash('Session expired, action aborted.'); gb_redirect('admin.php?a=' . urlencode((string)($_POST['back'] ?? 'dashboard'))); }
    $back = (string)($_POST['back'] ?? 'dashboard');
    $db   = gb_db();
    $bid  = (int)($_POST['id'] ?? 0);
    $act  = (string)($_POST['act'] ?? '');
  try {

    if ($act === 'approve') {
        $why = gb_approve($bid);
        gb_flash($why === '' ? "Banner #$bid approved and live." : "Banner #$bid NOT approved: $why.");
    }
    if ($act === 'mark_paid') {   // offline payment (cash, check, invoice) or testing
        $b = gb_get_banner($bid);
        if ($b && (int)$b['paid'] !== 1) {
            gb_mark_paid($bid, 'manual', 'admin ' . gmdate('Y-m-d H:i'), (float)$b['spot_price']);
            gb_flash("Banner #$bid marked paid (" . gb_money((float)$b['spot_price']) . ")."
                   . (GB_AUTO_APPROVE ? '' : ' Now click Approve to publish it.'));
        } else {
            gb_flash("Banner #$bid is already paid.");
        }
    }
    if ($act === 'reject')   { $db->prepare("UPDATE gb_banners SET status='rejected' WHERE id=?")->execute([$bid]); gb_flash('Banner rejected.'); }
    if ($act === 'pause')    { $db->prepare("UPDATE gb_banners SET status='paused' WHERE id=?")->execute([$bid]); gb_flash('Banner paused.'); }
    if ($act === 'resume') {   // only a paused banner can be resumed
        $st = $db->prepare("UPDATE gb_banners SET status='active' WHERE id=? AND status='paused'");
        $st->execute([$bid]);
        gb_flash($st->rowCount() ? "Banner #$bid resumed." : "Banner #$bid is not paused, nothing to resume.");
    }
    if ($act === 'delete') {
        $b = gb_get_banner($bid);
        if ($b && $b['kind'] === 'image' && $b['image_path'])
            @unlink(GB_UPLOAD_DIR . '/' . basename($b['image_path']));
        $db->prepare("DELETE FROM gb_banners WHERE id=?")->execute([$bid]);
        gb_flash('Banner deleted.');
    }
    if ($act === 'spot_save') { // create or update spot (8 presets + custom sizes)
        $sid   = (int)($_POST['spot_id'] ?? 0);
        $vals  = [(string)$_POST['slug'], (string)$_POST['name'],
                  (int)$_POST['width'], (int)$_POST['height'],
                  (float)$_POST['price'], (int)$_POST['period_days']];
        if ($sid) {
            $db->prepare("UPDATE gb_spots SET slug=?,name=?,width=?,height=?,price=?,period_days=? WHERE id=?")
               ->execute(array_merge($vals, [$sid]));
        } else {
            $db->prepare("INSERT INTO gb_spots (slug,name,width,height,price,period_days) VALUES (?,?,?,?,?,?)")
               ->execute($vals);
        }
        gb_flash('Spot saved.');
    }
    if ($act === 'spot_delete') {
        $db->prepare("DELETE FROM gb_spots WHERE id=?")->execute([(int)$_POST['id']]);
        gb_flash('Spot deleted.');
    }
    if ($act === 'banner_create') { // admin banners: images OR custom HTML
        $kind = ($_POST['kind'] ?? '') === 'html' ? 'html' : 'image';
        $w = (int)$_POST['width']; $h = (int)$_POST['height'];
        $path = null;
        if ($kind === 'image') {
            [$path, $w, $h] = gb_save_upload($_FILES['image'] ?? []);
        }
        $db->prepare("INSERT INTO gb_banners (spot_id,kind,image_path,html,target_url,width,height,status,paid)
                      VALUES (?,?,?,?,?,?,?, 'active', 1)")
           ->execute([(int)$_POST['spot_id'], $kind, $path,
                      (string)($_POST['html'] ?? ''), (string)($_POST['url'] ?? ''),
                      $w, $h]);
        gb_flash('Banner created and activated.');
    }
  } catch (RuntimeException | PDOException $ex) {
    gb_flash('Error: ' . $ex->getMessage());
  }
    gb_redirect('admin.php?a=' . urlencode($back));
}

$a  = (string)($_GET['a'] ?? 'dashboard');
$db = gb_db();

function gb_layout(string $title, string $active, string $body): void {
    $nav = ['dashboard' => 'Dashboard', 'banners' => 'Banners', 'spots' => 'Spots', 'stats' => 'Stats'];
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>' . e($title) . '</title>'
       . '<style>body{font-family:system-ui,Arial,sans-serif;margin:0;color:#222}'
       . 'nav{background:#24325f;padding:10px 20px}nav a{color:#cfe0ff;margin-right:16px;text-decoration:none}'
       . 'nav .on{color:#fff;font-weight:bold}main{padding:24px;max-width:1100px;margin:auto}'
       . 'table{border-collapse:collapse;width:100%;margin:16px 0;background:#fff}'
       . 'td,th{border:1px solid #ddd;padding:7px 10px;font-size:14px;text-align:left}'
       . 'form.ib{display:inline}.flashes p{background:#e6f6ea;border:1px solid #2fa855;padding:6px 12px;border-radius:6px}'
       . 'input,select,textarea{padding:6px;border:1px solid #ccc;border-radius:4px;max-width:340px}'
       . 'button{padding:5px 10px;cursor:pointer}</style></head><body><nav>';
    foreach ($nav as $k => $label)
        echo '<a class="' . ($k === $active ? 'on' : '') . '" href="?a=' . $k . '">' . $label . '</a>';
    echo '<a href="?a=logout" style="float:right;color:#ffb3b3">Logout</a></nav><main>'
       . '<div class="flashes">';
    foreach (gb_flashes() as $f) echo '<p>' . e($f) . '</p>';
    echo '</div><h2>' . e($title) . '</h2>' . $body . '</main></body></html>';
}

/* ---- dashboard ---- */
if ($a === 'dashboard') {
    $counts = [];
    foreach (['pending', 'active', 'expired', 'rejected'] as $st)
        $counts[$st] = (int)$db->query("SELECT COUNT(*) FROM gb_banners WHERE status='$st'")->fetchColumn();
    $revenue = (float)$db->query("SELECT COALESCE(SUM(amount),0) FROM gb_orders WHERE status='paid'")->fetchColumn();
    $body = "<table><tr><th>Pending approval</th><td>{$counts['pending']}</td>"
          . "<th>Active</th><td>{$counts['active']}</td>"
          . "<th>Expired</th><td>{$counts['expired']}</td>"
          . "<th>Rejected</th><td>{$counts['rejected']}</td></tr></table>"
          . '<p>Total collected revenue: <b>' . gb_money($revenue) . '</b></p>'
          . '<p>Advertise link to give advertisers: <code>' . e(GB_BASE_URL) . '/advertise.php</code></p>';
    gb_layout('Dashboard', 'dashboard', $body);
    exit;
}

/* ---- banners ---- */
if ($a === 'banners') {
    $spots = $db->query("SELECT * FROM gb_spots ORDER BY name")->fetchAll();
    $body = '<h3>New banner (admin: image or custom HTML)</h3>
      <form method="post" enctype="multipart/form-data">
        ' . gb_csrf_field() . '<input type="hidden" name="act" value="banner_create"><input type="hidden" name="back" value="banners">
        <p>Spot <select name="spot_id">' .
        implode('', array_map(fn($s) => '<option value="' . (int)$s['id'] . '">' . e($s['name']) . '</option>', $spots)) .
        '</select>
        Kind <select name="kind"><option value="image">Image</option><option value="html">HTML code</option></select></p>
        <p><input name="url" placeholder="Target URL https://…" size="50"></p>
        <p><input type="file" name="image" accept="image/*"> or HTML:
           <textarea name="html" rows="2" cols="60" placeholder="<a href=...>…</a>"></textarea></p>
        <p><input name="width" value="300" size="4"> &times; <input name="height" value="250" size="4"></p>
        <button>Create &amp; activate</button>
      </form><hr>';

    $rows = $db->query(
        "SELECT b.*, s.name spot_name FROM gb_banners b
         JOIN gb_spots s ON s.id=b.spot_id
         ORDER BY FIELD(b.status,'pending','active','paused','expired','rejected'), b.id DESC"
    )->fetchAll();

    $body .= '<table><tr><th>ID</th><th>Spot</th><th>Type</th><th>Advertiser</th><th>Status / window</th>
              <th>Impr.</th><th>Clicks</th><th>CTR</th><th>Actions</th></tr>';
    foreach ($rows as $r) {
        $ctr = $r['impressions'] > 0 ? sprintf('%.2f%%', 100 * $r['clicks'] / $r['impressions']) : '—';
        $win = $r['starts_at'] ? e($r['starts_at']) . ' → ' . e($r['ends_at']) : '—';
        $act = fn(string $act2, string $label) =>
            '<form class="ib" method="post">' . gb_csrf_field()
          . '<input type="hidden" name="act" value="' . $act2 . '">'
          . '<input type="hidden" name="back" value="banners">'
          . '<input type="hidden" name="id" value="' . (int)$r['id'] . '">'
          . '<button>' . $label . '</button></form>';
        $paid = (int)$r['paid'] === 1;
        $live = $r['status'] === 'active' && $paid;
        $serving = $live
            ? '<b style="color:#2fa855">● serving</b>'
            : '<b style="color:#b00020">○ not serving</b> <small>(' . (!$paid ? 'unpaid' : e($r['status'])) . ')</small>';
        $buttons = (!$paid ? $act('mark_paid', 'Mark paid') : '')
                 . ($paid && $r['status'] !== 'active' && $r['status'] !== 'paused' ? $act('approve', 'Approve') : '')
                 . ($r['status'] === 'active' ? $act('pause', 'Pause') : '')
                 . ($r['status'] === 'paused' ? $act('resume', 'Resume') : '')
                 . ($r['status'] !== 'rejected' ? $act('reject', 'Reject') : '')
                 . $act('delete', 'Delete');
        $body .= '<tr><td>' . (int)$r['id'] . '</td><td>' . e($r['spot_name']) . '</td>'
              . '<td>' . e($r['kind']) . '</td><td>' . e($r['advertiser_name'] ?: '(admin)') . '</td>'
              . '<td>' . $serving . '<br>' . e($r['status']) . ($paid ? ' · paid 💰' : ' · <b>unpaid</b>')
              . '<br><small>' . $win . '</small></td>'
              . '<td>' . number_format((float)$r['impressions']) . '</td>'
              . '<td>' . number_format((float)$r['clicks']) . '</td><td>' . $ctr . '</td><td>'
              . $buttons . '</td></tr>';
    }
    $body .= '</table>';
    gb_layout('Banners', 'banners', $body);
    exit;
}

/* ---- spots ---- */
if ($a === 'spots') {
    $rows = $db->query("SELECT * FROM gb_spots ORDER BY name")->fetchAll();
    $body = '<table><tr><th>Slug</th><th>Name</th><th>Size</th><th>Price</th><th>Days</th><th></th></tr>';
    foreach ($rows as $r) {
        $body .= '<tr><td><code>' . e($r['slug']) . '</code></td>'
              . '<td>' . e($r['name']) . '</td><td>' . (int)$r['width'] . '&times;' . (int)$r['height'] . '</td>'
              . '<td>' . gb_money((float)$r['price']) . '</td><td>' . (int)$r['period_days'] . '</td>'
              . '<td><form class="ib" method="post">' . gb_csrf_field()
              . '<input type="hidden" name="act" value="spot_delete"><input type="hidden" name="back" value="spots">'
              . '<input type="hidden" name="id" value="' . (int)$r['id'] . '"><button>Delete</button></form></td></tr>';
    }
    $body .= '</table><h3>Add / manage sizes (8 presets + custom)</h3>
      <form method="post">' . gb_csrf_field() . '
        <input type="hidden" name="act" value="spot_save"><input type="hidden" name="back" value="spots">
        <p>Slug <input name="slug" required> Name <input name="name" required></p>
        <p>Width <input name="width" value="300" size="4"> Height <input name="height" value="250" size="4">
           Price <input name="price" value="0"> Period (days) <input name="period_days" value="30"></p>
        <button>Save spot</button>
      </form>
      <p>Embed anywhere: <code>&lt;div data-gb-spot="slug"&gt;&lt;/div&gt;</code> +
      <code>&lt;script src="' . e(GB_BASE_URL) . '/embed.js.php" async&gt;&lt;/script&gt;</code></p>';
    gb_layout('Ad spots', 'spots', $body);
    exit;
}

/* ---- stats ---- */
if ($a === 'stats') {
    $from = preg_replace('~[^0-9-]~', '', $_GET['from'] ?? date('Y-m-d', strtotime('-30 days')));
    $to   = preg_replace('~[^0-9-]~', '', $_GET['to']   ?? date('Y-m-d'));

    $perBanner = $db->prepare(
        "SELECT b.id, s.name spot_name, COALESCE(b.advertiser_name,'(admin)') adv,
                SUM(st.impressions) imp, SUM(st.clicks) clk
         FROM gb_banners b
         JOIN gb_spots s ON s.id = b.spot_id
         LEFT JOIN gb_stats st ON st.banner_id = b.id AND st.day BETWEEN ? AND ?
         GROUP BY b.id ORDER BY imp DESC");
    $perBanner->execute([$from, $to]);

    $body = '<form method="get"><input type="hidden" name="a" value="stats">
             From <input type="date" name="from" value="' . e($from) . '">
             to <input type="date" name="to" value="' . e($to) . '">
             <button>Filter</button></form>
             <table><tr><th>Banner</th><th>Spot</th><th>Advertiser</th><th>Impressions</th><th>Clicks</th><th>CTR</th></tr>';
    $ti = $tc = 0;
    foreach ($perBanner->fetchAll() as $r) {
        $imp = (int)$r['imp']; $clk = (int)$r['clk']; $ti += $imp; $tc += $clk;
        $body .= '<tr><td>#' . (int)$r['id'] . '</td><td>' . e($r['spot_name']) . '</td><td>' . e($r['adv']) . '</td>'
              . '<td>' . number_format($imp) . '</td><td>' . number_format($clk) . '</td>'
              . '<td>' . ($imp ? sprintf('%.2f%%', 100 * $clk / $imp) : '—') . '</td></tr>';
    }
    $body .= '<tr><th colspan="3">Total</th><th>' . number_format($ti) . '</th><th>' . number_format($tc)
          . '</th><th>' . ($ti ? sprintf('%.2f%%', 100 * $tc / $ti) : '—') . '</th></tr></table>';

    $daily = $db->prepare(
        "SELECT st.day, SUM(st.impressions) imp, SUM(st.clicks) clk
         FROM gb_stats st JOIN gb_banners b ON b.id = st.banner_id
         WHERE st.day BETWEEN ? AND ? GROUP BY st.day ORDER BY st.day");
    $daily->execute([$from, $to]);
    $body .= '<table><tr><th>Day</th><th>Impressions</th><th>Clicks</th></tr>';
    foreach ($daily->fetchAll() as $r)
        $body .= '<tr><td>' . e($r['day']) . '</td><td>' . number_format((float)$r['imp'])
              . '</td><td>' . number_format((float)$r['clk']) . '</td></tr>';
    $body .= '</table>';
    gb_layout('Banner statistics', 'stats', $body);
    exit;
}

gb_redirect('admin.php');
ADMIN_FILE;

/* ---------------------------------------------------------------------
   Installer logic
   --------------------------------------------------------------------- */

/** Split schema.sql into statements. Strips "-- comment" lines FIRST:
 *  previously a leading comment was glued onto the next statement, so
 *  CREATE TABLE gb_spots and the seed INSERT were silently skipped. */
function schema_statements(string $schema): array {
    $schema = preg_replace('/^\s*--.*$/m', '', $schema);
    return array_values(array_filter(array_map('trim', explode(';', $schema))));
}

if (isset($opts['print-sql'])) {
    foreach (schema_statements($files['schema.sql']) as $i => $sql)
        echo "-- [", $i + 1, "]\n", $sql, ";\n\n";
    exit(0);
}

echo "Banner Ads Manager — installer\n";
echo str_repeat('-', 60), "\n";

if (!is_dir($targetDir)) {
    if (!@mkdir($targetDir, 0755, true))
        fail("Could not create target directory: $targetDir");
    echo "Created directory: $targetDir\n";
}

if (!is_writable($targetDir))
    fail("Target directory is not writable: $targetDir");

$written = 0;
foreach ($files as $name => $content) {
    $path = $targetDir . DIRECTORY_SEPARATOR . $name;
    if (file_exists($path) && !$overwrite) {
        echo "  SKIP   $name (already exists — use --force to overwrite)\n";
        continue;
    }
    if (@file_put_contents($path, $content) === false)
        fail("Could not write: $path");
    @chmod($path, 0644);
    echo "  WROTE  $name (" . number_format(strlen($content) / 1024, 1) . " KB)\n";
    $written++;
}

// writable uploads directory + protective .htaccess (no script execution)
$uploadDir = $targetDir . DIRECTORY_SEPARATOR . 'uploads';
if (!is_dir($uploadDir)) {
    if (!@mkdir($uploadDir, 0775))
        fail("Wrote files but could not create the uploads directory: $uploadDir");
}
$htaccess = $uploadDir . DIRECTORY_SEPARATOR . '.htaccess';
if (!is_file($htaccess)) {
    @file_put_contents($htaccess,
        "Options -Indexes -ExecCGI\n"
      . "RemoveHandler .php .phtml .phar\nRemoveType .php .phtml .phar\n"
      . "<IfModule mod_php.c>\nphp_flag engine off\n</IfModule>\n"
      . "<FilesMatch \"\\.(?i:php\\d?|phtml|phar|html?|svg|js)$\">\nRequire all denied\n</FilesMatch>\n");
}
echo "  DIR    uploads/ (+ .htaccess; ensure the web server user can write to it)\n";

/* ---- optional: create database + import schema ---- */
if ($doDbImport) {
    echo "\nDatabase setup:\n";
    $host = $opts['db-host'] ?? 'localhost';
    $user = (string)$opts['db-user'];
    $pass = (string)($opts['db-pass'] ?? '');
    $name = (string)$opts['db-name'];

    try {
        $pdo = new PDO("mysql:host=$host;charset=utf8mb4", $user, $pass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

        $safeName = str_replace('`', '', $name);
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `$safeName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo->exec("USE `$safeName`");
        echo "  OK     database '$safeName' created/verified\n";

        $count = 0;
        foreach (schema_statements($files['schema.sql']) as $sql) {
            $pdo->exec($sql);
            $count++;
        }
        echo "  OK     schema imported ($count statements executed)\n";
        echo "  NOTE   update GB_DB_* in config.php to match: host=$host, db=$safeName, user=$user\n";
    } catch (Throwable $ex) {
        fail('Database setup failed: ' . $ex->getMessage());
    }
}

echo str_repeat('-', 60), "\n";
echo "Done. $written file(s) installed in: $targetDir\n\n";
echo "Next steps:\n";
echo "  1. Edit config.php  (GB_BASE_URL, GB_ADMIN_PASSWORD, DB creds,\n";
echo "                       PayPal/Stripe keys, GB_ADMIN_EMAIL)\n";
echo "  2. Upload the folder to your web server (docroot or subdir)\n";
echo "  3. If you skipped auto-import:  mysql -u USER -p DBNAME < schema.sql\n";
echo "  4. Stripe dashboard: add webhook -> <your-url>/webhook-stripe.php\n";
echo "     listening for 'checkout.session.completed'\n";
echo "  5. PayPal: enable IPN -> <your-url>/ipn-paypal.php (fallback only;\n";
echo "     notify_url is sent per-transaction automatically)\n";
echo "  6. Log in at /admin.php and create your first banner\n";
echo "  7. Embed on any site:\n";
echo "       <div data-gb-spot=\"leaderboard\"></div>\n";
echo "       <script src=\"https://YOUR-DOMAIN/embed.js.php\" async></script>\n";

function fail(string $msg): void {
    fwrite(STDERR, "\nINSTALL ERROR: $msg\nAborted.\n");
    exit(1);
}
