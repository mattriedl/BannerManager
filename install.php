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
  price DECIMAL(10,2) NOT NULL DEFAULT 10.00,
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
('medium-rectangle','Medium Rectangle',300,250,10.00,30),
('leaderboard','Leaderboard',728,90,10.00,30),
('full-banner','Full Banner',468,60,10.00,30),
('mobile-banner','Mobile Banner',320,50,10.00,30),
('skyscraper','Skyscraper',160,600,10.00,30),
('half-page','Half Page',300,600,10.00,30),
('large-rectangle','Large Rectangle',336,280,10.00,30),
('square','Square',250,250,10.00,30);
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
define('GB_BRAND_NAME', 'Kestrel Media');          // shown on every page
define('GB_BASE_URL', 'https://ads.example.com'); // no trailing slash!
define('GB_ADMIN_PASSWORD', 'CHANGE-ME-NOW');   // strong password required
define('GB_ADMIN_EMAIL', 'admin@example.com');   // receives payment alerts

// --- Uploads ---
define('GB_UPLOAD_DIR', __DIR__ . '/uploads');
define('GB_MAX_UPLOAD_BYTES', 5 * 1024 * 1024);  // 5 MB max image

// --- Moderation ---
define('GB_AUTO_APPROVE', false);  // false = banners need admin approval
define('GB_BLOCK_SELF_ADS', true); // never show a banner on the website it links to
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

/* ---------------- shared page chrome ---------------- */

if (!defined('GB_BRAND_NAME')) define('GB_BRAND_NAME', 'Kestrel Media');

/** Brand mark: three blocks of a page layout (placeholder logo). */
function gb_brand_mark(): string {
    return '<svg viewBox="0 0 26 26" aria-hidden="true"><rect x="1" y="1" width="24" height="24" rx="4" fill="currentColor"/>'
         . '<rect x="5.5" y="5.5" width="15" height="5.5" rx="1" fill="#14213D"/>'
         . '<rect x="5.5" y="13" width="6.5" height="7.5" rx="1" fill="#14213D"/>'
         . '<rect x="14" y="13" width="6.5" height="7.5" rx="1" fill="#14213D" opacity=".45"/></svg>';
}

function gb_head(string $title, string $bodyClass = ''): string {
    return '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
         . '<meta name="viewport" content="width=device-width, initial-scale=1">'
         . '<title>' . e($title) . ' | ' . e(GB_BRAND_NAME) . '</title>'
         . '<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>'
         . '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Archivo:wdth,wght@100..125,500..800&family=IBM+Plex+Sans:wght@400;500;600&display=swap">'
         . '<link rel="stylesheet" href="style.css?v=' . (int)@filemtime(__DIR__ . '/style.css') . '">'
         . '</head><body' . ($bodyClass !== '' ? ' class="' . e($bodyClass) . '"' : '') . '>';
}

/**
 * Advertiser-facing page top: navy masthead with brand, heading and the
 * order progress (1 Order details, 2 Review and pay, 3 Approval).
 */
function gb_public_open(string $title, string $lede, int $step): string {
    $steps = [1 => 'Order details', 2 => 'Review and pay', 3 => 'Approval'];
    $li = '';
    foreach ($steps as $n => $label) {
        $cls = $n < $step ? 'done' : ($n === $step ? 'current' : '');
        $li .= '<li class="' . $cls . '"' . ($n === $step ? ' aria-current="step"' : '') . '>'
             . '<span class="n">' . ($n < $step ? '&#10003;' : $n) . '</span><span class="label">' . $label . '</span></li>';
    }
    return gb_head($title)
         . '<header class="masthead"><div class="wrap">'
         . '<div class="topbar"><a class="brand" href="advertise.php">' . gb_brand_mark() . e(GB_BRAND_NAME) . '</a>'
         . '<a class="help" href="mailto:' . e(GB_ADMIN_EMAIL) . '">Questions? Contact our ad team</a></div>'
         . '<div class="intro"><h1>' . e($title) . '</h1><p class="lede">' . e($lede) . '</p></div>'
         . '<ol class="steps" aria-label="Order progress">' . $li . '</ol>'
         . '</div></header><main class="page"><div class="wrap">';
}

function gb_public_close(): string {
    return '</div></main><footer class="site-foot"><div class="wrap">'
         . '<span>&copy; ' . date('Y') . ' ' . e(GB_BRAND_NAME) . '. All advertising is reviewed before publication.</span>'
         . '<span>Payments are processed securely by PayPal and Stripe.</span>'
         . '</div></footer></body></html>';
}

/** A placement drawn to true scale, fitted inside a box of $maxW x $maxH px. */
function gb_shape(int $w, int $h, int $maxW = 168, int $maxH = 112, ?float $scale = null): string {
    $s = $scale ?? min($maxW / max($w, 1), $maxH / max($h, 1), 1);
    return sprintf('<span class="shape" style="width:%dpx;height:%dpx" aria-hidden="true"></span>',
        max(2, (int)round($w * $s)), max(2, (int)round($h * $s)));
}

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

/* ---------------- no self-ads: domain matching ---------------- */

if (!defined('GB_BLOCK_SELF_ADS')) define('GB_BLOCK_SELF_ADS', true);

/** Host name from a URL or bare host: lowercased, no port, no "www." prefix. '' if none. */
function gb_host(?string $urlOrHost): string {
    $s = trim((string)$urlOrHost);
    if ($s === '' || strtolower($s) === 'null') return '';      // Origin: null (sandboxed pages)
    if (!preg_match('~^[a-z][a-z0-9+.-]*://~i', $s)) $s = 'http://' . ltrim($s, '/');
    $h = parse_url($s, PHP_URL_HOST);
    if (!is_string($h) || $h === '') return '';
    $h = strtolower(rtrim($h, '.'));
    if (preg_match('/[^\x20-\x7e]/', $h) && function_exists('idn_to_ascii')) {   // café.com -> xn--caf-dma.com
        $a = idn_to_ascii($h, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
        if (is_string($a) && $a !== '') $h = $a;
    }
    return (string)preg_replace('/^www\d*\./', '', $h);
}

/**
 * The part of a host name that identifies one website ("registrable domain"):
 * blog.example.com -> example.com, shop.example.co.uk -> example.co.uk,
 * alice.github.io -> alice.github.io (shared hosting: each user is a site).
 */
function gb_site_key(string $host): string {
    if ($host === '' || !str_contains($host, '.') || filter_var(trim($host, '[]'), FILTER_VALIDATE_IP)) return $host;
    static $suffixes = null;
    $suffixes ??= array_flip([
        // country second-level domains
        'co.uk','org.uk','me.uk','ac.uk','gov.uk','ltd.uk','plc.uk','net.uk','sch.uk',
        'com.au','net.au','org.au','edu.au','gov.au','id.au','co.nz','org.nz','net.nz','govt.nz',
        'co.jp','ne.jp','or.jp','ac.jp','co.kr','or.kr','com.cn','net.cn','org.cn','com.hk','com.tw',
        'com.sg','com.my','com.ph','com.vn','co.th','co.id','com.pk','co.in','net.in','org.in','gov.in',
        'com.br','net.br','org.br','com.mx','org.mx','com.ar','com.co','com.pe','com.tr','co.za','org.za',
        'co.il','com.eg','com.ng','co.ke','com.ua','com.pl','com.es','co.at','or.at',
        // shared hosting platforms: every subdomain is a different owner's site
        'github.io','gitlab.io','blogspot.com','wordpress.com','wixsite.com','squarespace.com','weebly.com',
        'tumblr.com','substack.com','myshopify.com','herokuapp.com','netlify.app','vercel.app','pages.dev',
        'workers.dev','web.app','firebaseapp.com','appspot.com','azurewebsites.net','cloudfront.net',
        'onrender.com','fly.dev','glitch.me','repl.co','app.github.dev',
    ]);
    $l = explode('.', $host);
    $n = count($l);
    if ($n >= 4 && isset($suffixes[implode('.', array_slice($l, -3))])) return implode('.', array_slice($l, -4));
    if ($n >= 3 && isset($suffixes[implode('.', array_slice($l, -2))])) return implode('.', array_slice($l, -3));
    return implode('.', array_slice($l, -2));
}

/** True when two URLs/hosts belong to the same website. */
function gb_same_site(string $a, string $b): bool {
    $ha = gb_host($a); $hb = gb_host($b);
    return $ha !== '' && $hb !== '' && gb_site_key($ha) === gb_site_key($hb);
}

/** Every click destination of a banner: its target URL, plus each link inside an HTML banner. */
function gb_banner_destinations(array $b): array {
    $urls = [(string)($b['target_url'] ?? '')];
    if (($b['kind'] ?? '') === 'html' && preg_match_all('~\bhref\s*=\s*(["\']?)([^"\'\s>]+)\1~i', (string)$b['html'], $m))
        $urls = array_merge($urls, $m[2]);
    return array_values(array_filter($urls, fn($u) => gb_host($u) !== ''));
}

/** True when the banner links to the website it would be shown on. */
function gb_is_self_ad(array $b, string $site): bool {
    if (!GB_BLOCK_SELF_ADS || gb_host($site) === '') return false;
    foreach (gb_banner_destinations($b) as $u) if (gb_same_site($u, $site)) return true;
    return false;
}

/**
 * Random rotation among eligible banners in a spot. When $site (the website
 * showing the ad) is given, banners that link to that same website are skipped.
 */
function gb_pick_banner(int $spot_id, string $site = ''): ?array {
    $st = gb_db()->prepare(
        "SELECT * FROM gb_banners
         WHERE spot_id=? AND status='active' AND paid=1
           AND (starts_at IS NULL OR starts_at<=NOW())
           AND (ends_at   IS NULL OR ends_at>=NOW())
         ORDER BY RAND()");
    $st->execute([$spot_id]);
    while ($b = $st->fetch()) {
        if (!gb_is_self_ad($b, $site)) { $st->closeCursor(); return $b; }
    }
    return null;
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
     GET serve.php?spot=leaderboard&site=examplewebsite.com
   Banners whose click destination is the requesting website itself
   (same domain, subdomains included) are skipped: no self-ads.
   ================================================================ */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');   // allow remote embedding
header('X-Robots-Tag: noindex, nofollow');

// Which website is showing the ad? embed.js sends ?site=<page host>; older
// copies of the embed code don't, so fall back to what the browser reports.
$site = gb_host(is_string($_GET['site'] ?? null) ? $_GET['site'] : '');
if ($site === '') $site = gb_host((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
if ($site === '') $site = gb_host((string)($_SERVER['HTTP_REFERER'] ?? ''));

$spot = gb_find_spot_by_slug((string)($_GET['spot'] ?? ''));
gb_expire_check();
// Banners that link to $site itself are never shown there (no self-ads).
$banner = $spot ? gb_pick_banner((int)$spot['id'], $site) : null;

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
      fetch(BASE + "/serve.php?spot=" + encodeURIComponent(el.getAttribute("data-gb-spot")) +
            "&site=" + encodeURIComponent(location.hostname))
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

$files['style.css'] = <<<'STYLE_FILE'
/* ================================================================
   Banner Manager design system — shared by every page.
   Navy masthead, white panels on a cool grey field, one emerald
   accent. Placements are always drawn to true scale.
   ================================================================ */
:root {
  --ink: #14213D;        /* masthead, headings */
  --ink-2: #3B4863;      /* body text */
  --muted: #667089;      /* secondary text */
  --line: #DCE1E9;       /* borders */
  --line-2: #EBEEF3;     /* hairlines inside panels */
  --field: #F4F6F9;      /* page background */
  --paper: #FFFFFF;
  --brand: #0B6E4F;      /* emerald accent */
  --brand-ink: #085A40;
  --brand-tint: #E6F2EC;
  --danger: #B42318;  --danger-tint: #FDEDEB;
  --warn: #8A5300;    --warn-tint: #FFF3DC;
  --r-sm: 6px; --r-md: 10px;
  --font-display: "Archivo", "Arial Narrow", Arial, sans-serif;
  --font-body: "IBM Plex Sans", "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
}
*, *::before, *::after { box-sizing: border-box; }
html { -webkit-text-size-adjust: 100%; }
body { margin: 0; background: var(--field); color: var(--ink-2);
  font: 400 15px/1.55 var(--font-body); }
h1, h2, h3 { font-family: var(--font-display); font-stretch: 110%; color: var(--ink);
  line-height: 1.15; margin: 0; letter-spacing: -0.01em; }
h1 { font-size: clamp(28px, 4vw, 40px); font-weight: 750; }
h2 { font-size: 19px; font-weight: 700; }
h3 { font-size: 15px; font-weight: 650; }
p { margin: 0 0 12px; }
a { color: var(--brand); text-underline-offset: 2px; }
a:hover { color: var(--brand-ink); }
code { font: 13px/1.5 ui-monospace, "SF Mono", Consolas, monospace; background: var(--field);
  border: 1px solid var(--line-2); border-radius: 4px; padding: 1px 5px; color: var(--ink); }
:focus-visible { outline: 3px solid rgba(11,110,79,.35); outline-offset: 2px; }
.muted { color: var(--muted); }
.small { font-size: 13px; }
.num { font-family: var(--font-display); font-variant-numeric: tabular-nums; }
.wrap { max-width: 1120px; margin: 0 auto; padding: 0 24px; }
.sr-only { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }

/* ---------- brand ---------- */
.brand { display: inline-flex; align-items: center; gap: 10px; color: inherit; text-decoration: none;
  font: 700 17px/1 var(--font-display); font-stretch: 112%; letter-spacing: -0.01em; }
.brand svg { width: 26px; height: 26px; color: var(--brand); flex: none; }
.brand small { display: block; font: 400 12px/1.2 var(--font-body); letter-spacing: 0; opacity: .7; margin-top: 3px; }

/* ---------- public masthead ---------- */
.masthead { background: var(--ink); color: #fff; }
.masthead .brand svg { color: #4FC79A; }
.topbar { display: flex; align-items: center; justify-content: space-between; gap: 16px; padding: 18px 0; }
.topbar a.help { color: #C9D2E3; font-size: 14px; text-decoration: none; }
.topbar a.help:hover { color: #fff; text-decoration: underline; }
.intro { padding: 36px 0 40px; max-width: 680px; }
.intro h1 { color: #fff; margin-bottom: 12px; }
.intro .lede { color: #C9D2E3; font-size: 17px; max-width: 60ch; margin: 0; }
.steps { display: flex; gap: 0; margin: 0; padding: 0 0 0; list-style: none; border-top: 1px solid rgba(255,255,255,.12); }
.steps li { flex: 1; display: flex; align-items: center; gap: 10px; padding: 14px 0; color: #8F9AB3; font-size: 14px; }
.steps li .n { width: 24px; height: 24px; border-radius: 50%; border: 1.5px solid currentColor;
  display: grid; place-items: center; font: 600 12px/1 var(--font-body); flex: none; }
.steps li.done { color: #C9D2E3; }
.steps li.done .n { background: #4FC79A; border-color: #4FC79A; color: var(--ink); }
.steps li.current { color: #fff; font-weight: 600; box-shadow: inset 0 -3px 0 #4FC79A; }
.steps li.current .n { background: #fff; border-color: #fff; color: var(--ink); }

.page { padding: 32px 0 64px; }
.site-foot { border-top: 1px solid var(--line); padding: 22px 0 40px; color: var(--muted); font-size: 13px; }
.site-foot .wrap { display: flex; flex-wrap: wrap; justify-content: space-between; gap: 8px; }

/* ---------- panels ---------- */
.panel { background: var(--paper); border: 1px solid var(--line); border-radius: var(--r-md); padding: 28px; }
.panel + .panel { margin-top: 20px; }
.review-grid > .panel, .two-col > .panel { margin-top: 0; }
.nowrap { white-space: nowrap; }
.panel-head { display: flex; justify-content: space-between; align-items: baseline; gap: 16px; margin-bottom: 18px; }
.panel-head p { margin: 4px 0 0; color: var(--muted); font-size: 14px; }
fieldset.panel { margin: 0; min-width: 0; }
fieldset.panel legend { float: left; width: 100%; padding: 0; margin-bottom: 4px; }
fieldset.panel legend + * { clear: both; }

/* ---------- alerts ---------- */
.alert { border-radius: var(--r-sm); padding: 14px 16px; margin-bottom: 20px; border: 1px solid; font-size: 14px; }
.alert ul { margin: 6px 0 0; padding-left: 18px; }
.alert-error { background: var(--danger-tint); border-color: #F4C7C1; color: #7A1A12; }
.alert-success { background: var(--brand-tint); border-color: #BFDDCE; color: #07462F; }
.alert-info { background: #EEF2FA; border-color: #D3DCEE; color: var(--ink); }

/* ---------- forms ---------- */
label.field { display: block; margin-bottom: 18px; }
label.field > span:first-child { display: block; font-weight: 600; color: var(--ink); font-size: 14px; margin-bottom: 6px; }
.help-text { display: block; color: var(--muted); font-size: 13px; margin-top: 6px; }
input[type=text], input[type=email], input[type=url], input[type=password], input[type=number],
input[type=date], select, textarea {
  width: 100%; font: inherit; color: var(--ink); background: var(--paper);
  border: 1px solid #C5CCD8; border-radius: var(--r-sm); padding: 10px 12px; min-height: 42px; }
input:focus, select:focus, textarea:focus { outline: none; border-color: var(--brand); box-shadow: 0 0 0 3px rgba(11,110,79,.18); }
textarea { min-height: 90px; resize: vertical; font-family: ui-monospace, Consolas, monospace; font-size: 13px; }
.row { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 0 16px; }

/* ---------- buttons ---------- */
.btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; font: 600 14px/1 var(--font-body);
  border-radius: var(--r-sm); border: 1px solid transparent; padding: 11px 18px; cursor: pointer; text-decoration: none;
  min-height: 42px; white-space: nowrap; }
.btn-primary { background: var(--brand); color: #fff; }
.btn-primary:hover { background: var(--brand-ink); color: #fff; }
.btn-secondary { background: var(--paper); color: var(--ink); border-color: #C5CCD8; }
.btn-secondary:hover { border-color: var(--ink); color: var(--ink); }
.btn-dark { background: var(--ink); color: #fff; }
.btn-dark:hover { background: #0C1529; color: #fff; }
.btn-lg { font-size: 16px; padding: 14px 26px; min-height: 50px; }
.btn-sm { font-size: 13px; padding: 6px 11px; min-height: 32px; }
.btn-danger { background: var(--paper); color: var(--danger); border-color: #EBC2BD; }
.btn-danger:hover { background: var(--danger-tint); color: var(--danger); }
.btn-block { width: 100%; }

/* ---------- placement picker (the signature element) ---------- */
.placements { display: grid; grid-template-columns: repeat(auto-fill, minmax(196px, 1fr)); gap: 14px; }
.placement { position: relative; display: flex; flex-direction: column; cursor: pointer;
  border: 1px solid var(--line); border-radius: var(--r-md); background: var(--paper); padding: 14px; }
.placement input { position: absolute; opacity: 0; pointer-events: none; }
.placement:hover { border-color: #9AA6BC; }
.placement:has(input:checked) { border-color: var(--brand); box-shadow: 0 0 0 1px var(--brand); background: #FAFDFB; }
.placement:has(input:focus-visible) { outline: 3px solid rgba(11,110,79,.35); outline-offset: 2px; }
.stage { height: 132px; border-radius: var(--r-sm); margin-bottom: 12px; display: grid; place-items: center;
  background-color: var(--field);
  background-image: linear-gradient(var(--line-2) 1px, transparent 1px), linear-gradient(90deg, var(--line-2) 1px, transparent 1px);
  background-size: 12px 12px; }
.shape { display: block; background: var(--brand-tint); border: 1.5px solid var(--brand); border-radius: 2px; }
.placement:has(input:checked) .shape { background: var(--brand); }
.placement-name { font-weight: 600; color: var(--ink); }
.placement-size { color: var(--muted); font-size: 13px; }
.placement-price { margin-top: 10px; padding-top: 10px; border-top: 1px solid var(--line-2); font-size: 13px; color: var(--muted); }
.placement-price strong { font: 700 18px/1 var(--font-display); color: var(--ink); margin-right: 4px; }
.placement .tick { position: absolute; top: 10px; right: 10px; width: 20px; height: 20px; border-radius: 50%;
  border: 1.5px solid #C5CCD8; background: var(--paper); }
.placement:has(input:checked) .tick { border-color: var(--brand); background: var(--brand)
  url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 20 20'%3E%3Cpath d='M5 10.5l3 3 7-7' fill='none' stroke='white' stroke-width='2.2'/%3E%3C/svg%3E") center/14px no-repeat; }

/* ---------- order form ---------- */
.order-grid { display: grid; grid-template-columns: minmax(0, 1fr) 340px; gap: 32px; align-items: start; }
.dropzone { display: block; border: 1.5px dashed #AEB8C9; border-radius: var(--r-md); padding: 20px; text-align: center;
  background: var(--field); cursor: pointer; }
.dropzone:hover, .dropzone:focus-within { border-color: var(--brand); background: #F7FBF9; }
.dropzone input { width: 100%; font-size: 13px; color: var(--muted); }
.dropzone strong { display: block; color: var(--ink); margin-bottom: 4px; }
.creative-check { position: sticky; top: 20px; }
.creative-frame { min-height: 180px; border-radius: var(--r-sm); display: grid; place-items: center; padding: 16px;
  background-color: var(--field);
  background-image: linear-gradient(var(--line-2) 1px, transparent 1px), linear-gradient(90deg, var(--line-2) 1px, transparent 1px);
  background-size: 12px 12px; color: var(--muted); font-size: 13px; text-align: center; }
.creative-frame img { max-width: 100%; max-height: 260px; display: block; box-shadow: 0 1px 3px rgba(20,33,61,.25); }
.check-line { display: flex; gap: 8px; align-items: flex-start; font-size: 14px; margin-top: 14px; }
.check-line::before { content: ""; flex: none; width: 8px; height: 8px; border-radius: 50%; margin-top: 7px; background: #AEB8C9; }
.check-line.ok { color: #07462F; } .check-line.ok::before { background: var(--brand); }
.check-line.bad { color: var(--danger); } .check-line.bad::before { background: var(--danger); }
.form-foot { display: flex; align-items: center; justify-content: space-between; gap: 20px; flex-wrap: wrap;
  margin-top: 20px; padding-top: 20px; border-top: 1px solid var(--line); }
.form-foot p { margin: 0; max-width: 52ch; }

/* ---------- review page ---------- */
.review-grid { display: grid; grid-template-columns: minmax(0, 1fr) 360px; gap: 28px; align-items: start; }
.browser { border: 1px solid var(--line); border-radius: var(--r-md); overflow: hidden; background: var(--paper); }
.browser-bar { display: flex; align-items: center; gap: 6px; padding: 10px 14px; background: var(--field); border-bottom: 1px solid var(--line); }
.browser-bar i { width: 10px; height: 10px; border-radius: 50%; background: #CBD2DE; }
.browser-url { margin-left: 10px; flex: 1; max-width: 340px; background: var(--paper); border: 1px solid var(--line);
  border-radius: 20px; padding: 3px 12px; font-size: 12px; color: var(--muted); }
.browser-body { padding: 28px; }
.ph { height: 10px; background: #E6EAF0; border-radius: 5px; margin-bottom: 10px; }
.ph.title { height: 18px; width: 55%; background: #D3D9E3; margin-bottom: 18px; }
.ph.short { width: 70%; }
.banner-slot { display: flex; justify-content: center; margin: 22px 0; pointer-events: none; }
.banner-slot > * { max-width: 100%; }
.summary dl { margin: 0; }
.summary dl div { display: flex; justify-content: space-between; gap: 16px; padding: 10px 0; border-bottom: 1px solid var(--line-2); font-size: 14px; }
.summary dt { color: var(--muted); white-space: nowrap; }
.summary dd { margin: 0; color: var(--ink); font-weight: 500; text-align: right; overflow-wrap: anywhere; }
.total { display: flex; justify-content: space-between; align-items: baseline; padding: 16px 0 20px; }
.total span { font-weight: 600; color: var(--ink); }
.total strong { font: 750 28px/1 var(--font-display); color: var(--ink); }
.pay-options { display: grid; gap: 10px; }
.pay-options form { margin: 0; }
.btn-paypal { background: #FFC439; color: #111A33; }
.btn-paypal:hover { background: #F2B52A; color: #111A33; }
.status-box { border-radius: var(--r-sm); padding: 16px; font-size: 14px; }
.status-box strong { display: block; color: var(--ink); font-size: 15px; margin-bottom: 2px; }
.status-box.live { background: var(--brand-tint); }
.status-box.wait { background: var(--warn-tint); }
.status-box.other { background: var(--field); }
.secure-note { display: flex; gap: 8px; align-items: center; color: var(--muted); font-size: 12.5px; margin-top: 14px; }
.secure-note svg { width: 14px; height: 14px; flex: none; }

/* ---------- console (admin) ---------- */
body.console { display: grid; grid-template-columns: 248px minmax(0, 1fr); min-height: 100vh;
  background: linear-gradient(90deg, var(--ink) 248px, var(--field) 248px); }
.sidebar { background: var(--ink); color: #C9D2E3; padding: 22px 16px; display: flex; flex-direction: column; gap: 28px;
  position: sticky; top: 0; height: 100vh; }
.sidebar .brand { color: #fff; padding: 0 8px; }
.sidebar .brand svg { color: #4FC79A; }
.side-nav { display: flex; flex-direction: column; gap: 2px; }
.side-nav a { display: flex; align-items: center; gap: 12px; padding: 10px 12px; border-radius: var(--r-sm);
  color: #C9D2E3; text-decoration: none; font-size: 14.5px; }
.side-nav a svg { width: 18px; height: 18px; flex: none; opacity: .85; }
.side-nav a:hover { background: rgba(255,255,255,.06); color: #fff; }
.side-nav a.on { background: rgba(79,199,154,.14); color: #fff; font-weight: 600; box-shadow: inset 3px 0 0 #4FC79A; }
.side-nav .count { margin-left: auto; background: #4FC79A; color: var(--ink); font-size: 12px; font-weight: 700;
  border-radius: 10px; padding: 1px 8px; }
.side-foot { margin-top: auto; display: flex; flex-direction: column; gap: 2px; border-top: 1px solid rgba(255,255,255,.1); padding-top: 14px; }
.console-main { min-width: 0; }
.console-head { background: var(--paper); border-bottom: 1px solid var(--line); padding: 22px 36px;
  display: flex; justify-content: space-between; align-items: center; gap: 16px; flex-wrap: wrap; }
.console-head h1 { font-size: 26px; }
.console-head p { margin: 4px 0 0; color: var(--muted); font-size: 14px; }
.console-body { padding: 28px 36px 60px; max-width: 1240px; }

.kpis { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 24px; }
.kpi { background: var(--paper); border: 1px solid var(--line); border-radius: var(--r-md); padding: 20px 22px; }
.kpi .label { color: var(--muted); font-size: 13.5px; }
.kpi .value { font: 750 32px/1.1 var(--font-display); color: var(--ink); margin-top: 8px; font-variant-numeric: tabular-nums; }
.kpi .sub { font-size: 13px; color: var(--muted); margin-top: 4px; }
.kpi.accent { background: var(--ink); border-color: var(--ink); }
.kpi.accent .label, .kpi.accent .sub { color: #AEB8CC; }
.kpi.accent .value { color: #fff; }

.table-wrap { overflow-x: auto; border: 1px solid var(--line); border-radius: var(--r-md); background: var(--paper); }
table.data { width: 100%; border-collapse: collapse; font-size: 14px; }
table.data th { text-align: left; font-weight: 600; color: var(--muted); font-size: 13px; background: #FAFBFC;
  padding: 11px 16px; border-bottom: 1px solid var(--line); white-space: nowrap; }
table.data td { padding: 14px 16px; border-bottom: 1px solid var(--line-2); vertical-align: middle; color: var(--ink-2); }
table.data tr:last-child td { border-bottom: 0; }
table.data td.n, table.data th.n { text-align: right; font-variant-numeric: tabular-nums; }
table.data tfoot td { font-weight: 600; color: var(--ink); background: #FAFBFC; border-top: 1px solid var(--line); }
.cell-main { color: var(--ink); font-weight: 600; white-space: nowrap; }
.cell-sub { color: var(--muted); font-size: 13px; }
.thumb { width: 72px; height: 44px; border-radius: 4px; border: 1px solid var(--line); background: var(--field);
  display: grid; place-items: center; overflow: hidden; flex: none; font-size: 11px; color: var(--muted); }
.thumb img { max-width: 100%; max-height: 100%; }
.with-thumb { display: flex; align-items: center; gap: 12px; }
.actions { display: flex; flex-wrap: nowrap; gap: 6px; }
tr.editing td { background: #FAFDFB; box-shadow: inset 3px 0 0 var(--brand); padding: 20px 16px; }
.edit-row { margin: 0; }
.edit-head { display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap; margin-bottom: 16px; }
.edit-fields { display: grid; grid-template-columns: minmax(180px, 2fr) minmax(120px, 1fr) minmax(120px, 1fr) auto; gap: 0 14px; align-items: end; }
.edit-buttons { display: flex; gap: 8px; margin-bottom: 18px; }
.edit-row .help-text { margin: 0; max-width: 90ch; }
@media (max-width: 920px) { .edit-fields { grid-template-columns: 1fr 1fr; } .edit-buttons { grid-column: 1 / -1; } }
.row-actions { display: flex; flex-direction: column; align-items: flex-start; gap: 4px; }
.row-actions form { margin: 0; }
.row-actions .more { display: flex; gap: 2px; }
.btn-text { background: none; border: 0; color: var(--muted); font-weight: 500; font-size: 13px; padding: 4px 6px; min-height: 0; }
.btn-text:hover { color: var(--ink); text-decoration: underline; }
.btn-text.danger:hover { color: var(--danger); }
.actions form { margin: 0; }
.empty { text-align: center; padding: 48px 20px; color: var(--muted); }
.empty h3 { margin-bottom: 6px; }

.pill { display: inline-flex; align-items: center; gap: 6px; font-size: 12.5px; font-weight: 600;
  border-radius: 20px; padding: 3px 10px; white-space: nowrap; }
.pill::before { content: ""; width: 7px; height: 7px; border-radius: 50%; background: currentColor; }
.pill-live { background: var(--brand-tint); color: var(--brand-ink); }
.pill-off { background: #EEF0F4; color: #4D5770; }
.pill-wait { background: var(--warn-tint); color: var(--warn); }
.pill-bad { background: var(--danger-tint); color: var(--danger); }
.reason { display: block; font-size: 12.5px; color: var(--muted); margin-top: 4px; }

.bar { height: 8px; border-radius: 4px; background: var(--brand); min-width: 2px; }
.bar-track { background: var(--line-2); border-radius: 4px; width: 100%; min-width: 120px; }
.filters { display: flex; flex-wrap: wrap; gap: 12px; align-items: flex-end; }
.filters label.field { margin: 0; }
.snippet { position: relative; }
.snippet pre { margin: 0; padding-top: 48px !important; background: var(--ink); color: #DDE4F0; border-radius: var(--r-sm); padding: 16px 18px;
  font: 13px/1.6 ui-monospace, Consolas, monospace; overflow-x: auto; white-space: pre; }
.snippet .btn { position: absolute; top: 10px; right: 10px; }
.two-col { display: grid; grid-template-columns: minmax(0, 1.4fr) minmax(0, 1fr); gap: 20px; align-items: start; }
details.creator > summary { list-style: none; cursor: pointer; }
details.creator > summary::-webkit-details-marker { display: none; }
details.creator[open] > summary { margin-bottom: 18px; }

/* ---------- login ---------- */
body.login { min-height: 100vh; display: grid; place-items: center; padding: 24px;
  background: var(--ink); }
.login-card { width: 100%; max-width: 400px; background: var(--paper); border-radius: var(--r-md); padding: 36px; }
.login-card .brand { color: var(--ink); margin-bottom: 28px; }
.login-card h1 { font-size: 24px; margin-bottom: 6px; }

/* ---------- responsive ---------- */
@media (max-width: 920px) {
  .order-grid, .review-grid, .two-col { grid-template-columns: 1fr; }
  .creative-check { position: static; }
  body.console { grid-template-columns: 1fr; background: var(--field); }
  .sidebar { position: static; height: auto; flex-direction: row; flex-wrap: wrap; align-items: center; gap: 12px; padding: 14px 16px; }
  .side-nav { flex-direction: row; flex-wrap: wrap; }
  .side-foot { margin: 0 0 0 auto; border: 0; padding: 0; flex-direction: row; }
  .console-head, .console-body { padding-left: 18px; padding-right: 18px; }
}
@media (max-width: 600px) {
  .wrap { padding: 0 16px; }
  .panel { padding: 20px; }
  .steps li .label { display: none; }
  .steps li.current .label { display: inline; }
  .placements { grid-template-columns: 1fr 1fr; gap: 10px; }
  .stage { height: 100px; }
  .shape { transform: scale(.72); }
  .row { grid-template-columns: 1fr; }
  .topbar a.help { font-size: 13px; max-width: 12em; text-align: right; }
}
@media (prefers-reduced-motion: reduce) { * { scroll-behavior: auto !important; transition: none !important; } }
STYLE_FILE;

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
$spots = gb_db()->query("SELECT * FROM gb_spots WHERE is_active=1 ORDER BY price, id")->fetchAll();

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
$selected = (string)($_POST['spot'] ?? ($spots[0]['slug'] ?? ''));
$current  = null;
foreach ($spots as $s) if ($s['slug'] === $selected) $current = $s;
$current ??= $spots[0] ?? ['width' => 0, 'height' => 0];
// One scale for every tile, so placements can be compared by eye.
$scale = 1.0;
foreach ($spots as $s) $scale = min($scale, 168 / max(1, (int)$s['width']), 112 / max(1, (int)$s['height']));

echo gb_public_open('Advertise with ' . GB_BRAND_NAME,
    'Choose a placement, upload your banner and review your order. Our team checks every ad before it goes live, usually within one business day.', 1);
?>
<?php if ($errors): ?>
  <div class="alert alert-error" role="alert"><strong>Your order wasn't submitted.</strong>
    <ul><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<form method="post" enctype="multipart/form-data" id="order" novalidate>
  <?= gb_csrf_field() ?>

  <fieldset class="panel">
    <legend><div class="panel-head"><div><h2>Choose a placement</h2>
      <p>Placements are drawn to the same scale so you can compare sizes. Prices cover the full run.</p></div></div></legend>
    <div class="placements">
      <?php foreach ($spots as $s): ?>
        <label class="placement">
          <input type="radio" name="spot" value="<?= e($s['slug']) ?>" required
                 data-w="<?= (int)$s['width'] ?>" data-h="<?= (int)$s['height'] ?>" data-name="<?= e($s['name']) ?>"
                 <?= $s['slug'] === $selected ? 'checked' : '' ?>>
          <span class="tick" aria-hidden="true"></span>
          <span class="stage"><?= gb_shape((int)$s['width'], (int)$s['height'], 168, 112, $scale) ?></span>
          <span class="placement-name"><?= e($s['name']) ?></span>
          <span class="placement-size"><?= (int)$s['width'] ?> × <?= (int)$s['height'] ?> px</span>
          <span class="placement-price"><strong><?= e(number_format((float)$s['price'], 2)) ?></strong><?= e(GB_CURRENCY) ?>
            for <?= (int)$s['period_days'] ?> days</span>
        </label>
      <?php endforeach; ?>
    </div>
  </fieldset>

  <fieldset class="panel">
    <legend><div class="panel-head"><div><h2>Your banner and details</h2>
      <p>We'll send your receipt and approval notice to this e-mail address.</p></div></div></legend>
    <div class="order-grid">
      <div>
        <div class="row">
          <label class="field"><span>Name or company</span>
            <input type="text" name="name" maxlength="120" required autocomplete="organization" value="<?= e(is_string($_POST['name'] ?? null) ? $_POST['name'] : '') ?>"></label>
          <label class="field"><span>E-mail</span>
            <input type="email" name="email" maxlength="190" required autocomplete="email" value="<?= e(is_string($_POST['email'] ?? null) ? $_POST['email'] : '') ?>"></label>
        </div>
        <label class="field"><span>Where should clicks go?</span>
          <input type="url" name="url" maxlength="500" placeholder="https://www.yourcompany.com/offer" required value="<?= e(is_string($_POST['url'] ?? null) ? $_POST['url'] : '') ?>">
          <span class="help-text">The full address of your landing page, starting with https://</span></label>
        <label class="dropzone" for="image">
          <strong>Upload your banner image</strong>
          <span class="muted small">PNG, JPEG, GIF or WebP, up to <?= (int)(GB_MAX_UPLOAD_BYTES / 1048576) ?> MB</span>
          <input id="image" name="image" type="file" accept="image/png,image/jpeg,image/gif,image/webp" required>
        </label>
      </div>
      <aside class="creative-check" aria-live="polite">
        <div class="creative-frame" id="creativeFrame">Your banner will appear here</div>
        <p class="check-line" id="sizeCheck"><span id="sizeMsg">This placement needs an image of exactly
          <strong><?= (int)$current['width'] ?> × <?= (int)$current['height'] ?> px</strong>.</span></p>
      </aside>
    </div>
    <div class="form-foot">
      <p class="muted small">Next you'll see a preview and choose how to pay. You won't be charged yet.</p>
      <button class="btn btn-primary btn-lg" type="submit">Continue to preview</button>
    </div>
  </fieldset>
</form>

<script>
(function () {
  var form = document.getElementById("order"), file = document.getElementById("image"),
      frame = document.getElementById("creativeFrame"), line = document.getElementById("sizeCheck"),
      msg = document.getElementById("sizeMsg"), img = null;
  function spot() { return form.querySelector('input[name="spot"]:checked'); }
  function check() {
    var s = spot(); if (!s) return;
    var w = +s.dataset.w, h = +s.dataset.h;
    var size = w + " \u00d7 " + h + " px";
    line.className = "check-line";
    if (!img) { msg.innerHTML = 'This placement needs an image of exactly <strong>' + size + '</strong>.'; return; }
    var ok = img.naturalWidth === w && img.naturalHeight === h;
    line.className = "check-line " + (ok ? "ok" : "bad");
    msg.textContent = ok
      ? "Size matches the " + s.dataset.name + " placement (" + w + " × " + h + " px)."
      : "Your image is " + img.naturalWidth + " × " + img.naturalHeight + " px, but the " + s.dataset.name +
        " placement needs exactly " + w + " × " + h + " px. Choose another placement or resize the image.";
  }
  form.addEventListener("change", function (ev) { if (ev.target.name === "spot") check(); });
  file.addEventListener("change", function () {
    var f = file.files[0]; img = null; frame.textContent = "Your banner will appear here";
    if (!f) return check();
    var i = new Image();
    i.onload = function () { img = i; frame.textContent = ""; frame.appendChild(i); check(); };
    i.onerror = function () { frame.textContent = "This file isn't an image we can read."; };
    i.src = URL.createObjectURL(f); i.alt = "Your banner";
  });
})();
</script>
<?= gb_public_close() ?>
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
$paid = (int)$b['paid'] === 1;
echo gb_public_open('Review your order',
    'This is how your banner will look on our pages. Check the details, then choose how to pay.', $paid ? 3 : 2);
?>
<div class="review-grid">
  <section class="panel">
    <div class="panel-head"><div><h2>Preview</h2>
      <p>Shown in a sample page. Views and clicks here are not counted.</p></div></div>
    <div class="browser">
      <div class="browser-bar"><i></i><i></i><i></i><span class="browser-url"><?= e(parse_url(GB_BASE_URL, PHP_URL_HOST) ?: 'example.com') ?></span></div>
      <div class="browser-body" aria-hidden="true">
        <div class="ph title"></div><div class="ph"></div><div class="ph"></div><div class="ph short"></div>
        <div class="banner-slot"><?= gb_banner_html($b) ?></div>
        <div class="ph"></div><div class="ph"></div><div class="ph short"></div>
      </div>
    </div>
  </section>

  <aside class="panel summary">
    <div class="panel-head"><div><h2>Order summary</h2><p>Order #<?= (int)$b['id'] ?></p></div></div>
    <dl>
      <div><dt>Placement</dt><dd><?= e($b['spot_name']) ?></dd></div>
      <div><dt>Size</dt><dd><?= (int)$b['width'] ?> × <?= (int)$b['height'] ?> px</dd></div>
      <div><dt>Run length</dt><dd><?= (int)$b['spot_period'] ?> days from approval</dd></div>
      <div><dt>Advertiser</dt><dd><?= e($b['advertiser_name']) ?></dd></div>
      <div><dt>Clicks go to</dt><dd><?= e($b['target_url']) ?></dd></div>
    </dl>
    <div class="total"><span>Total</span><strong><?= gb_money($price) ?></strong></div>

    <?php if ($paid): ?>
      <?php if ($b['status'] === 'active'): ?>
        <div class="status-box live"><strong>Your banner is live</strong>
          Payment received. It runs until <?= e($b['ends_at']) ?> UTC.</div>
      <?php elseif ($b['status'] === 'pending'): ?>
        <div class="status-box wait"><strong>Payment received</strong>
          Your banner is awaiting approval. We'll e-mail you when it goes live.</div>
      <?php else: ?>
        <div class="status-box other"><strong>Payment received</strong>
          Banner status: <?= e($b['status']) ?>. Contact us if you have questions.</div>
      <?php endif; ?>
    <?php else: ?>
      <div class="pay-options">
        <form method="post" action="pay-stripe.php">
          <?= gb_csrf_field() ?>
          <input type="hidden" name="banner_id" value="<?= (int)$b['id'] ?>">
          <button class="btn btn-dark btn-lg btn-block" type="submit">Pay by card</button>
        </form>
        <form method="post" action="<?= e($ppBase) ?>/cgi-bin/webscr" target="_top">
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
          <button class="btn btn-paypal btn-lg btn-block" type="submit">Pay with PayPal</button>
        </form>
      </div>
      <p class="secure-note"><svg viewBox="0 0 16 16" aria-hidden="true"><path d="M4 7V5a4 4 0 0 1 8 0v2h1v8H3V7h1zm2 0h4V5a2 2 0 0 0-4 0v2z" fill="currentColor"/></svg>
        Card payments are handled by Stripe. We never see your card number.</p>
    <?php endif; ?>
    <p class="muted small" style="margin:16px 0 0">Bookmark this page to check your order status later.</p>
  </aside>
</div>
<?= gb_public_close() ?>
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
        $loginError = true;
    }
    echo gb_head('Admin login', 'login')
       . '<main class="login-card"><a class="brand" href="advertise.php">' . gb_brand_mark()
       . '<span>' . e(GB_BRAND_NAME) . '<small>Publisher console</small></span></a>'
       . '<h1>Admin login</h1><p class="muted">Sign in to review orders and manage placements.</p>'
       . (!empty($loginError) ? '<div class="alert alert-error" role="alert">That password is not correct.</div>' : '')
       . '<form method="post"><label class="field"><span>Password</span>'
       . '<input type="password" name="password" autocomplete="current-password" required autofocus></label>'
       . '<button class="btn btn-primary btn-block btn-lg" type="submit">Sign in</button></form></main></body></html>';
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
    // Shared checks for a placement's name, price and run length.
    $spotTerms = function (): array {
        $name   = trim(is_string($_POST['name'] ?? null) ? $_POST['name'] : '');
        $price  = is_numeric($_POST['price'] ?? null) ? round((float)$_POST['price'], 2) : -1;
        $period = filter_var($_POST['period_days'] ?? null, FILTER_VALIDATE_INT);
        if ($name === '' || mb_strlen($name) > 120) throw new RuntimeException('Enter a name of up to 120 characters.');
        if ($price < 0 || $price > 99999999)       throw new RuntimeException('Enter a price of 0 or more.');
        if ($period === false || $period < 1 || $period > 3650)
            throw new RuntimeException('Enter a run length between 1 and 3650 days.');
        return [$name, $price, (int)$period];
    };

    if ($act === 'spot_save') { // add a new placement (custom size)
        [$name, $price, $period] = $spotTerms();
        $slug = strtolower(trim(is_string($_POST['slug'] ?? null) ? $_POST['slug'] : ''));
        $w = (int)($_POST['width'] ?? 0); $h = (int)($_POST['height'] ?? 0);
        if (!preg_match('/^[a-z0-9-]{1,60}$/', $slug))
            throw new RuntimeException('Embed name can only use lowercase letters, numbers and dashes.');
        if ($w < 1 || $h < 1 || $w > 5000 || $h > 5000) throw new RuntimeException('Enter a width and height between 1 and 5000 px.');
        $dup = $db->prepare("SELECT COUNT(*) FROM gb_spots WHERE slug=?"); $dup->execute([$slug]);
        if ((int)$dup->fetchColumn() > 0) throw new RuntimeException("A placement with the embed name \"$slug\" already exists.");
        $db->prepare("INSERT INTO gb_spots (slug,name,width,height,price,period_days) VALUES (?,?,?,?,?,?)")
           ->execute([$slug, $name, $w, $h, $price, $period]);
        gb_flash("Placement \"$name\" added.");
    }
    if ($act === 'spot_update') { // edit name, price and run length of an existing placement
        $sid  = (int)($_POST['id'] ?? 0);
        $spot = $db->prepare("SELECT * FROM gb_spots WHERE id=?"); $spot->execute([$sid]);
        $old  = $spot->fetch();
        if (!$old) throw new RuntimeException('That placement no longer exists.');
        try {
            [$name, $price, $period] = $spotTerms();
        } catch (RuntimeException $ex) {
            gb_flash('Error: ' . $ex->getMessage());
            gb_redirect('admin.php?a=spots&edit=' . $sid);
        }
        $db->prepare("UPDATE gb_spots SET name=?, price=?, period_days=? WHERE id=?")
           ->execute([$name, $price, $period, $sid]);
        gb_flash("Placement \"$name\" updated: " . gb_money($price) . " for $period days. "
               . "New and unpaid orders use these terms.");
    }
    if ($act === 'spot_delete') {
        $db->prepare("DELETE FROM gb_spots WHERE id=?")->execute([(int)$_POST['id']]);
        gb_flash('Placement deleted.');
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

function gb_icon(string $name): string {
    $p = [
        'dashboard' => '<rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/>',
        'banners'   => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 15l5-4 4 3 3-2 6 4"/><circle cx="15.5" cy="9" r="1.5"/>',
        'spots'     => '<rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M9 9v12"/>',
        'stats'     => '<path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>',
        'external'  => '<path d="M14 4h6v6M20 4l-9 9M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5"/>',
        'logout'    => '<path d="M15 4h4a1 1 0 0 1 1 1v14a1 1 0 0 1-1 1h-4M10 17l5-5-5-5M15 12H3"/>',
    ][$name] ?? '';
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p . '</svg>';
}

function gb_layout(string $title, string $active, string $body, string $intro = '', string $headActions = ''): void {
    $waiting = (int)gb_db()->query("SELECT COUNT(*) FROM gb_banners WHERE status='pending' AND paid=1")->fetchColumn();
    $nav = ['dashboard' => 'Dashboard', 'banners' => 'Banners', 'spots' => 'Placements', 'stats' => 'Analytics'];
    $links = '';
    foreach ($nav as $k => $label)
        $links .= '<a href="?a=' . $k . '"' . ($k === $active ? ' class="on" aria-current="page"' : '') . '>'
                . gb_icon($k) . $label
                . ($k === 'banners' && $waiting ? '<span class="count" title="Paid, waiting for approval">' . $waiting . '</span>' : '')
                . '</a>';
    $flashes = '';
    foreach (gb_flashes() as $f) {
        $bad = (bool)preg_match('/^Error|NOT |not paused|already/', $f);
        $flashes .= '<div class="alert ' . ($bad ? 'alert-error' : 'alert-success') . '" role="status">' . e($f) . '</div>';
    }
    echo gb_head($title, 'console')
       . '<aside class="sidebar"><a class="brand" href="?a=dashboard">' . gb_brand_mark()
       . '<span>' . e(GB_BRAND_NAME) . '<small>Publisher console</small></span></a>'
       . '<nav class="side-nav" aria-label="Console">' . $links . '</nav>'
       . '<div class="side-nav side-foot"><a href="advertise.php" target="_blank">' . gb_icon('external') . 'Advertiser page</a>'
       . '<a href="?a=logout">' . gb_icon('logout') . 'Log out</a></div></aside>'
       . '<div class="console-main"><header class="console-head"><div><h1>' . e($title) . '</h1>'
       . ($intro !== '' ? '<p>' . e($intro) . '</p>' : '') . '</div>' . $headActions . '</header>'
       . '<div class="console-body">' . $flashes . $body . '</div></div></body></html>';
}

/** Small POST button for a banner/spot action. */
function gb_action(string $act, int $id, string $label, string $back, string $class = 'btn-secondary', string $confirm = ''): string {
    return '<form method="post"' . ($confirm !== '' ? ' onsubmit="return confirm(' . e(json_encode($confirm)) . ')"' : '') . '>'
         . gb_csrf_field()
         . '<input type="hidden" name="act" value="' . e($act) . '"><input type="hidden" name="back" value="' . e($back) . '">'
         . '<input type="hidden" name="id" value="' . $id . '">'
         . '<button class="btn btn-sm ' . $class . '" type="submit">' . e($label) . '</button></form>';
}

function gb_status_cell(array $r): string {
    $paid = (int)$r['paid'] === 1;
    $end  = $r['ends_at'] ? date('M j, Y', strtotime((string)$r['ends_at'])) : '';
    if ($r['status'] === 'active' && $paid)
        return '<span class="pill pill-live">Serving</span>' . ($end ? ' <span class="reason">Until ' . e($end) . '</span>' : '');
    $reason = !$paid ? 'Unpaid' : [
        'pending' => 'Paid, waiting for approval', 'paused' => 'Paused' . ($end ? ', ends ' . $end : ''),
        'expired' => 'Run ended' . ($end ? ' ' . $end : ''), 'rejected' => 'Rejected',
    ][$r['status']] ?? ucfirst((string)$r['status']);
    $pill = $paid && $r['status'] === 'pending' ? 'pill-wait' : ($r['status'] === 'rejected' ? 'pill-bad' : 'pill-off');
    return '<span class="pill ' . $pill . '">Not serving</span> <span class="reason">' . e($reason) . '</span>';
}

function gb_thumb(array $r): string {
    if ($r['kind'] === 'html') return '<span class="thumb">HTML</span>';
    return '<span class="thumb"><img src="' . e((string)$r['image_path']) . '" alt="" loading="lazy"></span>';
}

/** One main action for the banner's current state, with the rest as small text buttons. */
function gb_banner_actions(array $r, string $back): string {
    $id = (int)$r['id']; $paid = (int)$r['paid'] === 1; $st = $r['status'];
    $main = !$paid ? gb_action('mark_paid', $id, 'Mark paid', $back)
          : ($st === 'active' ? gb_action('pause', $id, 'Pause', $back)
          : ($st === 'paused' ? gb_action('resume', $id, 'Resume', $back)
          : gb_action('approve', $id, 'Approve', $back, 'btn-primary')));
    return '<div class="row-actions">' . $main . '<div class="more">'
         . ($st !== 'rejected' ? gb_action('reject', $id, 'Reject', $back, 'btn-text') : '')
         . gb_action('delete', $id, 'Delete', $back, 'btn-text danger', 'Delete banner #' . $id . ' and its statistics? This cannot be undone.')
         . '</div></div>';
}

function gb_snippet(string $code): string {
    return '<div class="snippet"><pre>' . e($code) . '</pre>'
         . '<button class="btn btn-sm btn-secondary" type="button" onclick="navigator.clipboard&&navigator.clipboard.writeText(this.previousElementSibling.textContent).then(()=>{this.textContent=\'Copied\'})">Copy</button></div>';
}

$embedCode = '<div data-gb-spot="leaderboard"></div>' . "\n"
           . '<script src="' . GB_BASE_URL . '/embed.js.php" async></script>';

/* ---- dashboard ---- */
if ($a === 'dashboard') {
    $q = fn(string $sql) => (int)$db->query($sql)->fetchColumn();
    $waiting = $q("SELECT COUNT(*) FROM gb_banners WHERE status='pending' AND paid=1");
    $serving = $q("SELECT COUNT(*) FROM gb_banners WHERE status='active' AND paid=1");
    $unpaid  = $q("SELECT COUNT(*) FROM gb_banners WHERE paid=0 AND status NOT IN ('rejected')");
    $revenue = (float)$db->query("SELECT COALESCE(SUM(amount),0) FROM gb_orders WHERE status='paid'")->fetchColumn();
    $todayImp = $q("SELECT COALESCE(SUM(impressions),0) FROM gb_stats WHERE day=CURDATE()");

    $body = '<div class="kpis">'
          . '<div class="kpi accent"><div class="label">Revenue collected</div><div class="value">' . e(gb_money($revenue)) . '</div><div class="sub">All paid orders</div></div>'
          . '<div class="kpi"><div class="label">Waiting for approval</div><div class="value">' . $waiting . '</div><div class="sub">Paid orders to review</div></div>'
          . '<div class="kpi"><div class="label">Serving now</div><div class="value">' . $serving . '</div><div class="sub">' . number_format($todayImp) . ' views today</div></div>'
          . '<div class="kpi"><div class="label">Unpaid orders</div><div class="value">' . $unpaid . '</div><div class="sub">Started but not paid</div></div>'
          . '</div>';

    $rows = $db->query("SELECT b.*, s.name spot_name, s.price spot_price FROM gb_banners b JOIN gb_spots s ON s.id=b.spot_id
                        WHERE b.status='pending' AND b.paid=1 ORDER BY b.id")->fetchAll();
    $body .= '<section class="panel"><div class="panel-head"><div><h2>Waiting for your approval</h2>'
           . '<p>These advertisers have paid. Approving starts their run today.</p></div></div>';
    if ($rows) {
        $body .= '<div class="table-wrap"><table class="data"><thead><tr><th>Banner</th><th>Advertiser</th><th>Clicks go to</th><th class="n">Paid</th><th></th></tr></thead><tbody>';
        foreach ($rows as $r) {
            $prev = 'preview.php?id=' . (int)$r['id'] . '&sig=' . gb_sig((int)$r['id']);
            $body .= '<tr><td><div class="with-thumb">' . gb_thumb($r) . '<div><div class="cell-main">#' . (int)$r['id'] . ' ' . e($r['spot_name']) . '</div>'
                   . '<div class="cell-sub"><a href="' . e($prev) . '" target="_blank">Open preview</a></div></div></div></td>'
                   . '<td><div class="cell-main">' . e($r['advertiser_name']) . '</div><div class="cell-sub">' . e($r['advertiser_email']) . '</div></td>'
                   . '<td class="cell-sub">' . e($r['target_url']) . '</td>'
                   . '<td class="n">' . e(gb_money((float)$r['spot_price'])) . '</td>'
                   . '<td><div class="actions">' . gb_action('approve', (int)$r['id'], 'Approve', 'dashboard', 'btn-primary')
                   . gb_action('reject', (int)$r['id'], 'Reject', 'dashboard') . '</div></td></tr>';
        }
        $body .= '</tbody></table></div>';
    } else {
        $body .= '<div class="empty"><h3>You\'re all caught up</h3><p>New paid orders will appear here for review.</p></div>';
    }
    $body .= '</section>'
           . '<div class="two-col" style="margin-top:20px">'
           . '<section class="panel"><div class="panel-head"><div><h2>Put ads on a website</h2>'
           . '<p>Paste this where the ad should appear. Change <code>leaderboard</code> to any placement\'s embed name.</p></div></div>'
           . gb_snippet($embedCode) . '</section>'
           . '<section class="panel"><div class="panel-head"><div><h2>Sell placements</h2>'
           . '<p>Share this page with advertisers. They can order and pay without contacting you.</p></div></div>'
           . gb_snippet(GB_BASE_URL . '/advertise.php') . '</section></div>';
    gb_layout('Dashboard', 'dashboard', $body, 'Orders, revenue and what is running right now.');
    exit;
}

/* ---- banners ---- */
if ($a === 'banners') {
    $spots = $db->query("SELECT * FROM gb_spots ORDER BY name")->fetchAll();
    $opts  = implode('', array_map(fn($s) => '<option value="' . (int)$s['id'] . '">' . e($s['name']) . ' (' . (int)$s['width'] . ' × ' . (int)$s['height'] . ')</option>', $spots));
    $body = '<details class="panel creator"><summary class="panel-head"><div><h2>New house banner</h2>'
          . '<p>Run your own promotion for free, as an image or custom HTML. It goes live immediately.</p></div>'
          . '<span class="btn btn-secondary">Create banner</span></summary>'
          . '<form method="post" enctype="multipart/form-data">' . gb_csrf_field()
          . '<input type="hidden" name="act" value="banner_create"><input type="hidden" name="back" value="banners">'
          . '<div class="row"><label class="field"><span>Placement</span><select name="spot_id">' . $opts . '</select></label>'
          . '<label class="field"><span>Type</span><select name="kind"><option value="image">Image</option><option value="html">HTML code</option></select></label></div>'
          . '<label class="field"><span>Where clicks go</span><input type="url" name="url" placeholder="https://"><span class="help-text">For image banners.</span></label>'
          . '<div class="row"><label class="field"><span>Image</span><input type="file" name="image" accept="image/*"></label>'
          . '<label class="field"><span>Width (HTML only)</span><input type="number" name="width" value="300" min="1"></label>'
          . '<label class="field"><span>Height (HTML only)</span><input type="number" name="height" value="250" min="1"></label></div>'
          . '<label class="field"><span>HTML code</span><textarea name="html" placeholder="&lt;a href=&quot;https://…&quot;&gt;…&lt;/a&gt;"></textarea>'
          . '<span class="help-text">Shown exactly as written. Only paste code you trust.</span></label>'
          . '<button class="btn btn-primary" type="submit">Create and publish</button></form></details>';

    $rows = $db->query(
        "SELECT b.*, s.name spot_name FROM gb_banners b
         JOIN gb_spots s ON s.id=b.spot_id
         ORDER BY FIELD(b.status,'pending','active','paused','expired','rejected'), b.id DESC"
    )->fetchAll();

    $body .= '<div class="table-wrap" style="margin-top:20px">';
    if ($rows) {
        $body .= '<table class="data"><thead><tr><th>Banner</th><th>Advertiser</th><th>Status</th>'
               . '<th class="n">Views</th><th class="n">Clicks</th><th class="n">CTR</th><th>Actions</th></tr></thead><tbody>';
        foreach ($rows as $r) {
            $ctr = $r['impressions'] > 0 ? sprintf('%.2f%%', 100 * $r['clicks'] / $r['impressions']) : '—';
            $body .= '<tr><td><div class="with-thumb">' . gb_thumb($r) . '<div><div class="cell-main">#' . (int)$r['id'] . ' ' . e($r['spot_name']) . '</div>'
                   . '<div class="cell-sub">' . (int)$r['width'] . ' × ' . (int)$r['height'] . ($r['kind'] === 'html' ? ', HTML' : '') . '</div></div></div></td>'
                   . '<td>' . ($r['advertiser_name'] ? '<div class="cell-main">' . e($r['advertiser_name']) . '</div><div class="cell-sub">' . e($r['advertiser_email']) . '</div>' : '<span class="muted">House banner</span>') . '</td>'
                   . '<td>' . gb_status_cell($r) . '</td>'
                   . '<td class="n">' . number_format((float)$r['impressions']) . '</td>'
                   . '<td class="n">' . number_format((float)$r['clicks']) . '</td><td class="n">' . $ctr . '</td>'
                   . '<td>' . gb_banner_actions($r, 'banners') . '</td></tr>';
        }
        $body .= '</tbody></table>';
    } else {
        $body .= '<div class="empty"><h3>No banners yet</h3><p>Orders from the advertiser page and house banners you create will be listed here.</p></div>';
    }
    $body .= '</div>';
    gb_layout('Banners', 'banners', $body, 'Review orders, control what is serving and see how each banner performs.');
    exit;
}

/* ---- placements (spots) ---- */
if ($a === 'spots') {
    $rows = $db->query("SELECT s.*, (SELECT COUNT(*) FROM gb_banners b WHERE b.spot_id=s.id AND b.status='active' AND b.paid=1) live
                        FROM gb_spots s ORDER BY s.id")->fetchAll();
    $body = '<div class="table-wrap"><table class="data"><thead><tr><th>Placement</th><th>Embed name</th><th class="n">Price</th>'
          . '<th class="n">Run</th><th class="n">Serving</th><th></th></tr></thead><tbody>';
    $editId = (int)($_GET['edit'] ?? 0);
    foreach ($rows as $r) {
        $id = (int)$r['id'];
        $placement = '<div class="with-thumb"><span class="thumb" style="background:var(--field)">' . gb_shape((int)$r['width'], (int)$r['height'], 60, 36) . '</span>'
                   . '<div><div class="cell-main">' . e($r['name']) . '</div><div class="cell-sub">' . (int)$r['width'] . ' × ' . (int)$r['height'] . ' px</div></div></div>';
        if ($id === $editId) {   // inline edit form in place of the row
            $body .= '<tr class="editing" id="edit"><td colspan="6"><form method="post" class="edit-row">' . gb_csrf_field()
                   . '<input type="hidden" name="act" value="spot_update"><input type="hidden" name="back" value="spots">'
                   . '<input type="hidden" name="id" value="' . $id . '">'
                   . '<div class="edit-head">' . $placement . '<span class="cell-sub">Embed name <code>' . e($r['slug']) . '</code></span></div>'
                   . '<div class="edit-fields">'
                   . '<label class="field"><span>Name</span><input type="text" name="name" maxlength="120" required value="' . e($r['name']) . '"></label>'
                   . '<label class="field"><span>Price (' . e(GB_CURRENCY) . ')</span><input type="number" name="price" min="0" step="0.01" required value="' . e(number_format((float)$r['price'], 2, '.', '')) . '" autofocus></label>'
                   . '<label class="field"><span>Run length (days)</span><input type="number" name="period_days" min="1" max="3650" required value="' . (int)$r['period_days'] . '"></label>'
                   . '<div class="edit-buttons"><button class="btn btn-primary" type="submit">Save changes</button>'
                   . '<a class="btn btn-secondary" href="?a=spots">Cancel</a></div></div>'
                   . '<p class="help-text">New and unpaid orders use the new price. Orders already paid keep the price they paid; '
                   . 'a new run length also applies to paid orders you haven\'t approved yet. '
                   . 'Size and embed name can\'t be changed because existing banners and website code depend on them.</p>'
                   . '</form></td></tr>';
            continue;
        }
        $body .= '<tr><td>' . $placement . '</td>'
               . '<td><code>' . e($r['slug']) . '</code></td>'
               . '<td class="n">' . e(gb_money((float)$r['price'])) . '</td><td class="n">' . (int)$r['period_days'] . ' days</td>'
               . '<td class="n">' . (int)$r['live'] . '</td>'
               . '<td><div class="actions"><a class="btn btn-sm btn-secondary" href="?a=spots&amp;edit=' . $id . '#edit">Edit</a>'
               . gb_action('spot_delete', $id, 'Delete', 'spots', 'btn-danger',
                     'Delete the ' . $r['name'] . ' placement? Its banners and their statistics are deleted too.') . '</div></td></tr>';
    }
    $body .= '</tbody></table></div>'
           . '<div class="two-col" style="margin-top:20px"><section class="panel"><div class="panel-head"><div><h2>Add a placement</h2>'
           . '<p>Create a custom size to sell. The embed name is used in the website code.</p></div></div>'
           . '<form method="post">' . gb_csrf_field() . '<input type="hidden" name="act" value="spot_save"><input type="hidden" name="back" value="spots">'
           . '<div class="row"><label class="field"><span>Name</span><input type="text" name="name" required placeholder="Billboard"></label>'
           . '<label class="field"><span>Embed name</span><input type="text" name="slug" required pattern="[a-z0-9-]+" placeholder="billboard"><span class="help-text">Lowercase letters, numbers and dashes.</span></label></div>'
           . '<div class="row"><label class="field"><span>Width (px)</span><input type="number" name="width" value="970" min="1"></label>'
           . '<label class="field"><span>Height (px)</span><input type="number" name="height" value="250" min="1"></label></div>'
           . '<div class="row"><label class="field"><span>Price (' . e(GB_CURRENCY) . ')</span><input type="number" name="price" value="10" min="0" step="0.01"></label>'
           . '<label class="field"><span>Run length (days)</span><input type="number" name="period_days" value="30" min="1"></label></div>'
           . '<button class="btn btn-primary" type="submit">Add placement</button></form></section>'
           . '<section class="panel"><div class="panel-head"><div><h2>Embed code</h2>'
           . '<p>Use one <code>div</code> per placement on the page; the script line only needs to appear once. '
           . 'A website never sees banners that link back to its own domain.</p></div></div>'
           . gb_snippet($embedCode) . '</section></div>';
    gb_layout('Placements', 'spots', $body, 'The ad sizes you sell, with their prices and run lengths.');
    exit;
}

/* ---- analytics (stats) ---- */
if ($a === 'stats') {
    $from = preg_replace('~[^0-9-]~', '', (string)($_GET['from'] ?? date('Y-m-d', strtotime('-30 days'))));
    $to   = preg_replace('~[^0-9-]~', '', (string)($_GET['to']   ?? date('Y-m-d')));

    $perBanner = $db->prepare(
        "SELECT b.id, s.name spot_name, COALESCE(b.advertiser_name,'House banner') adv,
                COALESCE(SUM(st.impressions),0) imp, COALESCE(SUM(st.clicks),0) clk
         FROM gb_banners b
         JOIN gb_spots s ON s.id = b.spot_id
         LEFT JOIN gb_stats st ON st.banner_id = b.id AND st.day BETWEEN ? AND ?
         GROUP BY b.id ORDER BY imp DESC");
    $perBanner->execute([$from, $to]);
    $rows = $perBanner->fetchAll();

    $daily = $db->prepare(
        "SELECT st.day, SUM(st.impressions) imp, SUM(st.clicks) clk
         FROM gb_stats st JOIN gb_banners b ON b.id = st.banner_id
         WHERE st.day BETWEEN ? AND ? GROUP BY st.day ORDER BY st.day DESC");
    $daily->execute([$from, $to]);
    $days = $daily->fetchAll();

    $ti = array_sum(array_map(fn($r) => (int)$r['imp'], $rows));
    $tc = array_sum(array_map(fn($r) => (int)$r['clk'], $rows));
    $ctr = fn(int $i, int $c) => $i ? sprintf('%.2f%%', 100 * $c / $i) : '—';

    $filter = '<form method="get" class="filters"><input type="hidden" name="a" value="stats">'
            . '<label class="field"><span>From</span><input type="date" name="from" value="' . e($from) . '"></label>'
            . '<label class="field"><span>To</span><input type="date" name="to" value="' . e($to) . '"></label>'
            . '<button class="btn btn-secondary" type="submit">Update</button></form>';

    $body = '<div class="kpis">'
          . '<div class="kpi"><div class="label">Views</div><div class="value">' . number_format($ti) . '</div><div class="sub">' . e($from) . ' to ' . e($to) . '</div></div>'
          . '<div class="kpi"><div class="label">Clicks</div><div class="value">' . number_format($tc) . '</div><div class="sub">Visits sent to advertisers</div></div>'
          . '<div class="kpi accent"><div class="label">Click-through rate</div><div class="value">' . $ctr($ti, $tc) . '</div><div class="sub">Clicks per view</div></div>'
          . '</div>';

    $body .= '<div class="two-col"><section><div class="table-wrap"><table class="data"><thead><tr><th>Banner</th><th>Advertiser</th>'
           . '<th class="n">Views</th><th class="n">Clicks</th><th class="n">CTR</th></tr></thead><tbody>';
    foreach ($rows as $r) {
        $body .= '<tr><td><div class="cell-main">#' . (int)$r['id'] . '</div><div class="cell-sub">' . e($r['spot_name']) . '</div></td>'
               . '<td>' . e($r['adv']) . '</td><td class="n">' . number_format((int)$r['imp']) . '</td>'
               . '<td class="n">' . number_format((int)$r['clk']) . '</td><td class="n">' . $ctr((int)$r['imp'], (int)$r['clk']) . '</td></tr>';
    }
    if (!$rows) $body .= '<tr><td colspan="5" class="empty">No banners yet.</td></tr>';
    $body .= '</tbody><tfoot><tr><td colspan="2">Total</td><td class="n">' . number_format($ti) . '</td><td class="n">' . number_format($tc)
           . '</td><td class="n">' . $ctr($ti, $tc) . '</td></tr></tfoot></table></div></section>';

    $max = max(1, ...array_map(fn($r) => (int)$r['imp'], $days ?: [['imp' => 1]]));
    $body .= '<section class="table-wrap"><table class="data"><thead><tr><th>Day</th><th>Views</th><th class="n">Clicks</th></tr></thead><tbody>';
    foreach ($days as $r) {
        $pct = round(100 * (int)$r['imp'] / $max, 1);
        $body .= '<tr><td class="cell-sub" style="white-space:nowrap">' . e($r['day']) . '</td>'
               . '<td><div class="with-thumb"><div class="bar-track"><div class="bar" style="width:' . $pct . '%"></div></div>'
               . '<span class="num">' . number_format((int)$r['imp']) . '</span></div></td>'
               . '<td class="n">' . number_format((int)$r['clk']) . '</td></tr>';
    }
    if (!$days) $body .= '<tr><td colspan="3" class="empty">No views in this period.</td></tr>';
    $body .= '</tbody></table></section></div>';
    gb_layout('Analytics', 'stats', $body, 'Views and clicks for the dates you choose.', $filter);
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
