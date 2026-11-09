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
     --lastmod=DATE    Date (YYYY-MM-DD) for sitemap.xml <lastmod>;
                       default today. Use the release date.

   Afterwards edit config.php (passwords, PayPal/Stripe keys, URL).
   ===================================================================== */

$opts = getopt('', ['dir:', 'db-host:', 'db-user:', 'db-pass:', 'db-name:', 'force', 'print-sql', 'lastmod:']);
$targetDir = $opts['dir'] ?? getcwd() . DIRECTORY_SEPARATOR . 'banner-manager';
$overwrite = isset($opts['force']);
$doDbImport = isset($opts['db-name'], $opts['db-user']);

/* ---------------------------------------------------------------------
   File manifest: every project file as literal content (nowdoc).
   --------------------------------------------------------------------- */

$files = [];

$files['schema.sql'] = <<<'SCHEMA_FILE'
-- Import with: mysql -u USER -p DBNAME < schema.sql
-- or open setup.php in a browser. Safe to run again: existing data is kept.

CREATE TABLE IF NOT EXISTS gb_spots (
  id INT AUTO_INCREMENT PRIMARY KEY,
  slug VARCHAR(60) NOT NULL UNIQUE,
  name VARCHAR(120) NOT NULL,
  width SMALLINT UNSIGNED NOT NULL DEFAULT 300,
  height SMALLINT UNSIGNED NOT NULL DEFAULT 250,
  price DECIMAL(10,2) NOT NULL DEFAULT 10.00,
  period_days SMALLINT UNSIGNED NOT NULL DEFAULT 30,
  is_active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS gb_banners (
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
  tos_version VARCHAR(20) NULL,
  tos_accepted_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_serve (spot_id, status, ends_at),
  FOREIGN KEY (spot_id) REFERENCES gb_spots(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS gb_orders (
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

CREATE TABLE IF NOT EXISTS gb_stats (
  banner_id INT NOT NULL,
  day DATE NOT NULL,
  impressions INT UNSIGNED NOT NULL DEFAULT 0,
  clicks INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (banner_id, day),
  FOREIGN KEY (banner_id) REFERENCES gb_banners(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 8 preset sizes seeded as sellable spots (add custom sizes in admin).
-- INSERT IGNORE: re-running never duplicates or overwrites placements.
INSERT IGNORE INTO gb_spots (slug,name,width,height,price,period_days) VALUES
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

// --- Database (CHANGE: create a MySQL database in your hosting control
//     panel and copy its name, user and password here) ---
define('GB_DB_HOST', 'localhost');
define('GB_DB_NAME', 'YOUR_DATABASE_NAME');
define('GB_DB_USER', 'YOUR_DATABASE_USER');
define('GB_DB_PASS', 'YOUR_DATABASE_PASSWORD');

// --- Site ---
define('GB_BRAND_NAME', 'BannerPromote');          // shown on every page
define('GB_BASE_URL', 'https://bannerpromote.com'); // no trailing slash!
define('GB_ADMIN_PASSWORD', 'CHANGE-ME-NOW');   // CHANGE: admin console password (10+ characters)
define('GB_ADMIN_EMAIL', 'admin@bannerpromote.com'); // CHANGE: your inbox for payment alerts

// --- Uploads ---
define('GB_UPLOAD_DIR', __DIR__ . '/uploads');
define('GB_MAX_UPLOAD_BYTES', 5 * 1024 * 1024);  // 5 MB max image

// --- Moderation ---
define('GB_AUTO_APPROVE', false);  // false = banners need admin approval
define('GB_BLOCK_SELF_ADS', true); // never show a banner on the website it links to
define('GB_GTM_ID', 'GTM-T9SK6QS3');  // Google Tag Manager container; '' turns analytics off
define('GB_CURRENCY', 'USD');

// --- PayPal ---
define('GB_PAYPAL_BUSINESS', 'YOUR-PAYPAL-EMAIL@example.com'); // CHANGE: the PayPal account that receives payments
define('GB_PAYPAL_SANDBOX', true);  // true while testing, false for live

// --- Stripe (raw REST, no SDK needed) ---
define('GB_STRIPE_SECRET', 'sk_test_REPLACE');
define('GB_STRIPE_WEBHOOK_SECRET', 'whsec_REPLACE');

// --- Mail ---
define('GB_MAIL_FROM', 'noreply@bannerpromote.com');

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

if (!defined('GB_BRAND_NAME')) define('GB_BRAND_NAME', 'BannerPromote');

/** BannerPromote mark: a banner shaped like a forward arrow, on a rounded square. */
function gb_brand_mark(): string {
    return '<svg viewBox="0 0 28 28" aria-hidden="true"><rect width="28" height="28" rx="6" fill="currentColor"/>'
         . '<path d="M6 8.5h11.5L23 14l-5.5 5.5H6l3.2-5.5z" fill="#fff"/></svg>';
}

/** Wordmark: "BannerPromote" set as Banner (heavy) + Promote (light). Other names print as-is. */
function gb_wordmark(): string {
    if (preg_match('/^([A-Z][a-z]+)([A-Z][A-Za-z]+)$/', GB_BRAND_NAME, $m))
        return '<span class="wordmark"><b>' . e($m[1]) . '</b>' . e($m[2]) . '</span>';
    return '<span class="wordmark"><b>' . e(GB_BRAND_NAME) . '</b></span>';
}

/** Absolute URL on this site for a path like "/pricing.php". */
function gb_url(string $path = '/'): string {
    return rtrim(GB_BASE_URL, '/') . '/' . ltrim($path, '/');
}

/** Root-relative link for a site path ("/pricing.php"), so links work from any folder (e.g. /blog/). */
function gb_path(string $path = '/'): string {
    $base = rtrim((string)(parse_url(GB_BASE_URL, PHP_URL_PATH) ?? ''), '/');
    return $base . '/' . ltrim($path, '/');
}

/** Public pages search engines may index, in menu/sitemap order: path => menu label. */
function gb_public_pages(): array {
    return [
        '/'                          => 'Home',
        '/advertise.php'             => 'Buy a banner ad',
        '/pricing.php'               => 'Pricing',
        '/banner-ad-sizes.php'       => 'Banner ad sizes',
        '/how-to-buy-banner-ads.php' => 'How it works',
        '/faq.php'                   => 'FAQ',
        '/blog/'                     => 'Blog',
    ];
}

/* ---------------- analytics (Google Tag Manager + consent) ---------------- */

if (!defined('GB_GTM_ID')) define('GB_GTM_ID', 'GTM-T9SK6QS3');

/**
 * Consent Mode defaults + Tag Manager loader, placed first in <head>.
 * - Europe/UK/Switzerland: nothing from Google loads until the visitor accepts
 *   (the popup is shown when the browser's time zone is in Europe), and Google's
 *   own regional default keeps analytics_storage denied there as a second layer.
 * - Elsewhere: Tag Manager loads with analytics allowed; "Cookie settings" in
 *   the footer lets anyone decline. Ad-related consent is always denied.
 * A saved choice (localStorage "bp_consent") is respected everywhere.
 */
function gb_analytics_head(): string {
    if (!preg_match('/^GTM-[A-Z0-9]{4,12}$/', GB_GTM_ID)) return '';
    $strictRegions = ['AT','BE','BG','HR','CY','CZ','DK','EE','FI','FR','DE','GR','HU','IE','IT','LV','LT','LU','MT','NL',
                      'PL','PT','RO','SK','SI','ES','SE','IS','LI','NO','GB','CH'];
    return '<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}'
         . 'gtag("consent","default",{ad_storage:"denied",ad_user_data:"denied",ad_personalization:"denied",analytics_storage:"denied",'
         . 'region:' . json_encode($strictRegions) . ',wait_for_update:500});'
         . 'gtag("consent","default",{ad_storage:"denied",ad_user_data:"denied",ad_personalization:"denied",analytics_storage:"granted"});'
         . '(function(){var id=' . json_encode(GB_GTM_ID) . ',k="bp_consent",c=null,tz="";'
         . 'try{c=localStorage.getItem(k)}catch(e){}'
         . 'try{tz=Intl.DateTimeFormat().resolvedOptions().timeZone||""}catch(e){}'
         . 'var strict=/^(Europe\/|Atlantic\/(Reykjavik|Canary|Madeira|Azores|Faroe))/.test(tz);'
         . 'function load(){if(window.bpGtm)return;window.bpGtm=1;dataLayer.push({"gtm.start":Date.now(),event:"gtm.js"});'
         . 'var s=document.createElement("script");s.async=true;s.src="https://www.googletagmanager.com/gtm.js?id="+encodeURIComponent(id);'
         . 'document.head.appendChild(s)}'
         . 'window.bpConsent={key:k,strict:strict,choice:c,load:load};'
         . 'if(c==="granted"){gtag("consent","update",{analytics_storage:"granted"});load()}'
         . 'else if(c==="denied"){gtag("consent","update",{analytics_storage:"denied"})}'
         . 'else if(!strict){load()}})();</script>';
}

/** Cookie popup + its script; shown in Europe until a choice is made, or from "Cookie settings". */
function gb_consent_banner(): string {
    if (!preg_match('/^GTM-[A-Z0-9]{4,12}$/', GB_GTM_ID)) return '';
    return '<div class="consent" id="consent" role="dialog" aria-modal="false" aria-labelledby="consent-title" hidden>'
         . '<p class="consent-title" id="consent-title">Analytics cookies</p>'
         . '<p>We use Google Analytics to see which pages are useful and improve the site. It sets cookies only if you allow it. '
         . '<a href="' . e(gb_path('/privacy.php')) . '">Privacy Policy</a></p>'
         . '<div class="consent-actions"><button type="button" class="btn btn-primary btn-sm" data-consent="granted">Accept</button>'
         . '<button type="button" class="btn btn-secondary btn-sm" data-consent="denied">Decline</button></div></div>'
         . '<script>(function(){var b=document.getElementById("consent"),c=window.bpConsent;if(!b||!c)return;'
         . 'if(c.strict&&!c.choice)b.hidden=false;'
         . 'b.addEventListener("click",function(e){var v=e.target.getAttribute("data-consent");if(!v)return;'
         . 'try{localStorage.setItem(c.key,v)}catch(x){}c.choice=v;'
         . 'gtag("consent","update",{analytics_storage:v});if(v==="granted")c.load();b.hidden=true;});'
         . 'document.querySelectorAll("[data-cookie-settings]").forEach(function(a){a.addEventListener("click",function(e){'
         . 'e.preventDefault();b.hidden=false;var f=b.querySelector("button");if(f)f.focus();});});})();</script>';
}

/**
 * Document head with search-engine metadata.
 * $meta: index (bool, default false = noindex), description, path (canonical),
 *        title_full (exact <title>), jsonld (list of schema.org objects), og_type.
 * Pages are hidden from search engines unless they opt in with 'index' => true.
 */
function gb_head(string $title, string $bodyClass = '', array $meta = []): string {
    $index = !empty($meta['index']);
    $full  = $meta['title_full'] ?? ($title . ' | ' . GB_BRAND_NAME);
    $h = '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
       . (($meta['analytics'] ?? $index) ? gb_analytics_head() : '')
       . '<meta name="viewport" content="width=device-width, initial-scale=1">'
       . '<title>' . e($full) . '</title>'
       . '<meta name="robots" content="' . ($index ? 'index, follow, max-image-preview:large' : 'noindex, nofollow') . '">';
    if ($index) {
        $canon = gb_url($meta['path'] ?? '/');
        $desc  = (string)($meta['description'] ?? '');
        $img   = gb_url('/og-image.png');
        $h .= '<meta name="description" content="' . e($desc) . '">'
            . '<link rel="canonical" href="' . e($canon) . '">'
            . '<meta property="og:site_name" content="' . e(GB_BRAND_NAME) . '">'
            . '<meta property="og:type" content="' . e($meta['og_type'] ?? 'website') . '">'
            . '<meta property="og:title" content="' . e($full) . '">'
            . '<meta property="og:description" content="' . e($desc) . '">'
            . '<meta property="og:url" content="' . e($canon) . '">'
            . '<meta property="og:image" content="' . e($img) . '">'
            . '<meta property="og:image:width" content="1200"><meta property="og:image:height" content="630">'
            . '<meta property="og:image:alt" content="' . e(GB_BRAND_NAME . ': banner ads from $10 for 30 days') . '">'
            . '<meta property="og:locale" content="en_US">'
            . '<meta name="twitter:card" content="summary_large_image">'
            . '<meta name="twitter:title" content="' . e($full) . '">'
            . '<meta name="twitter:description" content="' . e($desc) . '">'
            . '<meta name="twitter:image" content="' . e($img) . '">';
        $graph = array_merge([
            ['@type' => 'Organization', '@id' => gb_url('/#organization'), 'name' => GB_BRAND_NAME,
             'url' => gb_url('/'), 'logo' => gb_url('/favicon.svg'), 'email' => GB_ADMIN_EMAIL],
            ['@type' => 'WebSite', '@id' => gb_url('/#website'), 'name' => GB_BRAND_NAME, 'url' => gb_url('/'),
             'publisher' => ['@id' => gb_url('/#organization')], 'inLanguage' => 'en-US'],
        ], $meta['jsonld'] ?? []);
        $h .= '<script type="application/ld+json">'
            . json_encode(['@context' => 'https://schema.org', '@graph' => $graph], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG)
            . '</script>';
    }
    return $h
       . '<link rel="preload" href="' . gb_path('/fonts/ibm-plex-sans.woff2') . '" as="font" type="font/woff2" crossorigin>'
       . '<link rel="preload" href="' . gb_path('/fonts/archivo.woff2') . '" as="font" type="font/woff2" crossorigin>'
       . '<link rel="icon" href="' . gb_path('/favicon.svg') . '" type="image/svg+xml">'
       . '<link rel="stylesheet" href="' . gb_path('/style.css') . '?v=' . (int)@filemtime(__DIR__ . '/style.css') . '">'
       . ($meta['extra_head'] ?? '')
       . '</head><body' . ($bodyClass !== '' ? ' class="' . e($bodyClass) . '"' : '') . '>';
}

/** BreadcrumbList schema: Home > [parents...] > this page. $parents as [label => path]. */
function gb_breadcrumb(string $name, string $path, array $parents = []): array {
    $items = [['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => gb_url('/')]];
    foreach ($parents as $label => $p) $items[] = ['@type' => 'ListItem', 'position' => count($items) + 1, 'name' => $label, 'item' => gb_url($p)];
    $items[] = ['@type' => 'ListItem', 'position' => count($items) + 1, 'name' => $name, 'item' => gb_url($path)];
    return ['@type' => 'BreadcrumbList', 'itemListElement' => $items];
}

/** Top bar with the logo and the site menu. $active is the current page path. */
function gb_topbar(string $active = ''): string {
    $links = '';
    foreach (['/pricing.php' => 'Pricing', '/banner-ad-sizes.php' => 'Banner sizes',
              '/how-to-buy-banner-ads.php' => 'How it works', '/faq.php' => 'FAQ', '/blog/' => 'Blog'] as $path => $label)
        $links .= '<a href="' . e(gb_path($path)) . '"' . ($path === $active || ($path === '/blog/' && str_starts_with($active, '/blog/')) ? ' aria-current="page"' : '') . '>' . $label . '</a>';
    return '<div class="topbar"><a class="brand" href="' . e(gb_path('/')) . '" aria-label="' . e(GB_BRAND_NAME) . ' home">' . gb_brand_mark() . gb_wordmark() . '</a>'
         . '<nav class="site-nav" aria-label="Main">' . $links
         . '<a class="btn btn-primary btn-sm nav-cta" href="' . e(gb_path('/advertise.php')) . '"' . ($active === '/advertise.php' ? ' aria-current="page"' : '') . '>Buy a banner ad</a></nav></div>';
}

/**
 * Order-flow page top: masthead with menu, heading and the order progress
 * (1 Order details, 2 Review and pay, 3 Approval).
 */
function gb_public_open(string $title, string $lede, int $step, array $meta = []): string {
    $steps = [1 => 'Order details', 2 => 'Review and pay', 3 => 'Approval'];
    $li = '';
    foreach ($steps as $n => $label) {
        $cls = $n < $step ? 'done' : ($n === $step ? 'current' : '');
        $li .= '<li class="' . $cls . '"' . ($n === $step ? ' aria-current="step"' : '') . '>'
             . '<span class="n">' . ($n < $step ? '&#10003;' : $n) . '</span><span class="label">' . $label . '</span></li>';
    }
    return gb_head($title, '', $meta)
         . '<header class="masthead"><div class="wrap">' . gb_topbar($meta['path'] ?? '')
         . '<div class="intro"><h1>' . e($title) . '</h1><p class="lede">' . e($lede) . '</p></div>'
         . '<ol class="steps" aria-label="Order progress">' . $li . '</ol>'
         . '</div></header><main class="page"><div class="wrap">';
}

/**
 * Content page top (pricing, guides, FAQ, blog): masthead with menu, breadcrumb, H1 and intro.
 * $parents: extra breadcrumb levels between Home and this page, as [label => path].
 */
function gb_site_open(string $h1, string $lede, array $meta, string $crumb = '', array $parents = [], string $afterLede = ''): string {
    $trail = '<a href="' . e(gb_path('/')) . '">Home</a>';
    foreach ($parents as $label => $path) $trail .= '<span aria-hidden="true">/</span><a href="' . e(gb_path($path)) . '">' . e($label) . '</a>';
    return gb_head($h1, '', $meta + ['index' => true])
         . '<header class="masthead"><div class="wrap">' . gb_topbar($meta['path'] ?? '')
         . '<div class="intro">'
         . ($crumb !== '' ? '<nav class="crumbs" aria-label="Breadcrumb">' . $trail . '<span aria-hidden="true">/</span><span aria-current="page">' . e($crumb) . '</span></nav>' : '')
         . '<h1>' . e($h1) . '</h1><p class="lede">' . e($lede) . '</p>' . $afterLede . '</div>'
         . '</div></header><main class="page"><div class="wrap">';
}

function gb_public_close(): string {
    $links = '';
    foreach (gb_public_pages() as $path => $label)
        $links .= '<li><a href="' . e(gb_path($path)) . '">' . e($label) . '</a></li>';
    return '</div></main><footer class="site-foot"><div class="wrap">'
         . '<div class="foot-brand"><a class="brand" href="' . e(gb_path('/')) . '">' . gb_brand_mark() . gb_wordmark() . '</a>'
         . '<p>Self-serve banner advertising: pick an ad size, upload your banner and run it for 30 days.</p></div>'
         . '<nav aria-label="Site"><ul class="foot-links">' . $links
         . '<li><a href="mailto:' . e(GB_ADMIN_EMAIL) . '">Contact us</a></li>'
         . '<li><a href="' . e(gb_path('/privacy.php')) . '">Privacy Policy</a></li><li><a href="' . e(gb_path('/terms.php')) . '">Terms of Service</a></li>'
         . (gb_consent_banner() !== '' ? '<li><a href="#" data-cookie-settings>Cookie settings</a></li>' : '')
         . '</ul></nav>'
         . '<p class="foot-legal">&copy; ' . date('Y') . ' ' . e(GB_BRAND_NAME) . '. All advertising is reviewed before publication. '
         . 'Payments are processed securely by PayPal and Stripe.</p>'
         . '</div></footer>' . gb_consent_banner() . '</body></html>';
}

/** A call-to-action band used at the end of content pages. */
function gb_cta(string $heading = 'Ready to run your banner?', string $text = 'Choose an ad size, upload your image and check out in a few minutes.'): string {
    return '<section class="cta-band"><div><h2>' . e($heading) . '</h2><p>' . e($text) . '</p></div>'
         . '<a class="btn btn-primary btn-lg" href="' . e(gb_path('/advertise.php')) . '">Buy a banner ad</a></section>';
}

/** Current Terms of Service version (its effective date). Orders record which version was accepted. */
if (!defined('GB_TOS_VERSION'))  define('GB_TOS_VERSION', '2026-11-06.2');   // bump when the Terms change
if (!defined('GB_TOS_EFFECTIVE')) define('GB_TOS_EFFECTIVE', '2026-11-06');  // date the current version took effect
if (!defined('GB_PRIVACY_DATE')) define('GB_PRIVACY_DATE', '2026-11-06');

/**
 * Add columns introduced after a site was first set up (safe to call often).
 * Returns true when the Terms-of-Service columns exist. Never touches data.
 */
function gb_migrate(?PDO $db = null): bool {
    static $done = null;
    if ($db === null && $done !== null) return $done;
    $db ??= gb_db();
    $has = function (string $col) use ($db): bool {
        try { $db->query("SELECT $col FROM gb_banners LIMIT 0"); return true; } catch (PDOException $ex) { return false; }
    };
    $ok = true;
    foreach (['tos_version' => 'VARCHAR(20) NULL', 'tos_accepted_at' => 'DATETIME NULL'] as $col => $type) {
        if ($has($col)) continue;
        try { $db->exec("ALTER TABLE gb_banners ADD COLUMN $col $type"); }
        catch (PDOException $ex) { $ok = false; error_log('BannerPromote: could not add column ' . $col . ': ' . $ex->getMessage()); }
    }
    $ok = $ok && $has('tos_version') && $has('tos_accepted_at');
    return $done = $ok;
}

if (!defined('GB_PRICE_PITCH')) define('GB_PRICE_PITCH', '$10 for 30 days');   // headline price on the home page

/** Reference facts about standard banner sizes (used by the home page and size guide). */
function gb_size_guide(): array {
    return [
        'leaderboard'      => ['Leaderboard', 728, 90, 'Across the top of a page, above or below the main navigation.',
                               'Brand visibility on desktop; it is often the first ad a visitor sees.', 'Desktop and tablet'],
        'medium-rectangle' => ['Medium Rectangle', 300, 250, 'In the sidebar or between paragraphs of an article.',
                               'Almost any campaign. It is the most widely supported banner size and fits on every screen.', 'Desktop, tablet and mobile'],
        'large-rectangle'  => ['Large Rectangle', 336, 280, 'Inside article content or at the end of a post.',
                               'More room for a product image and a clear call to action than the 300×250.', 'Desktop and tablet'],
        'skyscraper'       => ['Skyscraper', 160, 600, 'In a tall sidebar column beside the content.',
                               'Staying on screen while visitors scroll; good for sequential messages and offers.', 'Desktop'],
        'half-page'        => ['Half Page', 300, 600, 'In the sidebar, where it takes up a large share of the screen.',
                               'High-impact campaigns: product launches, rich imagery and strong offers.', 'Desktop'],
        'full-banner'      => ['Full Banner', 468, 60, 'Above content, inside forums and in narrow page headers.',
                               'Simple text-led messages and tight budgets. It is the classic original banner size.', 'Desktop'],
        'square'           => ['Square', 250, 250, 'In sidebars and between content blocks on smaller layouts.',
                               'Logos, icons and short offers on sites with narrow columns.', 'Desktop and mobile'],
        'mobile-banner'    => ['Mobile Banner', 320, 50, 'Across the top or bottom of a phone screen.',
                               'Reaching people on phones with a short message and a tap-friendly link.', 'Mobile'],
    ];
}

/** Placements for public pages; empty list (never an error) if the database is unavailable. */
function gb_public_spots(): array {
    try { return gb_db()->query("SELECT * FROM gb_spots WHERE is_active=1 ORDER BY price, id")->fetchAll(); }
    catch (Throwable $ex) { return []; }
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
     <script src="https://bannerpromote.com/embed.js.php" async></script>

   (Uses GB_BASE_URL from config.php.) You can place
   multiple <div data-gb-spot="..."> per page (one per slot).
   ================================================================ */

require __DIR__ . '/config.php';
header('Content-Type: application/javascript; charset=utf-8');
header('X-Robots-Tag: noindex');

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
/* Web fonts, hosted on this server (no requests to Google). Latin subset.
   Archivo and IBM Plex Sans are licensed under the SIL Open Font License (see fonts/). */
@font-face { font-family: "Archivo"; font-style: normal; font-weight: 500 800; font-stretch: 100% 125%;
  font-display: swap; src: url(fonts/archivo.woff2) format("woff2"); unicode-range: U+0000-00FF, U+0131, U+0152-0153, U+02BB-02BC, U+02C6, U+02DA, U+02DC, U+0304, U+0308, U+0329, U+2000-206F, U+20AC, U+2122, U+2191, U+2193, U+2212, U+2215, U+FEFF, U+FFFD; }
@font-face { font-family: "IBM Plex Sans"; font-style: normal; font-weight: 400 600;
  font-display: swap; src: url(fonts/ibm-plex-sans.woff2) format("woff2"); unicode-range: U+0000-00FF, U+0131, U+0152-0153, U+02BB-02BC, U+02C6, U+02DA, U+02DC, U+0304, U+0308, U+0329, U+2000-206F, U+20AC, U+2122, U+2191, U+2193, U+2212, U+2215, U+FEFF, U+FFFD; }

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
.brand svg { width: 28px; height: 28px; color: var(--brand); flex: none; }
.wordmark { font-weight: 400; letter-spacing: -0.015em; }
.wordmark b { font-weight: 780; }
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

.prose figure { margin: 22px 0; }
.prose figure img { display: block; max-width: 100%; height: auto; }
.prose figcaption { margin-top: 8px; font-size: 14px; color: var(--muted, #5B6478); }
.chart-figure { margin: 0 0 28px; background: var(--paper); border: 1px solid var(--line); border-radius: var(--r-md); padding: 16px; }
.chart-figure img { display: block; width: 100%; max-width: 760px; height: auto; margin: 0 auto; }
.chart-figure figcaption { margin-top: 10px; text-align: center; font-size: 14px; color: var(--muted, #5B6478); }
.section-title { font-size: 24px; margin: 0 0 14px; }
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

/* ---------- site menu, breadcrumbs, footer ---------- */
.site-nav { display: flex; align-items: center; gap: 4px 22px; flex-wrap: wrap; justify-content: flex-end; }
.site-nav a { color: #C9D2E3; text-decoration: none; font-size: 14.5px; }
.site-nav a:hover, .site-nav a[aria-current="page"] { color: #fff; }
.site-nav a[aria-current="page"]:not(.nav-cta) { box-shadow: 0 2px 0 #4FC79A; }
.site-nav .nav-cta { color: #fff; margin-left: 6px; }
.crumbs { display: flex; gap: 8px; font-size: 13.5px; color: #8F9AB3; margin-bottom: 14px; }
.crumbs a { color: #C9D2E3; }
.site-foot { padding: 36px 0 44px; }
.site-foot .wrap { display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 18px 40px; align-items: start; }
.foot-brand .brand { color: var(--ink); margin-bottom: 8px; }
.foot-brand p { max-width: 42ch; margin: 0; }
.foot-links { list-style: none; margin: 0; padding: 0; display: grid; grid-template-columns: repeat(2, auto); gap: 6px 32px; }
.foot-links a { color: var(--ink-2); text-decoration: none; }
.foot-links a:hover { color: var(--brand); text-decoration: underline; }
.foot-legal { grid-column: 1 / -1; margin: 6px 0 0; padding-top: 16px; border-top: 1px solid var(--line); }

/* ---------- content pages ---------- */
.prose { max-width: 820px; }
.panel.prose, .article.prose { max-width: none; }
.prose h2 { font-size: 24px; margin: 34px 0 10px; }
.prose h2:first-child { margin-top: 0; }
.prose h3 { font-size: 17px; margin: 22px 0 6px; }
.prose p, .prose li { font-size: 16px; line-height: 1.65; max-width: 72ch; }
.prose ul, .prose ol { padding-left: 22px; margin: 0 0 14px; }
.prose li { margin-bottom: 6px; }
.article { background: var(--paper); border: 1px solid var(--line); border-radius: var(--r-md); padding: 40px 44px; }
.article + .article, .article + .cta-band, .panel + .cta-band, .table-wrap + .article { margin-top: 24px; }
.feature-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 8px 28px; margin: 8px 0 16px; }
.feature-grid h3 { margin-top: 14px; }
.feature-grid p { font-size: 15px; margin: 0; }
.cta-band { display: flex; justify-content: space-between; align-items: center; gap: 20px; flex-wrap: wrap;
  background: var(--ink); color: #C9D2E3; border-radius: var(--r-md); padding: 30px 36px; margin-top: 24px; }
.cta-band h2 { color: #fff; font-size: 24px; margin: 0 0 4px; }
.cta-band p { margin: 0; }
.size-table td:first-child { width: 96px; }
.size-table .stage-mini { width: 84px; height: 56px; display: grid; place-items: center; border-radius: 4px;
  background-color: var(--field); background-image: linear-gradient(var(--line-2) 1px, transparent 1px), linear-gradient(90deg, var(--line-2) 1px, transparent 1px);
  background-size: 8px 8px; }
.size-name { font-weight: 600; color: var(--ink); white-space: nowrap; }
.toc { display: flex; flex-wrap: wrap; gap: 8px; margin: 6px 0 4px; padding: 0; list-style: none; }
.toc a { display: inline-block; padding: 6px 12px; border: 1px solid var(--line); border-radius: 20px; background: var(--paper);
  font-size: 13.5px; color: var(--ink); text-decoration: none; }
.toc a:hover { border-color: var(--brand); color: var(--brand); }
.spec { display: grid; grid-template-columns: 168px minmax(0, 1fr); gap: 24px; align-items: start; padding: 26px 0; border-top: 1px solid var(--line-2); }
.spec:first-of-type { border-top: 0; }
.spec .stage { height: 132px; margin: 4px 0 0; }
.spec h2 { margin-top: 0; }
.faq details { border-bottom: 1px solid var(--line-2); padding: 16px 0; }
.faq details:first-child { padding-top: 0; }
.faq summary { cursor: pointer; font: 650 17px/1.35 var(--font-display); font-stretch: 105%; color: var(--ink); list-style: none; display: flex; justify-content: space-between; gap: 16px; }
.faq summary::-webkit-details-marker { display: none; }
.faq summary::after { content: "+"; font: 400 22px/1 var(--font-body); color: var(--muted); }
.faq details[open] summary::after { content: "\2212"; }
.faq details p { margin: 10px 0 0; }
.steps-list { counter-reset: s; list-style: none; padding: 0; margin: 0; display: grid; gap: 18px; }
.steps-list > li { counter-increment: s; display: grid; grid-template-columns: 40px minmax(0, 1fr); gap: 16px; }
.steps-list > li::before { content: counter(s); width: 32px; height: 32px; border-radius: 50%; background: var(--brand-tint);
  color: var(--brand-ink); display: grid; place-items: center; font: 700 15px/1 var(--font-display); }
.steps-list h3 { margin: 4px 0 4px; }
.steps-list p { margin: 0; }

/* ---------- home page ---------- */
.hero { display: grid; grid-template-columns: minmax(0, 1.05fr) minmax(0, 1fr); gap: 48px; align-items: center; padding: 30px 0 56px; }
.hero h1 { color: #fff; font-size: clamp(34px, 5vw, 54px); margin-bottom: 16px; }
.hero .lede { color: #C9D2E3; font-size: 18px; max-width: 52ch; margin-bottom: 26px; }
.hero-actions { display: flex; gap: 12px; flex-wrap: wrap; }
.btn-ghost { color: #fff; border-color: rgba(255,255,255,.35); background: transparent; }
.btn-ghost:hover { color: #fff; border-color: #fff; }
.hero-note { color: #8F9AB3; font-size: 14px; margin-top: 18px; }
.lineup { background: rgba(255,255,255,.04); border: 1px solid rgba(255,255,255,.1); border-radius: var(--r-md); padding: 22px;
  background-image: linear-gradient(rgba(255,255,255,.05) 1px, transparent 1px), linear-gradient(90deg, rgba(255,255,255,.05) 1px, transparent 1px);
  background-size: 14px 14px; }
.lineup-grid { display: flex; flex-wrap: wrap; gap: 16px 18px; align-items: flex-end; }
.lineup figure { margin: 0; display: flex; flex-direction: column; gap: 6px; }
.lineup .shape { background: rgba(79,199,154,.18); border-color: #4FC79A; }
.lineup figcaption { font-size: 11.5px; color: #AEB8CC; white-space: nowrap; }
.lineup-note { color: #8F9AB3; font-size: 12.5px; margin: 14px 0 0; }
.section-head { max-width: 640px; margin-bottom: 20px; }
.section-head h2 { font-size: 28px; margin-bottom: 8px; }
.section-head p { color: var(--muted); font-size: 16px; margin: 0; }
.home-section + .home-section { margin-top: 56px; }
.size-cards { display: grid; grid-template-columns: repeat(auto-fill, minmax(230px, 1fr)); gap: 14px; }
.size-card { display: block; background: var(--paper); border: 1px solid var(--line); border-radius: var(--r-md); padding: 16px; color: inherit; text-decoration: none; }
.size-card:hover { border-color: var(--brand); }
.size-card .stage { height: 110px; margin-bottom: 10px; }
.size-card strong { display: block; color: var(--ink); }
.size-card span { color: var(--muted); font-size: 13.5px; }

/* ---------- legal pages and agreement ---------- */
.legal h2 { font-size: 20px; }
.rule-box { border: 1px solid #F4C7C1; background: var(--danger-tint); border-radius: var(--r-md); padding: 4px 24px 12px; margin: 26px 0; }
.rule-box h2 { color: #7A1A12; margin-top: 18px; }
.rule-box p, .rule-box li { color: #5C140E; }
.agree { display: flex; gap: 12px; align-items: flex-start; margin-top: 22px; padding: 16px 18px; border: 1px solid var(--line);
  border-radius: var(--r-sm); background: var(--field); font-size: 14.5px; cursor: pointer; }
.agree input { width: 18px; height: 18px; margin-top: 2px; flex: none; accent-color: var(--brand); }
.agree:has(input:checked) { border-color: var(--brand); background: #F7FBF9; }
.consent p a { color: var(--brand); }

/* ---------- blog ---------- */
.post-byline { color: #8F9AB3; font-size: 14px; margin: 14px 0 0; }
.post-layout { display: grid; grid-template-columns: minmax(0, 1fr) 260px; gap: 28px; align-items: start; }
.post-body h2 { scroll-margin-top: 20px; }
.post-body blockquote { margin: 18px 0; padding: 4px 18px; border-left: 3px solid var(--brand); color: var(--ink); }
.post-body table { width: 100%; border-collapse: collapse; margin: 16px 0; font-size: 15px; display: block; overflow-x: auto; }
.post-body th, .post-body td { text-align: left; padding: 9px 12px; border-bottom: 1px solid var(--line-2); vertical-align: top; }
.post-body th { background: #FAFBFC; color: var(--ink); }
.post-body .faq { margin-top: 8px; }
.post-body .key-takeaways { background: var(--brand-tint); border-radius: var(--r-md); padding: 6px 22px 4px; margin: 4px 0 22px; }
.post-body .key-takeaways h2 { font-size: 18px; margin-top: 16px; }
.post-aside { position: sticky; top: 20px; }
.post-toc { background: var(--paper); border: 1px solid var(--line); border-radius: var(--r-md); padding: 18px 20px; }
.post-toc-title { font: 700 14px/1.3 var(--font-display); color: var(--ink); margin-bottom: 8px; }
.post-toc ol { margin: 0; padding-left: 18px; font-size: 14px; }
.post-toc li { margin-bottom: 6px; }
.post-toc a { color: var(--ink-2); text-decoration: none; }
.post-toc a:hover { color: var(--brand); text-decoration: underline; }
.post-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 16px; }
.post-card { display: flex; flex-direction: column; gap: 8px; background: var(--paper); border: 1px solid var(--line); border-radius: var(--r-md);
  padding: 22px; color: inherit; text-decoration: none; }
.post-card:hover { border-color: var(--brand); }
.post-card strong { font: 700 19px/1.3 var(--font-display); color: var(--ink); }
.post-cat { font-size: 13px; font-weight: 600; color: var(--brand); }
.post-excerpt { color: var(--ink-2); font-size: 14.5px; }
.post-meta { color: var(--muted); font-size: 13px; margin-top: auto; }
.related { margin-top: 32px; }
.related h2 { font-size: 24px; margin-bottom: 14px; }
@media (max-width: 920px) { .post-layout { grid-template-columns: minmax(0, 1fr); } .post-aside { position: static; order: -1; } }

/* ---------- cookie popup ---------- */
.consent { position: fixed; left: 16px; bottom: 16px; z-index: 50; max-width: 400px; background: var(--paper);
  border: 1px solid var(--line); border-radius: var(--r-md); padding: 18px 20px; box-shadow: 0 8px 30px rgba(20,33,61,.18); }
.consent[hidden] { display: none; }
.consent p { font-size: 14px; margin: 0 0 12px; }
.consent .consent-title { font: 700 15px/1.3 var(--font-display); color: var(--ink); margin-bottom: 4px; }
.consent-actions { display: flex; gap: 8px; }
@media (max-width: 600px) { .consent { left: 10px; right: 10px; bottom: 10px; max-width: none; } }

/* ---------- responsive ---------- */
@media (max-width: 920px) {
  .order-grid, .review-grid, .two-col, .hero { grid-template-columns: 1fr; }
  .hero { gap: 28px; padding-bottom: 40px; }
  .topbar { flex-wrap: wrap; }
  .site-nav { justify-content: flex-start; gap: 4px 16px; }
  .site-foot .wrap { grid-template-columns: 1fr; }
  .article { padding: 26px 22px; }
  .spec { grid-template-columns: 1fr; gap: 10px; }
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
  .site-nav a { font-size: 14px; }
  .site-nav .nav-cta { margin-left: 0; }
  .cta-band { padding: 24px 20px; }
  .size-cards { grid-template-columns: 1fr 1fr; gap: 10px; }
  .size-card { padding: 12px; }
  .size-card .stage { height: 84px; }
  .size-card .shape { transform: scale(.8); }
}
@media (prefers-reduced-motion: reduce) { * { scroll-behavior: auto !important; transition: none !important; } }
STYLE_FILE;

$files['setup.php'] = <<<'SETUP_FILE'
<?php
declare(strict_types=1);
require __DIR__ . '/config.php';
gb_session();

/* ================================================================
   Browser-based database setup, for hosts without a command line
   (SFTP-only). Open https://your-site/setup.php after uploading.

   - Creates only the tables that are missing.
   - Adds the 8 starter placements only if there are no placements.
   - Never changes or deletes existing banners, orders or stats,
     so it is safe to open again later (e.g. after an update).
   - Requires the admin password from config.php.
   Delete this file from the server once your site is running.
   ================================================================ */

$tables = ['gb_spots', 'gb_banners', 'gb_orders', 'gb_stats'];
$errors = []; $done = [];

$exists = function (string $t): bool {
    try { gb_db()->query("SELECT 1 FROM `$t` LIMIT 1"); return true; }
    catch (PDOException $ex) { return false; }
};

if (GB_ADMIN_PASSWORD === 'CHANGE-ME-NOW' || strlen(GB_ADMIN_PASSWORD) < 10) {
    $errors[] = 'Set a strong GB_ADMIN_PASSWORD (10+ characters) in config.php and upload it again, then reload this page.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!gb_csrf_ok()) {
        $errors[] = 'This page expired. Enter the password again.';
    } elseif (!hash_equals(GB_ADMIN_PASSWORD, is_string($_POST['password'] ?? null) ? $_POST['password'] : '')) {
        usleep(500000);
        $errors[] = 'That password is not correct. Use GB_ADMIN_PASSWORD from config.php.';
    } else {
        try {
            $db = gb_db();
            $sql = preg_replace('/^\s*--.*$/m', '', (string)file_get_contents(__DIR__ . '/schema.sql'));
            $before = array_filter($tables, $exists);
            foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
                // Only create tables that are missing; existing ones are never touched.
                if (preg_match('/^CREATE TABLE IF NOT EXISTS (\w+)/i', $stmt, $m) && !in_array($m[1], $before, true))
                    $db->exec($stmt);
            }
            foreach ($tables as $t)
                $done[] = in_array($t, $before, true) ? "Table $t already existed, kept as is." : "Created table $t.";

            if (gb_migrate($db)) $done[] = 'Terms of Service columns are in place.';
            // Upgrade older installs: allow manual ("Mark paid") orders.
            if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql')
                $db->exec("ALTER TABLE gb_orders MODIFY gateway ENUM('paypal','stripe','manual') NOT NULL");

            if ((int)$db->query('SELECT COUNT(*) FROM gb_spots')->fetchColumn() === 0) {
                foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt)
                    if (stripos($stmt, 'INSERT') === 0) $db->exec($stmt);
                $done[] = 'Added the 8 starter placements ($10 for 30 days each).';
            } else {
                $done[] = 'Placements already exist, kept as is ('
                        . (int)$db->query('SELECT COUNT(*) FROM gb_spots')->fetchColumn() . ').';
            }
            $done[] = 'Existing banners kept: ' . (int)$db->query('SELECT COUNT(*) FROM gb_banners')->fetchColumn() . '.';
            if (!is_dir(GB_UPLOAD_DIR)) @mkdir(GB_UPLOAD_DIR, 0755, true);
            if (is_dir(GB_UPLOAD_DIR)) gb_protect_uploads(GB_UPLOAD_DIR);
            $done[] = is_writable(GB_UPLOAD_DIR)
                ? 'The uploads folder is ready.'
                : 'Warning: the uploads folder is not writable. Set its permissions to 755 (or 775) in your file manager.';
        } catch (PDOException $ex) {
            $errors[] = 'Could not use the database: ' . $ex->getMessage()
                      . '. Check GB_DB_HOST, GB_DB_NAME, GB_DB_USER and GB_DB_PASS in config.php.';
        }
    }
}

echo gb_head('Set up', 'login') . '<main class="login-card" style="max-width:520px">'
   . '<a class="brand" href="advertise.php">' . gb_brand_mark() . '<span>' . gb_wordmark() . '<small>Site setup</small></span></a>'
   . '<h1>Set up the database</h1>';
foreach ($errors as $er) echo '<div class="alert alert-error" role="alert">' . e($er) . '</div>';
if ($done) {
    echo '<div class="alert alert-success" role="status"><strong>Setup complete.</strong><ul>';
    foreach ($done as $d) echo '<li>' . e($d) . '</li>';
    echo '</ul></div><p>Next: <a href="admin.php">open the admin console</a>. '
       . 'For safety, delete <code>setup.php</code> from the server now that the site is running.</p>';
} elseif (GB_ADMIN_PASSWORD !== 'CHANGE-ME-NOW' && strlen(GB_ADMIN_PASSWORD) >= 10) {
    echo '<p class="muted">Creates any missing tables. Existing banners, orders and statistics are never changed.</p>'
       . '<form method="post">' . gb_csrf_field()
       . '<label class="field"><span>Admin password</span><input type="password" name="password" required autofocus autocomplete="current-password"></label>'
       . '<button class="btn btn-primary btn-block btn-lg" type="submit">Run setup</button></form>';
}
echo '</main></body></html>';
SETUP_FILE;

$files['import.php'] = <<<'IMPORT_FILE'
<?php
declare(strict_types=1);
require __DIR__ . '/config.php';
gb_session();

/* ================================================================
   One-time import of banners exported from a test site
   (tests/export-banners.php -> banners-export.json).

   Adds the banners with their images, run dates, orders and daily
   stats. Never changes or deletes anything already on this site,
   and importing the same file twice adds nothing the second time.
   Requires the admin password. Delete this file when you're done.
   ================================================================ */

$report = []; $errors = [];
$enum = fn($v, array $ok, $def) => in_array($v, $ok, true) ? $v : $def;
$dt   = fn($v) => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $v) ? $v : null;

if (GB_ADMIN_PASSWORD === 'CHANGE-ME-NOW' || strlen(GB_ADMIN_PASSWORD) < 10) {
    $errors[] = 'Set a strong GB_ADMIN_PASSWORD (10+ characters) in config.php first.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $up = $_FILES['export'] ?? null;
    if (!gb_csrf_ok()) {
        $errors[] = 'This page expired. Try again.';
    } elseif (!hash_equals(GB_ADMIN_PASSWORD, is_string($_POST['password'] ?? null) ? $_POST['password'] : '')) {
        usleep(500000);
        $errors[] = 'That password is not correct. Use GB_ADMIN_PASSWORD from config.php.';
    } elseif (!$up || ($up['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $code = $up['error'] ?? UPLOAD_ERR_NO_FILE;
        $errors[] = in_array($code, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
            ? 'The file is larger than this server accepts (' . ini_get('upload_max_filesize') . '). Export fewer banners at a time with --only=ID,ID.'
            : 'Choose the banners-export.json file to import.';
    } else {
        $data = json_decode((string)file_get_contents($up['tmp_name']), true);
        if (!is_array($data) || ($data['format'] ?? '') !== 'bannerpromote-export-1') {
            $errors[] = 'That file is not a banner export. Use the banners-export.json made by tests/export-banners.php.';
        } else {
            try {
                $db = gb_db();
                if (!is_dir(GB_UPLOAD_DIR)) @mkdir(GB_UPLOAD_DIR, 0755, true);
                gb_protect_uploads(GB_UPLOAD_DIR);
                $mimeExt = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/gif' => 'gif', 'image/webp' => 'webp'];

                // Placements: use the live one with the same embed name; add it only if missing.
                $spotId = [];
                foreach ((array)($data['spots'] ?? []) as $s) {
                    $slug = (string)($s['slug'] ?? '');
                    if (!preg_match('/^[a-z0-9-]{1,60}$/', $slug)) continue;
                    $q = $db->prepare('SELECT id FROM gb_spots WHERE slug=?'); $q->execute([$slug]);
                    $id = $q->fetchColumn();
                    if ($id === false) {
                        $db->prepare('INSERT INTO gb_spots (slug,name,width,height,price,period_days) VALUES (?,?,?,?,?,?)')
                           ->execute([$slug, mb_substr((string)$s['name'], 0, 120), max(1, (int)$s['width']), max(1, (int)$s['height']),
                                      max(0, (float)$s['price']), max(1, (int)$s['period_days'])]);
                        $id = $db->lastInsertId();
                        $report[] = 'Added placement "' . $s['name'] . '" (it did not exist on this site).';
                    }
                    $spotId[$slug] = (int)$id;
                }

                foreach ((array)($data['banners'] ?? []) as $b) {
                    $src = (int)($b['source_id'] ?? 0);
                    $sid = $spotId[(string)($b['spot_slug'] ?? '')] ?? 0;
                    if (!$sid) { $report[] = "Skipped banner #$src: its placement is missing from the file."; continue; }
                    $kind = $enum($b['kind'] ?? '', ['image', 'html'], 'image');
                    $imgPath = null; $bytes = null;

                    if ($kind === 'image') {
                        $name = (string)($b['image']['name'] ?? '');
                        $bytes = base64_decode((string)($b['image']['data'] ?? ''), true);
                        if (!preg_match('/^[A-Za-z0-9_-]{1,64}\.(png|jpe?g|gif|webp)$/', $name) || $bytes === false || $bytes === '') {
                            $report[] = "Skipped banner #$src: its image is missing from the export."; continue;
                        }
                        $info = @getimagesizefromstring($bytes);
                        if (!$info || !isset($mimeExt[$info['mime']]) || strlen($bytes) > GB_MAX_UPLOAD_BYTES) {
                            $report[] = "Skipped banner #$src: its image is not a valid PNG, JPEG, GIF or WebP under the size limit."; continue;
                        }
                        // Already imported? (same image file name, same click destination)
                        $q = $db->prepare('SELECT id FROM gb_banners WHERE image_path=? AND COALESCE(target_url,\'\')=?');
                        $q->execute(['uploads/' . $name, (string)($b['target_url'] ?? '')]);
                        if ($q->fetchColumn() !== false) { $report[] = "Banner #$src was already imported; skipped."; continue; }
                        if (is_file(GB_UPLOAD_DIR . '/' . $name) && sha1_file(GB_UPLOAD_DIR . '/' . $name) !== sha1($bytes))
                            $name = bin2hex(random_bytes(10)) . '.' . $mimeExt[$info['mime']];   // never overwrite another image
                        $imgPath = 'uploads/' . $name;
                    } else {
                        $q = $db->prepare("SELECT id FROM gb_banners WHERE kind='html' AND html=? AND created_at=?");
                        $q->execute([(string)($b['html'] ?? ''), (string)($b['created_at'] ?? '')]);
                        if ($q->fetchColumn() !== false) { $report[] = "Banner #$src was already imported; skipped."; continue; }
                    }

                    $db->beginTransaction();
                    try {
                        if ($imgPath && !is_file(GB_UPLOAD_DIR . '/' . basename($imgPath))
                            && file_put_contents(GB_UPLOAD_DIR . '/' . basename($imgPath), $bytes) === false)
                            throw new RuntimeException('could not save its image in the uploads folder (check its permissions)');
                        $db->prepare('INSERT INTO gb_banners (spot_id,advertiser_name,advertiser_email,kind,image_path,html,target_url,
                                      width,height,status,paid,starts_at,ends_at,impressions,clicks,created_at)
                                      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                           ->execute([$sid,
                               ($b['advertiser_name'] ?? null) !== null ? mb_substr((string)$b['advertiser_name'], 0, 120) : null,
                               ($b['advertiser_email'] ?? null) !== null ? mb_substr((string)$b['advertiser_email'], 0, 190) : null,
                               $kind, $imgPath, $kind === 'html' ? (string)($b['html'] ?? '') : null,
                               mb_substr((string)($b['target_url'] ?? ''), 0, 500),
                               max(1, (int)($b['width'] ?? 1)), max(1, (int)($b['height'] ?? 1)),
                               $enum($b['status'] ?? '', ['pending', 'active', 'paused', 'rejected', 'expired'], 'pending'),
                               (int)($b['paid'] ?? 0) === 1 ? 1 : 0,
                               $dt($b['starts_at'] ?? null), $dt($b['ends_at'] ?? null),
                               max(0, (int)($b['impressions'] ?? 0)), max(0, (int)($b['clicks'] ?? 0)),
                               $dt($b['created_at'] ?? null) ?? gmdate('Y-m-d H:i:s')]);
                        $new = (int)$db->lastInsertId();
                        foreach ((array)($b['orders'] ?? []) as $o)
                            $db->prepare('INSERT INTO gb_orders (banner_id,gateway,amount,currency,status,txn_ref,created_at) VALUES (?,?,?,?,?,?,?)')
                               ->execute([$new, $enum($o['gateway'] ?? '', ['paypal', 'stripe', 'manual'], 'manual'), max(0, (float)($o['amount'] ?? 0)),
                                          substr((string)($o['currency'] ?? GB_CURRENCY), 0, 3),
                                          $enum($o['status'] ?? '', ['pending', 'paid', 'failed', 'refunded'], 'pending'),
                                          mb_substr((string)($o['txn_ref'] ?? ''), 0, 190), $dt($o['created_at'] ?? null) ?? gmdate('Y-m-d H:i:s')]);
                        foreach ((array)($b['stats'] ?? []) as $st)
                            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($st['day'] ?? '')))
                                $db->prepare('INSERT INTO gb_stats (banner_id,day,impressions,clicks) VALUES (?,?,?,?)')
                                   ->execute([$new, $st['day'], max(0, (int)$st['impressions']), max(0, (int)$st['clicks'])]);
                        $db->commit();
                        $report[] = "Imported banner #$src as #$new (" . ($b['spot_slug'] ?? '') . ', ' . ($b['status'] ?? '')
                                  . ((int)($b['paid'] ?? 0) === 1 ? ', paid' : ', unpaid') . ').';
                    } catch (Throwable $ex) {
                        $db->rollBack();
                        $report[] = "Could not import banner #$src: " . $ex->getMessage() . '.';
                    }
                }
                gb_expire_check();   // anything whose run already ended is marked expired
            } catch (PDOException $ex) {
                $errors[] = 'Database problem: ' . $ex->getMessage() . '. Run setup.php first if you haven\'t.';
            }
        }
    }
}

echo gb_head('Import banners', 'login') . '<main class="login-card" style="max-width:560px">'
   . '<a class="brand" href="advertise.php">' . gb_brand_mark() . '<span>' . gb_wordmark() . '<small>Import banners</small></span></a>'
   . '<h1>Import banners</h1>';
foreach ($errors as $er) echo '<div class="alert alert-error" role="alert">' . e($er) . '</div>';
if ($report) {
    echo '<div class="alert alert-success" role="status"><strong>Import finished.</strong><ul>';
    foreach ($report as $r) echo '<li>' . e($r) . '</li>';
    echo '</ul></div><p><a href="admin.php?a=banners">Open the banner list</a>. '
       . 'When everything looks right, delete <code>import.php</code> from the server.</p>';
} elseif (GB_ADMIN_PASSWORD !== 'CHANGE-ME-NOW' && strlen(GB_ADMIN_PASSWORD) >= 10) {
    echo '<p class="muted">Adds banners from a <code>banners-export.json</code> file. Nothing already on this site is changed, '
       . 'and importing the same file again adds nothing.</p>'
       . '<form method="post" enctype="multipart/form-data">' . gb_csrf_field()
       . '<label class="field"><span>Export file</span><input type="file" name="export" accept=".json,application/json" required></label>'
       . '<label class="field"><span>Admin password</span><input type="password" name="password" required autocomplete="current-password"></label>'
       . '<button class="btn btn-primary btn-block btn-lg" type="submit">Import banners</button></form>';
}
echo '</main></body></html>';
IMPORT_FILE;

$files['home.php'] = <<<'PAGE_HOME'
<?php
declare(strict_types=1);
require __DIR__ . '/config.php';

/* ================================================================
   Home page template. The installer renders this into a static
   index.html (fast, and works even if the database is busy), then
   removes this file. Edit here, not in index.html.
   ================================================================ */

$sizes = gb_size_guide();
$scale = 1.0;
foreach ($sizes as $z) $scale = min($scale, 150 / $z[1], 96 / $z[2]);

$faq = [
    ['How much does a banner ad cost?', 'Every placement is ' . GB_PRICE_PITCH . '. You pay once when you order; there is no bidding, no minimum spend and no contract.'],
    ['Which banner sizes can I buy?', 'All the standard sizes: 728×90 leaderboard, 300×250 medium rectangle, 336×280 large rectangle, 160×600 skyscraper, 300×600 half page, 468×60 full banner, 250×250 square and 320×50 mobile banner.'],
    ['How fast does my ad go live?', 'Our team reviews each banner, usually within one business day. Your run starts the moment it is approved.'],
];

echo gb_head('Home', 'home', [
    'index'       => true,
    'path'        => '/',
    'title_full'  => 'Online Banner Advertising: ' . GB_PRICE_PITCH . ' | ' . GB_BRAND_NAME,
    'description' => 'Online banner advertising made simple: buy a banner ad in any standard size, upload it and pay by card or PayPal. '
                   . ucfirst(GB_PRICE_PITCH) . '.',
    'jsonld'      => [['@type' => 'FAQPage', 'mainEntity' => array_map(fn($q) => ['@type' => 'Question', 'name' => $q[0],
                        'acceptedAnswer' => ['@type' => 'Answer', 'text' => $q[1]]], $faq)]],
]);
?>
<header class="masthead"><div class="wrap">
  <?= gb_topbar('/') ?>
  <section class="hero">
    <div>
      <h1>Online banner advertising, <?= e(GB_PRICE_PITCH) ?></h1>
      <p class="lede">Buy banner ads online and put your business in front of new visitors. Pick a standard ad size,
        upload your image and pay by card or PayPal. One flat price, no bidding and no contract.</p>
      <div class="hero-actions">
        <a class="btn btn-primary btn-lg" href="advertise.php">Buy a banner ad</a>
        <a class="btn btn-ghost btn-lg" href="banner-ad-sizes.php">See banner sizes</a>
      </div>
      <p class="hero-note">Every ad is reviewed by a person, usually within one business day.</p>
    </div>
    <div class="lineup" aria-label="The eight banner sizes you can buy, drawn to scale">
      <div class="lineup-grid">
        <?php foreach ($sizes as $z): ?>
          <figure><?= gb_shape($z[1], $z[2], 150, 96, $scale) ?><figcaption><?= e($z[0]) ?> <?= $z[1] ?>×<?= $z[2] ?></figcaption></figure>
        <?php endforeach; ?>
      </div>
      <p class="lineup-note">All eight banner sizes, drawn to the same scale.</p>
    </div>
  </section>
</div></header>

<main class="page"><div class="wrap">

  <section class="home-section" aria-labelledby="how">
    <div class="section-head"><h2 id="how">How buying a banner ad works</h2>
      <p>From choosing a size to going live takes a few minutes of your time.</p></div>
    <div class="article">
      <ol class="steps-list">
        <li><div><h3>Choose an ad size</h3><p>Pick the placement that fits your goal, from a wide 728×90 leaderboard to a phone-friendly 320×50 mobile banner. Not sure? Read the <a href="banner-ad-sizes.php">banner ad size guide</a>.</p></div></li>
        <li><div><h3>Upload your banner and link</h3><p>Upload a PNG, JPEG, GIF or WebP image at the exact size, and enter the page you want visitors to land on. The order form checks the size for you.</p></div></li>
        <li><div><h3>Review and pay</h3><p>See a preview of your banner on a sample page, then pay by card through Stripe or with PayPal.</p></div></li>
        <li><div><h3>Go live for 30 days</h3><p>Once our team approves your banner it starts showing straight away and runs for the full 30 days.</p></div></li>
      </ol>
    </div>
  </section>

  <section class="home-section" aria-labelledby="sizes">
    <div class="section-head"><h2 id="sizes">Banner ad sizes you can buy</h2>
      <p>Every standard display ad size, each <?= e(GB_PRICE_PITCH) ?>.</p></div>
    <div class="size-cards">
      <?php foreach ($sizes as $slug => $z): ?>
        <a class="size-card" href="banner-ad-sizes.php#<?= e($slug) ?>">
          <span class="stage" style="display:grid"><?= gb_shape($z[1], $z[2], 150, 96, $scale) ?></span>
          <strong><?= e($z[0]) ?> <?= $z[1] ?>×<?= $z[2] ?></strong>
          <span><?= e($z[5]) ?></span>
        </a>
      <?php endforeach; ?>
    </div>
  </section>

  <section class="home-section" aria-labelledby="why">
    <div class="section-head"><h2 id="why">Why advertisers choose <?= e(GB_BRAND_NAME) ?></h2></div>
    <div class="article prose">
      <div class="feature-grid">
        <div><h3>Simple, flat pricing</h3><p>One price per placement for a full 30-day run. You know the cost before you start. See <a href="pricing.php">banner ad pricing</a>.</p></div>
        <div><h3>No account or contract</h3><p>Order as a guest in a few minutes. There is nothing to cancel when your run ends.</p></div>
        <div><h3>Human review</h3><p>We check every banner and landing page, which keeps the ad spaces clean and trustworthy for visitors.</p></div>
        <div><h3>Fast loading ads</h3><p>Banners load after the rest of the page, so they don't hold up the websites that show them.</p></div>
      </div>
    </div>
  </section>

  <section class="home-section" aria-labelledby="small-business">
    <div class="section-head"><h2 id="small-business">Affordable website advertising for small businesses</h2></div>
    <div class="article prose">
      <p>Advertising on websites used to mean minimum spends, ad networks and bidding wars. <?= e(GB_BRAND_NAME) ?> keeps it simple:
        cheap banner advertising at one flat price, so a local shop, a startup or a freelancer can run display ads next to the content
        their customers already read. Choose the size, upload your banner, and your ad rotates in that placement for the whole run.</p>
      <p>It is a low-risk way to test a new offer, promote a sale or event, or keep your name in front of potential customers.
        Compare the options in <a href="/blog/banner-ads-vs-google-ads.html">banner ads vs Google Ads</a>, or see what makes a
        <a href="/blog/banner-ad-click-through-rate.html">good banner ad click-through rate</a>.</p>
    </div>
  </section>

  <!--LATEST_POSTS-->

  <section class="home-section" aria-labelledby="faq">
    <div class="section-head"><h2 id="faq">Banner advertising questions</h2>
      <p>Quick answers. See all <a href="faq.php">frequently asked questions</a>.</p></div>
    <div class="article faq">
      <?php foreach ($faq as $q): ?>
        <details><summary><?= e($q[0]) ?></summary><p><?= e($q[1]) ?></p></details>
      <?php endforeach; ?>
    </div>
  </section>

  <?= gb_cta('Start your banner campaign today', 'Choose a size, upload your banner and go live after a quick review. ' . ucfirst(GB_PRICE_PITCH) . '.') ?>

<?= gb_public_close() ?>
PAGE_HOME;

$files['pricing.php'] = <<<'PAGE_PRICING'
<?php
declare(strict_types=1);
require __DIR__ . '/config.php';

/* Banner ad pricing. Prices come from the placements in the admin console. */

$spots = gb_public_spots();
$guide = gb_size_guide();
$prices = array_map(fn($s) => (float)$s['price'], $spots);
$min = $prices ? min($prices) : 0.0; $max = $prices ? max($prices) : 0.0;
$days = array_unique(array_map(fn($s) => (int)$s['period_days'], $spots));
$money = fn(float $v) => '$' . (fmod($v, 1.0) == 0.0 ? number_format($v, 0) : number_format($v, 2));
$flat = $spots && $min === $max && count($days) === 1;
$pitch = $flat ? $money($min) . ' for ' . reset($days) . ' days' : ($spots ? 'from ' . $money($min) : GB_PRICE_PITCH);

$faq = [
    ['How much does a banner ad cost?', $flat
        ? 'Every banner ad placement costs ' . $money($min) . ' for ' . reset($days) . ' days, whichever size you choose.'
        : 'Prices start at ' . $money($min) . ' per run; each placement shows its price and run length in the table on this page.'],
    ['Are there any other fees?', 'No. The price shown is the full price of the run. There is no setup fee, no minimum spend and nothing to cancel afterwards.'],
    ['When does the 30-day run start?', 'Your run starts when our team approves your banner, not when you pay, so you never lose days waiting for review.'],
    ['How do I pay?', 'By card through Stripe or with your PayPal account. You pay once, when you place the order.'],
    ['How much does banner advertising cost on other networks?', 'On ad networks the cost of banner advertising is usually set by auction, '
        . 'per thousand impressions (CPM) or per click (CPC), so it changes with your audience, targeting, ad size and the season. '
        . 'There is no single standard rate; the only way to know is to set a budget and measure what you get for it.'],
    ['What is banner advertising CPM?', 'CPM means cost per mille, the price of 1,000 ad impressions. A $5 CPM means you pay $5 each time your banner is shown 1,000 times. '
        . 'To compare a flat-rate placement with a CPM offer, divide the flat price by the impressions you expect and multiply by 1,000.'],
    ['Is banner advertising worth it?', 'It works best for reaching new people with a clear, simple offer and for staying visible to an audience you have already reached. '
        . 'A low flat price lets you test a banner for a month and judge it on the clicks and sales it brings, without a large commitment.'],
];

echo gb_site_open('Banner advertising cost and pricing', 'How much banner advertising costs, how banner ads are usually priced, and our simple flat rate: '
    . 'one price per placement for a full run, with no bidding, no minimum spend and no contract.', [
    'path'        => '/pricing.php',
    'title_full'  => 'Banner Advertising Cost: ' . ucfirst($pitch) . ', Any Size',
    'description' => 'How much does banner advertising cost? CPM, CPC and flat-rate pricing explained, and our price: '
                   . $pitch . ' for any standard banner size, no contract.',
    'jsonld'      => [gb_breadcrumb('Pricing', '/pricing.php'),
        ['@type' => 'FAQPage', 'mainEntity' => array_map(fn($q) => ['@type' => 'Question', 'name' => $q[0],
            'acceptedAnswer' => ['@type' => 'Answer', 'text' => $q[1]]], $faq)],
        ['@type' => 'Service', 'name' => 'Banner ad placement', 'serviceType' => 'Display advertising', 'provider' => ['@id' => gb_url('/#organization')],
         'offers' => array_map(fn($s) => ['@type' => 'Offer', 'name' => $s['name'] . ' (' . (int)$s['width'] . 'x' . (int)$s['height'] . ')',
             'price' => number_format((float)$s['price'], 2, '.', ''), 'priceCurrency' => GB_CURRENCY,
             'availability' => 'https://schema.org/InStock', 'url' => gb_url('/advertise.php')], $spots)]],
], 'Pricing');
?>
<?php if ($spots): ?>
<div class="table-wrap">
  <table class="data size-table">
    <caption class="sr-only">Banner ad prices by size</caption>
    <thead><tr><th scope="col"><span class="sr-only">Shape</span></th><th scope="col">Placement</th><th scope="col">Size</th>
      <th scope="col" class="n">Run</th><th scope="col" class="n">Price</th><th scope="col"><span class="sr-only">Order</span></th></tr></thead>
    <tbody>
    <?php foreach ($spots as $s): ?>
      <tr><td><span class="stage-mini"><?= gb_shape((int)$s['width'], (int)$s['height'], 72, 46) ?></span></td>
        <td><span class="size-name"><?= e($s['name']) ?></span></td>
        <td><?= (int)$s['width'] ?> × <?= (int)$s['height'] ?> px</td>
        <td class="n"><?= (int)$s['period_days'] ?> days</td>
        <td class="n"><strong><?= e($money((float)$s['price'])) ?></strong> <span class="muted small"><?= e(GB_CURRENCY) ?></span></td>
        <td><a class="btn btn-secondary btn-sm" href="advertise.php">Order</a></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<article class="article prose">
  <h2>What's included</h2>
  <ul>
    <li>Your banner shown in the ad space you choose, in rotation with other banners of the same size.</li>
    <li>A full <?= $days ? e(implode(' or ', $days)) : '30' ?>-day run that starts when your banner is approved.</li>
    <li>A preview of your banner on a sample page before you pay.</li>
    <li>A review of your banner and landing page by our team before it goes live.</li>
    <li>Click-through links that send visitors straight to the page you choose.</li>
  </ul>

  <h2>How much does banner advertising cost?</h2>
  <p>It depends on how the ad is sold. Large publishers and ad networks often charge by the thousand impressions or by the click, with
    prices set by auction, so a month of website advertising can cost anything from a few dollars to thousands. With <?= e(GB_BRAND_NAME) ?>,
    the banner ad cost per month is simply the placement price: <?= e($pitch) ?>, with nothing else to pay.</p>

  <h2>How banner ads are usually priced</h2>
  <p>Most ad networks sell banner ads in one of three ways. With <strong>CPM</strong> (cost per thousand impressions) you pay for every thousand times your ad is shown.
    With <strong>CPC</strong> (cost per click) you pay each time someone clicks. Both usually involve bidding against other advertisers, so the final cost of a campaign is hard to predict.</p>
  <p><?= e(GB_BRAND_NAME) ?> uses <strong>flat-rate pricing</strong> instead: you pay one fixed price for a fixed run. You know the total cost before you start,
    and it never goes up because another advertiser outbid you. That makes it a simple, low-risk way to test banner advertising or run a steady campaign on a small budget.</p>

  <h2>What affects website banner advertising rates?</h2>
  <ul>
    <li><strong>The audience.</strong> Sites with buyers that advertisers compete for, such as finance or software, charge more than general-interest sites.</li>
    <li><strong>Targeting.</strong> Narrowing an auction campaign by location, interest or device usually raises the price of each impression.</li>
    <li><strong>Size and position.</strong> Large or prominent spaces such as the 970×250 billboard or a leaderboard at the top of the page usually cost more than small ones further down.</li>
    <li><strong>Season.</strong> Auction prices tend to rise when many advertisers compete at once, for example before the holidays.</li>
    <li><strong>How you pay.</strong> CPM and CPC costs move with demand; a flat rate stays the same for the whole run.</li>
  </ul>
  <p>To compare offers, work out your cost per click: divide what you paid by the clicks you received. Our article on
    <a href="blog/banner-ad-click-through-rate.html">banner ad click-through rates</a> explains what to expect and how to raise yours.</p>

  <h2>Which size gives the best value?</h2>
  <p>Because every size costs the same, choose by where you want to be seen. The
    <a href="banner-ad-sizes.php#medium-rectangle">300×250 medium rectangle</a> fits every screen, the
    <a href="banner-ad-sizes.php#leaderboard">728×90 leaderboard</a> is the first thing desktop visitors see, and the
    <a href="banner-ad-sizes.php#half-page">300×600 half page</a> gives your design the most room. Many advertisers run two sizes side by side to compare results.
    The <a href="banner-ad-sizes.php">banner ad size guide</a> explains each one.</p>

  <h2>Pricing questions</h2>
  <div class="faq">
    <?php foreach ($faq as $q): ?><details><summary><?= e($q[0]) ?></summary><p><?= e($q[1]) ?></p></details><?php endforeach; ?>
  </div>
</article>

<?= gb_cta('Book your banner ad', 'Choose a placement and check out in a few minutes. ' . ucfirst($pitch) . '.') ?>
<?= gb_public_close() ?>
PAGE_PRICING;

$files['banner-ad-sizes.php'] = <<<'PAGE_SIZES'
<?php
declare(strict_types=1);
require __DIR__ . '/config.php';

/* Banner ad size guide: the standard IAB-style sizes, where each appears and what it suits. */

$guide = gb_size_guide();
$spots = [];
foreach (gb_public_spots() as $s) $spots[(int)$s['width'] . 'x' . (int)$s['height']] = $s;
$scale = 1.0;
foreach ($guide as $z) $scale = min($scale, 168 / $z[1], 132 / $z[2]);

$chartAlt = 'Standard banner ad sizes chart drawn to scale: 970×250 billboard, 970×90 and 728×90 leaderboards, 468×60 full banner, '
          . '300×250 and 336×280 rectangles, 250×250 and 200×200 squares, 120×600 and 160×600 skyscrapers, 300×600 half page, '
          . '300×1050 portrait, and 320×50, 300×50 and 320×100 mobile banners.';

$faq = [
    ['What are the standard banner ad sizes?',
     'The standard banner ad sizes are 728×90 (leaderboard), 300×250 (medium rectangle), 336×280 (large rectangle), 160×600 (wide skyscraper), '
     . '300×600 (half page), 970×250 (billboard), 970×90 (large leaderboard), 468×60 (full banner) and, for phones, 320×50, 300×50 and 320×100. '
     . 'They are the sizes most websites and ad networks, including Google, accept.'],
    ['What is the most common banner ad size?',
     'The 300×250 medium rectangle. It fits desktop sidebars, sits between paragraphs of an article and works on phones, so almost every site has a space for it. '
     . 'Google lists it among its top-performing sizes together with 336×280, 728×90, 300×600 and 320×100.'],
    ['What size is a mobile banner ad?',
     'The most common mobile banner ad size is 320×50 (the mobile leaderboard). 300×50 is a narrower version and 320×100 (large mobile banner) gives twice the height '
     . 'for a bigger message. The 300×250 medium rectangle also works well on phones.'],
    ['What size is a leaderboard banner ad?',
     'A standard leaderboard is 728×90 pixels and runs across the top of a desktop page. The large (or super) leaderboard is 970×90, and the 970×250 billboard is the tallest version.'],
    ['How large can a banner ad file be?',
     'Google Ads accepts uploaded image ads up to 150 KB. The IAB guidelines allow 150 KB for most sizes at first load, 50 KB for smartphone banners and up to 250 KB for the billboard and portrait. '
     . 'Keeping a banner under 150 KB is a safe rule for any site.'],
    ['What banner ad sizes does Google accept?',
     'Google Ads accepts uploaded image ads in square and rectangle sizes (200×200, 240×400, 250×250, 250×360, 300×250, 336×280, 580×400), skyscrapers '
     . '(120×600, 160×600, 300×600, 300×1050), leaderboards (468×60, 728×90, 930×180, 970×90, 970×250, 980×120) and mobile sizes (300×50, 320×50, 320×100).'],
];

echo gb_site_open('Banner ad sizes: the standard size chart and guide',
    'Every standard banner ad size in one chart, from the 728×90 leaderboard and 300×250 medium rectangle to the 320×50 mobile banner: '
    . 'where each one appears, which sizes Google and the IAB use, and how to design for them.', [
    'path'        => '/banner-ad-sizes.php',
    'og_type'     => 'article',
    'title_full'  => 'Banner Ad Sizes: Standard Size Chart & Guide (2026)',
    'description' => 'All standard banner ad sizes in one chart: 728x90, 300x250, 160x600, 300x600, 970x250 and mobile 320x50, '
                   . 'with Google and IAB specs, file sizes and design tips.',
    'jsonld'      => [gb_breadcrumb('Banner ad sizes', '/banner-ad-sizes.php'),
        ['@type' => 'Article', 'headline' => 'Banner ad sizes: the standard size chart and guide',
         'author' => ['@id' => gb_url('/#organization')], 'publisher' => ['@id' => gb_url('/#organization')],
         'dateModified' => '2026-11-09',
         'mainEntityOfPage' => gb_url('/banner-ad-sizes.php'), 'image' => ['@id' => gb_url('/banner-ad-sizes.php#chart')], 'inLanguage' => 'en-US'],
        ['@type' => 'ImageObject', '@id' => gb_url('/banner-ad-sizes.php#chart'), 'contentUrl' => gb_url('/images/banner-ad-sizes-chart.png'),
         'name' => 'Standard banner ad sizes chart', 'description' => $chartAlt, 'width' => 1200, 'height' => 1528,
         'creator' => ['@id' => gb_url('/#organization')], 'copyrightNotice' => GB_BRAND_NAME],
        ['@type' => 'FAQPage', 'mainEntity' => array_map(fn($q) => ['@type' => 'Question', 'name' => $q[0],
            'acceptedAnswer' => ['@type' => 'Answer', 'text' => $q[1]]], $faq)]],
], 'Banner ad sizes');
?>
<figure class="chart-figure" id="chart">
  <a href="images/banner-ad-sizes-chart.png"><img src="images/banner-ad-sizes-chart.png" width="1200" height="1528" alt="<?= e($chartAlt) ?>"></a>
  <figcaption>Standard banner ad sizes chart, every size drawn at the same 50% scale. Click to open the full image.</figcaption>
</figure>

<h2 class="section-title">Banner ad sizes at a glance</h2>
<div class="table-wrap">
  <table class="data size-table">
    <caption class="sr-only">Standard banner ad sizes at a glance</caption>
    <thead><tr><th scope="col"><span class="sr-only">Shape</span></th><th scope="col">Name</th><th scope="col">Size (px)</th>
      <th scope="col">Devices</th><th scope="col">Best for</th></tr></thead>
    <tbody>
    <?php foreach ($guide as $slug => $z): ?>
      <tr><td><span class="stage-mini"><?= gb_shape($z[1], $z[2], 72, 46) ?></span></td>
        <td><a class="size-name" href="#<?= e($slug) ?>"><?= e($z[0]) ?></a></td>
        <td class="nowrap"><?= $z[1] ?> × <?= $z[2] ?></td><td class="cell-sub"><?= e($z[5]) ?></td>
        <td class="cell-sub"><?= e(strtok($z[4], '.;')) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<article class="article prose">
  <h2>The sizes in detail</h2>
  <p>Display ad sizes are measured in pixels, width × height. The web banner dimensions below are the standard IAB ad sizes you will find on most
    websites and in most ad networks, including Google's display network, so a banner made for one site usually works on many others.</p>
  <ul class="toc" aria-label="Jump to a size">
    <?php foreach ($guide as $slug => $z): ?><li><a href="#<?= e($slug) ?>"><?= $z[1] ?>×<?= $z[2] ?> <?= e($z[0]) ?></a></li><?php endforeach; ?>
  </ul>

  <?php foreach ($guide as $slug => $z): $sp = $spots[$z[1] . 'x' . $z[2]] ?? null; ?>
    <section class="spec" id="<?= e($slug) ?>">
      <div class="stage"><?= gb_shape($z[1], $z[2], 168, 132, $scale) ?></div>
      <div>
        <h2><?= $z[1] ?>×<?= $z[2] ?> <?= e($z[0]) ?></h2>
        <p><strong>Where it appears:</strong> <?= e($z[3]) ?></p>
        <p><strong>Best for:</strong> <?= e($z[4]) ?></p>
        <p><strong>Devices:</strong> <?= e($z[5]) ?>.</p>
        <?php if ($sp): ?>
          <p><a href="advertise.php">Buy a <?= $z[1] ?>×<?= $z[2] ?> <?= e(strtolower($z[0])) ?> ad</a>:
            $<?= e(rtrim(rtrim(number_format((float)$sp['price'], 2), '0'), '.')) ?> for <?= (int)$sp['period_days'] ?> days.</p>
        <?php endif; ?>
      </div>
    </section>
  <?php endforeach; ?>

  <h2 id="most-common">The most common banner ad sizes</h2>
  <p>If you only design a few banners, make them the sizes that almost every website has room for. Google names five
    <a href="https://support.google.com/adsense/answer/6002621" rel="noopener">top-performing ad sizes</a> for publishers:</p>
  <ol>
    <li><strong>300×250 medium rectangle</strong>: the most widely used size, on desktop and mobile.</li>
    <li><strong>336×280 large rectangle</strong>: a little more room, often placed inside articles.</li>
    <li><strong>728×90 leaderboard</strong>: across the top of desktop pages.</li>
    <li><strong>300×600 half page</strong>: a large vertical space with the most room for your design.</li>
    <li><strong>320×100 large mobile banner</strong>: twice the height of the standard mobile banner.</li>
  </ol>
  <p>Add the 160×600 wide skyscraper and the 320×50 mobile banner and you can run on nearly any site.</p>

  <h2 id="mobile">Mobile banner ad sizes</h2>
  <p>Many visitors browse on phones, so plan at least one mobile size. The standard mobile banner ad sizes are:</p>
  <div class="table-wrap">
    <table class="data">
      <caption class="sr-only">Mobile banner ad sizes</caption>
      <thead><tr><th scope="col">Size (px)</th><th scope="col">Name</th><th scope="col">Where it appears</th></tr></thead>
      <tbody>
        <tr><td class="nowrap">320 × 50</td><td>Mobile leaderboard</td><td>Top or bottom of the screen, often fixed in place</td></tr>
        <tr><td class="nowrap">300 × 50</td><td>Mobile banner</td><td>Same places, for narrower layouts</td></tr>
        <tr><td class="nowrap">320 × 100</td><td>Large mobile banner</td><td>Between sections of content</td></tr>
        <tr><td class="nowrap">300 × 250</td><td>Medium rectangle</td><td>Inside articles as visitors scroll</td></tr>
      </tbody>
    </table>
  </div>
  <p>Small mobile banners have room for a logo, five or six words and a button, nothing more. Write the message for that space instead of shrinking a desktop design.</p>

  <h2 id="google">Google banner ad sizes</h2>
  <p>Google Ads accepts <a href="https://support.google.com/google-ads/answer/1722096" rel="noopener">uploaded image ads</a> as GIF, JPG or PNG files up to 150 KB, in these sizes:</p>
  <ul>
    <li><strong>Square and rectangle:</strong> 200×200, 240×400, 250×250, 250×360, 300×250, 336×280, 580×400</li>
    <li><strong>Skyscraper:</strong> 120×600, 160×600, 300×600, 300×1050</li>
    <li><strong>Leaderboard:</strong> 468×60, 728×90, 930×180, 970×90, 970×250, 980×120</li>
    <li><strong>Mobile:</strong> 300×50, 320×50, 320×100</li>
  </ul>
  <p>Every size in this guide is on that list, so you can use the same banners here and on the Google Display Network.</p>

  <h2 id="iab">IAB standard banner sizes and file limits</h2>
  <p>The IAB (Interactive Advertising Bureau) publishes the industry's standard ad sizes. Its
    <a href="https://www.iab.com/wp-content/uploads/2019/04/IABNewAdPortfolio_LW_FixedSizeSpec.pdf" rel="noopener">fixed-size ad specifications</a>
    also set how heavy each banner may be when the page first loads:</p>
  <div class="table-wrap">
    <table class="data">
      <caption class="sr-only">IAB standard banner sizes and initial file size limits</caption>
      <thead><tr><th scope="col">IAB name</th><th scope="col">Size (px)</th><th scope="col" class="n">Max initial file size</th></tr></thead>
      <tbody>
        <tr><td>Medium rectangle</td><td class="nowrap">300 × 250</td><td class="n">150 KB</td></tr>
        <tr><td>Leaderboard</td><td class="nowrap">728 × 90</td><td class="n">150 KB</td></tr>
        <tr><td>Skyscraper</td><td class="nowrap">160 × 600</td><td class="n">150 KB</td></tr>
        <tr><td>Super leaderboard</td><td class="nowrap">970 × 90</td><td class="n">200 KB</td></tr>
        <tr><td>Billboard</td><td class="nowrap">970 × 250</td><td class="n">250 KB</td></tr>
        <tr><td>Portrait</td><td class="nowrap">300 × 1050</td><td class="n">250 KB</td></tr>
        <tr><td>Smartphone banner</td><td class="nowrap">320 × 50 or 300 × 50</td><td class="n">50 KB</td></tr>
      </tbody>
    </table>
  </div>

  <h2>Which banner size should you choose?</h2>
  <ul>
    <li><strong>One size only?</strong> Choose the 300×250 medium rectangle. It fits on desktop and mobile layouts and is the most widely used size.</li>
    <li><strong>Want to be seen first on desktop?</strong> The 728×90 leaderboard sits at the top of the page.</li>
    <li><strong>Need room for imagery?</strong> The 300×600 half page and 336×280 large rectangle give your design the most space.</li>
    <li><strong>Targeting phones?</strong> The 320×50 mobile banner is made for small screens.</li>
    <li><strong>Long scrolling pages?</strong> The 160×600 skyscraper stays beside the content as people read.</li>
  </ul>

  <h2 id="tips">Banner design tips that work at every size</h2>
  <ul>
    <li><strong>Use the exact pixel size.</strong> Ad spaces have fixed dimensions; an image that is a few pixels off gets rejected or squeezed.</li>
    <li><strong>Keep the file small.</strong> Aim for well under 150 KB so your banner appears instantly. Save photos as JPEG or WebP and flat graphics as PNG.</li>
    <li><strong>One message, one call to action.</strong> Visitors glance at a banner for a second or two. Say one thing and add a clear button such as "Shop the sale".</li>
    <li><strong>Make text readable.</strong> Use short headlines, large type and strong contrast. Small sizes like 320×50 have room for only a few words.</li>
    <li><strong>Show your brand.</strong> Put your logo or name where it is visible even if the banner is only noticed briefly.</li>
    <li><strong>Animate with care.</strong> An animated GIF can draw attention, but keep it short and end on a frame that makes sense on its own.</li>
    <li><strong>Add a border on white banners.</strong> A thin border stops a light banner from blending into the page.</li>
  </ul>
  <p>Ready to order? See <a href="pricing.php">banner ad pricing</a> or read <a href="how-to-buy-banner-ads.php">how to buy banner ads</a> step by step.</p>

  <h2 id="faq">Banner ad size questions</h2>
  <div class="faq">
    <?php foreach ($faq as $q): ?><details><summary><?= e($q[0]) ?></summary><p><?= e($q[1]) ?></p></details><?php endforeach; ?>
  </div>
</article>

<?= gb_cta('Order the size that fits your campaign', 'Every standard banner size, ' . GB_PRICE_PITCH . '.') ?>
<?= gb_public_close() ?>
PAGE_SIZES;

$files['how-to-buy-banner-ads.php'] = <<<'PAGE_HOWTO'
<?php
declare(strict_types=1);
require __DIR__ . '/config.php';

/* Guide: how to buy banner ads (and get results from them). */

echo gb_site_open('How to buy banner ads: a step-by-step guide',
    'Everything you need to plan, design and buy a banner ad that gets clicks, even if you have never advertised online before.', [
    'path'        => '/how-to-buy-banner-ads.php',
    'og_type'     => 'article',
    'title_full'  => 'How to Buy Banner Ads: A Step-by-Step Guide | ' . GB_BRAND_NAME,
    'description' => 'Learn how to buy banner ads: set a goal, choose an ad size, design a banner that gets clicks, '
                   . 'track results with UTM links and launch for ' . GB_PRICE_PITCH . '.',
    'jsonld'      => [gb_breadcrumb('How to buy banner ads', '/how-to-buy-banner-ads.php'),
        ['@type' => 'Article', 'headline' => 'How to buy banner ads: a step-by-step guide',
         'author' => ['@id' => gb_url('/#organization')], 'publisher' => ['@id' => gb_url('/#organization')],
         'mainEntityOfPage' => gb_url('/how-to-buy-banner-ads.php'), 'image' => gb_url('/og-image.png'), 'inLanguage' => 'en-US']],
], 'How to buy banner ads');
?>
<article class="article prose">
  <h2>What is a banner ad?</h2>
  <p>A banner ad is an image advertisement shown in a fixed-size space on a website, such as across the top of a page or in the sidebar.
    When a visitor clicks it, they go to a page you choose: your shop, an offer, a sign-up form. Banner ads are one of the simplest forms of online
    display advertising, and they work well for building awareness and sending visitors to a specific page.</p>

  <h2>Buying a banner ad in seven steps</h2>
  <ol class="steps-list">
    <li><div><h3>Decide what the ad should achieve</h3>
      <p>Pick one goal: sales of a product, sign-ups, bookings or simply getting your name known. A single goal makes every other choice easier,
        from the image to the page people land on.</p></div></li>
    <li><div><h3>Choose the landing page</h3>
      <p>Send clicks to the page that matches the ad, not your home page. If the banner advertises a spring sale, link to the sale. People who
        land exactly where they expected are far more likely to act.</p></div></li>
    <li><div><h3>Pick an ad size</h3>
      <p>The 300×250 medium rectangle is the safest first choice because it fits every screen. Add a 728×90 leaderboard for desktop reach or a
        320×50 mobile banner for phones. Our <a href="banner-ad-sizes.php">banner ad size guide</a> compares all eight sizes.</p></div></li>
    <li><div><h3>Design the banner</h3>
      <p>Use one short headline, your logo and a clear button such as "Book now". Export it at the exact pixel size as a PNG, JPEG, GIF or WebP,
        and keep the file small so it loads instantly. The size guide has more <a href="banner-ad-sizes.php#tips">design tips</a>.</p></div></li>
    <li><div><h3>Add tracking to your link</h3>
      <p>Add UTM tags to the address you enter, for example <code>?utm_source=bannerpromote&amp;utm_medium=banner&amp;utm_campaign=spring-sale</code>.
        Tools such as Google Analytics then show exactly how many visits and sales the banner brought in.</p></div></li>
    <li><div><h3>Order and pay</h3>
      <p>On the <a href="advertise.php">order page</a>, choose your placement, upload the banner and enter your link. You will see a preview on a
        sample page, then pay by card or PayPal: <?= e(GB_PRICE_PITCH) ?>.</p></div></li>
    <li><div><h3>Go live and measure</h3>
      <p>Our team reviews your banner, usually within one business day, and your 30-day run starts the moment it is approved. Check your analytics
        each week; if one message or size performs better, use it for your next run.</p></div></li>
  </ol>

  <h2>How to advertise on other websites</h2>
  <p>You have three main options. You can contact a website owner directly and negotiate a deal, which takes time and usually has a minimum.
    You can use a large ad network, which reaches many sites but involves bidding, targeting settings and ongoing management. Or you can use a
    self-serve service like <?= e(GB_BRAND_NAME) ?>, where you choose a placement, pay a flat price and upload your banner. For most small
    businesses starting out, self-serve banner advertising is the quickest way to get an ad live on relevant websites.</p>

  <h2 id="mistakes">Common banner ad mistakes to avoid</h2>
  <ul>
    <li><strong>Too much text.</strong> If it takes more than a glance to read, most people won't.</li>
    <li><strong>No call to action.</strong> Tell visitors what to do: "Shop now", "Get a quote", "Try it free".</li>
    <li><strong>Wrong dimensions.</strong> A banner that is not the exact size of the ad space can't be used.</li>
    <li><strong>A slow or mismatched landing page.</strong> Check the link on your phone before you order.</li>
    <li><strong>Judging too early.</strong> Give a banner its full run before deciding whether it works.</li>
  </ul>

  <h2>Is banner advertising worth it for a small business?</h2>
  <p>Banner ads are a low-cost way to be seen by people who don't know you yet, and flat-rate pricing makes the cost predictable: you can
    test an idea for the price of a lunch and keep only what works. They are most effective with a clear offer, a matching landing page and
    a way to measure results, exactly the steps above. See <a href="pricing.php">banner ad pricing</a> or the <a href="faq.php">FAQ</a> for details.</p>
</article>

<?= gb_cta('Put this guide into action', 'Pick a size, upload your banner and launch your campaign. ' . ucfirst(GB_PRICE_PITCH) . '.') ?>
<?= gb_public_close() ?>
PAGE_HOWTO;

$files['faq.php'] = <<<'PAGE_FAQ'
<?php
declare(strict_types=1);
require __DIR__ . '/config.php';

/* Frequently asked questions (with FAQPage structured data). */

$spots = gb_public_spots();
$prices = array_map(fn($s) => (float)$s['price'], $spots);
$days = array_unique(array_map(fn($s) => (int)$s['period_days'], $spots));
$money = fn(float $v) => '$' . (fmod($v, 1.0) == 0.0 ? number_format($v, 0) : number_format($v, 2));
$priceAnswer = !$spots ? 'Each placement is ' . GB_PRICE_PITCH . '.'
    : (min($prices) === max($prices) && count($days) === 1
        ? 'Every placement costs ' . $money(min($prices)) . ' for ' . reset($days) . ' days, whatever size you choose. You pay once; there is no bidding, minimum spend or contract.'
        : 'Prices start at ' . $money(min($prices)) . ' per run. Each placement shows its price and run length on the pricing page.');
$runDays = count($days) === 1 ? reset($days) : 30;

$groups = [
    'Pricing and payment' => [
        ['How much does a banner ad cost?', $priceAnswer],
        ['How do I pay for a banner ad?', 'Pay by card through Stripe or with PayPal when you place your order. Card details are handled by Stripe; we never see your card number.'],
        ['Are there any extra fees?', 'No. The price shown covers the whole run. There is no setup fee and nothing to cancel when the run ends.'],
    ],
    'Sizes and files' => [
        ['What banner ad sizes can I buy?', 'All standard sizes: 728×90 leaderboard, 300×250 medium rectangle, 336×280 large rectangle, 160×600 skyscraper, 300×600 half page, 468×60 full banner, 250×250 square and 320×50 mobile banner.'],
        ['Which banner size is best?', 'If you choose one, choose the 300×250 medium rectangle: it fits desktop and mobile layouts. Our banner ad size guide explains when to use each size.'],
        ['What file types can I upload?', 'PNG, JPEG, GIF (including animated GIF) and WebP, up to ' . (int)(GB_MAX_UPLOAD_BYTES / 1048576) . ' MB. The image must be exactly the size of the placement, for example 728 × 90 pixels for a leaderboard.'],
        ['Can I use an animated banner?', 'Yes. Animated GIF banners are accepted. Keep the animation short and make sure the final frame shows your message.'],
    ],
    'Running your ad' => [
        ['How long does a banner ad run?', 'Each run lasts ' . $runDays . ' days. The run starts when our team approves your banner, not when you pay.'],
        ['How quickly will my ad go live?', 'Every banner is reviewed by our team, usually within one business day. You get an e-mail when it is live.'],
        ['Where will my banner appear?', 'In the ad space you choose on the websites that show ' . GB_BRAND_NAME . ' ads. Your banner rotates with other banners of the same size, and it is never shown on your own website.'],
        ['Can an ad be declined?', 'Yes. We review every banner and landing page and decline ads that break our Terms of Service, such as misleading, adult or illegal content. If we decline an ad you have paid for, we e-mail you once, at the address you ordered with, to ask for a new link and/or banner. If you don\'t send a valid replacement within 5 business days, the order is terminated with no refund.'],
        ['How do I track clicks from my banner?', 'Add UTM tags to the link you enter, such as ?utm_source=bannerpromote&utm_medium=banner. Your analytics tool will then show the visits your banner sends.'],
        ['Can I change my banner after ordering?', 'Contact us with your order number and the new image, and we will help you update it.'],
    ],
];
$all = array_merge(...array_values($groups));

echo gb_site_open('Banner advertising FAQ', 'Answers to the questions advertisers ask most about pricing, ad sizes, files and how long banner ads run.', [
    'path'        => '/faq.php',
    'title_full'  => 'Banner Ad FAQ: Cost, Sizes & Files | ' . GB_BRAND_NAME,
    'description' => 'Banner ad questions answered: how much banner ads cost, which sizes and file types you can use, how long ads run and how fast they go live.',
    'jsonld'      => [gb_breadcrumb('FAQ', '/faq.php'),
        ['@type' => 'FAQPage', 'mainEntity' => array_map(fn($q) => ['@type' => 'Question', 'name' => $q[0],
            'acceptedAnswer' => ['@type' => 'Answer', 'text' => $q[1]]], $all)]],
], 'FAQ');
?>
<article class="article prose">
  <?php foreach ($groups as $title => $qs): ?>
    <h2><?= e($title) ?></h2>
    <div class="faq">
      <?php foreach ($qs as $q): ?><details><summary><?= e($q[0]) ?></summary><p><?= e($q[1]) ?></p></details><?php endforeach; ?>
    </div>
  <?php endforeach; ?>
  <h2>Still have a question?</h2>
  <p>E-mail our ad team at <a href="mailto:<?= e(GB_ADMIN_EMAIL) ?>"><?= e(GB_ADMIN_EMAIL) ?></a>. You can also read
    <a href="how-to-buy-banner-ads.php">how to buy banner ads</a>, the <a href="banner-ad-sizes.php">banner size guide</a> or
    <a href="pricing.php">pricing</a>.</p>
</article>

<?= gb_cta() ?>
<?= gb_public_close() ?>
PAGE_FAQ;

$files['terms.php'] = <<<'PAGE_TERMS'
<?php
declare(strict_types=1);
require __DIR__ . '/config.php';

/* Terms of Service. Advertisers must accept these to place an order.
   When you change the rules, update GB_TOS_VERSION (lib.php) so new orders record the new version. */

echo gb_site_open('Terms of Service', 'The rules for advertising with ' . GB_BRAND_NAME
    . '. You must agree to them to place an order. No exceptions.', [
    'path'        => '/terms.php',
    'title_full'  => 'Terms of Service for Banner Advertisers | ' . GB_BRAND_NAME,
    'description' => 'The rules every ' . GB_BRAND_NAME . ' advertiser agrees to: no fraud, no adult content, nothing illegal '
                   . 'and no link redirects. Breaking them means removal, no refund.',
    'jsonld'      => [gb_breadcrumb('Terms of Service', '/terms.php')],
], 'Terms of Service');
?>
<article class="article prose legal">
  <p class="muted">Version <?= e(GB_TOS_VERSION) ?>. Effective <?= e(date('F j, Y', strtotime(GB_TOS_EFFECTIVE))) ?>.</p>

  <p>These terms apply to everyone who buys banner advertising from <?= e(GB_BRAND_NAME) ?> ("we", "us").
    By ticking "I agree to the Terms of Service" and submitting an order, you ("the advertiser") accept them in full.
    If you do not agree, do not place an order.</p>

  <h2>1. Who can advertise</h2>
  <p>You must be at least 18 years old and, if you order for a business, have the authority to accept these terms on its behalf.</p>

  <h2>2. Ads that are not allowed</h2>
  <p>Your banner, your link and the website it leads to must not contain, promote or link to:</p>
  <ul>
    <li><strong>Fraud or deception</strong>, including scams, phishing, fake offers, impersonation, misleading claims or anything designed to trick people.</li>
    <li><strong>Pornography or sexually explicit material</strong>, including adult services and dating or escort sites of a sexual nature.</li>
    <li><strong>Anything illegal</strong>, including illegal products, services, drugs, weapons, gambling where it is unlawful, or content that infringes someone else's copyright or trademark.</li>
    <li><strong>Malware or harmful software</strong>, including viruses, unwanted downloads or pages that attack visitors' devices.</li>
  </ul>

  <h2>3. Your link must stay as approved</h2>
  <p>The address you submit must be the final page visitors land on. After your banner and link are submitted, you must not:</p>
  <ul>
    <li>redirect or forward the link to another page, website or location;</li>
    <li>use link shorteners, cloaking, or different content for reviewers and visitors; or</li>
    <li>replace the landing page with content that breaks these rules.</li>
  </ul>

  <h2>4. Review</h2>
  <p>We review every banner and landing page before it goes live and may check them at any time while it runs.
    We may decline any ad that we believe breaks these terms or is unsuitable for the websites that show our ads.</p>

  <h2>5. Ads declined at review</h2>
  <p>If we decline your ad after you have paid because your banner, or the content your link leads to, is not appropriate or does not meet these terms:</p>
  <ul>
    <li>we will contact you by e-mail, at the e-mail address you gave when you submitted your ad and paid;</li>
    <li>in that e-mail we will make <strong>one request</strong> for a new link and/or banner, and set out these terms again; and</li>
    <li>you must reply with a valid link and/or banner that meets these terms <strong>within 5 business days</strong> of our e-mail.</li>
  </ul>
  <p>If you do not provide a valid link and/or banner that meets these terms after that one request, or you do not reply within
    5 business days, <strong>your order is terminated with no refund</strong>. We do not make a second request.</p>

  <div class="rule-box" role="note">
    <h2>6. Breaking the rules</h2>
    <p>If any of these rules is broken, at any time and in any way:</p>
    <ul>
      <li>your banner ad is <strong>terminated immediately</strong>, without notice;</li>
      <li><strong>no refund</strong> is given, for any part of the run; and</li>
      <li>we may share your order details and any related information with the <strong>appropriate law enforcement authorities</strong> where required or where we believe a crime has been committed.</li>
    </ul>
    <p><strong>No exceptions. Breaking the rules of any kind will not be tolerated.</strong></p>
  </div>

  <h2>7. Payment and refunds</h2>
  <p>You pay the full price of the run when you order. Your run starts when your ad is approved and lasts for the period shown for your placement.
    Refunds are given only at our discretion, and never when an ad is removed for breaking these terms
    or an order is terminated under section 5.</p>

  <h2>8. No guaranteed results</h2>
  <p>Your banner is shown in rotation with other banners of the same size. We do not guarantee a number of views, clicks, sales or any other result.</p>

  <h2>9. Your responsibility</h2>
  <p>You are responsible for your banner, your link and your website, and for having the rights to everything in your ad.
    You agree to cover any claims, losses or costs that arise because your ad or website broke these terms or the law.</p>

  <h2>10. Changes</h2>
  <p>We may update these terms. The version you agreed to applies to your order; new orders use the version in force when they are placed.</p>

  <h2>11. Contact</h2>
  <p>Questions about these terms: <a href="mailto:<?= e(GB_ADMIN_EMAIL) ?>"><?= e(GB_ADMIN_EMAIL) ?></a>.
    See also our <a href="privacy.php">Privacy Policy</a>.</p>
</article>
<?= gb_public_close() ?>
PAGE_TERMS;

$files['privacy.php'] = <<<'PAGE_PRIVACY'
<?php
declare(strict_types=1);
require __DIR__ . '/config.php';

/* Privacy Policy. Keep it in step with what the site actually does; update GB_PRIVACY_DATE when you change it. */

echo gb_site_open('Privacy Policy', 'What information ' . GB_BRAND_NAME . ' collects, why, who it is shared with, and the choices you have.', [
    'path'        => '/privacy.php',
    'title_full'  => 'Privacy Policy | ' . GB_BRAND_NAME,
    'description' => 'How ' . GB_BRAND_NAME . ' handles advertiser and visitor data: order details, Stripe and PayPal payments, '
                   . 'analytics cookies with consent, and your privacy rights.',
    'jsonld'      => [gb_breadcrumb('Privacy Policy', '/privacy.php')],
], 'Privacy Policy');
$mail = '<a href="mailto:' . e(GB_ADMIN_EMAIL) . '">' . e(GB_ADMIN_EMAIL) . '</a>';
?>
<article class="article prose legal">
  <p class="muted">Effective <?= e(date('F j, Y', strtotime(GB_PRIVACY_DATE))) ?>.</p>

  <p>This policy explains how <?= e(GB_BRAND_NAME) ?> ("we", "us") collects and uses information on <?= e(parse_url(GB_BASE_URL, PHP_URL_HOST) ?: 'this website') ?>
    and through the banner ads we serve on other websites. We do not sell personal information.</p>

  <h2>Information we collect</h2>
  <h3>When you order a banner ad</h3>
  <ul>
    <li>Your name or company name and your e-mail address.</li>
    <li>Your banner image and the web address it links to.</li>
    <li>Your order details: the placement, price, dates, and when you agreed to our <a href="terms.php">Terms of Service</a> and which version.</li>
    <li>Payment status and a transaction reference from Stripe or PayPal. <strong>We never receive or store your card number</strong>; card payments are handled by Stripe, and PayPal payments by PayPal.</li>
  </ul>
  <h3>When you visit this website</h3>
  <ul>
    <li><strong>Essential cookie.</strong> A session cookie keeps the order form and admin login secure. It is needed for the site to work and is deleted when you close your browser.</li>
    <li><strong>Analytics (optional).</strong> With your permission we use Google Analytics, through Google Tag Manager, to understand which pages are used. It may set cookies and collects information such as the pages you view, your browser and device type, approximate location and how you arrived. Visitors in Europe and the UK are asked first and nothing from Google loads until they agree.</li>
    <li><strong>Your cookie choice</strong> is saved in your browser so we don't ask again.</li>
    <li>Like most websites, our hosting provider keeps standard server logs (such as IP address, browser and pages requested) for security and troubleshooting.</li>
  </ul>
  <h3>When you see one of our ads on another website</h3>
  <p>To choose and display a banner, our ad server receives the name of the website showing the ad, and counts how many times each banner is shown and clicked per day.
    These counts are totals; we do not build profiles of the people who see or click ads, and our ads do not set tracking cookies.</p>

  <h2>How we use information</h2>
  <ul>
    <li>To process orders and payments, review ads, and send you order, approval and receipt e-mails.</li>
    <li>To show banners, count views and clicks, and stop fraud and abuse.</li>
    <li>To understand and improve the website (analytics, only with permission where required).</li>
    <li>To enforce our <a href="terms.php">Terms of Service</a> and meet legal obligations.</li>
  </ul>

  <h2>Who we share it with</h2>
  <ul>
    <li><strong>Stripe and PayPal</strong>, to process payments.</li>
    <li><strong>Google</strong>, for analytics, if you allow it.</li>
    <li><strong>Our hosting provider</strong>, which stores the website and its data.</li>
    <li><strong>Law enforcement or other authorities</strong>, when the law requires it or when we believe it is necessary to report fraud, illegal activity or a breach of our Terms of Service.</li>
  </ul>
  <p>Your banner image and link are, of course, shown publicly while your ad runs.</p>

  <h2>Your choices and rights</h2>
  <ul>
    <li><strong>Analytics:</strong> accept or decline at any time with <a href="#" data-cookie-settings>Cookie settings</a>. You can also block cookies in your browser.</li>
    <li><strong>Do Not Track:</strong> our site does not respond to browser "Do Not Track" signals; use Cookie settings to turn analytics off instead.</li>
    <li><strong>Your information:</strong> e-mail <?= $mail ?> to ask what information we hold about you, to correct it, or to delete it. We may keep records we are required to keep, such as payment records, or that we need to enforce our terms.</li>
    <li>Depending on where you live (for example in California, the EU or the UK), you may have additional rights, such as to object to processing or to complain to a data protection authority. Contact us and we will help.</li>
  </ul>

  <h2>How long we keep it</h2>
  <p>We keep order and payment records for as long as needed to run our business and meet tax and legal requirements. Banner images are kept while your ad is in our system and can be deleted on request after your run ends.</p>

  <h2>Children</h2>
  <p>This website is for businesses and adults. It is not directed to children under 13, and we do not knowingly collect their information.</p>

  <h2>Changes to this policy</h2>
  <p>If we change this policy we will update the date at the top of this page.</p>

  <h2>Contact</h2>
  <p>Privacy questions or requests: <?= $mail ?>.</p>
</article>
<?= gb_public_close() ?>
PAGE_PRIVACY;

$files['sitemap.xml.php'] = <<<'PAGE_SITEMAP'
<?php
declare(strict_types=1);
require __DIR__ . '/config.php';
/* Rendered by the installer into sitemap.xml. lastmod = date of the release (GB_LASTMOD) or today. */
$lastmod = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)getenv('GB_LASTMOD')) ? (string)getenv('GB_LASTMOD') : gmdate('Y-m-d');
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">' . "\n";
foreach (gb_public_pages() as $path => $label) {
    echo "  <url>\n    <loc>" . htmlspecialchars(gb_url($path), ENT_XML1) . "</loc>\n    <lastmod>$lastmod</lastmod>\n";
    if ($path === '/') echo "    <image:image><image:loc>" . htmlspecialchars(gb_url('/og-image.png'), ENT_XML1) . "</image:loc></image:image>\n";
    echo "  </url>\n";
}
echo "</urlset>\n";
PAGE_SITEMAP;

$files['robots.txt.php'] = <<<'PAGE_ROBOTS'
<?php
declare(strict_types=1);
require __DIR__ . '/config.php';
/* Rendered by the installer into robots.txt. */
header('Content-Type: text/plain');
echo "# " . GB_BRAND_NAME . "\n";
echo "User-agent: *\n";
echo "Allow: /\n";
foreach (['/admin.php', '/preview.php', '/pay-stripe.php', '/ipn-paypal.php', '/webhook-stripe.php',
          '/setup.php', '/import.php', '/serve.php', '/go.php', '/embed.js.php', '/schema.sql'] as $p)
    echo "Disallow: $p\n";
echo "\nSitemap: " . gb_url('/sitemap.xml') . "\n";
PAGE_ROBOTS;

// IndexNow key (Bing, Yandex and others): proves this site owns the key used in deploy notifications.
$files['8896ea600333598d3649af506349872f.txt'] = '8896ea600333598d3649af506349872f';

// Social sharing image (1200x630), stored as base64 and written as a binary file.
$binaryFiles = [];
$binaryFiles['og-image.png'] = 'iVBORw0KGgoAAAANSUhEUgAABLAAAAJ2CAIAAADAIuwLAACtTUlEQVR42uzdd1xTVxsH8BNCgCz23nvIVBFFEPfee8+6R23raLV1tuqrdbTVuqtV6957771FVBBk7z3DDrx/XIgx4yYMEeT3/fgHJjdP7j25F+6Tc85zGK4t+wkEBaTGuFw24iAO4iAO4iAO4iAO4iAO4iAO4jSgOKoCQUGeIJ/UBsRBHMRBHMRBHMRBHMRBHMRBHMRpQHFUCAAAAAAAADRKSAgBAAAAAACQEAIAAAAAAAASQgAAAAAAAEBCCAAAAAAAAEgIAQAAAAAAAAkhAAAAAAAAICEEAAAAAAAAJIQAAAAAAACAhBAAAAAAAACQEAIAAAAAAAASQgAAAAAAAEBCCAAAAAAAAEgIAQAAAAAAAAkhAAAAAAAAICEEAAAAAAAAJIQAAAAAAACAhBAAAAAAAACQEAIAAAAAAAASQgAAAAAAAEBCCAAAAAAAAEgIAQAAAAAAgIYql8uulUCIgziIgziIgziIgziIgziIgziI07DiqAoEBXmC/FqJhTiIgziIgziIgziIgziIgziIgzgNKA6GjAIAAAAAABDMIQQAAAAAAAAkhAAAAAAAAICEEAAAAAAAAJAQAgAAAAAAABJCAAAAAAAAQEIIAAAAAAAASAgBAAAAAAAACSEAAAAAAAAgIQQAAAAAAAAkhAAAAAAAAICEEAAAAAAAAJAQAgAAAAAAABJCAAAAAAAAQEIIAAAAAAAASAgBAAAAAAAACSEAAAAAAAAgIQQAAAAAAAAkhAAAAAAAAICEEAAAAAAAAJAQAgAAAAAAABJCAAAAAAAAkKDK5bJrJRDiIA7iIA7iIA7iIA7iIA7iIA7iNKw4qgJBQZ4gv1ZiIQ7iIA7iIA7iIA7iIA7iIA7iIE4DioMhowAAAAAAAARzCAEAAAAAAAAJIQAAAAAAACAhBAAAAAAAACSEAAAAAAAAgIQQAAAAAAAAkBACAAAAAAAAEkIAAAAAAABAQggAAAAAAABICAEAAAAAAAAJIQAAAAAAACAhBAAAAAAAACSEAAAAAAAAgIQQAAAAAAAAkBACAAAAAAAAEkIAAAAAAABAQggAAAAAAABICAEAAAAAAAAJIQAAAAAAACAhBAAAAAAAACSEAAAAAAAAgIQQAAAAAAAAJKhyuexaCYQ4iIM4iIM4iIM4iIM4iIM4iIM4DSuOqkBQkCfIr5VYiIM4iIM4iIM4iIM4iIM4iIM4iNOA4mDIKAAAAAAAAMEcQgAAAAAAAEBCCAAAAAAAAEgIAQAAAAAA4KukiiaQxrHQ1vO20HIzUdPlqOuwVdTqopWExaXFmQVFGfnZQYnpz2IL4rLwQQAAAEBd0mlqxrPX51nr8m31WFpsNAh8TYoz83MjMwRRGXnhaZkv49EgSAhlUWHo+1ia9XLl2erV/Zsz1VTZRny2EV/bxchqiFdeRHrc2bfpT2NIWTk+GQAAAPis1A15jtP8NZ0M0BTwtVLT4ejpcPSamRNCct6nhm65V5SSh2ZBQiiWj7FZLnPba7kY1ZP94dnqOc8OyA5ODl57U1hQgg8IAAAAPhOj9vY2o7yZbBaaAhoJTSeDpqt6Rex9mnI7HK2BOYSEEKKmy/FY1q3+ZIMiWi5GHsu6qely8BkBAADAZ8kGOzraT/JFNgik8fUGOUxpbdTREU2BhJCosJguc9tzzLXr5+5xzLVd5rZXYTHxSQEAAEDt0jDkWY9shnaARst6ZDMNQx4SwsaNQRym+fGsdevzPvKsde2ntsYVCwAAALWonEEcpvmraqBvEBovVQ2W/TS/cgYSwkZM291Ev5VV/d9PA19rbQ8TXLQAAABQW/h2+qgiA6DlZMi300dC2FgxGDajvEmD6dH2JgwGLloAAAConYTQXh+NAIBroVEnhFrOhvV26qA0roW2lrMhrlgAAAConZtgBySEALgWGndCqO1pih0GAACAxtorgvGiALgWGndCyHcwwA4DAABA46RhwEMjAOBaaNQJobo+FzsMAAAAAABICBsjlrYGdhgAAAAAAJAQNkZMlip2GAAAAAAAkBACAAAAAAAAEkIAAAAAAABoBDAEsRFp7tWkX68O1M9qLFZxSYnMzYqLSxKT0uITk2Pjkj5ExBQWFaPpPqulC6YxmUyaDcrKyrOzc8MjY6Ni4sPCY/ILCtFoAABQbwmLhXkfUtUNeKhiCoCEEOoXFyfbqROGVO13ulB458HzQ8cunr5wSygUog0/h0ljB6mpsZTcuLRUeODYhZVrt6dnZKPpAACgHirJzn/z21Wr4c3Me7vW5/1MfxGX/SaxKE1ACGFpa+g2t9D1NMPHJy7jZVzCxWDqZ66Vrs3I5miTrzMh5HLZtRLoa41DCPnRsRNftRbKe6YV5/3x4dYXPC51NbWqvoTJZLZv49O+jc9vi2ZN/f7XF6+CG8nnXqdxGFW5YlWZY4b17tuj3Z+b9+/efwrtTB9nzqyx/r7N4uKTZ81bifZBHMRBHMSpA2WlwvyEHEJIcYagKEOgrqt4xaysN4nx596Wl5VT/3WY7qeuzZG3cX58VuyJIEFsZlGaQMOAx7fX5zsY6Le0YrJZVdrP9xvvpj2M+uSeR4P1ZRPCoqz8lFvhueFpBXHZxRn5LB02S1NDp6mZWU9Xphrzs7aGPMVZhdlvkiruVlQY5KvG43Ia7fWuKhAU5AnyayXW1xrndXbCFBu/Wgmlr8ZdGnyxtLys2hFq8nkVFVd/8KeBvu72P5f2GDQtIiqukXzudRen4i8gSU5JP3nuuswk0NTYUFuL7+nuxOWwCSFamvzFP01Ny8g8eOwi2pkmTrdOfva2llyOhvLvgvMZcRAHcRCnGkrzixIvh2Y8i8mLziJlZYSQxMvvEy+/V+Gw+LZ6loO9NB0MiIzBpaVRB14mXg1hlIv9VSyROyIp4UpI5N7nVHxCSH5sVn5sVvLND0k3w5rMbc/iK/v1ffrTGIls8MsqF5ZF7HuefCO0vPTjLWJRSl5RSl7eh7SUW+EO0/20nAw/U2tAlS6cr/J6x5BRxS4nB/cwbmLB1ql5KDdN06Uu3WuYE9aiuPjklLQMiQfZGuoWZsY8nuTXJHq6Wj9+N2HKd8txSnwmSclpi37bROR8a5UnyDc20v926siJYwYwGAxCyKol370IDH4fFoWmk0lPV8vW2hztAADwuWUHJwevuyXMl/G9c1l+SfabpKB3V1zmttP1+qT/Lft9Svj2BwWJuUp2PKU+ior896nse+KwtKDll11/7kzTtfjJ9lEfb37Y5trWI5tpOhgI80u+VAOG73qcfPODvGeLUvPe/3HHc0V38e7WWmwNACSEipWR8s0Rd1e59qmVaPUqJzx47MKaP3fLfMrD1XHGpGED+nQSfzDAzxvnw5fNGBcu+5MQMmnsQEIIl8ueMmHIDwvWoGVk8m/VTEUFhZQBAD6vwtS8kD9vi7JBFTZL29U441mspquxsKBEEJFOlUcL3XS3+YZ+om6rqEMv48+8oX5mctRkJpOf3IyVCCP/ey76r76vtV5Ly5LswoSLwYVJuYSQgvicxMsh1kObKbnPop8N/W2okaKqHLUv0oAZz+PEs0EtN2OD1jaqXLWMl/HJtz9Qfacl2QXRRwIdp7b+HK0BgIRQKcG5yQ8yIlvr2nx9OaE8r9+GTvluuZGhnl+rph9/4+hpt2rh8ejpa5oXamvxXZ3tbK3NLcxNsnJyI6PiwsJjwiNjy8vLZW7PYWu09fdma6gXFBZRj9x9+CIvL1/UydPWz9vSwoStofE+LPJtSLi8DjELM2O3JvaiOIWFxTfvPhE962hv5dPc3dTEsLS09F1IxKugkKTkNKLscFmdVi08LMyM9XS10zOyomMT7z96mZWdK297TzdHUxNDUXdrZEzCy8CKuZdNPV1aebvzedzHz4Nu33tWvY9m2aotvbu1NTbSJ4Q083QRf8rczMi9iQMhpLycXLp2rzKNb+7h6sjnce88eB4Y9F5mTH09be+mbh6uDoSQ+MSUkNDI56/e0eyDX6umKioMQkhEVJzoE3F2tPFu6mpuZlRSXBodl3j3wfPklHTpxgzw87a1MmMwGIFvQkNCI5Qf6uBgZ+XexN7FyS5PkB8bl/giMDgqJkF6Mz6f69+qKSGkR9eAyk9Bo3tn/4q/u5nZj58FSb+KwWD4NHfzdHPS1dUSlgrjE1PeBn8IfBOK334AAPQyAxNKcyr+gut4mTl+26Y0tzDjWayOh6lpd+d3v9/MDkokhAjzSxIvhVgO9qK2TLxW8fdI09XYcWrrZ7NO0L9Lyu3wkoyKvxe63hZOs9pU/NzU/MWc02UlQkJIyp0Iq0FeDKYSXwUKy8WnDtJvWyoozk/IZmlqaBhwGVX8nrEoTSAsEbKN+dTQHtkN+Dr+45/j1tZOMysOTa+FJdtEM/rgC+q/uWGpn6s1xOZ/CmKzmBosthGvSkdakltYnFVYml+spqnB0tL4HKl1qaC4OLugJLdIlaOmpsNm8dRx6SEh/AJ2RT1soW3JUmE2npyQEHL/0UvxhJAQ0rqll8yEUEdbc/ig7v16dvDycJb+rRcVk7Dsf1vOXbot/UJNTd7ebZ8U/Og1ZMbjZ0F8Pnf10u/79GwnUQvnXUj4rHmrXr+VvFNv07rZn6t/Ev03N1dg69mdEOLqbLdy6Xe+LTwk9urClbtzf1mbmpYp79j5fO64EX0H9e3s4mQr8VqhUPj4+Ztlqza/CAyWfuE3YwYOH9Rd9N+bd58MGTvX3Mxoz9YVHq6O1IMPnwRWOyEsKi5+FRTSzcifEOLiaMPjcUT5s3+rZht/X0D9bOzQXl2NtW/7qgC/ippgbi72EiN++XzuikXfdmjrY2SgJ/n3KSvn0rX7f2zeJz1rlBDyy7zJzo42hJAde44vXPank4P11j8Wu7nYi29TWFR88OiFBcv+pOrTctgaK5fMHti3s4b6xw+0vLz88IlLC5f/lZsroMkDVy75tkVTN+mZ05HR8SfOXtuy87BQ+PE6srYwlTijzM2MRI88fBLYZ9gs8WedHKxnTRnRtaOfthZfIn5iUurZS7f/2rpfOrMFAABKflyW6GfTHi6qGqzS3IrlkVRUmbbjfF7OOV3xpznik9+lqnx1q2HNjNvbK/MuGYEfvwE07uQg+lldn6vb3DztUTQhpCSzICMwQa8Z3WSBt6uul5eXFcR9rNSdeDUk/VkMIUTfx8q4k+PHG4nwtOgjrwRRGaW5FekuQ5XJNtU0aG1t1quJdL70ZtU1Ul5OCFFRV20yp31Bcm749ofZwcmEEPdl3WROoay4qSgo5VjqlAqKSwVFRm0/aQ3dpmaihLAwMaesVKiiyqzF1hAvThP+z+PcD+nlpUJCiCpP3bCNreUwLwaTPoEsS70XEXfuXWHCJ5XPdZqa2Y7zEV905P3meyVZBdTPRh0cDFpZS97bpAvCtj0Q/ddxur+aNpvKUVPvRcZfCC4QO80IIVwrHbO+bvo+lgwMBUJCWJfSigUnEgKHmtda53uDyAml+8Fk3hk7OVifOvCXvp62vDjWlqa7N/965/7z0ZMXSCyjl5mZQyR7q3S0NHlH9qyT6P6iNHG2u3h8y5yf1x46/kk9lZxPMwo+n8tiqTo72Bz+d62Bvoz5nz26tGnRzG3Y+HnSuSUhxN7WcuemZVYWJkRO8dXWPp4Xjm3+dc32v3cclHhWkP9JlxefxyWE7Nq0XJQN1lzoh+hunfwJISoqKm18m128WtEZWFjZy0oI0dbiLZw7WZQNSuvUrtX6lfNMjGX/iaIy/P69O077/lfpTD4/v+JD1NBQd7S3OnNoo66OlsQ2Gupq40f143LZM+as0NfTPrTrd093JyLVLzdsYPemHi5tuo2V2Yc8e9qoH2aO4bBlz4y3sTKbM3Ps8IHdx0795VVQSDVaclC/LhvXLFBVlf0Xz8TYYPK4QUMHdBs58UeZ/YoAAFBWJDb1Tqo/imOiaTehJdtci2utqyrWF6fjbmo7xltNR9lJbnkfPvaP8T8tr8J3NqRSIEJIbmgqfQqU9S5RvHuQEFIQn1MQn0MI4VrrivKcuLNv4k4GiZd4IYSUlwrzYzKjYzKzAhPsp7aWWGUx90NaWUEJIaScQYQlpe/W3ChMzFHm0Byn+9FkXKKfWboclcq/VrXVGqI9D157oyTn4y1EaV5RwsVgQVyWga81zb6FbLiV+TJe+qnMl/Gvgs+6LuzEt6+4x2Ab8dPuRVZ+F0ykE8KM53GicqY8Oz1RNhi87lZWoIzRQILozNC/7qY2M3f6NkBeCVZAQvhZHI1/2dnQWVeN03hyQrcmDhKPhIVHE6mxmod3r6XJBsnHKYjNVy6Z/d1PqyX6u0pLheJ35Hwed+2KuTKzQYqaGmvpgunnLt8W9YwRQgqklms3NNDdveU3mdkgqRy++Mv8yUPGzpXOBg//+7t0hiOdFi5dMI3JVPlr6/5Pjqjok4kQXA67b4/2TeUfTjWI92WJJ+3ihWTtbS1HDu5B/RweGctiqRaXfPyb3czTZf/O/1FT7IqLS/7Zd/LZyzdPnr/hcjS8PFx8W3iMGd6HwWBoqKvt3Lh08Ni5dx88/7QwasVfU46G+upl3+vqaMXGJ1298fDJize62pruro5D+ndhMpmEkCH9u+7ad3LWlBGe7k7pGdlXbjx4/Ow1k8m0t7UcNaQnn8+lvlAYPqj7gaMXJA5z+qRhv8ybXPFNRGr6nv2nX74OefbyrZWlqaerY6/ubdu38SGEmJoY/rdjVZtuYzOzcgghqemZW3cdoXJ+S3MTQkh6RvbRU5dJZWe1KH6HAJ/N636mun/Ly8uPnLz84HHg9duPjAz0vNydevdo186/BSFES5N38J817XtNiI5NxK9BAAAJGkaaop8Tzr9lm2pKbCDe7SbiPDtA+bcoySsqya74K6+qqa6q/skgTyp5EC2BWPMjij3xOu7Uxy8BVTgsrrl2SU4hNT2PEJIdnPx25TWv1b3FUxGmGpNKCBnlJPl6mJLZIFEwvTBW9LOup+nnaI2yUuH7v+6IZ4PqBjw1HbYgNis7KLGsqFTeCyP3PRVlgypqTJ2mZup63IwXcVQrCQtLQ7c+9FzRndo9o44OsSffUAVRs98lFWUXqGt9Muon48XHIzVsY0f9EHMsUJQNMjVUuXb6PGvdwuTc3LC0kuwCQkjmi7jwfx46TvPHZYiEsO6Ulpftin4416FjbWZc9Tgn7NOj3dABXcUfefws6OmLtxKbzf9uvJnpJ99OXbx6b8s/h9+FhLu52C+YM6mlt7voqZFDel68eu/y9fufNKzwk4SwTetm/Xp2oEYt3n/0Mr+g0M3FvomzHfm0gOS3U0auXLfj42+9UsnfWT/PmUx18YVHxj15HsTlsFs0c5XoEGvfxqdN6+bi2Q6Dwdj6x2LxbLCsrGzD5n3nL92JiIpzdrSZMLr/kP4fm2Xe7PFnL96KjP74DVlx8Sd7oq6uNrwyMastouKZZWVlL8WGrZaWfizVPW5EXyaTefDYxTV/7oqLT5ZYY+fXX2ZR2WBaetbEmYvvP34lemF4ZNzx01cfPAn8438/sjXUmUzm77/+0Kbb2JKSUrFBsxWnaxu/5ob6uldvPpz2/a/ZOR/n6N+693TbH4upn/9e97OdjcXrt6Hjpv4SG58k2mbPgVP3r/zHZKoQQqZ9M1QiITTQ1/l++mhSOaN1zJSF8QkpFTlw0PvAoPd7D5399ZeZUycMIYQYGer9tmjWjDkriFixVjcXeyohzMjMklm+deGcSVQ2mJGZPfvH1Zeu3aOquSanpL9+G7r30Nm+Pdpv2bCIxVLl87m/zJ8yadZS/BoEAJCg72sdc+w1da+f+SL+2czjOp6m1DqEwpJSJqsWbjVL8j6mKyxNyekD4usriCc2MvntG0U+XYTQdpyPSZePA1jyE7Ljz3281THv5241pGLeY05oSsiG21QyVpicG3/ureUADyKrdzT+QjBLi23Wx5VjqlWUIVDXVbYvoby8PCe44k92ZlBCwvkQ0fBay0Getd4ahJDUB5FFaZVjrJgMx+l+Br42hBBhSWnY5vvpj2PkzehLuRUu+q/L3PbabiaEEItBnoE/nStMySOEFCZkZ71K1G9pSQhR1+boNjPLeBZLJcxp9yPNejQRW7OkOOttkmhcroGfjWiByoqD0mF7regpynXLy8qC19/OfBFHCEl7GG0zyhtrbCAhrFP30iN6GLk20TT+OnJCf99mVBeNNBdH27b+n9QUffU6ZMp3yyTG9amrsXp2+eRLvvdhUWOmLKR+vv/41ZCxcwIfHBfv0RozrLdEQigRk0q3jp68Mmv+Kmr6GYulevCfNRL7062Tn3hCKD3ecHD/LkKh8JdfN+3ce5xUdmaeOvgnlSSI9OvVQTwh7N+rg6fbJ99l/rT0j93/VSwB//zVu+ev3hUVl4we2otUDoz86YeJU2Yv+5hAfvo5GujpUO944sy142euPngSaGZiqKFR/cnQDnZWPs3dRZPoCsU6JMvKPr51r+7t/tl74qelf0hHGDawu09zN+rnRSs2iWeDIifOXHNv4jBz8nBCiJ2NRa+ubWWukWiorxsZHT9y4k8SH+LJs9d//WWmob4u9fKi4uIR3/woMd44PDLu0dNAapKqs6ONnq5Weka22DTFKdRpIxQKR09emJCYIv3ui37b1NTDhfrGYUDvTr/8upHqJFTG0AHdRENYV/+xS1SDR9zpCzcD/L3HDOtNCOnTvd3v9lahH6LxaxAAQBzbiG8zunnknqeiei2ZL+IJIYmX3yffDtdyNdbzttT3tWKqVf+eUzSLjxCioio5KlVFrJuuOLOghocTvvORaKSotoeJKBskhGg6GloNa/ahcqpb/Jk3RgF26voy7qOKM/ObrurFMdcmVV6QsPzNb1clHtT2MLH7ppVoeG0ttkZ5eXn8uWDx3J7KBgkhTJaqw+TWWYEJwsJSWQvW5xt3cRIWFAsLSoXFpVQ2SAhR1WDpNrdIuFgRs0Csm9SwnT2VEBJC0h/HiCeEGS/iRON49VpaqnLVKircRFcUelDTYYv3fDJUVCwGemg1MVLlqqvy1AiDgcuwhjARs8o2R9yVVzCzhjmhKqOuPw5fH8+pE4bI/CeefeXmCv7ecbDH4OmiLhrxMaWx8UkRUXFJyWnZOXlFxcVXbjwQ3yC/oPDGnSfij1hZmircsSvXH0yf8xuVDRJCSkpKp36/XLyHihBibmqkMM6KtTtE2SAhJDY+acnKzRLbSMSR6M0LCY0UZYMfw/6+TbwISvfO/vLyakIIn89VVWWu37R3ynfLr9x4mJeX/z4sSl61T2WsWjJbTa1ifMj+I+fFnyor+3hmqjKZ6zbtkX45l8P+ee4kUjkj9OTZ6/LeSDx4357t5W22bfdR6SuivLw86G2Y6L9HT16ROfv0tVgZTy1NvviI1mEDu1E/X735UGY2SDl2+krF8aoyRS9RiMlk/jBzDPVz8PuIXftOym3tdTuogbgqKipjR/TFL0AAAGmmXZ1dF3TiWErmP2WFpZnP4z5se/B0+vGUuxE1WPX+41efDJbkzRKDJZYCZdcoISzKyM8JSRHPYYhkd6iVikZFZltWLKSq0Ugz9LepRjYo4zadxbQa2dzp2zbi8xVrsTVKsgrEi7Xot7T65G8lm6XbwlLmCzlm2jYjm9tP9HWa1abJnE/uEFg6HzO3wuSP3/Pqepmq6XFFFVM/dksSIj4R0dDftuJAmCoqlZ2ugoiMd2tvpL+IE91v8G30zHo0MWprp9fcAhVHkRB+AXGFWZdTQmo97JfKCRUKC48eN/2XZf/bKpGPkcoes7Y9xrfsMMLdd4C9Vw9z507LV2+V2CYpKZV8WghE4Zv+seU/iUfS0rNEpVNEiZZ0bUxx6RnZ/+yVLGN9/vIdiezCwsxIPE/w9fEUf/bZy7cyI798/fEcYGuod27vS3fOxCf/b8M/Nf8s2rfxuXBssyhXf/U6ZOO2A/I2vvfohcwaqr4+ntSSFYSQE2evi7JuaR8iYkSVVJp7NZG32aFjF2U+niHW3Xf99mPZn1Fmttjc0Y8janp3bydaQnD/kQs0bXLs9FXRgiWijlOFmnm6iIbdyix+K37ihUdUfKPp08wNvwABAGTSdjfxWtWryYKO5v3c2GaS0wiF+cVhW+5XOycU710sF0p9BSlWaLom/ZBUgiT+X66ljvSesE21xJZbkD0mU9PFqFZataxEGL3/+ZOpx6OPvBJW3obVYmuU5H1S9YBjqiWV+Cmop5AbnpbyICr+YnDMidfUv5x3yR/3X2z3GCoqxh0/VqZIqawxU1YizHgZJ6qSqu1hIprCo9vc4mPS+CI+ZO3NpzOPf9j5MOlaaO6HVFx0BENGv6yyzza2U4XUu15vBzur4/s25OTmHT5xedmqLeJlS5QkyC+QKAmjo61JM7TvfVjU0xdvZD0eSUg78UesrUyTU+WuB3Dx6t18qUoz5eXlYeExonUCCSHGhvpEbFENiVUuxOe8SeTJ4gU8Pd2cTpy5Jm9PDhw9r0yvsr2t5eWT22ScFQyGgYGujrameLHNouLiWfNX0UR7GRgiZ8lBHbF09w1RtBwlNSDT2EjfyEBPurWzc/IkPt+Pv7uzP37EkbLWriCEZIolhBzOx+8UxedwXr35kGYP8/Ly34dGenk4E0JcnOyUPCfNxE4AhaViQkIjqSmsTo42+O0HACAPg8HQcTfVcTc1am//fPYpgzY2DBWVjJdxolUKow++0PO2YLJZVY3MEhsuKFH5kxBSXvzxETUddk0OQaJLTU1Pxtw/NR2OgKRXVm0plL3DWtXcDQaT4fZL53JheWFybm54aur96PJSYXmpMO5UUHFWgcNk39ptjdK8ok93W12q5TXk1yZNjTr0Ujz9U8iwnV3sidfUPqc/i7bo50YIyXqTWFY5KtWwzScLfdmM9i5MysmLzPiYwWYWJN/4kEw+EELY5lpmvZoYBdjj0kNC+AWYa2h3NXKp9bBvchKWB18qLhfW5bHExSenpGVIP66lyTfU1xEfBqnJ500aO7Ctn/fkb5e+DQmXGa1ze19nRxtrS1MLc2Px4X/URDLlxcsZH5gkNeZQTU2N/uhkPp766SGris13t7ORrM684IeJC36YqHCf3ZvQ/T669+ilMgfO5bKbKVeM9EVg8E9L/wgJjaTZJisnV+bjerraYoOBFdQfyxdL9hzsraQTQprBnOITGj9ExMr5bkV2nqyro1lZoaeEpg+TIuohNDHWV/IcE/WREkI6tmvpVvnxqbFY4rVYK7LHykHFbA11YyP9pOQ0/BoEAFCIY65j3ttVWCwMXlexNn1xVkFWcLKSK+N98udeLL8qySuiyWqqnYlRmOqf3BgzFH3/ryJnwYNqr8zOYDC0mhgTQoi7iXEnRwM/2zerrjHKCSEk5dYHk06OPFu9WmwNleou2JAfl/Xmf9fL8ksqK8GoqBvw1HQ5DAYpSs+XV2FVXZuj29wi/XE0IUQQkZGfkM0x1cp8FS+eMUpUTHVf2i3+fHDG85i8cMk7kIK47A9bHxbE51gPb4bLDQlhXZtu24ZZ2wM73+QkfJGiMgePXVjz5255z3q4Os6YPHxA749VVR3trbb+sbhD72/Eh48yGIyRQ3pOnTDEycG6VvYqMUn2MIDMrOyqpbsJyXJ6rnLlvYRaM7AaxBMMImvYYa20TEZmdmxc0o49xw+fuKRw42w5hym+QEhObp6CXKugSOaQTvKxpGqJwj0RCoVV7VjW0daS2b1MnxDKW65QmpGhnlgZoSrUDdbX1UZCCAAgUl5Wlh+XlReVkR+dxbXRFU0AE1+MwSjAlkoICSGFybnVuVvlsBiqTGrN9OLM/LLSMvFiKkUZArEUokbVJjWMPxnsWpRdwJHqzyzO/PhdKttU67M2r7abibarsWiBvuyQFJ6tXi22hsQ6kMXZRaoc9U8PVvZf4Yi9T0XZoK6PpeN0f9EKHHFn30YffCH3728HeyohJISkP43h9HXPfFWxsISmq7GGAV96FqVFPzeLfm4luYXZb5OzQ5LzwlLzorNI5ZfO8WffarubiKraABLCutBa16Z2S4x+wWxQoddvQ6fMXhYblzh72ijRg86ONnNnjVu1fqfokT9X/zR8UPdafF9lcozPFEfeAuVKvJDuUkqV1Q0rLTwydtn/tsh8Kk9Q8CooRLySjUJFcg5ftPKExEL2snOtoiKZQzo/N15l8qnMhyi+8KO2Fj8rW/HdhvKpowQtLT5+DQIAiAjisgN/Ol85jdBUOiEkhAiLheL399VNjYypriRGOckLT9d0MhDvrRL9rOlUo8l76rocFTVmWeUO54WlcT5NEUsFxfkxH9+ObVLTPwqFqXnx594WpQuK0gTFmQXN/+yvqvFJCqoitsxgaV5h7baGmg67nEEYlYN1BFHpHBNNiTXrZd+WRGSIlxQSX4+xMDWXPsXVMOJT3wtkBSXqNDUvSs2TKCcjE4uvod/KSr+VFSGkKCs/fMcjUSmajBdxSAiRENZhYzFUxlu1aiTZoMiq9f9MmTBEQ11NbIhdK1FCOKR/V4lssLi45ObdJ2+CP4SGRVFdNz27Bgwd0K1BfMTiK+lRbtx5EvohSuELc3LoutpKhUqNBM7Ly5conCOexeUJ8mvlGMWzU319HfqNdbQ0ZQ4f/dzSKsvhiE8mVCZJy81TqpXEG3Pxik2i1erZGuoFtEny+7BI/CYEAPj458lSh2ulQy0PkBWUkHo/wsDPVmLqvviCdVwrneq9kX5ra9HYwvRn0aIUqKxEmFXZ/aimzdb1Nq/hERm2tUu6WlEBO+1xlGGbTw4n5X5EeeWSvxrGfE0nw5reW/LVk65/EPV3pd2LNO7kKJ5/5oWliNf2rN3WoEao5lSuAZjxMl607AQhpCSvSHw858c7vZxCoVilU/Ekv7SoJOOF2Eukat4wGAzjjg5RB14QQnJDU6m1BAkhKhyWXstPKpoKS0oLk/IKU/MKk3JMujipiH1lr67NsR7lLUoIpcfNAhLCz2igmZeBOq9RZYPUeL+Q9xFU0Q6Kvc3Hok+Txw+SSKiGT5gvURLGu6lrQ/mIc6S64J4+D1q7cc/XdBqLl8kxNFAwvfOT8aU5eXW2kwmVw4ZZLFWJ9Qml6VUmjQJBgVC53Ft8JGpySrooD6/FxBsAoJGwHNo0eM0N6ufQv+8n3/ig5W5KCClMyom/GJz6IEoQniYaE8i306veu+j5WEb8+5TKQ1JuRxj42fKsdfNiMmOOBhYmVXRJGQTYqVR3pI+IRX+PlPuR1HjIjJfx8efemvWquI3JDk6OPfb644EP8ar526lqsLTdjLJeV2RxkQdflBaV6jYzV9Ni54WnxZ58Lb64vCidrsXWMGhtLUoI0x5E6zWzoHrhhMWlYVvuS2d0hBAWX52poSpanzD3QyrfXp8qcBq25UFJRj7NFEdCiEEbu6jDL4mwvLy0LPFyxSpcBq2sJbpGEy+GRB8SlWBgmPX4pMiCICqdphgsICH8XPTVuINMvRpbNkjR1v5k/ACHo8Fha+QXFPL5XFHtfsrx01elC4RaWjSYfvyw8GipyWb6X9mZHCNWVNNAUb0fA7EuxNDw6Dosd/QxazU1NlSQEFaWyYmXX+FGQlR0vHTNGAAAqAZdLzOTrk6iO/vs4OTs4GRCSPLND+KbqXBYjlNaM1RUCCFF6YJns07IC/h89sflfzkW2k1X9yaEMNVU9VpYpNwOJ4SU5hUFLjwveUfLUzfq4FDzw1HTZpv3co058ooakBl14EXK3QhtN5Pc8LTc0I81DjSdDQ1aWddKA1qPaP76w2UqBS0rKIne/zx6/3PpzXSam4vWNqzF1tD3sYrY/aSiWmlZ2fu/7iReM2KbamY8jSvJLhAN7ySEkHKGqJePbaYlqvISfTSwOLtAhaWaFZSQ+z5Vy824MCWvKCWPEJITnJL2JLpcWCbe8aimpaHva512L5IQUlJZ1tXAX7KOt1F7+9jjr8tKhISQqP+eJV19z7XWZZvwGarMkpyitAcfB+xoOhrgMiRYh7BujLdqxVJhksaXDdrbWlp9mtGFhcdQyzn4NHMTrw5MCElKkTHWvHVLr4byKQcGvZeYgebdzPUrO5PDxVaA8PZypR9J0rxyg7j4ZJmrGn6uhFCsIBD9+ePkYC3qxnz3PlzJ+HcefPxb69eqKX6/AQDUhNXw5hYDPVT5stcHV+GwTHs2abqql7o+l9QocWqmJWd9P3VDnvvSrmzD2hnGZdbL1WKQJ2FW3OHkx2YlXAwWzwZ1mpk7zW5bW63HtdRxmd2WSVuYlGen5zjD/3O0hipXzXrEJ1U6c94lJ18LK8ku0DDVshzkQT4uKvhxDI55f4/K9JCUFZTEn34beyww930qx1LHcbq/aEZfeanw/R93Yo4GSiZ7bT+pJso24Ws5G0lPGnScHaBSWdSnMDk3/XF03Kk3sccCk66EVBRTZTKsRjTTdDTENUjQQ1gHXPhGfnq2tRXtVVbcb+8vN4hs0EBfZ+sfiyWyvjfvwuRVUjE1lrwmZ00ZIb3sBI/LoVmH8Mu6ceeJeGFVNxf75l5Nnr96J5Ep7dy4TE1NNSUtMzUtIyU14/HT1/JW46hvkpLTnr96Ry0036WjL82akL26BYhyrXuPXtTlTl6/9Tg3V0AtfDKgT6dtu4/K23LsiL6in2/dfapk/LT0rFevQ6iB0B0CfOxtLT9ExMjbuHf3tplZufcevsBvQgAAInvheKblQE/Tnk2ygxIFMZl5kemZL+I5ltp6PlZazoY8B30mqxZuOFl8jSYLOkUffJH+PJbqgKpIqKx1Xea3V9fm1NbhqKiqWA7w0PEwjTr8UhCZIZovp6LG5Fhom3R1pi9/Ug3a7ibNN/SNO/Um8WqoaI4iRU2fa9Hf3SjAjsFU+UytYdrNRcNEM+bQS2ouKEWziZHjTH/xyKIxooQQvWbmHou7he96lB+bJTYW1MZ2rI8qR81igLsgNjMvLE20q5LH62rCNuEXJOZWrjYhuzNTr5m55/JuyTc+pD6IKvl0iUgVDVWjtnbGXZwlquAAEkLy+RaLn27b5qvMBi0tTLp39pd+XJPPMzHWt7e17Nuzg3g5mYpDCKpY8fydVArUt2f7dZv2UHX51dXUvp06Ys6ssURWr6O8Bd+/uH2HzoonhISQpQumj5j4o3iFzx9mjOnTox0RW3CvS7/JDeiUXvvXvwd3raE+o1FDe23cdkDmZiOH9BL9fOTklbrcw+TU9D0Hz8ycPJwQ0szTpamny8vAYCKrGGmf7u0q11QUnDx7nXwy/bVMdD5Lv/bIyctUQshgMKZNHDpn4e8y98Ta0nTT2p85bI3klPSV63YcOHoBvxIBAORNh9NrYanXwrIwNff5i3gDP1vz3rLHoajrcf0OjK5eqmYz2ttmtHdpYUlBXDaDpaKmxWZpaUh8c62Q06w2TrMU3Nrx7fXdf+5MCCnOKihKy2NpsdX1uTRv5LNpYA3TXZvR3lbDm5XkFJTkFAmLSll8dRZfXZWnLu9Na6s1CCG6nma6nmalhSX50VnlQiFLl0OVV1XX5sj7pDSdDJqu7l2cmV+YJigvEbK02ZzKRTjUdbmey7oXpuYVpQnYZlpqmhrSS3cUplZONWQyDPys5e0Yx0ybOsaSvKKS7IKSvGJVtqqqpoaapgY1/BiQENaRrkYuFmyd2soGf31/SShnMe66N3RAt6rW/3z+6t1/R86LypPk5uWLL0+no6157/LeazcfqrJUfX08qb7BpOS0xKTUpmJLrs+eNjIsItpAX1fmXf6Xde/hi/OX7/TsGiB6pFULjzOHNp4+fyM6JtHOxtzVxb5Te1/xlxw+cTnwTWgDOqWv3Xr08Emgr48nIeTnuZMKC4t27Dn+yZ9qNbXN63/p2LYl9d+nL97cffC8jnfyr637Rw/rraXJI4Qc/GfNNzMW3X/8SnwDCzPj/3asEq0ouPfQGWoks3hWSSpr59ham0eIjZUlhOw9eHbU0F5NnO0IIWOG9VZhMOYvXi+xDy2aue3cuJRao0Jbm//0xVv8PgQAqCfJJ1XIpA6oabPVtOto4SUVVRV1Xa66LveLtIaqBkt8BQulGkeHI7GYoYiGAU/DQPaw1YTLIaKOUMM2dsocL4unzuKp48xHQvjFeGqZvclJrHmc5KKcjeF3GnRTPH/1buCo78UrNB47dWX8qH7i22hp8gb27UzEipTOmLuifRsf8YTQr1XTl3ePEkIMbAPq4WHO/vF/djYWzo424gNH3VzsZW78+FnQz7/+1eA+yhVrtx/Zs47D1mAymSuXzO7ZNeD127Cgd6EG+rqOdlatW3rZWJmRysXrZ81bWfd7mJmVs27jv8t/nkkI0dPVOrj79zv3n70J/hD6Idre1tLJ3trft6loUYqgt2G/rtlOJCvTJIuG+B74Z/XVmw8JIeamRvMXr09NyywqLp45d+WxfeupIKOG9mrp7f7s5bvAN+8zs3Jsrcx8vN3bt/ERRdtz4ExYHZbVAQBouFhaHLdfOqsb8NAUIC3jeVzChRBRnRqzPq5oky+fEHK5tfOdx9cahxDyv9Cr9ecDq8lxqaupVfu1IaGR/x0+d+TEZQaDIb6y+R9b9jX1cBZflIJ8ujrf/MUbXrwKTk/P/mb0ADZbXeYi6RIjG1gsVfG3ENFQl3w5W0NdtCVbQ11qezWZcVifTn1kfLpWOzXUcMLMxWuW/eDvq6DcyO17z6Z8t7y8rFw8ghqLJXmYHA6h6RWuPH4VFRWZO6z85y7eCDIPXxTnbXD4lNnL/vjfT3q6WlSKLrO2Slp61sSZS5JTMiRCMStnMtDss1g7MORtoy42IFn80yQVw3fPcdjsOd+OZamqsjXUu3b069rRTzrI67ehM+askD4BTp2/MXHMAE1NHiHEzsbCrnK5lFXrdhYUFBFCIqPjR078aesfi6wsTAkhDnZWDnZWEutqUg4cvbB+4x55R9F4fh8iDuIgTqO6/yE1mFKo1cQYN9kg7v3Gu0XpAkKIeHkey4Ge1NjU+kCZv/Jf6/WuKhAU1Na6W19rnHqlJp9XUXExUXrtwbT0rKSU9OSU9ITElKs3H1y58VDext0GTps9beS3U0ZKnJRXrj9YsmozVavjbciHaT/8umLRt2amH6vOpKRlUMcikSuVlJTKPMbCIsnVbAoKi0RbSq8nXlhULDNOSWmp+H/LZZ0qiYmp/UfObu7VZPK4Qd06+1ODBsWFR8Zu3nFo3+Fz5VIDgItLSiRPxfx8uk+t/ONcRJrNlPncxRtB3uGLHrx07X6zNoMnjRs0dEBXBzsric0io+PPXLi5ddeRtPQsWWdImcJ9FmuHcnnbFBUVy/w0RdZt2nPw+MWZk4f369lBfA0M6q2D3oYdOn5pz8HT6mpq0q8Nfh8xaOyc7X8usbY0rdil4pK4hOTM7BzRxs9evm3Rbviwgd16dW3bormbzqcrrAiFwtv3n+/cc5zqXcTvQ8RBHMTB/Q9A9QiLS8VTQUKIZhMji35u9WcPlbxwvsrrnWHt1rVWAtXWgs51Gad6E5q/rMBJx+tnO3PYGs2buro62+nqaCUkpbwMDJaeVsdgMOxtLa0tTYVC4f1Hr8QT1Pp8/vD53A5tfMzNjPX1tHNy89IzsuPik27cefI1XRd+Lb2cHG1MjQ0E+QXRsYmvXodIzLj74sfFYql2bNvKxspUX08nPTM7Jjbx4ZNXovUJaeIwGAwHO0szE6O4hOQPETHltDN42/g2c3Wx19PVzsjMSsvIev7yHX07fE2/DxEHcRCnEcZpiDdC0EC9W3cz83mcaEyUaVcni8GeEovRf1n3R+xrtL83MIcQakd+QeHdB8/pS4+Ul5eHhUc3uIlYubmC0xduft0f3/3HryRKttQ3JSWll67dq8YLy8vLQz9Eh35Q6qx7+TrkLpaXAAAAqG2OM/ypEqaqPHV1I56qOgttQlBUBgAAAAAAGklh2KqWMAVSd2vsAQAAAAAAABLCxqYwNQ87DAAAAI1TUXYBGgEA10KjTghLGtpnX4Jf3AAAAFBL8qMy0QgAuBYadUIoaGifvQC/uAEAAKC27iuiM9AIALgWGnVCmP4sBjsMAAAAjfUmGF80A+BaaNwJYVZQUmFyboM5U2Mys4KScMUCAABArch4ESeIQSchNPpsMDYr43ksEsLGqrw8+sirhrKz0UdfEdo1tQEAAACUV1ZUGvr3/bISIZoCGu9VUCJ8v/FOWbEQCWHjlfYwKvV+ZAPYz0fRmc/jcNECAABALcqPzYo6+ALtAI1W1MEXBXHZBMtONHIfdjzMi0yvz3uYF5URtuU+PikAAACodYmXQj7sfCQsKEFTQKNSKigO3Xw/8VIImgIJISkrFgavv1WcXVg/d68kpzB47U0M5wAAAIDPJPlG2Msfz2a/Q6kCaCyy3yW9mHcm9V4EmoIQooomIIQUp+e/+OGUy9z2Wi5G9etkDU4OXnsTX9oBAADAZ1WUJnjz21W2CV/T2UjT2UjbxUhNn4tmga9JYWpeTkhKTkhydkhKYWIOGgQJoSRhQcmbFVf1Wlia93bl2ep98f3Ji0iPP/c27UkMKUMhGQAAAKgLBYm5BYm5yTc/8LicPEF+zQMiDuJ8TXGQEDYCZeXpj6PTH0dzrXR1W1houRip6XLUddgqanXRSsLi0uLMgqKM/Jw3ienPYvNjs/CBAAAAAAAAEkJS52tTZgiiM2LxzQQAAAAAABAUlQEAAAAAAAAkhAAAAAAAAICEEAAAAAAAAJAQAgAAAAAAABJCAAAAAAAAaBBUuVx2rQRCHMRBHMRBHMRBHMRBHMRBHMRBnIYVR1UgKKit5RAQB3EQB3EQB3EQB3EQB3EQB3EQpwHFwZBRAAAAAAAAgjmEAAAAAAAAgIQQAAAAAAAAkBACAAAAAAAAEkIAAAAAAABAQggAAAAAAABICAEAAAAAAAAJIQAAAAAAACAhBAAAAAAAACSEAAAAAAAAgIQQAAAAAAAAkBACAAAAAAAAEkIAAAAAAABAQggAAAAAAABICAEAAAAAAAAJIQAAAAAAACAhBAAAAAAAACSEAAAAAAAAgIQQAAAAAAAAkBACAAAAAAAAEkIAAAAAAABAQggAAAAAAAASVLlcdq0EQhzEQRzEQRzEQRzEQRzEQRzEQZyGFUdVICjIE+TXSizEQRzEQRzEQRzEQRzEQRzEQRzEaUBxMGQUAAAAAACAYA4hAAAAAAAAICEEAAAAAAAAJIQAAAAAAACAhBAAAAAAAACQEAIAAAAAAAASQgAAAAAAAEBCCAAAAAAAAEgIAQAAAAAAAAkhAAAAAAAAICEEAAAAAAAAJIQAAAAAAACAhBAAAAAAAACQEAIAAAAAAAASQgAAAAAAAEBCCAAAAAAAAEgIAQAAAAAAAAkhAAAAAAAAICEEAAAAAAAAJIQAAAAAAACAhBAAAAAAAACQEAIAAAAAAIAEVS6XXSuBEAdxEAdxEAdxEAdxEAdxEAdxEKdhxVEVCAryBPm1EgtxEAdxEAdxEAdxEAdxEAdxEAdxGlAcDBkFAAAAAAAgmEMIAAAAAAAASAgBAAAAAAAACSEAAAAAAAAgIQQAAAAAAAAkhAAAAAAAAICEEAAAAAAAAJAQAgAAAAAAABJCAAAAAAAAQEIIAAAAAAAA9ZAqmgAag8jXl3g8jrxn/95xcOmqLWglgIbowdV9DnZW8p49e/H2hBmL0EoAAAAEPYQAAAAAAABA0EMoU6d2rVgsyQZha6gXFBZVKU5xcUl2Tl52Tl5Wdk5qWiYaFgAAAAAAkBDWd1v/WKylyav1sEnJafGJKckp6e/Doq7ffvT4WRCaGgAAAAAAkBA2CsZG+sZG+oSQHl3afD9jdHJq+vVbjzdtPxgWHo3GAQAAAAAAgjmEjYeRgd6IwT1und+1bsVcfT1tNAgAAAAAACAhbFzU1Fhjhve5c3FPgF9ztAYAAAAAACAhbHQM9HWO79swdkRfNAUAAAAAACAhbIxWLZnt3sQB7QAAAAAAAEgIGx0WS3Xj2oXSK14AAAAAAAAQVBmtJ169DikrL1eQYTMYPB6Hz+PyeBwuh61kZFdnu749Oxw7dQWNDAAAAAAASAjro/6jvsvLy1d+e3Mzo6kThgwd0E1bi684eK+OSAgBAAAAAKBOE0Iul10rgb6COAxFG/A4HFJehYBZWbn/W//P/9b/s/2vJZ3b+9Jv3M7f28rCJD0j+6tv5y8Th0E/apfF43LQzoiDOA0xjooK3dwHVVWmxNWNdkYcxEEcxEEcxPnkb6VAUJAnyK+VWA09jsJcLy8/v3r7NmH6oksntrq62BPahSicHKyv3Hj41bfzl4lD++mWlJRIvwrtjDiI0yDilJWV0WxQWipU5r3QzoiDOIiDOIjTaONgyGhdKCwq/mHh75dPbqPfTFeHbp16v1ZNNflctoZ6QWGR9LPJKekvAoOV36XWLb2YTLlfqyelpL8Ui9a9s7+8Ldka6lnZuTfuPKleywT4NadmWso7rotX733BD65/r47+vk3NTI0MDXRNjQ31dLUys3Kyc/IyMrPvPnh+5OTl0A/RtfJGRoZ6HQJ8HO2tba3NzUwMtbT4mnyuro5WQWFRTk5eTm5eRmb2s5dvHz0NunStOg3iaG9lZ2Mhr50/RMSGhcs+kCH9uwb4NTc1NjA1MTQ21GeqMrOyctIysqKiE67denj5+v209Czld6Olt7uujpbE+SPanyfPg+T1kM+aMsLDzdHEyMDEWN/S3EQgKMjIyk5Pzwp88/7m3afnL9+pxQ/d3tayWye/1i29zEwMeTwOl8Ph8djqampZ2bm5eYK8vPzI6Pizl25Xb4B3145+Kiqye6uFQqH090Gebk4jh/T08nAyMtRTV1eLiU18ERi8ZefhtyHhn/vk79ujvZODtZ2NhaWFiY62piafp6nJVVdTS8/Izs7JzczKeRv84dGz17fuPk1Ny6ytNzUzNRzUt0v7Ni2MjfT5fK4mnycUCpOS0+ITUuITU+48eC6z2cvLa//w2/m3aOJiZ2tlZm1pZqCvw+dz+TyuthY/v6AwJycvKzs3JTUjPDL2Q0TMq6CQJ8/f4M8cAAAQzCEEeqEforNz8rQ0eTTb6GjTTTX8bdEsN/l9jBev3hszZaHy+7P85xlUhiDT2Yu3J8xYJPrvmuU/GBvp00Rr0W5YVExCVdtEX0/70K7facqrRsUk1E1CKBSWSezY1AlDB/TpaGFmLPUZaepoa1pbmjbzdJk9bdSLwOAFS/+oUiouYdjA7oP7dQnway4v32ZrqBsZ6hFCWnp7zJg0vKi4+PbdZ4dOXDx78bby79KvZ4d5s8fLe3bXvpM/Ltkg8eDQAd1mTRnh5GAt8bixkb6xkb6bi32vbgGFRcX/HT679q9/FQ51psyfPUHekRJCJs1aeur8DfFHuBz29IlDJ4weoK+nLTFAgstlW5gZe3k4jx3RNzUtc+uuI3/vOCQUCmtyGsydNXbE4J4W5sYyn9XW4lOTgV2cbHt0abNm2feXr99ft2nvh4gY5d9i2x+LaUZ3WLh0Kiwqpn7W09VauXh2/94dGYyPCaSDnZWDnVVEVNznSwj9fZuNHdGncztfefupp6ulp6tFCGnu1WTM8D6EkCfP3xw9dfn4mWu5uYJqv6+zo83qZd+3bukl/ZSdjQX1y2r4oO4Lfvhm36Gz/+w7Kf5eNfzcxTX1dBk1pGePLgESp5wIh63BYWsYG+k7O9qITub0jOzb95/tPXA68E0o/tgBAADBshMgz/uwKPoNlLyrrnv7j56n32BI/67VCNuza1v6xTaOnb5aNwdYWvrxhrJ9G59H1w/MnjZSOhuU1szT5czhjfNnjxe/a1eSh6vj1VPbN/6+gCZHkqauptalY+tdf/9668JuOxtzUhvDCXR0NMX/a2pieO3Mzk1rF0pngxI01NUmjhn48Nr+Tu1a1Xw3dHU/6Txs07r5s9uH5383Qd6tuYiBvs6i+VNund+lzEcmjcFgjBne58XdIz9+/428bFAan88d1K/LzfO7fv1lJv0XPRLDzmmeNTUxFAU/uf/PAX06VeO8qjYDfZ1tfyw+8d+Gfj07VGlOgk9zt99/nfP05qGObVtWr/3nzBx75dR2mdmgBEtzk5/nTn5wZZ93U1fysYewFroItTR561bMvXJy25jhfRSectJJ8oDeHU8d/Oufv5c7O9rgjx0AACAhBNkys7IVZYyR9XPP9x06K54ySevS0a8aYbvQFtopKyvbf+RcXSWEpdQPE8cMPLhrtfL391SGNm/2+A2r5lfpHadPHHr97E4vD+dq77Ors92lE9u6dfJXKg+hrY4rXgXXwc7q3JFNnm6Oyu+Jjrbm7i2/9e3RXuGWAkEBXUKo/TEv7dGlzf6d/6vSfbmzo835o39X43b8v+2r1q2YW71kUkNdbeqEIfev7Gvm6aLM9gUFRcp8EFs3LHZxsq3LazzAr/ndS3trkoLq6Wod2v37vG/HVfWF2/9a8tMP37A11JV/ibGR/skDfw7q14X6r5B2DqEymns1uX91H9XhWRMdAnwuHd86fFB3/L0DAAAkhEBkzU2yInSjFoXv3kfUzz2PT0i5cfsxzQaebo7WlqZVislha/i1akqzwf1HL+Pik+vmAItLSgkhQwd0W7V0NpPJrEaEkUN6Tp80TMmNVy6ZvWzhjJrvtrYWf8fGpW1aK+5gzKVNCPk8bsXn6O509vCmaqRGGupqOzctU5gU0fcQ8vk80TDaPVtXVClDoJgYG+zf+T8ej0OU7uU7e3hTl46tSY2ngB7+d614n5XclDifLiXW5PMIIV07+nXp4FuXF/iA3h0P/LNa79Me2uqZ/92E2dNGKb/9L/Mm9+vZoXqp+OZ1P1MNVSasUULYrZP/nm0rjAz0SC0VfPtrzYLvZ4zGnzwAAEBCCJ9QV1ezsaJLmeITU0pKSuvt/iscNTq48tt6onR1DfphacfPXKuzoyspKTE3M1q1ZHZNgixbML19Gx9l7oAnjR1YW3uuoa62S4lRakXFxTTPUkX5uRz23q0rapIVbFq7kD6dLi4poU/PqKT0j//Nr/Y+WJqbrFj0rZIbn9z/Z6sWHrXyQWhr8Y/8u7a5VxMFZ1pxCf2oReoMqctLu30bn83rf1FXU6utgAvnTByk3G+DYQO7Vyl7JFJjTVcs/lZfT7smPYRODtY7Ny2lSlvVoh+/m6DMbwMAAAAkhI3IrCkj6BfLOleVGiF178KVu0nJaXQJnnJjF8USQrpumdxcwfFanUBIP8uouLj0j1U/UglJTSz/WUG/X69ubZW5A6ZKWb4J/kCfyIlSkTXLf1CQEBYVK1zE5ud5k0Vz2KrHwc5q/Mh+tO1cQpuXsgkh61fOq14nrVia0c3BzkrhZht/X6DkyNi4+OTwyDiiRH/jn6t/Ikr0RcttAR6nV7e2dTkJzdrSdMuGRQobvLCoOD4h5fXbUPqu5oo/Kioqa5Z9r6OtSRTN2Vvy07Qa7r++ns6fqxfQF5Whv/b/+N+PtZgMizCZzL/X/Sw+GBsAAICgymhj5tPcbfL4QTQbpKZlrvlzdz0/igNHL/wwcwyRWyLFwdrSVMlaowwGo61/C7r0+PKdwqLiWtx5+plRPbu28fdtJv7IwyeB9x6+iI5NVFFhWJibNPN0adXCQ2E3grOjzYTR/XftOylvg5WLv1VYWOi7n1aLry3h6e70/fTRPbsG0LzK18ezfRufm3flLv5RUlpK282o7unuNGFUv5q387iRfXfuPS6/J5ZuN9ga6tO+GeLh6ljDfVBRUZk8btC8Retothk6oNuwgQomet25//z4mat3H7yIjU8iohKUQ3uNGdabyO9umj97PM21XEqbunDYGt06+dXlRb34x2kK+4RXrtux4e99os5kI0Pdwf27zpk5lj43njFp2G+/b6fZ5ue5k5WZI5qalnnz7pOw8OiY2EQ+n2dlYdK3Z3tLcxPRBl06+NL/rqC59rt18qcf6JuWnnX15sObd57EJyanpmVGRscb6OsY6uvq6+u09vEaNayXob4ukV+kZ97s8T8v/wt/AQEAAAlhYzd94tBp3wxlqdI19dqN/9JPLqoP9h468+3UkaqqTHl3XYP7dfn9r3+Jcmt80d8LHj5xqS4PTTwbfBkYPG/x+vCIWIkJbzrammt+/UHhfKcp4wfLSwjHDO9jYmxAnw126P1NQmKK+IOBQe/HTftlxeJvJ4+j+05hxJAeNAkhfRVGtob678t/EO8mun3v2cVr914GBodFxDT1cPb18Wrt46lMEUgnB+s2rZvfffBc9m6QctoZgIbzvv24NkZ+QeHZi7du3HkSEhqZkJji16qpr49nO/8WCmufEkIG9Om4bPUWeaV01NXUli6YTmg7xH7/c/dfW/dLPP4yMPhlYPCde8/Wrpgrr/Nn1tSRJ8/dSExKlflsGW1CaGig1z5AcpxhQWFRbFySigrD2FBf+emRyrC1Nu/dvS39NsMnzL9265H4I+GRcf9b/8/b4A+b1y/SUJfbtzaobxeahNDVxX68El9A7P7v1PzF6yUeXL5668C+nX/7ZZbodwjNbig8T2iePX3h5sSZS6QTVGrdxdv3nq1av/O3RbOmjB8st/UGdkdCCAAASAi/Kh0DWtJPgiKVlSG0NHlamjx9PZ0+Pdor/Bb89IWbNH1KpJ6VlqGpwNG1o5+SCSHNSveEkKiYhPuPXn6RYzx1/sakWUtJ5bQ68kmR2JxJs5aqsVg9urShv8lu1cLj0dPXpOrTLH/5baNENiiyat3OTu1a2VrLXWeirZ83TWT6PITH4zStrAeTkZn9w8LfxZd6v3P/+Z37z6mvNpSphdO9s7+8hJC++If4AM7g9xFTv//1ndhqe+cv36H2asfGpQrTck0+r32bFvKWapwwuh/9VTlx5pLL1+/TXLBFxSX7tq8kcmZ1zpg07JdfN8qpnVtOu3xLF9HwxbKysv1HL1y4fEc8H9PS5NnaWGRl5dTK2T5ySE/6DQ4euyiRDRKxpUq7dfKnWW/GzNTQ090pMOi9zGfHjVBcz3Ptxj2rN/wj86njp6/evvf0zKGNyowNpkHTPRgY9F46G5Rxzf66kcthjxrai8jpKe3WyV+8wx8AAAAJYcO2c9MyUsvroQs3bP5P3k1PPXTg2AWahNDDzVHJUaMdaNcrO3rqypfKeH9Y+Dv9Nj8u2eDv21STT7cuxeD+XaUTQi1NHn3FkeSU9GO0B77/yPlF86cQ+Ws/+LX0uv/4lew8RLmF2gqLiifMWCwvG9+883BSSvq2PxYTReth1PCDiI1PGjxmTnJqOpGzfn1GRvaE0f3pg7g3cZSZEDKZzIm0RX0ePgmkyQYpl67de/gk0NfHU15KvGzVFlL1+WyiOZwFhUXjpv58484TiS8msnPyXgYG19YJTz9smxCybdcRmme37DxMvwBp1w6t5SWEAbTfXxBCjp68Qv+LMS09a9y0Xy6d2Mavbq8pi6VqKr/H/tGz10rG+e33bcMGdpc3dKJbJz8khAAAQFBUBoicNdkmfbu0AWWDhJDzl+/QlJZhMBiD+nZWGKSpp4uVhQmRv/zgAUUVTT+Tlet25OYK6LdJSk7bsec4/Ta9ugawWJJftfTo0kb6QfJpjwR92Oty+mo+pkA1nn23cet++r7ZE2euKUyWmtQ4IZz2/a/yskFRWp6SllG9vHT4oO7iM9CkiebLKTxb5D2lq6PVo2ubmrTA9wvW3LjzhHzmZeg9XB1oNsjOyXsr1kMr7U3wh+QUuo9J3lKKfq2aGhroEtpvylat36nwEEI/RO8/Uv3fFY52VjTVdOgPjXw60vv2/WcpaRlB78Lu3H928NjF9Zv2zl+8fuTEH9v1GP/dT6vxxw4AAAh6CIF82kUQGPT+9IWbh09couaiNCz0pWW6dvRbu3EPfYSetEMu7z2su+UHySczo2KPnLyszJZ/7zg0dfwQmjUzdHW0mns1kegkPHjs4qVr9zX5XD6Py+NxeFwOj8vhcDS4HDaXy+aw2U9fvKF/X/q7c+p9a9ICubmCLbQ9QpR/95/u2tGPvuqpliYvOyevervx4PGrx8+CFG525MTlmZOH02xgbmYk8/He3dsRuhImGTRTMcU9evo6MjrexspM3rtUO1c5f/lO7ZbYlVesxc6rh5Ymj8fl8Hlc6mzkcTlcLpvLYXM47FRFKTchJDEp1chQr6onJP0CpISQKzceigr50Nu17+TYEX2qsWQlIUSbtg5qi2ZuyocaNn4eqSy6Q7/YJgAAABLCxi43V7B115Grtx7V4rgvUp9Ky3i6OykcNdqxXSuaZ4+d/jLjRY+evKL8h/j81bsAP7rl4F1d7KVHjWZm5WTWbPZXWnoWzeQ3fX2dmgTfd/icwg5SQsi1W4/CI+PsbMzpU9NqJ4Sbdx5WZrMde45P+2YITQ+PvPor9AN3lSyTS4mIipOXEPpUJZ0QV1oqXLF2R539OlLmE6dB35ErLyF0UbSuxvXbj5VOazPuPnhBLU9fVdGxCbSr1fuNHNKzJj2QAAAABENGgciqMTBv9vgrJ7dFBF7cvO6XNq2bN7hDoErLEPmjRgf2oRs1amNlRjPHLDdXcPLs9S9yXCfOXlN+40dPA+k3cHaw+Ty9OnSdNtqa1V/0rLCoePPOQ0pu/ODJK1KDvhca70LCFQ5JpSQkpoSFx9BswOVwZK4LQi3+Lk9kdLzye/shIobmSre3taxGCzx5HhQWHt1Qfhskp2bQ9xXLW52F0I6huHDljvL7cP32o+rtfFx8Ms16FQwGY92KuTs3LXO0tyIAAABICOFzZIaD+3c58d+GWxd2jxnWm352Gal/pWUI7TfrNM/27t6OZlmwWl9+kCjd81alTCDwjYL5fp/pJlJYRlelU163rVJHFPRe+UlTKakKtqx2nQ/lu4YIIWnpdCOuZY7plVcGRiQ8Ilb5HQgNi6J51sOtOlM6r9x42IB+FQhL6arXqjBVZFZhpSmWSwiJT0ip0lj6B49fVXv/nz4Pol9cvm+P9vev7Ht179ixvet/WzRrYN/ONENkAQAACIaMQjW4OtutWzlv3Kh+Y6Ys/CJz50h1S8sYG+mTqo8a7UQ7XvTw8Ytf5IjCI2Nrd/tq3DVqafIM9HX1dLW0tTRVVBjSSwUWFBZxOezP1ALxcpa7kJ2JpWXRb8BUYVa7vmgVdiODbjdUZH3vYGNlTh+zuVeTX3+ZqeQO0H/KTZxsT1S9BRT2vtYla0tTXR0tHW1NNTWW+HlIPpZFNahqTCtLUxUVui8ik1LSqhQwJDSyuLhEtIdVcuTkFWWGaZiZGpqZGrb1964ca5r4Kijk+cu3128/Dv3QYLpzAQAAkBDWa+5NHK6d3jn8m/kNZW4hTWkZBoMxoE+n9Zv2Sj+lr6dNs/BXVEzC/cevGkRCGBEVX1hUTLMctpKZm4erY+f2vs28mjT3aqKnq0W+5EjgKnwZkZKW/pl2IyY2sbZ6CElVBjGK0KyqUlVmlWtIkKqsQ/PmXdgXPA2MjfS7d/Zv1cLT083Rzsbic7yFwo8gITG1Gt8jVG9vDx2/uGzh9KpWY7KyMLGyMOnbo/3yn2cmJKY8efHmzr1nZy/dzsrOxd8yAABAQviVs/HolpeXr2TXgZWFibmpkbGxgZW5SY8ubeT1p4no6Wrt/vvXrgOmKD9yj9TX0jLdOvrJTAh7dm1LMzj2qHJFPj+HxOTUqpaKzc7J1TDQq15CyONxxo3oO7BvZzcX+3rygVapay4lNeMz7UaVBu5mZGTTPCuzy4h+AmHtol+sUvYRZeaUlJR+kRNgSP+uwwZ2q4NZzQqbJSunymlVekZ2tdPXtX/9u3LJ7GofjqmJYb+eHfr17LB+1fxHT1+fuXBT4bI0AAAASAgbheSU9OSU9CfP34iWTRvYp/OA3h3p+x/MTA3/WrNg6Li5pIGUlpF3OE09XWSOGu3S3rfaUxM/q/z8wqq+pKS4tKoT2Cjt/FusXzXPwsy4Xn2giUlpVenIKvtMuxERFVdbMyqJnHG5ddakvKpPpMzNE9T9R29rbf7XmgUtvd3rKE9W9BEUF1d5FnFJSUm192fHnuPGRvrfTh1Z80Nr1cKjVQuPoQO6/bT0j2cv3+LvIAAAEBSVAXGXr98fOemnA0cV5DwdAnxatfAgDb+0jHStUQaDQVPS40stP0ipxtJhRcV096AqKioy84H53004unddfcsGqcGKX9k+FMv6gHhcTp0dTjUmfObm1fUSdt06+V8/s7POskFlPoLi4tJq1MityS79umbbT0v+yMjMrpUD9HR3Ondk08I5k/BXDwAAkBCCDD8s/F3hN8ffjBlAGk5pGXnPdpdafb6tnzefz5W3/enzN77kZcCo8oWgsKRnQUGRxCM//fDNvG/H4Sr4gujTeFLblYSrnNgUFtVla3Tv7L9j41Iej1OXbyq/xjCprO1Z5YuRpVrToS6HT1wK6D7uzIVbtXKMTCbz+xmjj+/bgCsOAAAaBFWasW1V8hXEYSj8bpvDIeU13Z8Nf+/bv/N/NFu2828h80t0Jm1pPlUms0q9H/T5jKqqUtGOn7k2Y9IwmU95ujm6OtuLL/3cVf5w2azs3Bt3n9RK743cz51BX+iCJ/HuCs8fDlud5tnikhK2hrp4nC4dW8+ZOZYot7pdckp6ZlZuRmZWaWVZf1WWamlJaZ8e7fT1dKpxDlA7Q0NDQ1359mezFURjy4mmqBQko0rngBqLRX89S0fLzy8gdTeHkCu9A0xm1a67z/f70MRYf+sfi2kKIxGxAfBRMQlZ2TmZWbnFJSWlYrMcWzRzc3d1oCn0Kt0CpaVCRWuWcJU/Dajjol/mRJnfZlwuWyAomP3j/3b/d3LM8D7t2rTQqe5amiIBfs2XLpi+9q9/G+ffU8RBHMRBHMRpQHFUBYKCaoyXI7U07q5exVGY6+Xl59dk36jXXr/9uKi4WF1NjaYKX1NPl7sPnpOqzJgqLy+v0r4ZGdBVzC8tFSoTbceeY1PGD5aXW3bu0OqPzf99vHds7k7T2ZiRkf15P3faT1eFyZR+Ff3+aKjTJUXilxX1w/zZ44mitRD/3PLfsdNX0tKzZA60yxPk+3i70ySEpUK5n1qBoq6nwsIi5dtfuvNT+u1kRitW0EFXtXO4mH7mWLmMT1BhwZLhE+Zfu/WI1MbASJnHQj8sVigsq+p5WO3r4tupIzlsDULXm1q8+79Tu/87JT6xU+K41iz/gSYhLJP1S0nm6S2Rv1XpkPME+eq0aa2Sv82obe49ennv0UtCiK+PZ6d2rdq1aeHh6ljtNp8xadiZCzdfVKt2NP4uIw7iIA7iIE6dxcGQUVL3E6VevVawrLmLo01Vw2pX5ftsI0M9DUVdRkS50jJ3pBJX8nHJQV/xLNdD/o3jnoNnvuyHYmSgW6XtWSxV+gGBAsEnPVG9u7elr4KYkpbRvteErbuO0N8uqygcbwe0cnIFijp+NRpDO+hoaw4b2J1+mwnTFy36bRN9mZ9qDO9UWDinGl1z+vo6td5ED58E/rpmW8feE919ByxZ+fep8zfehYQXVb3gzYxJw3HdAQAAQZVRqOoi4Oyq35Xq6Worv7G9rWVtHcvRk5c7BPjIfMq7aRNtLT61NlfPrgEMOclM6Ifol4HBdVntg8hYgNuMVHHdSKJgpb5PFmbo0bkN/fZLVm6mmZD5WW98G5U8RQkh94ueh3Wmd/d2RMG6Mmev3HioMI6Bvm5V3zpX0UdgbmpEqli8x7Dqu6G8pOS0zTsPi/7b0tvdw9WxqYdLMy8XZda66NKxtb6etsJ+UQAAAIKiMo2Kva1FNQqvl9IuUFalZc2tLExIrVVPfSAvk2EymT0qS8sE+HnLi3Dq3PUv/olYW5lWaXs7RZ9gWHi0+H/bB7QktHO0jp26osz7ftYb38YgXNGyFmYmBo2hHbp29KPf4N//TikTx0TR8qpE1hTZ2k0Im3q61GXTPX4WtGPP8elzfmvVcWTH3hPvyh8iQSrGlqu18W2OSw8AAJAQwicc7a2Jgu+kZaxNL6Cth6GtxVemPgSRGsxZQ+Xl5QePXZT3bPvKzkO/Vl5EzvSevQfPfvFPxMLM2NTEsAr3oB4K7kFDP0SL98fSp+vhkbFEuRUCcO3U0L2HL+g3cHKwaQzt0MTJltBOugt6F0aUWGjRxdm2qm+dX1AYHZtIaKuzOtpbKR+wtfzFbD63129DB4z6/ocFa+g3s6y9L+AAAACQEH4N3Js4KMzcEpJSFE5Lk5HmtfdVch3qHl1qM7XYe0juDEDqq3FPdyd5NWzuPnyenJr+xT8UBoMxoHdH5bf3a+lFv0FIaARRuvNWyeFkfXu0w+VDarzwfWJSKqFdW7wxtIMW7erwqZ8OeJZnQO9ONMWxCN3XJVFEwXoYbZSP1rm975dtzH2Hz61ctwMJIQAAICEEZROPpQumE0WLLAe9DatGMYbhiqpEkMrqgvS176sqLj5Z3nQjPV0tXx9PeZMMCSHHTl2tJx/N4H5dlNzSu6mri5OCjpEnz9+Qj0UytGpyd04qe4C7yF+3gyixMAlQXr4OoXnW2Ei/RTO3r74R6EsiafJ5ygQZOqCbohNS9u+Z92EKEsLe3doSZfvqnT3dnWqrTfx9m00cM7B9G5+qvvbk2ev0C5DgugMAAIKiMkBZv3JegJ+C+SR37j/PLyiUfjxKbE0/Iqd6gZGhXnIKXYebqYnhwL6da/24Dhw936WDr5zhqa3kFabPzMo5fuZaPflomjjb9erW9tyl2wq3/G76KAZttc/g9xFUKR1SMUZOQdeuuZniSVNLF0xXeJuuqcnDJabQucu3RVNbZZoyYfDTF2+UCnXkbxcnm6zs3MzMnOycvKzs3KzsnMysnIysnPz8wotX7taH3m8i51snmnEKXC7b1MQwgbb21dgRfX2auymqF8qX+fite09nTqarvenp7uTX0uv+41cKD2T2NAUXI5E/6drD1cnZ0drOxsLe1tLe1tLIUE80EPTm3SdVihYVQ/fLOSU1A9cdAAAgIQTi6+M5e9qolt7uCre8dkt2b9vLwBCFr932x+Kh4+bJq43OYqnu275S+amGyjt/+U5ScpqxrAoTbf28HR2s5dya36Fflq2OLVs4/eGTV+kZ2TTbdGzbUmFBjlPnb4j/N13RiFAbKzP3Jg40s7Y6tWvVt2d7UrNuHyAVdXGvLPxhEk0S3rdH+71+Z+7cV1AsxN+3GXU5a/J5luYm0jnAv/tP1dtGyM7O1TCkW4m0X68Om3ccIvL7URf/OEXhu6ioqPD5XOmyorfvPUtISjU1pqvfs3Du5J6DFQymGDO8T1t/7+q1wF+rF8jrcvdwdVQyHSXKDVulH6UMAABAMGT0a+XkYN0hwGfM8D4Lfph47czOA/+sViYbjE9I2S2nvt+DJ4pvUPxaNf13629ETt/goV2/K7nIcjW+c5dXWsbT3YktZ83DfYfO1uVgXYXbWJqb7Pr7Vx6PI7/Ivs7vv80hivtLL5BPa8YUFhXT3zfPk79sfZcOvtv/XKLMMdrZWDSSZfRq6OCxC/Qb7PhrqauzHaGt07vp94U0G/y55b/63AL0ZV0IIdMmDJE3P9De1vLUgb+UHFbayttDTnXi+/Qv9Gnu9tuiWYS2UOrKJd9WuwVOXbhB8+y6lfPkTXsmsorr/DxvMs0GN+48wUUHAABICL8Ska8vpUbcUfLfvct7D/+7dt2KuT/MHOPp5qjkWyxY9geRv36XwhqJhJBO7Vod3btu4piBbVo319bi29mY9+jSZu1vc26e+0c0WjW/oPDAkfP0KUpVG2fPwdOlpVXo7gsJjXwZGFxnnx1TValpk61bep0/8rd3U1fppwb06XTj7D8WZsZEUQeUxDochUXFIe8jiIIqGv67N/9q8Okygw52Vht/X7B/52rxrr/MrBwiv8D9oM8wHvjrs2XXkfgEuvGQujpaZw5vHD6ou7zuoFMH/jIzlVuWNuhdmMSXAvXNqyAFww2MjfQvntgi8f2Rthb/u+mjLp/camdjrswJSQjp30t2raY9+88oXOR9yvjBR/aslblGzuxpo/7bsUrJkjZMporM65RmRUQ7G4sDu1YrvNgJIeZmRnu2rKD5+uBdSPi7kHBcdAAAQDBkFJRx5cbDi1fv0Wyw/+gFf99mCuO082/Rzr8FzQZ/bz9I32OmWvWqM/EJKTfvPlG+4t+pczfqsm3pB8omJqWaVA5ga+Jsd/H4lsSktODQiLiEZA11NTMTQy93Zy6XTZSYmrVyvYx6gy8Cg708nOlf26tb207tfQOD3r8LCdfV1TI21JfuUn7+6t3u/05tWiu3b2r5LzOLiktu3XuanJJubmZUVFScmpaJK0v6u5Ufl2z4b8cqQltY5a81C36YOfb2vacRUXHxCSm6ulqGBnoBrZsrnDu37H9b6nkLPH3+ZvK4QURRPeTrZ3e+Cwl/ERisoa5maKDX1MNZYlhyVnbu4LFzrp2WW2NzcP8usfFJx89cDf0QraXJ09XRioyOJ4RExyb8d+jcN2MG0O9D+zY+z24fPnPhlqjwshqL1bNbgET3XUJiCs2yMfJ+m23fc2zOzLHyXuXh6vji7pEbd56cPn9DPOnl87jaWnxtLb6Bvm6rFh7OjgrWKflz635ccQAAgIQQlPIiMPjb+Svptzl++urSn6YZGeqRmo0W27B53/SJw2i2UWGqVKu0zAUlE8LSUuF/h8/Vn8bfuuvI2BF9ba0/9nuYGOubGFd50e1tu47ExSdLP37k5OXxo/opHLaqoa7W0ttd3tBiQX7B1O+Wl5SW0kTgctji6eKYKQvpv2JotC5fv3/05JXB/RWUlrW2NLUe0beqkW/fe8bjcur3d08P0tKz9PW0iRLFlprI7/76YeGawKD3EVFx4teO5DYzx/wwc4zoQlv02ybq53Wb9gzu30WZoad9aBdcWbdpTytvD5qEUN7ogHUb9/Tr2cHOxoImeIcAH5oiyYrb+fqDE/WmbhYAAACGjNZrF6/e6ztsFn05E0JIeXn5ynU7a/hePy5eX1JSmk+7zD2zWgkhVVpGmS3vPHherwowZufkzfl5bQ2DvA0J/0POzLHnr95dlbMyh/J+Xv5XVExCfEJKbHwSLpma+/nXvz5ExNRuzIdPAmfNW1X/jz2/oHDzzkM1DHL4xKWzF28TQgLfvK/Gy1PTMhev+LuG+xAcGvm/9f/ILMssosaS/b1nSUnp5G+X0QwcraG8vPw5v6zFhQYAAEgIgShclPzHJRvGTFlIX3dE5MyFm3trUItl+eqt128/Jopm/lRvveny8vJDxy8ps+Wx01fqspG1tfj0G+Tk5t17+OLf/aer/RYxcYlDx87Ny8uXt8G8xevp563REAqFv67Ztr9y2uchOfV7oEoys3J6DZnx+FkQqbXFVy70H/kd/ZVVf2zeefjug+ekBt9hzVlYke38s/dEeXl5NYLsP3J+/aa91d4HoVD487I/CSHZYqu8EBmraMjtrX39NnT6nBUKZzNWQ3Jq+oiJPyr5BRkAAAASwkaqoLDo3/2nfTuN3LXvZJVeOPfntYeOVzklKCsr+3HJho3bDlSmQILq3ULR+/fAKYWlZTKzck6cuV6XTa2jrUkUdVYQQhYu//NgtXKt5JT0wWPm0Pd5JiSmDBk3JyYusarBBfkFE2YsFj9J1vy5OzwyFldQzaVnZPcfOVtimZDqZSaLfts0+8f/1atlVBTu84BR3ytTp0rahr/3jZmyUJRHPX4WdOzU1ertxqr1Ow+fuFSNF2ZkZo+d+svL1yGEkLSMrGrPH7507V6fobOomY215cGTwO4Dpj18EohLDAAAkBCC7PuwB49fzVu0rolP33mL1mXRfrdN5HTEzZq3au3GPUp2KlJJxfcL1ognFVm0/Rg8JQqoEPmlZei3OXvpdh3fN2sqWqCPGoRZUlL67fxVojlOSrp682H3gdMiouIUbhn6IbrHoOkvqlJbNSk5re+wWReu3JV4fP7i9Q2lJ6qeKykpnTRr6TczFwcGva9eN+OufSfbdBu3ddeRhnj4/Ud+V6UvQYqKiqf98NvKdTuk6+hUu5bmrHmrflyyISWtCqu3P3v5tkPvb0RrVyQlp9fk660XgcF9h83avOOQgHYgvTJi45KmzF7Wd9gsjOsGAACCojJACMnNFQjyCwT5BTk5efGJKa/fhga9DXv28m01kkBpqzf8c+jYhZ/nTe7SoTWXIzd/i4yO33fo7L8HTktMlUnPzKavTVLtHbtxW0GtUfoVLz5LQqjJpy8NKj6Yc+uuI9duPfp26sg+3dvRVxZ98vzNvwdOHT15pUp9iV37Txk/qt+ksQMd7Kzob3lPnLm2Y89xmc/euf/cr8voZQtm0JRFefgkMCY2EZchUWok9q0zF27179VxcL8uzbya6OlqKXzJ67eh+4+cr2r3fj307fxVJ89enzl5uGhZGpmiYhJOnr1+7NSV0PBomSMk2/YYP+/bcTMmD5f32+NdSPiLV8Eyv+Hate/k8dNXp4wfPHpYb2MjfZrfqBeu3j19/ubVm5/MyI2Iousw19BQPAA+Ny9/yarNm3cenj5xqJ9vM+UXCiKVI/+v3nx44crdew9f5AnycUEBAEDDwrB261orf8B4XA7ifKk4XTv6tfbxNDLU09HR4nLYySnpcQnJiUmpYeHR1IzButyfo3vX0Sx6Efw+IqD7uAbRzkymytgRfW2szPh8riafy+VwCguLsrJzs3JywyNjj5++mpySXpP9aeJs5+Xu1MTZzshQT09HixCSkJQan5ASn5D87NU7if4WeXG0tfgebo42VuZWFiYslqpQKIyKSUhOSX/09LXMLkRcX8rE8Wnu1tbP29jYgM/j8LgcPo9bVFQcl5CckJgSHZsYHZvwISImLT3rK2sfI0M93xaeHm6Oxob6enrabA315NT0+ITk+ISU9x+i7tx/rmScZp4utjYWjnZWbLZ6eTlJSk6Njk0M/RAdJpZJ0sQZ0r9rS293LS2+jpammhorKSUtITE1MSk1Mjpeejn7z9Q+2lp8f99mDnaWFuYmdjYW4oNOBYL83Lz8nNy87Jy8+ITkDxGxHyJiRCNOcX0hDuIgDuIgTkOMgx5C8nXU0Je+VfoiHOysAlrT9TPUfL4WqcPSo399zjXEamXF6qzs3Dv3n1M361Bbnjx/8+T5m8Z21Mkp6afO36j5FfoiMLhK46KJ1BotR05e/rJNkZWde+7SbVwIAABAMIcQoKq+Gd1fRUWFZirjngOn0UoAAAAAAEgI4WtjbmY0bFB3mg2On7mmcK1FAAAAAABAQggNz6L5U2mq0QiFwr+3H0QrAQAAAADUH6pcLrtWAiFOI4/Tr1eH/r060Gx5/dbjlNQMnpwS8GhnxEEcxEEcxEEcxEEcxEGcuo+jKhAU1FaZbMRptHG6d/b/37LvGAwGzWar/9hF/45oZ8RBHMRBHMRBHMRBHMRBnDqOgyqjUCP+vk17dAkYTjt1kBDy3+Fzr9+GorkAAAAAAAgWpocG6tqZnTwuJzsnlxCiq6NlbKQvvkIXkb9+w8p1O9B6AAAAAABICKEB47DV7WzMq/qqn5f/lZqWidYDAAAAACCoMgqNytGTVw6fuIR2AAAAAABAQgiNy/NX775fsAbtAAAAAACAhBAal8Cg90PHzS0qLkZTAAAAAAAgIYTGorRUuHnn4Z6DZ2Tn5KE1AAAAAAAIispAY1BSUnr24q0/t+5/FxKO1gAAAAAAQEIIX49fft1oY22up6Olra2pq62pqcnT0dbMzROkpmUmJKa+CHz34PEr9AoCAAAAACAhhK/QjTtPyJ0n4o/wuJw8QT5aBgAAAACAYA4hAAAAAAAAICEEAAAAAAAAJIQAAAAAAACAhBAAAAAAAACQEAIAAAAAAABBlVGod4yN9FVU5H5fkJsnyM0VoJUAAAAAAJAQwlfowZV9fD5X3rObth9c9r8tMp/y9fE8c2gjTeQR3/x49eZDtDAAAAAAAMGQUQAAAAAAAEBCCAAAAAAAAEgIAQAAAAAAAAkhAAAAAAAAICEEAAAAAACAWqPK5bJrJRDifG1xGAyaJ1ksVR6XIzMOW0OdPrCGhjr1WrQz4iAO4iAO4iAO4iAO4iDOl42jKhAU5AnyayUW4nxVccrLaZ4sKSkVvVwiTkRU3NZdR2he+z4sUuZb4/NCHMRBHMRBHMRBHMRBHMSp4zhYhxBqWVRMwqLfNqEdAAAAAAAI5hACAAAAAAAAEkIAAAAAAABAQggAAAAAAABICAEAAAAAAAAJIQAAAAAAANQdVBmtAgN9ncH9urRr08LIQI/H43A5HC6XraGulpsryC8ozBMU5ObmhYZHP3/17uqNh7HxSdV7l64d/VRUZK8BKBQKHzwOlPmUlYXJuJH9bK3NTYz0TYwNjI30s7JzM7NyklPSnzwPunbr0cMngXXTSjramq1aeNBs8Ozl29S0zGoc+5UbDxUeu6mJoZGh3mc9dr+WXm38mrfy9tDX0+FxOTweR0uTV1RcnJdXkJsnyMrKCQr+cOrc9Tv3n+OSAQAAAAAkhA0ej8eZMKp/987+3k1dZW7A53P5fK4RIYQQLw/nIf27rl72fXRs4okz1/7ecTA7J69Kb7ftj8U0q0w6e/eRWG/Ewsx4zqyxA/t21lBXE39cW4uvrcW3sTJr1cLj26kjQz9EL1n597Vbjz53czk72uzdtpJmgxHf/Hj15sNqHLuFS6fCouIvdexGhno/ff9Nnx7tNPk86WfV1dTUddX0dLWIpamXh/Poob1i45POXLi5/d/jtbXODAAAAAAAwZDRumRrbb5i0azX948vmj9FXjYoj5WFyfczRj+5efDH77/h87nKvzAvny5/MDU2EP/vtG+GPLz+38ghPSUyImmO9lYHd63Z9fev9bnB6Y/dzNTwixw7n89d/OPUR9f2jxraS2Y2KDt9NTOeMWn49TM7Jozuz2AwcDUBAAAAABLChmRQvy53L+0ZMaRnldI5Cbo6WnNnjb15bpeDnZWSL8nPL6R5Vk9XW/TzL/MmL/95prqamvL707t727OHN9XkiD4r+mPX19Op+2O3MDO+emr7rCkjeDxONY5IX09n9bLvLx7foqOtiWsKAAAAAJAQNgxjR/T9e+1CNTVWrUSzsjA5smdtm9bNldlYkF9A86y2Fp/6Yd3KebOnjarGzrRq4bFxzYL62eyKjl2zjo/d2dHm/NG/7Wwsanhczb2anDrwp76eNq4sAAAAAEBCWN/NnjZq7W9zVFRqs3G0tfgn/tvQq1tbhVuWFJcQ2uGLhJDpk4aNGda72jvTs2tA7+7t6mHL0x+7pmbtHPuA3h2V2dLNxf7MoY0mn47RrbYmznbH921APyEAAAAAICGs17p29Pt57qTPFPyv1T/Z2ZjTb1NcUkrzLI/LMTUxnDtzbA335MfvJ9TDiW30x87ncWvl2BfNn6rMsW//a4mS+VtYeHRScpoyOeGyhTNwiQEAAAAAQZXR+snBzmrL+l8UZgu5uYJnr96+fhOakJSampZhZmJkaWHi09zd082RKCpPsmPjsg69vqHZprSULilia6ivXPxtzScBmpkY9u/V4cTZ6/Wq/RUdu0atHLu5mZHCY1+9/HuF0z73HT53887jew9fZmblUB+ul7vzj99NaOntLu8lwwd1P3rqyt0HWJECAAAAAJAQ1jMa6mq7/l6uMN84cvLyvF/W5RfIKH/i6my3dsVc+nqk7k0cvp8xesPf++RtUF5eTvPyAL/mfq2aiv6blJx29tLtW3efhH6ILi4pbevX3Ke5e5eOrQ31dRUe7+hhvetbQkh/7B0CfAL8mtMfe7dOfuK1Z6p37B6ujoP7daF5eXJK+qx5q27efSLxNcHdB8/vPXzx0/fffDt1pKoqU+Zrf//1hzbdxpbQ9oUCAAAAACAhrGvTJg51drSh32bpqi1/7zgo79m3IeHdB07bv3N1lw6+NEG+GTOAJiEsK6NLisSzwQtX7n7302qqe4py8NjFg8cumpsZHdr1u5ODNf2x+Ps24/E4eXn1aJU8+mMXzwblHfvWf6z/+Xt5DY/9h5ljaHqJs3PyOvWdJG+MaHl5+ar1O9lsjWnfDJG5gZ2NxYDenQ6fuIQrDgAAAAAI5hDWH2OG9aHfYMee4zTZoMjIiT++D4ui2cDIQG/GpOGkWr1kIldvPhw79WfxjEgkLj6515AZz16+JUpUvyQNp4dQmWOPT0yp4bE7OVh36+RH88J/959SOGNw/aY92Tl58p4d0KcTLjcAAAAAQEJYj4wc0tPczIhmg6iYhCUr/1Yy2i+/bqTfYOqEwVVaQ09Cekb2lO+W02yQlZ07f9F6hXE83Zwa3Cf1uY991pQRTCaTJvjfOw4pDJ6Vnbt991F5zwa0bm5kqIeLDgAAAACQENYXfXu2p99g5dodys/7unXv6f1HL2k2MDbSbx/gU+293XvwTG6ugH6boHdhj58F0W9jaW7c4D6pz33s9EuDPHj8SmbPpLSDxy7Ke0pVlTl0QDdcdAAAAACAhLBeYDAYrVp40mwgEBScu3y7SjFv3HlCv4FvCw9S3dXbt+0+osyW+w6dpd+Az+M2rE/qcx+7p7sTl8OmedWHiBgldzU2Polm1Ki/b1NcdwAAAACAhLBeaNO6GVtDnWaD2/efVbUs5LlLChLI5rTFSGkcOnYxPSNbmS2v3XpIvwGPy2lYn9TnPvZW3gqy9NAP0crvbURkrLynHGytcN0BAAAAABLCeqGtnzf9Bs9fvatqzIiouOTUdJoNvDycaOaqEdqSKkTp6XaFRcU0G3C57Ib1SX3uY2+mqMpO8PsI5ff2Q0QszVqI2lp8XHoAAAAA8GWp1lZK0KDjNHG2o98gLj6pGp1pSclpRgZya4eoq6l5uDqGhUv2OCnMEpNT0pXfmYzMbFNjA3nPslgsuaHkr7tACGGxVKkXSrczfV8rIURDQ136Tak4NT928f2pxrHbWpnR78CoYb3Ky8qVbHwba7porX287ii9Qj2uU8RBHMRBHMRBHMRBHMT5HHFUBYKCPEHtrETXcONo8nlEUQ9hNfYqKiaBvownh6MhHVYoFNK8RCgsexsSruTyDISQ1LRMmqRIKBTKPS7atygpKRW9UCJCQWER/S4VFhbJfNM8Qb6iYxcqc+yi4NU4dh5PQaY9fmS/2voyRl9fp0onFa5TxEEcxEEcxEEcxEEcxKn1OBgySggherpa9BtExyZWI2xmpoJylLo6mlWNmZiUqnw2SAjJzMr+aj6m+MSUz33sfH7dVdnR0uTh0gMAAAAAgjmEX5yONl1iVlRcXL2wCl+oo1XlhDAtPbNK2wuFZV/Nx5Sa9tmPXUuz7ub1NbgSrwAAAACAhJB8lWtO0CeExUUl1UwIixQlhDpaVY0pLCtrtJ9UHSS3GupqdXY4CoenAgAAAAAgIfz8TaCiQj8QUUVFpdqppqIN0Pz1S1kd5tsYMgoAAAAASAhJPeh3Eubm5dNWxaxmrxGHrUEUlADNQfvXKzm5AoIhowAAAADQaKiiCQgh2Tm5NN01TCaTx+Pk5eXXeinYr6niy9chLy+fZnlAgaDA2r1rlQLyuJzaqiIFAAAAAEDQQ0i+RL+QpblJNcIayl+EkJKRgYSwfsnNE3yOvmIAAAAAACSE9Vd2di79Bk721tUIa21pSr9BahVLhsLnRt+bx2Qy63JdCgAAAAAAJIR14UNEDP0GdjbmVY3JYqlaWdD1KxYUFoWFx6Dx65XomAT6DWytzdFKAAAAAICE8Kty7+FL+g2aejapasyA1s2ZTCbNBo+eBlZpmXWoA09fvqXfwNPNCa0EAAAAAEgIvyrXbj8SCoV02Z1fc4UlQyX06NKGfoMHj1+h5eub+48UfDXg19ILrQQAAAAASAjJV1ZbMvBNKKFdr3zC6P6kKmuO9+3ZgX6bm3eeoOXrm/dhUalpdBM7O7ZrxcDykQAAAABAsOzE1+X46avNPF1oNpg1ZcR/h89lKSo/Q/l++mj6ZceD3oXRp6DwpVy6fn/00F5E/mryE8cM2LHnuMI4OtqaT24eLCsry8rOFf3LzMzOzM7NzMzOyMo5duoKWhsAAAAAkBDWC9v/PTZz8nATYwN5G+jqaG1e/8uIb35UGKqpp8uEUQq6Ezf8vRdtXj/t3HOcJiEkhMyZNe7E2WvpipYMmTJhMLWkoa6OlvSzl6/fR0IIAAAAAARDRuuPrbuP0m/Qub3vhlXz6bdp4my3b9tKHo9Ds83rt6FnL95Gg9dP70LC79x/TrOBnq7W4X/X0X/EHdu2nDFpuLxni4tLFv22CU0NAAAAAEgI65HNOw69eh1Cv82oob1OH/zL2dGGyBoiuGj+lNsXdhsZ0q1HX1hUPH/RerR2fbZq/U6ioNao49VT2+WNMZ46YciebSs01OWuYr/v0NnI6Hi0MwAAAAAQDBmtV6Z8t/zq6e2afLrpf61bet29tCcyOj4yOj4mNrGwqIgQYmZi1LmDL00OILLot43PX71DU9dnz16+3fHv8UnjBtJsY29refnktsCg9/cfv4qKic/MzDEy1DMx1u/bo4O5mRHNCzMysxUmnAAAAAAASAi/gIiouFnzVv275TeFlSRtrMxsrMyqGv/EmWv/7j+Ndq7/1m38t0PblnY2Cpah93R38nSv2sqEG7cdyM7JQwsDAAAAAMGQ0XrowpW7S1b+/Tki37r3dMp3y9HCDUJRccnsH1cJBAW1G3bHnuObth9E8wIAAAAAEsL6a8s/R0ZPXpibK6itgOXl5bv2nRw8Zg7atgF5/Cyo74hvk5LTaiVaSUnpDwvWLFz2JxoWAAAAAJAQ1neXrt3rMXh6VExCzUMVFhVPn7Pi1zXb0KoNTmDQ++6Dpr0LCa9hnIzM7GET5u07fA5NCgAAAABICBuGkNDIdj3Gr9+0NzUts9qp4IGjFwK6jcWKcw1XXHxyl35Tlq7akpiUWo2XR8cm/G/9P607j6JfygIAAAAA4ItQ5XLZtRLoa42z67+TG7cdmD5pWEDr5p5ujhoa6sq86s27D09fvPln74n4xBRCCI/LUX5/mEwmXQavosLjcqrwAdNGYzKZcqPRltVhsVSpF0ofF1tRE2loqEu/KRWHqWBvFR+7+P5U/9iljmvPgdN7DpyeNG5gh4CWXu5OypwG128/PnLy8v1HL6mJiFX61BrP9YU4iIM4iIM4iIM4iIM4XzYOw9qta54gv+aBeFxOY4jTIcCnpbeHoYEuj8fhcth8HpfBYOQJ8vME+QJBQUZm9svXwbfvPZMuI9lI2qeRxOna0a+lt7uerjaPy+HzORw2Ozs7Ny4hOS4hOTYuKTImPjDoPdoZcRAHcRAHcRAHcRAHcep/HCw7UTU37jy5cecJ2qGRu3z9/uXr99EOAAAAAEAwhxAAAAAAAACQEAIAAAAAAAASQgAAAAAAAEBCCAAAAAAAAEgIAQAAAAAAAAkhAAAAAAAAICEEAAAAAAAAJIQAAAAAAACAhBAAAAAAAACQEAIAAAAAAAASQgAAAAAAAEBCCAAAAAAAAEgIAQAAAAAAAAkhAAAAAAAAICEEAAAAAAAAJIQAAAAAAACAhBAAAAAAAACQEAIAAAAAACAhBAAAAAAAACSEAAAAAAAAgIQQAAAAAAAAvl6qXC67VgIhDuIgDuIgDuIgDuIgDuIgDuIgTsOKoyoQFOQJ8mslFuIgDuIgDuIgDuIgDuIgDuIgDuI0oDgYMgoAAAAAAEAwhxAAAAAAAACQEAIAAAAAAAASQgAAAAAAAEBCCAAAAAAAAEgIAQAAAAAAAAkhAAAAAAAAICEEAAAAAAAAJIQAAAAAAACAhBAAAAAAAACQEAIAAAAAAAASQgAAAAAAAEBCCAAAAAAAAEgIAQAAAAAAoI6oogngK2BvazNt6jhCyOUrN65cu/1F9uF/KxezVJlBb4L/3XuodiOvW7OMqaLy4tWbvf8dxmct0q1z+/bt/Akhf2zclpubV3/2qnPndkwVlTXrNiUkJuNj+oJnRWJSiugKIoQEvn6HKwgAAAAJoQz9+vRo499S3rNMFRVhWZn4I6vW/JWWlo52q1fYXLazkwMh5Omzl19qH5wd7VgsVlZ2du1HdnIghKSk1uuzbuKEUS7ODoSQFf/bkJGRRbNlh3ZtevboRAg5fPTUu3fvq/2OhkaGbm4uhBB1DY36kxDqG+pTn5e6ujouzLonflZIXEHp6ZloHwAAACSEMhgZGVC3C0pSV2M15uYaOrivqipr/8FjOHPqTMj7MKaKSlx8Yn3eSUsLc+o6UmOp0W9pYKBLbamtrYUPF+rmCiKE1PMrCAAAAAnhlxcTG5+fn08U9RAWFZc02ibSUFcfNWKwID8fCWFdmjN/CY/LyRPkoykAqncFoREAAACQECr2755Dj58+l3gQN+LimjRxUlFBISIAAAAAACSEUDPGRoaGBnoRUTF5eQIlX2JmamKgr/vq9dsvtc/OTvYKt2FraBga6mtrawvyBGnp6VnZOUoG53K4draW+fmF8QmJBYWFNFsymUw7W+vikpKY2PgyoVCpE11V1dBQX09Pt7ioOD0jMyMjs+zTjl+ZNDX5lhZmoWHhxVLdwhYWZnweNzwiqqiouGGdeCoqKpaW5hrq6hGRUcVfe3e3lhbf0twsNS0jKTlFmZbR19O1sDBXZaqkZ2SmpKaXlpYqONs5bDsbq/T0DFH9knrxa13qbP+KPzVcQQAAAEgIv4yZMybaWFmUl5cvWra6IL9AegN1dbXlS35SVWVGRsdu+nuneFL37cxJdrbWbHZFzYOsrJzgkNDN23ZL3Lc5ONhOnTSWELJtx95yUj7v+xlmZiaEkN9WruvXp4cqSzUuPmHDn9sIbdXNe/cfnTx9kf5YHB3sevXsYm9rra2tzeVycnPz0jMyIiKjHz56+uRpRY2W/v16+rf2MTQyIIRw2Gyqal9GRtafGz/uQM8enbt0amdvZyMePCIqZv2GzZFRMRJvunrlYlVV5q3bD86ev9zat8WIoQNsbKxEz168dG3z9j3SyZ6tjdWs6RNtbaxUWaqEkMKi4sdPnu3Ze4RRXi7v6Ly83Af07dnUy028bzMzM3vL9t33HzyR2HjNqiVMpsqNm3efPnv50/zZTb3cCSHHT57b9e8BUtlj/P13Uz3d3aiPr7y8/M279wcOHn8d9LaclFfpFFKm2YmcKqP0lZAIIUVFxQsXrZB4sLWvz9hRg42NjKjWKy8vT01Nf/Do6e49BxVmPp+bu3uTcaOHEkJ27zkYERUzctgAO1sbUxMjLpcbGxcfGRlz/NT5uLh4JaOZGBtOnTze0dFOk8+jHikoKIyLT/xv/5FnLwJlftUydEi/1q1aiK5KQkhpqfDI8TMHDx2X+d1B2za+I0cMNjUxYjAYhJCMzMybt+7/t/9oeXlttsahwyfehoQp3xryzvbdew5cv3lX9EifXl3bBrQmhPy752DQ2xCZ+zB18jgHe5uysvJFS1YVFhVV9RDU1Fgjhg10dnKwsrIghMTExN27//js+cscLufXJT8yVVQuXb5x6erNmnxqpCp1eklllVE1FuvXpT+pslTj4xPX/7lVdvu7Oo8bO5wQcuPWvfMXropfQaNHDDI1Ma6HVxAAAAASwrqWkpzavUsHQkgbv5ZXrt6Scb8Y0NrN1ZkQ8vDRM9GDbfxazZ41mc3WKCsrS0tLz8zKMTY20NbW9G3l7ebm/NemnQ8efkxRNDQ0qNob+vq6EyeMMjI0oB4vKSnNLyj0cWvq5Gi//+DxlJQ06Xdv396feq0okyHyq7TPmjlJ/BEdHS0dHS17O5sundrt3L3/5KnzhBATI0NR6R0mk0n9nJySKnrVmFFDhw7uS/2ck5uXmppmoK+nqcm3tbb8Y92KJb+uefUqSPxdqJQ4PDyqW+f2M2dMpO6qRbp368Rhc9as3yT+oE+LpvPnzKJu2bOyclLT0nV1tNr4tWrRzGvL9j0yj66Fd9NFC39gMplU9piYmMTncfX19XR0tBb++N3+g8cOHDrxaZJmy2Kx3oeGe7q7UtmgxA3r8qU/mZoYU7eqCYlJbLaGs6PdiuUL1v+1VVhaRlhVKI6vTLMTOVVGFVZCKiyUvIOfMG7EwP69CCGlJaXxCUklxcUmpsaGhvr9+nRv3sxj5eo/Y2LivuAFxdZQp47I1NT425mTzUyNP34zYm/rYG/bxr/VP/8euHjpmsJQ3bt1+mbcCOo8KSsri4mJY3PYerq6DvY2y5b8ePHy9U2b/xHfXl9f738rftHV1SGECIVlSckpZWVCUxNjVVXmiKH9XZ0dFi5eKfEWI4YNGDl8EBU/LS0jOyfH0EB/YP9ezZt6PHryvBZbw9jYaNLEsUq2Bs3Z/sN304yMDERne+Drt1MmjSWEdO/aSWZCyOFyunftqKrKfBX4phrZoKGh/qKf59paWxJCcnJyU1LT7Gyt3Vydm3q5/bvvMHVod+8/rsmnRqpep5eqMlpcUlJYVOzt5uLkaL/nvyPp6RnS23fu1I56ifib1vMrCAAAoPoJIZfLrpVADTcOS1VVdBPG43KUjPPw0ZNRIwYzmSpt/Vs/kOpoIoS0C2hNdTLcf/CYx+VwuWxLC7P5c2eqqKjExMRt+HNrQmIStWWAv+/ECaP5PN73sya/fx8qGoKoymRSP7T0bmpkaHD12q13we91dbSzsrPv3L3v06Ipg8Ho16fHAVn1XQL8WxFC4uIToqNjpQ9KdFxGhgZTp4wjhCQkJJ05dykqKjYhMdHC3MzW1rp7146mpsYTx4989+59fHzC3v8O7/3v8OJf5rk2ccoT5H0z6TvxOPZ2NlQ2mJqatmXbv2/fVdxi+vu1nDxxrLq62uzpE3/4cXGJ2PCq8rIyqpesS5d2b9+9P3vuYkjIBw6XY2JsNHHCKFNT44AA34uXr0dGRYteMnniWOp+cdv2PTduVXR3mJmZ/rLg+28mjCCV/RKi42UymXO+n85kMktKSvbsO3z9xh2qq8fW1uqH76Yb6OsNGdz3VeAb8du4UqGQxWJpa2v5+DQLevPu4aNnhYVFOTm5VMxRIwZR2eDtuw83b/lHVGjnpx9nT588XpXFpN5UvMFlnj/KN7vEOEBR5Ju37r19GywdubWvD9XzExkVLb4bvXp2oe5ln78I3Pj3zoKCAkKIClNl7Khh3bp2sDA3mzntm+W//V6T60uVWdElxeGwZZ5yImosVmXBXjVRHNFl2LtHV31d3aPHTwcHh34Ij9TV0fFu7jV82EA2W2PKxNGBgUE5ObmVcSpewmFriOIYGxtOnTRWVZVZXFx84OCJO3cfCPLzCSGamvxfFs6xsjTv3rVjRHjUnXsPRfvz3azJVDZ48dL18xeupKalU9vPnjXFzdXZ09NtQN8eV659/NJHX0932JABhJD8goLlv64VnaItfZp/O3NSNx0t0V7Rt4Myv5S6demgZGtU6WxPT88Ij4iys7Vu1dJbV1e7WGrYc5dO7VRVmYSQu/ceKjyfpc37YSaVDe78Z9/V67epk61rpw6jRw3R1ORXHCPr4/lcjU9N3v6InxUS7S+6gh4+euLd3JPBYHTv2vHU6fMScVSYKr4tvQkh0TFxqalp1EtEV9CTpy82b90t7wrC31PEQRzEQRzEaYhxVAWCgtoqmtJA45RUDvUpKCyS+RJ5Dwa9eefl6ebm5lJezhDkCyTmwLg2cSGEvHj5WjS5aNaMQSoqKvmC/B9//lV0G0cIuXD5OoOpMn3KeA6X06Vz+4OHT1KP5+ZVLK3WokXz8xeubt62m1QWufkQHjVuzAh9fV0/3xbbd+4lUuPNqBvcazfu0jRCniC/jZMDi8UihPy+4e/Q0HDq8fSMrFev3968dX/zpjXFRUUODrbvQz9QTwmFQkJIefknbZInyG/Roin1819/73zx8rXoqUtXburp6Y0YNsDQyMDF2Um8/1NYXkYIcXCwff7i9eJl/6so3pORGRMbTwhj8S9zGAyGl5dbUGXa07yph4mxISHk+o07Z85fFsV5H/ph8bLVm/78H/Xf4uIS0b619GnO53EJIafOXDx5+oLoJa+Dgtet//t/KxezVFkBbXy3ivUuUuO+vDzc4uISFi9dXVxSIj7FsVXLFoSQ+ISktev/Fg0jzBPk//Tzb7u2/8Hh6FJNJNHm0h9BVZtdtG+iUKFh4aFh4RJhLS3Nv2/pTZ05q9b8JdqYzWH37d2dEBIaGr5m7Ubx/dm4eSeHyw7w93Vt4mRlZfG2KqsCShxXqbCiQfLzFfxKEbVqUXGx6PePoKBi1qitrdVfm3ZcrhxMmJ6RFRYeWSoUjhszjMVitW/XRlThtrik4srNLygUxenbuweVySxfse6lWKd0niB//k9L//1nE5ut0a9vjwuXr4u6wtxdXQghIe/DNm35R1REKk+Qv2zF2n+2/aHJ57Vr539C7PwZPKgvk6lCCNm2Y0+QWFp+/eZdTS3NieNHivaq2r/KRK1hbW2pZGtU9Wy/fPXm9Cnj1TXUfFu1EB8VWfk7pxkhJCc378q12xIjZhUelL2tTRMXR0LI1eu3T575OF796IkzpWVCUfsUFxeLQlX1U6PZH/GzQvIUrbyCrt24+824kRwux7u5538HjkrE8fdryeFyCCE3blb8/hS/gpb9tlY8psQVFB0d28j/niIO4iAO4iBOQ4yDIaMfjRs7bMjgPoR22YmtO/aEhUWQil6a+16ebqqqzIAAX4mxW+0C/Kn7mxuV83bc3ZpQQxBPnrkgng2Siilz1wf272VkaODj3UyUEIpl/5z/pLoBr9+4M3RIP319vRbeTSVWY28X4Ed1TiocX2dooFdxA5oneTJlZmUNHzVZyaa7d/9JfHyijrZ2oFTBm1eBQSOGDSCE2FhZiCeElLKysu3/7JN48GXg67KyMhUVFQMDfdGDzZp5UD/cvvNAYvvIqJjQ0HBHRzuJx+Pi4v/ctF1bS+ux1Ci+oLchhYVFGhrqluZmRFZNi917Dohng4QQNzdnDQ11QsjDR08lbpFLS0vv3HvUv28PJZurtpqdfDpn9eefftBQVysvL1+3YYv4QLghA/tQ3TI7d/8n/cI9ew8H+PsSQnxbtXhbg2Xia0t+fsHNW/ckz/abd0aPHMxkMj09XOmXPPFt5U0ISUxKfvnpEGXqN+bjpy/aBbQ2MzOxsbak5rWqEPLHpu062tpxn/bHEkLyBfkREVFenm5mZqbij3u6N6Gy2Xv3Hku85NKVG2NGDVa4EmMVWqNA2dagP9uLCovVNdTEz/ar126NGz2Mw2F3aNdGIiHU0uK7NnEihNy5+1CZ2ksS+vbpXrGf129LPHX6zMVe3TsbGxvW8FOrodLS0gePn3bq0NbB3tbE2FCiIFDbNhWDO0TdwspfQdHRsfhLCgAADQ6WECBiK2ubOTs5SPxzcLAT/y+XyxVtf/f+w4KCQkKIf2sfiVBUwY+c3Lz7lSmQqA8t5P0H6bcuKyt78yaYEGJsYiT97Nu3wdI55NnzV6h7tU4d2kreXbX0JoQ8ff5SYf3S8Igo6odRIwepq1f/LjY4JPTCpev7Dx0XSpWBSa6c4mhopC/9wszMLOnaGMXFJdS4WX09XdGDBvr61Oydl4FvpOM8E+uWFIlPSLpy9daRY6ejZc3tycjMJIToG8jYq4KCQum7atEcTul7VkLIk6rMHKutZhc3e9ZkczNjQsjxk+ckviDw9m5KnWPBIWHSL0xKTqFGL5uZGNeHy/Bt8HuJVJwqXxQXl0DNp6V5rb6+HjWo+NFj2R/HsxevqB/MK3O8PEH+zVv3Tpw69+TpC+ntqbxaQ12N6nKn6OnrEkLCQsOlJ9cV5BcEB4fVYmu8f/9BydagP9szsyXP9uLiknsPHhNCnJ3sxecoEkI6tm9LTURUZsamNBcXByqxl56dWFZW9uTTk7N6n1rNXb9RkWa3b+cv8cWKt7cnIeTFy9ei37oN6woCAAAg6CGstnv3H4tXSSGVE11KSkrFasmkiBdyfPT4Wft2/u5uLlwOVzRqVFdXp6Jqgtj36zwOp3KWVwsvTzeZ97KEEE0+T0uLn539SfqXnCqjbExmVtazF4E+3k19fLzYHLao0qlPi6ZaWnxCyNWrtxUe8oNHz0Lef3B2sg/w9/Vt6R0aFvHmTfDLV6/lFR5UiM1h21pbWlpamBgbamryeTyulqYm9RSDMKS3T03LkBmnVFhKCGFWzqIU9arl5glkdlnQF9ZXVVW1trSwsDKzMDXV0tbicTk8HldXV5cQwpCxU0TmohRGlQltVla2rANJV76Var3Ze3TrSHVrvAsO3b3noMSz1LlXWFA4bswwifNZnImpUX24DOVV5sjKzrEiRE+XLiG0sbYUpUwyN8hIz6qcemoi/aypqbGzo72hob6ujg6Xx+XzeDY2FqKVBkjlVD0dbW3qVJS9n5nZtdgasXIKq9K0hsyzXUdbR/psv3DpWpdO7Qgh3bt12rnrY9+Xv19LQkhoaHhUtfq72BpsQkh2juz1ZkI/hNfup1Y9r4PepqamGxjo+fr6iFeWahvQmurgvSFWlFX8CqKJWU+uIAAAACSE1Xfj5r2qLkx/49a99u38mUxmh/b+ZysntnXp1I6qmXlRbMYLj1/Rtdi9a0f63bC2sgp8/UknmMwMhBBy5epNH++maiy1Lh3bnT57UVSihio0L30s0sqEwsVLVy/8abaXpxuLxXJt4uTaxGnokH4CgeDRkxenTl+IiIxWsvW6d+vULqC1axMnhswcS47iYmUX8dMz0COE5Any5NwyZspbc6JXj85NvTw0qtIRJ3PtREMDA9qEMEP5+LXY7IQQe1ubSRNHE0Kys3NX/G+D9AZ8Po8QwuFyqKoYRP7CmPXhMsyXtYgLISQ3N48QoqrKNDI0TE6RvTydublpZYeYnNQis+I8MRXrijc3N+vXp1srH2+dynowNMzNTKgzXLrTvuItsrJqsTUKCgqVb42qnu1hYRFhHyId7G3atm0tSghNjA0dHWwJIddv3a3ePnO4GoQQecMTsrNyauVTq7lbd+4PHtjH1trSxNiQak9CSBv/VhKDOxrcFQQAAICEsE69ePk6IzNTV0fHr7WPKCGkvl+PjIoRn+4iGvf1PjS8vLyMdjKY5NoFQjnTeB4+epaWlqGvr9u+nT+VEKqqqrZq6U0IuXbjtpKHIMgX/Lx4pYWFWetW3m6uzi7OTmy2BpfL7di+TbuA1seOn927/4jCICNHDB4xtD8hpLy8PPD126jo2OjoWGq4HZfLmT93Vs2bmq2uQQgpL5O9ypvMNQCberkvWzyf6maMiIwOC4uIjolLSEyiCpzOmjGR6pWVJpS12L1ohboyOSvNlZeXK58M10qzE0LYGhoLfpqtxlIrKytbs26jzGS1uLiEzdYoKCiMjomVmBMrQVtLU2YyrAxRo1EzLWnwuBWrzBUVFinZ+ISQssqrhsmU28hMFQVj4EUfkKjHT0dbe9WvC6kRoWlp6e/ffwiPjIqKjhOWlhJCevfq5t3ck3w6obdif4jc06AWf8OUKd0a9Gf7tzMn6+npSMe5duO2g72Nrra2bytvaoGc9u38GQxGYWGR+LqFVVJaIlRjEaJ0O1TjU6sVFy/dGDywDyGkU8e21CovWlp8DzdX8ungDokriD6mlia/tooEAAAAICFsMG7dfjCgX88mLo7UUE8LCzNqBNSt2/fFN0tLrehBWv/nVuXX11bo+s27Qwf3dbC3sbayiIqO9W3pTeUtly7fqFKc2Nj4w7Hxh4+eVmEy3V1dunZp59+6JZPJHDqkX1p6+oVL1wntmmPDh/QjhGRkZa363x/vgkOJ1FDYmsvNzWOzNXiaPJnPik/xEpk+dTyTyRQKhVu3/yt9CNJTs+jlVI7j1dHWku4d0tfTrVLXaM2bnTJ3znSqX+LosTOvZM2uJISkpqVpafHz8/PnzF9C3+NdE6Jc1MzMhH6ooZmZMc04W21tTTm32ppUupUsa+FNSnJyxZBvXW1t+vNEtHrn+LHDqAcvX7m5edtuDXU18fYJaONLpGa9VuwPny/7LXS0a7FVtbSUbQ36s72kVHZX/LVrt8eNHsZma3RsH0AlhK19fajKSQVyumqV6GDP5nDYXB5X9p5Lfb7V+NRqRXJKyvvQcCdHu9atWlAJYfu2bahiYBc/LWcqfgUp+rKDQwAAAAiKyjQyVCU6JpPZLsCfENKxfRtCiFAovPZphb209Ip7X2ND/Vp89/MXr1I9El27dBDdvwa+fpuUnEKq2yMR+PrNmrWb5v64tLSklBDSqWM7+pc4OthRudDJk+ckskFCiEktjfKi8g1591u6UoP92Bw2tWbg0+cvpe+PVZhM+vIkRP5QQC0tGQMLZXa/fNZmJ4T06dWtlY83IeRdcChNjyJ17snMmWtRWuU0TjNTBRO9TCtLmCQlp0o/q6MlOyvga/Kp7wXkdSESQhKTkyu7OmUP/hSlHKLZwja21oSQrKycTVv+KS2VnF1pIFVzSDQ2mBpGKCuh1arFVhVNwaVvDYVnu7y5l4VFRXfvPyKEtGjupanJt7Q0p77PulK50EU15OTkEUJ4PNnt4+RgX/NPrbZQJYstLc2p1vNr7UOkBnfU2RUEAACAhLChio2ND/sQSQjx8nIjhLRu1YIQ8vzFa4mhd6Lvtlv6NK/Fd09Pz3j2IpAQ4u/nw9bQoFa2uHb9jvIR9PX1eLK+yw8NC6fWlzdQlDiJZs7ExSdJP9u9a4faSQizcwghaiw1qmCPBLcmzhKPiAonJiQky9irLu2rujaAqAesiYuMHfDycKtStJo3u72tzfhxw6gZVjKnDoqde+mEEAaDQc0v/UyeP68oBdmpY1vR0vPSfFo0o2pFhn2IlFkKyMXFUfpBDXV16gMVddDJFB+XSP3g6uoscwNPzyYVZ0VScmX1Wl0q05AuI2RubkatvkA+XbGAWiDU1sZSVVVyhIWaGsvJ0b4WW9XRyV6Z1lB4trPkfyLUmhOqLNV2AX7t2/pRF/LrN8E1uFSzCCGafJ70VwMqKio+lfWWa/Kp1ZZr1+9Q37/4tGiqq6vj7GRPpAZ31NkVBAAAgISwAbt99wEhxN3VxdrakqqDd0Nq+s3r12+pOXWdOgXIrD2wYP53m/9aPXLE4Kq+O/Vdvq6Ozvixw9lsjdy8vDv3HirzQjU11t5dm/b8s1Fe6TxqtGdKarrE/CiJlRLSKpe840v1CbQNaE1Vv6TuOGvSyKLFHrpJZZjW1paeUoVbUytLs0r35Jibm40bXXHINKmLhGeVCU+7tn4qYuVPqZbs3KmdknGq0exE/tRBoVC4+veN8soOkYoFMysq7A8Z3FeVKZXDsFjbt6xft2a5xPBIa2vLJi6OTWSlZzKFvA8LeR9GJSffzZ7C5rBlJLF2NrNnTmYwGOXl5Xv/O0zkjEB2l0oM/P1aqaupEUJeBgbR7ENBYSF1njg52knvuZYW3791K0JIYlJycGVXdmZmNiFEfDkZUrn8wLwfpovq3KqpscSu5XfUS0TnttjXH51Ec00lNGvq4d3M08PdtUqnvYG+njKtUZOz/UN45IfwSEJIq5bNqfnPN2/dkRgG6d3M07uZp62NlTL7HBQUUlloqqP0EoWi5Vtq8qnVFkG+4NnLV4QQd3fXNn6tVFRUpAd3SF5Bqp/rCgIAAEBC+OXZ2lpS9z3i/7w83SQekV4A/fqNO6WlQjZb45vxI4hUhTpSOTTr372HqT6uDet+a9/eX5RWqamxJowb4e/nY2VlkZ1d5Zr1Dx4+TUvLEGVK9+49kh75RmRX+Cx58y6EKnzau2dXiWeHD+1PJbfvxBYrp0rJq7HUmjf1IB9rFVbUkR86uK+9nY3o8Q7t2sz5btqFi9eoVTSUvJuU59Ll61lZOYSQzh3bTpwwStS9ZmVpvnzxfOm+puzsXGqAmX/rlm0DPt6429vbrFrxc2JyypOnL6lhYEqOBIuKjn36/BUhxMLcbMWyBaKCh2wO+7dlC7kcdpFyFVOr0exE/tTBQ0dOvlG0WEVoWDg1sNnG2nL1qkXu7k1ExTl0dbWXLp5vZmrs6GArsd7DtzMm/f6/pb//b6menrJja9dt2JyTm0ct7b1r+59jRg5p3tSDw+Xo6uo4OdqPHT10/e/LqSmCu/49+ELW0pGEkIyMzLk/zBDvZ3NytB87dighpLRUePzkOfp92LnrP2oU5S8L5lhXrmdACDEw0Fu7ehlV8Gb7zn2iayQiIooQYm5mPGbUUFH6p6OtvXzJT6YmxqfOVBTvdRLrqTt24iz1w6wZ33Tr3F6UIQS08Z0wdri8FVAW/zxn2ZIf582ZXqXTPjMzS5nWUHi2U60t72ynxhS4uTqbmhgLhcJLVz4ZL2pja71syY/Llvw4bEh/Zfb5wqWrVInR/n17jB09lMvhEkKYTGafXt3Gjxkm3f9WjU+tFt269YAQ4uRk16plc0LI8+eB0nWVxK+gjRtWfr4rCAAAgKCozJc1SrneuddB7xb88hv5ZM5MbuDrt82beTTz8iBSFerIxzUq7lpbW/Tr012Tz5v73fTSGZMjo2OFwlJTUxNNPo+q+HfhYnVWgr5x6+6QQX2p29lzF64q/8LtO/ZaWZhbW1tOnTx20IBeor4+PV1dAwM9QsiLV6/3iPXkvH8fTg2aWr70p6ioGBaL9b/f/4qIjL5y9VaXzu3MzEz+XL8iLj4hL0+graVlbGz49t37Hf/sMzMz8fRwtTA3W7NqSXx84p+btlfjGItLSv7eumvOd9M0NNT79+3Rp1fXmNh4TT5PR0e7qKh4/Z9bfv7pe/Lpaof7Dhyd+910Nltj/pyZUyePS0hIVFNXt7W2zMjMXP37X+3atfFp0VRFRWX1ykVBQcEnz1yIjVVQ7Oef3futLS0MDPQ83Jvs2LohPj5RhalioKenylJdt2HLN+NHqqupyVxusYbNTqQ6r6ipg4SQkcMHjRw+SOZmS5atpoYTE0L+2fWfjo52i+Ze5uZm//vtl/z8gpjYeAaDYWdrrarKLC0p3bl7f/UWnROXkJi8ZNnqn3/6Xl9fV5PPGzqkn/Q2hYVF23fuvSx/itq5C1d6dOu8/vflmZnZaekZujpauro6DAajqLh43YbN8paqE8/bt27/95vxo7S0+Jv+WJWRkZmRmaWjra2jo8VkMktLhUeOnRZfg37f/qP+rVuqslSHDu7bt3e32LiEsrIyOxsrQsjqdZvycnP79elOCJk2aaxbE5fA12/u3H0YGhZ+/OS5gf17sVisWTMnTZo4JiExSV9fT5PPi4mNv3HzLtX3y1Rh1vyX0uUrNzp1bKdMa9Cf7V06t2/W1EPe2X7t5p1xY4ZRedfzF6/pO5yV+cpj24693307mclkDhnUd9CA3jExccbGRhoa6hcvXbt5+367tn41/NRq0f1HT/MF+Rwux8O9CbWSkOxrv/IKsrQ0p7mCUFQGAACQEDZeN2/fa96sotNMokKduF3/Hrj/4MnECaOsrSw4HLaDvQ0hpKys7OWroFOnL4hu36vqwqVrgwb0VlFRCY+IqtJtfVZ2zvwFv/bv36NT+wADAz3xiqCJScnHjp+9cu2WeHJ74eLVlj7NqDsn8S/yt2zbnV+Q37tnVyaTSc0QS0vLOHLs9L4Dx8qEwn37j1pYmOrq6Lg2cbKxtqxeQkgIefDwSWpa2qzpE60sLVRVmVT1i5SUtP/9/peoXqWa2HDWmzfvqbPUJowbzuVyNfk8TSeHfEH+9Rt3du05mJWVffrMhRbNvRzsbUxNjE1NjC8rUUUjNjZ+9pyFc76b0cTFkc3WoLry/t/evQbHVR5mAD7SrqQ9u7oNxliybMcWuDOdGoqhJoEEQggTMlM6aTqZkKYZGtrQpA2XkhkYQzophNAQ0iRtSZvSkkKhkJQEShgDSbmUxDaEEGxi4kINBtu1JV8k47W1u5JlW/2hoIIu9l7OStrd5/nFDLsv5/vOd5BenVsuN/iPt9/15FOrP3bRh9vbWxsbGyOf9tINZLLXf/GW899/zkc+/DsdHSckk+Ho7VKDg0Nr1v70vu//YOsUr4Mv1KZXNn/2imsu+eQfnPPudybf/stxf//e1WufffjRx3p6dh4lYXDw4OVXXXvJxb//nrPOGDtAtu/o+dZt/zru/ZxTHw5P/GLDxs98+pMnndg9Z85xo+dnspns5i3bbv/23aOXR47ZuWv3dV+46YrLPr2gqyORaFp60pJDw4c2bPzve+69f/QK2Mef/PH55703lUp98APv2/3mG//+5c57d+zo/ehHPjRv3txEomn07PeLG1/+8le+seL0095cig2TPT2osN06NJTvbBx9tT/y6OO/ecqyqVZ7Lptb+/Sz7z/vnCAIHntiyjfWHDp8KP8/Ue3dt+8zl17cNb+zvr5+flfHC+t/+eRTa1av/enYFbDDw4eL3mtBpC/2WL322Qs+8L6pLu4YdwT93od+e/78jvIdQUDlamgLF3/8tLZTOpvawhncjKF0Lr2hd8u964bTxTwsOt6aWHrx8hkfRQXNWHWoW7zsgkgeQx/V4+xrJ2fu3DmdHR370umdvbvyfwXCpNuzePGib/7Nl+vq6v7htjsezu8M4cSc1taW4+cc19rakk7v3/vGG+n0gWDKR+G3dC9ZXF9X17tz1/79B8ZyGhsbuuZ3tjSntvf0jjuTUx+Lndj9jraWli3btvdN9rKBguY5FostPam7OZXMDQ5ufPullRNz6mOxznlz58yZ09ffP7GKdM3v6OyYt39gYNOmzQVtz8KFXfPmHn/4yJGNG18+yu47Zk6e0x7tOly4sKu9va1vT9+u3X3FNc9jbk88Hl+27Nfj9fUjIyPpAwf69vRP+obDsZxTTz35phuuDYLg23f82wMPPjL2wNL29tZXN78+NHSwuO1pb2tdtLCrZ+fuSVdd8PZbNzvnzT1y5Mj/vPLauEsT29talyx5x6HDh196adO4fxUmw6Undjc2xHft6XvrObdJt+drt3wxmQz/9LKrjzm9Y7Nx9z33ffe+B/OfjalW++j2TLXagyD4/MqrzjpzRV/f3ksuvWLikojFYv/xvTsfe+Ind9x5T0HrsKmp8cTuxa9ufv3gweGxW3Cv/txngyC4/savPvfz9cEU78PMc6/N1P/npzqC/ByUI6eWc37t8vfMPXPJLPn9fs8zr2+6dU0RX/yNq85tX7GwBhtRnjNWrevZGcKZW3l7+vfs6Y8k6g8/cVFdXd3AQOaxx58Kin9Y/IGJr9ebVDp9YOwRL2+9ROrgweFxj2sP3vJn+FdeeS2qqTt8+PDo2ZsgvzMAO3p27pjirNRR/lVwrLOFx7zENNppDyJ9NG4kG38Uhw4deuGFF0sM2dHTu6OnNyjt4bSTFtGJ+vr6+/r6m1PJiTeq7UvvXz/FWHLZ3IYXN+bbIhZ0lvL0znxmo7jVvnRp9+gddI/+6PFJ/0DQ3f2OWCy2fXvBJ8GGhg6OexVNZ8ebDyWe+nWs+e+1YOYeLl3uIwioOMctXzB7NqZ9WWdxX2w5ubM2d1/RMxa4ZJTZ4N1nnTH6JPcHH3pk7M/wwKxyztlnplKpZ575+WzbsFgsdvmffaq+vn7/gYEfPPTDST9zwfnnHj58eM3TP8sncMGCri9c97m2ttYn/mv1P91+V/D2B3Ke+96zgyDYsmVb787dVgVQTWKJX90psH7lqvy/lQwT2dxg6f/1sZzlN18YBEFDS6LYUcSLGMU0jKt8OSXOWOApo8y4d73z9CsvuzQIgr6+/vsfWGVCYHY668wVz6/b8NRP1s6qrUolU9etvPLE7sVBENzzne/nBif5Ydne3rZo0cKHVv0ozysatm/fMRKMNDenPnjBeaPvsRh9A+GK31r+t1+/aUFXRxAE//69By0JAAicIaRoZ6w47VN/9InGhobRh1LuPzBw/Y1fzf9GRGCa3XzL382q7fmrGz/f0tqyoKujsaExCILHn/zxqof/c9JP7tuXvubaGwoK/9o3vvWlG1amUqlrr7ly27bt2Vxu0YKusYcMvfTypqleOgIAKITkpS6o65rfEQTByMjIz55bd9s/371rt+uvgHyFiUT34kVBEOx94437H3j4wYceiTB80yubr155w0Uf/d2z3rVi0aIFb76L9eDWrf+7es3TP3zsqVhdnV0AAAohxXv2uef/+E/+/IS5c157fWskDxeCmfXqq6/95Q1fCYJg23bP6vj/2eh/8x2Vkbv2L760cFHXyJGRMr3RYeu27bf89TfrY7GOE44/fs5x/Xv3vfWJON7XBwAKIaXauWv3zl3OClIlBgYyRb+Hs4pno3zFaXBoKMIH/wZTP/W0p3dXT+8u+xQAAg+VAQAAQCEEAABg5sVTqTCSIDly5MiRI0eOHDlyajwnGRbwRrswbIpkeybmlHjTQUGjmM5xlS8nnxmr1vUcz2RyUT2VRI4cOXLkyJEjR46cWs4p9EXqkbx4fWJOicMsfavKNK7y5eQ5Y1W5nl0yCgAAELiHEAAAAIUQAAAAhRAAAACFEAAAAIUQAAAAhRAAAACFEAAAAIUQAAAAhRAAAACFEAAAAIUQAAAAhRAAAACFEAAAAIUQAAAAhRAAAACFEAAAAIUQAAAAhRAAAACFEAAAAIUQAAAAhRAAAIBx4qlUGEmQHDly5MiRI0eOHDk1npMME/nnhGFTJNszMac5lSwlsKBRTOe4ypeTz4xV63qOZzK5gUw2kiw5cuTIkSNHjhw5cmo5J5sbLCin0M/nmVPiMEvfqjKNq3w5ec5YVa5nl4wCAAAE7iEEAABAIQQAAEAhBAAAQCEEAABAIQQAAEAhBAAAQCEEAABAIQQAAEAhBAAAQCEEAABAIQQAAEAhBAAAQCEEAABAIQQAAEAhBAAAQCEEAABAIQQAAEAhBAAAQCEEAABAIQQAAEAhBAAAYJx4KhVGEiRHjhw5cuTIkSNHTo3nJMNE/jlh2BTJ9kzMaU4lSwksaBTTOa7y5eQzY9W6nuOZTG4gk40kS44cOXLkyJEjR46cWs7J5gYLyin083nmlDjM0reqTOMqX06eM1aV69klowAAAIF7CAEAAFAIAQAAUAgBAABQCAEAAFAIAQAAUAgBAABQCAEAAFAIAQAAUAgBAABQCAEAAFAIAQAAUAgBAABQCAEAAFAIAQAAUAgBAABQCAEAAFAIAQAAUAgBAABQCAEAAFAIAQAAUAgBAAAYJ55KhZEEyZEjR44cOXLkyJFT4znJMJF/Thg2RbI9E3OaU8lSAgsaxXSOq3w5+cxYta7neCaTG8hkI8mSI0eOHDly5MiRI6eWc7K5wYJyCv18njklDrP0rSrTuMqXk+eMVeV6dskoAABA4B5CAAAAFEIAAAAUQgAAABRCAAAAFEIAAAAUQgAAACpR3BQAAPC2XxBbE0svXt52SmdTW1gjQx5K59Iberfcu244nbMAUAgBAKhdXR87pX3FwpoaclNbeMLZ3XXxuk23rrEACFwyCgBAzWo5ubM2B96+rNPeJ3CGEACAWhZL/OpXxPUrV5WSkwwT2dxg6dszDTnLb74wCIKGloS9T+AMIQAAAAohAAAACiEAAAAKIQAAAAohAAAACiEAAAAKIQAAAAohAAAACiEAAAAKIQAAAAohAAAAMyOeSoWRBMmRI0eOHDly5MipjpwxyTBRytfDsCmSzZjOnOZUspR5LmjGyjeufEZhv0e43yv6eI9nMrmBTDaSLDly5MiRI0eOHDnVkTMqmxuc8YRpzslzAqf6WKHbWaZxlbgM7PeaOt5dMgoAABC4hxAAAACFEAAAAIUQAAAAhRAAAACFEAAAAIUQAAAAhRAAAACFEAAAAIUQAAAAhRAAAACFEAAAAIUQAAAAhRAAAACFEAAAAIUQAAAAhRAAAACFEAAAAIUQAAAAhRAAAACFEAAAAIUQAACAceKpVBhJkBw5cuTIkSNHjpzqyBmTDBOlfD0MmyLZjOnMaU4lS5nngmasfOPKZxT2e4T7vaKP93gmkxvIZCPJkiNHjhw5cuTIkVMdOaOyucEZT5jmnDwncKqPFbqdZRpXicvAfq+p4z0eAACzTENbuPjjp7Wd0tnUFpoNijCUzqU39G65d91wOmc2AIUQACrJkotPn3vmEvNA0ZrawhPO7q6L1226dY3ZAAIPlQGACnLcqQtMAqVrX9ZpEoDAGUIAqCyxsGH0H9avXBUU+0yISO69kVOhOctvvjAIgoaWhKMJCJwhBAAAQCEEAABAIQQAAFAIAQAAUAgBAABQCAEAAFAIAQAAUAgBAABQCAEAAFAIAQAAUAgBAABQCAEAAFAIAQAAUAgBAABQCAEAAFAIAQAAUAgBAABQCAEAACibeCoVRhIkR44cOXLkyIk8JxkmissJw6ZItkdOpec0p5KlBBa9Ait3nvOZsaMc7wXNmP1eNfu9on/uxDOZ3EAmG0mWHDly5MiRIyfanGxusOicUr4rp2pySlyWpW9Vxc1znjM21ccK3U77vTr2e0X/3HHJKAAAQOAeQgAAABRCAAAAql3cFADAbDN8YLChJREEwfKbLzQblLKQivvioYGheHNTDa7AomdsVh2z9vu0zVjgDCEAUA77ftlrEijdGy/uLO6LAy/tNmOVe8za79M2Y4EzhABAObx+1/PBkZG2ZfMb2xJmgyIMpXP7ftGz9Tvrivv6ju9uGD44XFMrsMQZmyXHrP0+zTOmEAIAZTGczm36+7WlJDSnkpE8jlxObeYc2j9Y4gqs7vmJ6pi13yt9vwcuGQUAAEAhBAAAQCEEAABAIQQAAEAhBAAAQCEEAABAIQQAAEAhBAAAQCEEAABAIQQAAEAhBAAAQCEEAABAIQQAAEAhBAAAQCEEAADgWOKpVBhJkBw5cuTIkSNHjhw5cuTIqayceCaTG8hkI8mSI0eOHDly5MiRI0eOHDkVlOOSUQAAgMA9hAAAACiEAAAAKIQAAAAohAAAACiEAAAAKIQAAAAohAAAACiEAAAAKIQAAAAohAAAACiEAAAAKIQAAAAohAAAACiEAAAAKIQAAAAohAAAACiEAAAAKIQAAAAohAAAACiEAAAAKIQAAACME0+lwkiC5MiRI0eOHDly5MiRI0dOZeXEM5ncQCYbSZYcOXLkyJEjR44cOXLkyKmgHJeMAgAABO4hBAAAQCEEAABAIQQAAEAhBAAAQCEEAABAIQQAAEAhBAAAQCEEAABAIQQAAEAhBAAAQCEEAABAIQQAAEAhBAAAQCEEAABAIQQAAEAhBAAAQCEEAABAIQQAAEAhBAAAQCEEAABAIQQAAGCceCoVRhIkR44cOXLkyJEjR44cOXIqKyeeyeQGMtlIsuTIkSNHjhw5cuTIkSNHTgXluGQUAAAgcA8hAAAACiEAAAAKIQAAAFXp/wBPKxFLruRk9wAAAABJRU5ErkJggg==';

// Self-hosted web fonts (WOFF2, Latin subset) and their licenses.
$binaryFiles['images/banner-ad-sizes-chart.png'] = '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';
$binaryFiles['images/examples/bakery-300x250.png'] = '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';
$binaryFiles['images/examples/bookkeeping-160x600.png'] = 'iVBORw0KGgoAAAANSUhEUgAAAKAAAAJYCAMAAADSTx1CAAABgFBMVEX////+///+/v79/f79/f38/P37+/z6+vv5+fr1+fn39/j29vf09fbz8/Xw9PTx8vPv8PLu7/Hs7e/q6+7n6+3n6Ovk5uni5Ofh4+bg4eXd3+Pb3eHa3ODV3ObY29/X2d7W2NzL3t7R1NnQ0tjP0tfN0NbMz9XKzdPIy9HHytHGydDGyc/Fyc/FyM/Fx87Ex87Dx83CxczAw8q9wci8v8e5vcW3u8O1ucGzt7+vv8Sxtb6vs7yssbqqr7morbemq7Sip7KfpK+cpK6Zn6qXnaiWnah8w7pgtqtBp5syoZMqnY+Vm6eSmaSRlqOPlaKMk5+KkZ6IjpyFjZmEi5iDiZeAhpV9hZJ9g5J7g5F7gpF5gI93f452fY11fYx0fYt0e4txeYlvd4dsdIRpc4JocIFlbX5lbH5ibHxfaHpdZnhbZHZaYnVYYnRXYHNUX3BTXHBRW21PWWxOV2tNVmpKVWhKU2hIUWZETWM/SF88Rlw4QVkuOVEpNEwlL0ghLEUfKkTxYfQmAAAUfElEQVR42u3dC1vayNcA8PMiIItSQAREW6SIl/rv1i1aZKUqine8oNb7uoKgKKiIxaIC5qu/M5MEgqhtNRZ29+TZXcIEws/MTObMSWDhTY0v8AZqekEgAhGIQAQiEIEIRCACEYjAGgO29QqLo1gUy65AMHtSes2f09MP7vK36em3LwoMc8JyXCy64PZglbsuvWZqbuZh4Nzcn/9tIFn6OW6EPPQdXxWuDp1FoD7PfQH4g+NsBDg1O0ursnl6ZnZ25k9W7WRlupkHvp2bm3pxoDHPXa/luGtV8QjucTcKspIiR3Bubob8o4Jp8t/Zubm3lDRLiqb/jwLfzMrkexRoXliwwiDHWYvAHo7rgSw3RoGM9PbN3Nx7UM/MTRPpjJoVUSApUP2CKjYH4slLjmsrtcEst2blCk18G3xFJH/Ozb3im+QMPWYUR/5lh/TlgW15rhCPlQED3LcxLg7fA9IGUP/ywCB32wAjZcCmApfifFQ0ZxGrmByraVbF5NQoVPGMbH3kUeAYx60uFzgu2lQ6zUQ5Lt/IgLPTs3Ozv9GmNyW2yKkp2hhZLyZFzS/fi79xXGExy3H2EtDNcRF2Hpyi3fctHTdm+RV4O0PVv/HAV7Nz079gLG5yKADsOkkJqfGP/JrirYVfUb15JWx89UYFUNVgYSx5y0VrOZpZ5ArhBgy3EIhABCIQgQhEIAIRiEAEIhCBCEQgAhGIQAQiEIEIRCACEYjA/yjQtlCxdNcU0M1VLBMI/FcBXUm23FBZjl/30fJ6W5dVVUO9eIMCU+IzXTB1S54XsrtW+rSDuenNP2Blq84qA0dzxeouDNKCfbqaN5O1JF0LV/kIeqQNstBKj+g1XT0CGKKPV41VBibo+vmYO8BYG7Sok9Y459HTplpwVLsNUkVODeyOH/HGxyBdvd6j/w3UQCcRFgst/MpWlSmxzpOKWgBafMHVnf1kCQgWoePcmGrgNNO0Uyj1EgEIw/zTwRo4Dzpz0m4sAg/5p3vVB2r4c4q3w9IiAY6K4IGqAwfpKr0rDxwlYGuetj82HjZXGxgsnktCRaDmkq72Rlg3rjZwgY0Wbq05xM7OeY8LYIVvfibWOseqDPTeDb++QQ+l5ozsPlJh9KsiUHslylJrPNDAGh+NZhSsqi811T3NWI55X6yhkXlS7Axzyp+CWLUvV3tW95p8faOFrel7e99pcdqJQAQiEIEIRCACax2obel0mJTSEl1bl6087Guwd5bPiZUmR5etLE+jbHY6zcqKvbfaNc8Catdu+DxRUsy66AKX/Pc9vWIawbr6jcX/l0viV4p6U/zk+XpRTCN2/MVmBPlwF/88QhJ1duiO3NAyy9OBlm/F4D7vZSWObLEkxcJ77ZfSTP7azl4TKE0JkgZaoFi4FQtul5mZRuY+Yb7P5V4/FajJSucfQ6TEJp24X9Is0pr0JVf1/NexSkuW1uoXacmOCDwtqpNPBbI5UrzX2LZ0y+cA+XnH4ah74kqYgGrY8YsMDyzR2TFHDzM96vnPDn0/ezGp00/84d3b4Wc0wwKQNJzLJP8HNz0RGCxmXP4ibYZkNnpZepA2vuYcm9NBGy05oC9ZpmtB8kdQ8iV9zTuaEv4d4LiYfvez94vAbJOYeOp+DjC/1lks2CnliGJ01SF5sY8WfBGA3MWIQdxgZpXPr7OD2iYAPbQkTtc+PRHYK7bi+KKbte2vrHOUcv+f+encyMKXvdilAORz1aQ7XG77Wf8cKOZLALbpuk8AGoolg0/txZLWfT0K9Eu75Yuf9utjSQEFtpY60u2+TUheb/E7ZO1gVABqnw9ULOZLH74s1JBkIZmO0VvuDhDsX0sFNxY+8b7B7zAkTPPlAgI0uheTwomOfN2ZHayy86qD+m63BhzNn4tAQhwNZ4vkLknucJ2u98sJZKfDDxu3fF9jvxvwOyscoA2xnz8N+tnXPSVANsCMXfKJdit9SPOFSaFryQSkRyHL1rbobnr4c/BWqeQP/pjSq0uKUwHoE/qB0H0ToGCad2zEo3/oTf29wK/iOfIngAf0PdtkPz1syGuGRtb+N3q05lV2PayRv3By5FA59vnB5UOrk3Up4tEtCY2PDX2Fnc++Dfb2RZAN+KGsA9AzRX9ZyZqQWZIuG3BSdjWKDM+qpLTkQiMfEDySTnzJTls+Sck2+aj2YqiwlRSAOul5h56cwCApSZlARiCYl26Es+68EN293hdIp3zKvIfvroVFaM8JJyPFpyP+QOdiwhimDAiJxet5NTwG9P18L1bYSL7NKY0q9fQHVDr14lNVB0nD8eOalZTzUWJjV29vj1m6Hyd9V8ejl6LIGf1DTYf8EXqqrV2gcoT1utoFktP6UX0tA29yy/U1Pe20q3Di/u8B+mkk9KEqwE5yA5rju6/6Ik4Afz1w6/HhqvrAixoHam+rAKzMbjW0drdW3GFkpJma7jsRSUUezOhqrZcVWJndavCf83FngM9luUmHXIZWEgxGJpIs8P6WTHruz4P10TtsCnt6+YCV2a3WUslVOwi3pHwz01AwuVs+X76bBxPvXuGyMbmAldktqzS7lWsTPjXPJiTlwMo8mKNsuiALsCK7xc/cC8kvCRb5Z1Wlw0KAE8nrYhVX5sGANY3bq1ROPmBFdot1giyNlJsuhSm4AExtLwwLrd9XTOuU5cHY3V55ctCVi/ICpdmtjdLExiNM9IY5yd0eJWBlHsxfzCHxE2U5gBXZrbSQPRNn5VkB+A3uAivzYKUcBOzK1ovvzW7xc6EGlhoSgFsVwMo82HbpHLksG/De7BZ/wUHL1AJwuQJYmQdbK6mWZRxJyrNbR/TRxjY0CTerMGCoAliZB1sstd9tmcfiUnZrr3SPG2ufsQeBlXmwUvZLJVsnqchusRzgMe0vypjQeSuBfhCvRZTlwXqK5/ZA8TRzWcojPwlYkd3Ssh75NTgQZGfdnP4OkCVQr4Z7zffkwZTsvYXjpR1ONmBldquvrOQT3AGOloa6ijyYZMy5kQt4T3bLWyopjMBdIH+rGV/Ld/NgwLdg+saOG7mAj2S3CodOqABCOxuN+X5yNw8GCv5q1EUXywTLA7wvu2Vgvw9suH83apLFcqrvz4Pxb3UA4LwYgQhEIAIRiEAEIhCBCEQgAhH4yKJy8dd2LY/eHqt2qe8tN7l0cgHHd3d3172V9xbrMvxHTE4+tkdr5l5/1+lum1zA9SWXa+A48ETg3UXbwd41tC5fFa/TtK8nTnf+3ssSqwaPu1EAtpsZ0NhRB9o+r5FuNXo+alkDcHib+IZgsei97NZpTf/7RXbkrMEwqeI6p6+VtZG619rnAof3yVWZ0+3FNLmQ058OrZ87GNBzpKdA3cEw2BKr8wlyRaL/eH45YSNbxxbXMj3sVZNfNudPSRW0RheXTtif+DlOqlizfzCZIMnMyZVYpuU5wB2/fzFMv2BK7uF1nGuVif+R1OQO/Wj3iYVWsTYyD7BK0nBtZxplghR93iRbyTWykQgP3CR3S6QV8MVP8pZLIFaxN6IGfcIJk/u651UxAQYS46A8p/Vx8D8H/Y6kMdOoy/Rd0JvOJ4MbmwqoO//ocrnO7Y5j8jB4zjcAA32VjjUCbUYPayQ57Q8VgVs0/bUQ+KlG/GAVmzNmXYbWTtj7B22OqkyLLhPdpMnByfPgsY6AwqS379r7zunDrpoBNRmrCNRkDOA+8Y2e9RSBUZqQG1uRB6jKOCqPoM10RoomV2Hii3B8SRs4rSv1cWNGJwWaAuN+4Y59uY+gwn+uheV1LQydNSiP/KAObTLC0IGa7l9z3E+2aqB1E+hWGBwlWwdAtRQGKXAxUDxpU+Bg3AyOVPvzgRmynL0nLWrrLB4nGVX7wdHZnol9tGKfPwDdqSbDztnfB51sa3jPTIF/nR60lAE/kh0dh/QiUBFMRc988GygZNHbhEvchse2Wo18FWts5dvrD0gjNm1I7q/UtCmrFiyI44xkMaTJj9MY/x6ojWimYbfyf07jPSBV7K/DcAuBCKwZoMFeDPgfiPstlqoC+6LFYeCB0WBlucaBv6aKO4dbaeiu7narwepj4Yh92HUXqBvsVAq3v7XTQGbIrWFVTGJ+Jz8XsA110JGN3wK24a7SBAHU7iHjU4GhzYmonwxd8/OR6NBWKD0AitXEwslqOXB1bfI4XAeftsmTeBe0HQRXDth8RZcJLPx9RkbhhdjEdlQjbvHsB7a3lOIEoTESWjh44vfqLAly+NrI55AeEVsgl6j/BoWvHnRkYJUCSVSvS/QUgSFyIfh1HQ9sZyGbLU5irfURccsxGZftIE4QPCskJtY/Dag79VjEwX+VRCLdcb78yFXRBucDRaB/p0PNF7M3BoIwsk7mAvMb4pbdgJ19242fILiOevRPboPO9UTMy3/OCgF2xUETiOzspiuB48tFoGY8fL5mKgLH52EyQacC8+IWU+jgZF4lThDg0875XseTe7HKnVZIgR+PVBVHMMiH7wN7PJBOY7ZGpMCR0nfF6BayvD5xiBMEsjQGNp8GtK+pwX5eBnx/Ug+OMxe8J8CAAIzowX7WBc60GdpTXbBMjOvDUmDLBTlCoU5hS+OOCTSndnGC4CUPwxvwesX480D1fHz3ZKSsitXL8b/HRzI68/kRCePZPHJyPrSfpumRxa/RADmCzoPw/pFBCgTPaTS2rBG3eI924+sKcYLQtLO/R/5iT6btKVWsdZruFjXd86UZK5++MAkvtjnuhvN1bVrJFqW9tTRBIMOmi2RT6p0YzSAQgQj8VwLthhcFjrPx/OExyLV9GqInZ+V48mjk3lckKr97rXTKmEQnWX6X68GfM+mP9xhCx2Rz4C+bI/r5RzMjaZmz/GwKZ20if7mP//URIaUP8JGkTNXpTtBdkHu/nWJ0UjZDIFVssKv7+0kc2EJeVO9SGPsuXBbpXmQA9sVWM15NdHvigAz8YkpfjMfS3dBFf9G27muLmNwqzRAg2kfevRKMR5QQIjszZVSdkcyu9+5enpNE9w9A3zn9OQeSo9ectIspffElnng9fIrRtdMuEViaITDgSSMJrxwiEFpoFd/Zy3OB9H9GvTlGmmN4WEzpi9O+M/prKDywG+Lx+Hj5DIEC6bsjH8uAd/by7CqmHxE9oD16UEzp8y9wUx+8Z7+WQj7O7Xa3l4WPDwHL9yIPcJP/IoOY0mfLwBn7CNMFCegs6XpJgvUe4OJYCVi2F5mAg0cmaNzTiyl9ugyeCB+zEVDWryzA48DRfQ0MEaCVtkHJXuQCKuZTYXJdDsSUvngRIEM+xhBOnG1qvwPURc5jwwRYf5z5LN2LfEOdmJjX2yq3tZi//wmKst+5um8vGCwgEIEI5LPnNQ30JrCKfwJoN9TbxWS3kw5lrUZWxXxJG8mMNtHxwKWoFjAaOEuKye5tMv1Rn5noyCqULJHxNkgiLVu8akcwulhXSnb/RcK/MB36xZJ+8l20A3Ip3btQPWBfKdndkDbB+hAFFu+POQXL3vgQLLurCiwmu9eG9WkDBRZLoq2+UecGJPTVPYLidNId/rTJYsNiSWBos0151hqFqgLFZDdoUuEBBiyWdG+S+ebqOslPBwarBxST3SRMTzfw0bVYUv+VTEU95D43OFqr6onaavxeidWEIwkCEYhABCIQgQhEIAIRiEAEIhCBCEQgAhGIQAQiEIEIRCACEYhABCIQgQhEIAIRiEAEIhCBCEQgAhGIQAQiEIEIRCACEYhABCIQgQhEIAIRiEAEIhCBCEQgAhGIQAQiEIEIRCACEYhABCIQgQhEIAIRiEAEIhCBCHzG8mpqZq5smZl6VVPAqbmKZaqmgLOVwNmaAs7dsyAQgQhEIAIRiEAEIhCBCEQgAhGIQAQiEF4wyVbKsdUIcOrBHFuNAGcfzLHVCPBhBQIRiEAEIhCBCEQgAv9hwIej9xoBTn3/Cnl1gbPfv0JeXeAP7BCBCETg/Tt85Cai2gA+chNRbQAfuYmoNoBzP7UJgQhE4H8bKPegIDtQ7kFBdqDcg4LsQLk/C4EIRCACEYhABCIQgQhEIAIRiEAEIhCBCEQgAhGIQAQiEPDnXBCIQAQiEIEIRCACEYhABCIQgQhE4LOBA6kcV5Ullxr4EaCHq+Li+QFguprA9A8Ac9UE5n4AyFV1QSACEYjAfyowMX5aWXgcDP9q4Bh9hbItVrEhBKsVZZcAv/96oMXlMoH1h4DbMMH9emCI4/IaMmDH3Hr7WJ4THylw2bVMXnLk0hm6v5KVwxaw+McHlnSR2yWnru+IHNLBJht9z4sChw8P56GbiyqMw92SRwKMKD8UyEuaG0Jj8I5GJm7o3nKr6q2HY+DZtqpT1zrj2igMvHwbBMsJNwgJjnPCjfgYAp+2nR2cw1Q+pWFtYAMWODfMc5xZEyPI4BoMHR42a29f/AhG9Zqbbrii4WVKfAyRd+pYpDberiB/Qgl4yXFqtuPPE/wH3Lx4G+S6YH8QyGmlkx5B/jEEZi+MkG0paPt2qyoHmo0cdzW+vwakjW6N518W+G58fFQF2TB0xkIKJyc+kjaYN6sIJgZ9h0EwpKTAEZg/HIGVa43tMNLU9AvaoJEcxoAOwJktPtJevEM6C5dzAjjc0CUF5vpVoPLmuL1mUvmxXzWS3J7elD0Wl+w1x10U7oTySX4mcXF5i2PxPxx4Pb5X28Bz+FTbwNzhRQ0BWSSQ87cavVluyEU6c//Hb64JTogNPF0Fzuc65tZd6WoBWSTgUAaWG82FCTLGJMFHq1iIDYbhkMQ9fq6nsVA1IIkEstB+eNgHx1cKBzcMSQoUYoM4jB2C1VHQerjqAS+5I/5dO1w3XBtbWScRYoOC3hEwjqmisFdVYJYGeEfjFyR+9sMiAwqxATeo6nbH4J0mV1Ug16aJHHaQlbxOo7hhQCE24CIAobwK/uCqC0y3k0hwkTzzUQoFirFBXgsXJJhdq/p58Ob0bjcVYwMcixGIQAQiEIE1CKz5S2E1fzGx5i/H1vwFbbxnAYEIRCACEYhABCIQgQhE4K8E1vjy/1liT/Dw/wlJAAAAAElFTkSuQmCC';
$binaryFiles['images/examples/outdoor-store-728x90.png'] = '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';
$binaryFiles['images/examples/pet-supply-300x600.png'] = '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';
$binaryFiles['images/examples/plumber-320x50.png'] = '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';
$binaryFiles['images/examples/yoga-studio-336x280.png'] = 'iVBORw0KGgoAAAANSUhEUgAAAVAAAAEYCAMAAAA9PV7qAAABgFBMVEX///////7l3/LAtdb0omGvor3snWLkmGTkmGPjmGPjmGKnncTjl2Pjl2Lkl1/il2Pil2Lil2Gil8SmhpNxXYZaSYdYSIdXSIVWR4VWRoNVRoNVRYNURYNURYJURIJTRIJTRIBSRIFSQ4FSQ4BRQ39RQn5QQn5QQX5PQX1PQH1OQnlOQHxOQHtOP3tNQXhNP3tNP3pLP3ZMPnpMPnlLPnlLPXlLPXhKPXhKPHdJPHdJO3ZJOnVIO3ZIOnVHOnRGOnJHOXRGOXNGOHNLOGFFOXFFOHNFOHJFOHFFN3JEOHFEN3JEN3FDN3BJNVpDNnBDNm9CNm9CNW9CNW5BNW5BNWxBNG1ANG1ANGw/NGtAM2w/M2w/M2s/Mms+M2o+Mms+Mmo9Mmo+MWg9MWo9MWk8MWk8MGk8MGg8LVk7MGg7L2g7L2c6L2Y6LmY5LmU5LWU4LWQ4LGQ3LGM3K2M3K2I2K2I2K2E2KmE1K2E1KmE1KmA0KmA0KV8zKF4yKF4xJ10vJVqBHbtlAAA5F0lEQVR42tW9i18UNxf/P8wIvWxbZCYjBaWuCmrxUcQLqF+hKpXWglwL/kB+8n2BoFVr9VFbtfrwr39PcpJMkknmthc0cWBdlt3l8M4n55ycBC+u0A6Znbde2b9nF/RerfPWJ3u/6H3s6jvc399/GPoR3gfYNXB44AjtPxw5OoC9LvpR7Mfqx+rHoZ8Q/diJOvlCtC/NXqF9le7W5sXVmm7JpHGLsqZbUW/cotyO/UajFoXr8BHW0JoDrP0AF1j0KL3AkloDmx5jtjx+Alpf9xdKq2pHi1Wzm1fOinY6exU6rWx+j3btU+i0sNmXYvMw0Hlk4PAPYE+w4BGDTcbncbCkySZcxwYjasUvmsLmlyaZXzbJoCXY7FXsaLL5PWezD9nsU8nsp2weBoseoXwyLjmbRzmbdcGmwufx+jGw4/HjYEnR4u6EzS8bpzPPjuUNeqiAclp1M6Wc/Wrn411lE/lMlPPoEYNNrp0p3UQ+mU0HT0RNYLO4clYjtAib2cqpaGdKN6VVD3M2pW4in0cHuG4e1XXzmGLHpJFktDdDN4uwWcKgeWx+X5ZN1M3vUTf7zTmd6SZjc+AHzuZRTTeP1al2HkvrJmNz8MTQiaHuxuj8ykZn8wyaweahhnWzH3WzP9HNI+45/ajKZp3qZopNatUhsg9sFjJoy3SzT5/TFd1ENjN8TrduMjZZj5qrnM0zqPCUMtks7HP2pX1OVTePZOnmUV03j1vYRJsODg4Nxl/tA5s5BrWwGafZ7KVsHspm09BOqZuHTTb7kc0BXTcHTN00fM4TVDspmwmfQ9F+sJlHqGQzdrB5qBSb/VlsSt00fc6jRw02hc9J7ajrJmOT8TkUdzfAZgmfs6BBC+vmoWw2+xy6eTjN5mGM1LNidUM5j2EfxFGesHkC7DkY7Qub2YTadLNSrJ6rm0cK6WY90c3jGKunlZN12robiocaa14x3TyUp5tarN7vyCMdccTqR3NidbtuntB1cxDpPDkIPlOjbH7ZJIM2STcNn7O/sVg90c3jJ9I+p9TNIU4ntKjdumkx6CFHrN5rZDlL5ZGEchq6yZWzn0eXPFI/akTq1lgdldOqm0jnyaGTJUb8VxVi9TKENprjNJQzxSbLcPYrOc7cSJ2xiRnOYxbdlJYckg0sStrmc1oNmspxHmpnjjMrVjd087gWq2t0nqTXEPZThUZ8uRxneUJtutmsWL2JOc4TSYRp6uYQt+fJk33dTc9xliT0U8px1p05zkErm0Mam6yT5uc4yxFaOP9ezOd05TiPlMtxHrfkOAfTHqfGJm2nTkbt1007oTDme+1sHsrWzT6TTa6binaibvb/kBurHzd9zmPWWD1hcxDZ5HyCPU91tyMeyjJols9ZQDetsTpfDVZ108WmqZtgx2Oom8eKsDmksEmvU3FBn7NVzcuM1cvmOO0ep4zV3R6nVTezPE4bm9BPO5ymr9rApiS00upQId008u8Fc5zHzfx7PpusY4saWlVvkkEzdbNIrF4wx5nKI9lznC7dPOFgcwjZhOvU6ZPd+atDX7XSrl6GbhKCNLLP+B/4CBalH/v6CWtgx5h+ZB/QqoSARWMSUzZJGMWcTXYT2SQkVtiMSRgSsGOMzxifOAFPcWIQngGsyu46Ttnso7cYmzE8/iTlk+vmKXqdPkWvOMvjbD2fYFC3bsZ+iOM9CMCKfg0sGfs++b4/9APKZuBDC4BN4gdxP/FDwWboRwP9NT8+MtDLHhNRMg+zmyFj8zDcELE6MMm+QuoniM8ataUfDsJ/IyAzZK9C6LoGvUHHObsrlNp5WnSw52nS3jndTqgjVoef6nth0O/BoGDVQ/Aj9R0K4AOM9CDgmgmGCMGqodDN3iDog/8ClaEfxiQIesGi/CYb58SvBXJePw4PjX8IYyaZQYBlH34IbILRBodCn/6yakw44Q4aq/s1Etd8oirnqdPYT0ft8zgzCLXH6mjQPoXQ/kN+4BMAlBLaFwTcOyL0XkqoiNXhNlgRdDMIgM3QJ8Al2HKgHuNcVAsIECl0Ex59SOQ4g4DpJrz0ECV0kBIaD56s+QNUN/2Q6mZIbQmP4Lop2IT+4+kfo8ZW1ZtBqDNWVwiF0U4J7TtELUUvCiaOVLAoWAcGJRAq53T4EqG6CQYF7aTjG2x7tB6GIdXOPj+Ef3xt6PgJ+ugACR0EQumcTof8EBgUCR1iH8CilNCTp0BMTp2s+7WTgk0AE/7R9uPp7vZ5nE5CXR4nNSjzjYDQfiAUqOz1QxjfMbIZBGAgOv8QSm1ICeWR+gAYmPmcYND6AB399aN0tMMvgxyjY7wGvxOl5oOEAR3jJ5DQIYXQk4PMlvCBsglUMkLjk6cG/JrGJu1gT2rQ/WJTGNTpbzJC+5BQmI5C0E0gtL8WcvWEjxhhgkH7KLhKPMQU9OhAzT9E1ZMAljW/t14XNwNmWuFxxlw8B5FQmH9+QEJhTmfmGwoC5nPCkAfVhCc5dQp+S1I3RQOLxm30OB0GdfubIJiAYD+d1UMCBuinGgqzTy9TzyNIaDjACB1gE1NSxwmjnM7nVApAcpmnRG/Cs5yoA+Z1Nu3weiSwDSWceZtAKPM3A/aSwCYQGoY1NqdTrSZAJQwQQr+IfP6I2sn6GZjk2+dx2tq3QKgzVo+ZwwJ+e0zVMqQREdiCswn+JrpNEKuDQY8AjKGS4/RDzHFSDydAn5PeDCmXEVj1mJjPofXTr9RiluMEg7J4iFBdJdTnZM8QUn+TuU1UNfGLBpu0/3iGfLkvdkzaN16hWD0mhzJznIfdOU4Si7WhH0i/ox5pgHyfitVjouQ4lVidcUmIopvIJvZoX3STWRL6t5TQNtZxVs9xpjzOFJu0nfnxTPTVvjawqLfPdZxarH4yM1ZnbKq6eQrZ5Hz+SK9oH8b7N2hJ3r19reO0ravLPNKQzibG6qcVn/M0snmasXmGXd37xyaz6rffekXrOA83uY5z0LGqnlbOFJundN1kdLJ+ts0G1dlME9r+Ok5dN1Xl1HXTrZxnkE7RuvdjNmKW/PpbbF6jOc6G6jgL5jizdZOSSa+zZ0baZlBk8xudzm/p5RWs4zzcijpOh24mOc5TRXSTt7P06t6HEc/sKJuXmtfbWMfJdNNUzpOpHKfmc9qUE/tI2wj9JqWccsQDodXqOH9oRh1njs952qGcZ0zlZHwyi3a3WTnTzdvHOk7rrJ7STR6rn1HptLDJekuHvFU3vzat6jWtHql8HWfCpkU3C7F5Ftk8O0KvsyMjrSeU6+Y3VjoTQveljtPhcVp0swCbjM9WEpqlmwahrjrOwxXqOBuO1cvrJmcTW3fLY/WvnGwKQptdx3msaB2noZvpWN1k0/A5NTb/c35k5OyFkahN8RBY7muXQTN0MxWrV6zjHCymm6ccbNp9TsbmGaAy4XPkfLSPbEqDFqnjPNr8Ok4zx1lNN/9DlXPk7PmRC6xHLclxGmzmGXSf6jjzdfNHwaahm2dRN89qbI4wm0b7yCY3qK2Oc6AddZyZOc5CusnG+4URQSftZF90UyeU13Eebk4d54midZxKPVI6Vj9zuoRuwgVWPQ/2vHCBNJfNb8qwKQktXsdZb2Idp6GcDjaFdo5YlPO8xib08xfO11vjcRZiUxDa2jrOoXQdp7o65MojGdrJZyNDN0eYRYHN88AmvaB1N3M2qtK8QnWcR5tfx1k8x3lW182zVDdHTDbpeD9/8cLF7lbkOMsN+TSbAw42uc95rLEc5+myOU41VrfpJmeTtosNT/Pf2nKc5QgteK5cgRznoDPHOZSd4yweq9t0k3Zgk/XLpEHvSI3Uv65k0EJ71etZOc5ENwfzV4cq5DgN3eQ+J1rygkon9IuknT6n1aCOc+Wkbh53rA0NWnVzyK2beh2nTTdPn0Xt1HVTskm109BNxibyeZldl/sqe0embn5ddchbc5zptaHjja8NnXLopk07lTySwifq5vkL6rx+Eem8eAmuyxcvdzdpbagyofb8u+08TsfaUGYeqeFYndnU0M0RXTcvUTJpp/a8GLU4Vi9CaMZ5nPXkPM5WxOpnHLG6zeM8Lyx5Ia2cjM2Lly9dvlx+VmqOHRODmjlO53mcZo5TXxtKxUPNyHGmYnW7blI2GZ9jl8YKzkqOtaGvmzHkS+Y4HWtDQw42TZ/zx4xY3a6b5y3xEGfz4kWmm5cugTUvUz7H4F93GS/+myayKQktdh5nG3OcfLxfsPqc0uPUlHOM9kvQL49H7ddNg9CWzequaOhHWzSUMaubZGqzOtJ5Gekcg3+k9dFQNqGuaChnVj+ZtzrUslldZ/Mysgn2HId+5fKV4e6yytlkg7pO2S5+yoeZRyobDZ0tGQ0BncmszthknbXxsbwx3/xRrhv0uNPjPJEZDZ3Mi4ZO/Wir+CiwclluVgdLXhoHe16hfI7RTork35s2q6cJdelmtYqPZOWyajRkm9Uvumd1lc3xK/RyjPlvW6ebBqHmrD7Y7pVLOau7lfNSWjcZm6icgk3eo5w8Uktt6jlO2W59NHS2WjTE5/TLum5SO45DZ400L/9ewaANRENDpwvvznBm4NPR0EiObtJL182ET7QpS9tb8u8t001sB9nl2U/ZZmwODjWyO8MVDZ21rlzmRkMXU7p5WbA5jnbkfEKL2uJx2q3qFYiGrDXG5XZnpJTzLFu5zImGLjmiobG0bo5jv3qF9uHudnicJp/YveI1xifz8u9GxYexbilWh0zdHHF7nAabqnKOI53jiXJiu8quqB0ep92m3uC+rVxa2DR184Kum2Mu3ZRsXr1yjfb6wbYpp9BOndD2rFyO2Fcu07rJ2bxossl1cwx1c0zTTc7m1avX6BW1Uzc5nQfZ5Tl3tTVpd8ZI+ZVLUzkvabH6WCabtF+9drV+sB3j/aCle07dHMre1XbK4XEWX7k8byqnYPMS6uYlR6w+nlbOK2hTaNewM0TbqJyUTWxeqZVLfU7P3J1h0c3/YMWHLVZ35JGQzUs4p4+rujnmYJPxef3qNdJO3VRt6rli9ZN5uzOyqpGydPM85jjP23KcUjelz4lsjstYXWdT6CbTTuTzGrvagqjBpkpoxd0ZZ0pUfFiqkbLZvKjoZgabunZeZ9f1a9evgS/aPuVUDdq6XW32HKc9Vr/IY3Ul/+6O1TXdvJLopmjXWSftZlMQ2vjuDM7mmTPVcpyXbGwaOU6VTeRTsnlVYfMa0jlBr9HutummbtCGd2ecKZTjdOrmpSI5znHT5xRsaroJdmR8sjZBWjgbuZvXyooPe44zY23oopLhzIjVdd1EPpFN5BP6dehRS5XTadCm784YaUWO02TTVE5uTdmoRevdbdJNzaAZbFat+DjTnLWhDN3k2nndzibyCT1qK5uC0EQ3LbF6I7va3LE6z3FedOjm2JhdN5VY3aab1yiXjM0Jdk1M/J/u5upmPp+M0JzdGcXZPKPvaisSqxfPcRbQTUkm9smJyQadUQub3+Yb1Bqru3Wz4u4MPVZXV9UTNseS/Hsqy4m6ma2c3JJKmyQHm5dHKta8cid3ZeQ4q6wNXULdHE/nOPNidaducjbpNXFjYjJqH5uC0AZznCPFdmdcaDzHqbCZoZtIJrsmoVWV0YPl2ZSEunKcZ4rF6v8pn+O8XD7HecWpmyabE8gm76Vl9GBOrJ5v0Nbuzrio1yNZcpzjhXOcNuW06CZnc3LyBuuku2U+p9WgBXZn2HUza3cG6mYqVh/T8khjDt0sGKun2JwQusnYvDlJL9qLTkypWP1gFZt6hes4zzS5jnNM6ub4FUusftWdRxJs2rQTwJygbDI+b4A9b9y8UWaqh4cerMimIHS/6jjLKWcGmzblvCk6sylpSh6pMKH7Vcc55tTNK4V006qcOps3eSOt1U3NoPtXx5kbq5fWzRtSNwWbN2/cukmvqA1siiHfzDrOC2XqOKvkOAvp5g1kU/J58+atn6JGcpzlCG1NHWd6d4Yeq/dFtZAMlc9xJnSaypnoJvQ+2vrBktBvQ5+2jvrmspkQ2v46zt6gw4PWEV7ppJ+9UNfNqwUj9ZTPeUPoJntWX/AJfdqhowebxyY3qNvnDPHPGwV1WcfJ/+BR0HAdp+9h67zKDWqw6dRN0gmt15zVDd2cvMUNimyKLryngw3F6vlD3nUCzdle/mN3SjT5HSSjHqlQHWfkCYMKQovqZsBeX7Lp1E1O6G3G5u3b07emp6dvqzFT5Vi9AKFO5eQ/dwfh9R4h/j9IdNNdAZ+Z4wzwieBPWVwThBbSzYnr7FdKLGxOcj+JzeuC0IROsCjYlMb1B1uinBqh7jrOQFiQ5ZHiDnybccN1nEh6RHUzomO4k5g+53W7cg52cEJN3byhzukJoWjHpOHScivsqBCakeOM+RiPmGJy80aV6zhljhOf9j9GrG7EQ7YcJ0HJ4bG6Hg8xf/MmH+UKobfggvH+G3z4dfoX0tMC3dQNmlWPxAe9PwD+JkFAA44micJO8HxOF8xxgpsUyRwnTvGOtaGLpBYNI5tX4UVqEbkmc5w1NGjC5iTq5igJa/DAfjmnc4NO30Yy6ZsNIwK3Zoi0Y3fXgSA40NWt2eMLemfXFw0RmlXHyansBEJ9LqiUzZFQzNN+Z4y6ifD4qJv4VSLmczIedrCph3pHgZe0TqGhYMlOFFNCvyNgMzr6VvCaAaFzUKR8o6/qZtwp301tFDWTG5SNd/k8HvzRoOkZEFLK5wFfPLvfJa0Z+OK+4IsGCXXkODmWHUTMSCHVTeIrP1tHiCcp4FtG5eQGhRHObkUh9zYpn1aDApvsVoAvGFyfiNXX8II6rGaoBlV0M+xQvxDfpropCIWmvh682ZkZGPYHv9Ce3UfrBeoTdRyoaNCz2XWcYmbnPpRPZyCivjA1CztJgRPKtFMQOo63OjuEtwnxkIPQCTQofmcwMezrr+FfNQkVsXpoPDCmuok3qW526l/2oumZmXq3+ex03B/Q76toUS+vjpO/tBjwwGfdeDdwL3ApCGWxujDolSvqY0OW43QQel39yYOJkA/2TjEKoxs6ocLnFL9dX7xSQMe5JDSWXxY/yNWZmRn+7L68F4zX3SEeyMXAr2DObuLl1XFqOHbS+Zy/m07SS7iU+kClMKgSCQGhVzWDslmdRBFOSiQihJsxnOCECqNMBmI+F9rZOQl/WhifLYQ/6cvGO51/8IE+mQYpxTfWd0sYdGZ6JhJfnpnGl/XI7MwcfhPTTuQyOHiwK7EiCkJHd2l7kt9XvNx6pJrCxWkaqvOfik3pHAAyJghF3yhFaEcQ1ZNYHQ3KvHcx5CckoUEUi5AI5nNarcDi3ZDO6vx5FYezn/9ymH+E3xQmhP42HbFvjmZowy9H8lYXGo9F08KyiCX7c3t+WYOSX9ZWV73cHOeInwxt6m+ic+2fxxwn/xkUQscTQmGEC6nQ1obQCMzfFIReF4SGmOMMxO9hSM1x8ufFHCfLIyGBAfqbOHY6gUZJKPY7cEmDzglCO/wDylzeJTyBrgpDvedgTzS6ura6DgbNreOUgz5gOU60XMBjdT4ex8YFoSzHKQgVPnygx+qC0OtCOYFQblCf+5xyXIAH0xkN81hdJRS9eO4/YDzEUiedEXjw3KDMjtB6wRGFP2aJBp2dm1Ge3Q+4Vb/oUO8sa9VoahXsuba25hWo4+Ts+H3M4+RM8Eidm/eKGPIYY0pCr/EfQo/V0aAsVjcJDXgeSZ/5OjqH2ZwuCZV5pE4+dd9CH/43zqRC6C9RoD0XJXRIf3Z0mwJ94u8qzCYM9p+oNYFPRmhujrNPzEgsxxkKQlmTdkwIvaIQelWYVlsb4kNeIXRiQpqW5ziJ7kv4w5TKhFDMcU7f4sNYDdipFQWhMzOx6ZMAobNz5rMzi/oV3SbyM1jzHvS19bV1r8Da0CC+Qg3XhjihPFZPEcpy7/wHv5bc0vJIktDrCaGT/BZbH6K6ORxqP2An1U3+bEoWSRr0N7SkTqhizw7hIkVgz9n5Uf3ZA5RRX3Ptv8hns+dgNLzO2QRr3luHIZ9fxykMijlObkIeq3PzKoReTQiFMS4MqtUjcUJpjjNFqJpHIlFnEjYqhML8c5vnkWpyYoc2iRoKdpQG5V5V1AfKKSYl3mL12UnEQ/nA7+jQrJxl0Yj8TnVzlbLJ+FynhObWIwmDYo4z8Y9o7+SJ4vGY303XhoQzBarJTaDXI3FCryeEwiiXhN7Qc5yET8nXQTl1QqlNuQAhl/jWapJQ8DjRo4xBN8XcHs3PLsxhX5xbFM/+89LPhHT38JA+x7OnD+vpJn3zgs17jM+1+9C9AnWcJ7lBMU6/KAJ1OvuclB7nWIfUTZFApsopCNVWh7hBJzTlFKZFNsUwh4ZZ5Y5RUE1JqGz4wI5BZtFO6WgKQofwBh3lc/zXDITyZ1+Yh34Hn31yMUD/jhn1QI5BqTHX2Dy0hmRSNrF5BdaG5JDH/DuPlMJBUFBfcik8zs5I5n7IdYVQtY5TEJpwCbrJbyGZdT4Qqb850CHWM6a5E8ZznEw3RaQ0Mz2EWZKOoTuz3KBzMwPcDQY+z/G3FSzMDfOoHwhdGMZnX1jiY2355z4SBa4hD6M8IsPzK2s6m/comxvr9PIK1HFKQlE36zLHJW4QqpuBZzbKpSRUXRuShE4qyimGPOaRfBHLi2C+k+qmXDsJEkRlsM5vdM5IQmfnpjv0GQld97nk2flvv3NpkYh8ZCeX1o4oirq7WbgEn8CQhAyv0ra2eu8e0817XDfhur9Or/v3N7wCdZzCoKIeKZVtYmvqau4ikMrJDaqvD+H7Zf6mMOPkzYRQalHzNfxeqpoys+RD9p3P6Ga26dzsTELoXPrXDPcvpp69vri8aOalwuVV2lewr9GOoxzZXE10k5JJ+wPonr0eSavjFAaVdZxGPpTXcSbvPcCcvEKovjbECaX+ZqKcklBejqRnLGPmuQ/ITLLixev50L4ZsOesIHQ2Tr7YEYmE08Ki+exLy0vL80bmdIU2Zk3ZEt3UlRPYXN+4z7pXoI5TDnm5NnQpCsRYqsWyjpP/ZAHhdgQuJaFaPZIg9IaqnOIW1iPdvNUbyJx6EI3ytSFhCF/xOWfimngkSCmbz2eSuUh+RxDP8aRusDi/VFeffXJxZWkZrkj6obAQQdlcRjZXKZtcN9cU3VznuknZhOs+EHrfq1rHOUZoMc2gVsd5HpZ2ot7KdZzauiWN1c/R14jIpFwbAlcT4nVI311jXjxk3zFWnxmmr0zqdLTPzczSOX2OXbQRWPyK4nnwk+YXaLRP5hYWFxcXFq+wn4DcoXQu02sFesyeaBhsuIJsrgg2eayu6Oa60E163d/YAItuPPA+kTrOycSmST2S0m+jTWfMfgdtipZU+iyLh1SPk/Z56AtLrC8vLC9iX1ninVmU62bCpqmc99hYl7qJyknZfHB/E7pnnrLdxDrO64XqOG+k2LzJLHrrJst43NbCdJiLDDZ5m0WbMjvOKW0eOvM35xcWFmlnbYn15aVlpHMZbbkim0U571l1U2Vz4wFt3qdVx8kqZ9R6D+pv3kI2f9XZ5Lm5OQub83AtUEsKNuepxwl9nrG5uMToXBF0LiOdq/Ry6eaqoptrpm4Cmxv0gg5D/pOq48RKhVuyHklpvwk2p002Z9K6KdgEOheRzUXkcwlugEUV3VxGNpclm2ndXOVsSt1ct7H5YPPB5uaml1PHWXWvuls5i+smz3/8JrOcsmfoJvCpsqkp53K2clp8Tqmb94TPqSnnfa6cjE3sXvZe9XG7bl7Nr+O8XqiO01DOW4odp80c57TJJrfqrKmcgk2pm6pyLiXKqWvnalo5RY5TYfM+zuvQDTaRTyA09zzOLDYz5/T0rJ6u41TrkbQ53a6buDZ0h3tHKTZBN2eRTamdoJqL88sLS5JMnU1FN5fXEu1MdFPJI5lsbjLtBD65Pbc2t+DyjHqk8ey96uV1cyJPN29Ki4o6TnNOn0bdvKPrJmNzxmRzDmw5j2wm2olzupzVLbqZZtOlm+tUNzcUNgFMZHNzi15e+XPlLLo5AGtgZLgIm0YdZ4pNPt5/NdlEPoV3ZNPNFJt0rOd6nFmxeoZuPtB0c4t2sOc2dC+/jpPY96on+4FlxXy+blriIWrV0ro5Y2Nz3qWbiyV0c9XUzXVNNzdsusn53NqCy8uv4yR5dZyyYt7Yqz6RX8eZ8jlv/2aLh5huzpi6OZPBpt3j5Gxa4iGVTWus/kDxOZlubuhsIp/bMOQz9qoLQp1nfDDtTCrmjb3qWqx+087mLW5Hk03uc96xzOmGzyl1c87UTdPj1NlcseaR5NqQmuOE6wH6nA8on5vC5xS6iWxubW9tb297WbqpEJqxVz2pmDcj9Rsp3UQ73tI9zltsXhdRZUo3XT7nvBmrzxf2OAvkOK26icqpsSm0E/lkhLr3qodsFTHO2XPJV2eVeKiQbrJ1y9umbv5aQje5zzlvKueSy+NUY3VXjnPdkuPceLAhfM6Ubm5yNpFPQWiim8X2qmv172jQc1m6yWN1h27eQjZ/S8Xqbt3EWH1Rj9VRNxWfM+VxOmL1dT1WX3fE6lI30edMdBPZ3Nmml1f0PE6wYx0yiyHpN7NISv5d+JxjtE6e+5y8vl2P1a+TUFS9p3SzeKxu1U2VTd3jTOc4762aa0PrebqZaCfXzS3BJms7noNNxqeYlCiZcSjX5sJ64m9q5bOYfw+xjCagZF5PivHD65LMvk5Z5UimbZH6nexYfUHPI80jmyU8TpNNFqsnq0MPUDuFbuo+p0U3kc+d7V24vKwcp1KloC/cEOlvagad1OrkzYUhWBS+Tcf4rcjXqudzlLNUrJ4dqbvy70asjsqZ6KaunEI3txLdFHTS7mXlOMUa8IS2pMnWu0SOUzPoTa1OHk5S6DBKx5luppYpzVjdMacbsbpkc9HUzUWXx2nPca6buqnH6ppummw+VNjcZd3LitUFode5aX2xZM3LOEE3LUNeoHeTbzxIFsX9c7fkQnqHXEgPMnRzxp7jVNmUuil8zhSb6qyesTak5Dg39Byn4XOmdBPp3AF77u56WbG6JJQXiLEzJLF05Rr3OWH1nxdlQ7upGZSj6Md0J7Av6zh5VcaorHrviKvlOJfE6pBVNy0epxkPFc5xCo/Tpps7kk3oD3cfelmnI0lCuUHZvI67vq8msTpfFKZzulInr1Rz0nHOn2F6mleUj7I5ndcfN5jj5HmkZcfq0GrDOU6hnFtbBpvb2w+pRcGOuzvQafOyzuM0CIWZmrBY/YbwOdnaEBqU+ZzcoBHz3Ed5TRLO7B28PoHIOnjoRI55PsrnCsXqSyKPtIjzOq6qZ0bqagYedfPeBq9HemDPI21sarpp8zk1Nul4f/gYhnzGeZyiSmFiSKk/D0K2NyOJh7hBqc/J6+SZv8mh9Dux4aOmRf0h3hkkBp2Zkbo5O2eN1W1zuqabic/p8DhdsbqaR9rQ2UzF6pp2AptUO5HNXUmo8zzOpI5Gn5l9oq4OcYNSL55XsGEsFFnKivpSO9t4Had1VX3esqpusLloYdPtcd7LidU3EuXccijnwy1DN7fZeH+4u/t4949dRqj7PE5J6MREp+E2KbG6IPTmTVlOw2L10GJQMmOp3vLFbGRZVXewKXzOZdu6eqbHqcbq902Pc7OMx5nopmyPdx97WedxCkKpbhJ9a2k9idXRoCyq5AadVgj1tUZEPafaAnusvmCP1dNsFl1V1+o4U2yasXqGbu4kurlDdXN39wnY8gnrnus8ziSPRHiOE+qHZIWVrOMEM6YJxZyHnNi1WL1mTuyN5TgLrKonHmfBHOdmpm4+RN2EMa7y+ZhZ9PHjJ17WeZzCoEqOs85j0M4kxykIlbuGkFAxKfEoqMZgjPmk1MlHeaASWjHHuZq3OpTyOFNspnTTrpy7unI+TCyZdC/rPE5uUB4PdYwx3eR755IcJyeU6iY3KGY50Q+FbZg0+8GruYe5p+RPMIti9abvqEdaTGc5G1pVt+Q4HxTKcSa6iXP6jqGb1KaPWX8ChGbUIwlCbwSSynQdJzfotEoorGn8Kraqs53AdS6dEA/xSL8XmOTxU2cSqy+Y9UgYq2dVI6lspnVzVfE4UznOTTNWT7G5k4rVUTfpeP/DYBNt6mWtqwtCJ9P153GSf+dDnupmp7prKNmrLirZmXSKw4sCkReALQWiLWTlOBc13VRjdUc1kulxmvVIFt10sPkQ2Uzp5i7qJiWTXk+QUHcdpyR00nR2QqUeyUYo6qbpiQZMN1O17PY6zsz8e+7qkMvjXE/nOLcyc5w7pm7upHWTj/fHT588BYNm1HFyg9II06g/V+s40aBMNxNCcVVDy6J2BFfZTDTaqd0bZsTqFVbV0zuHEo8zXSuXw+ZDNVbf1dl8jGw+lmxyQrPqOMWQp9qp1p8TbW1IBJWQSeIGTbLvsdwJDMl5GQklPm1HZ59Zx6nnOFOx+mpGrJ6zqq7VcbpznNs23ZSx+mPF52Rs7go2aX8GhBav44zZmtJAyTpOvqZ0Tlsb+ondS8iktY7TEatbcpxJrO7eOZR4nNVynDumbjI2d3U2n7Lr6dOnXol6pPJ1nNPV6jgV3VwsoZu8Hum+y+OsnOOkNn3MYvW0ciZsYvcq1nHebnEdp0s3K+wc2rDnOLecOc5tM8dpxEMp3eQ2ffr02dNn3idYx2nqZn6sfi9vVT2Jhqy6uelgk8fqLt1EPp8JPp/Sy/sE6zhT9UiOHGfBnUOJx2nqphGr75ixeo5uPhG6ydhkfD575rWqjnOmkTrOxWo7h+7ne5wONh9uW2L1ncdpj9POJvLJule+jvN2I3WccwXrOFU2c3cOrWXuHNI8Ti2PlIrVU/n3Arr5lFvyGTbvk6zjbOrOITVSL7Q2xHOcfxTRTc7mn/R69idcXoE6zpv5dZy/NLOOs8k7hx5o65ZVcpxqrK74nFI3OZ1/0ssz6jgnK9dx3mlBHWfFnUMPsj3O0jlONVZ/atHNP2l/ht1rXx3nXOE6zhIV8EVW1Y21oS1jVR1Xh3bTOc50rJ7opqGcaE3avE+yjtOyc2it8s6hrdScbo3VqW7uWNjcdeimqp2sP6cdhnz2nsvpZuy5LFnHWXHn0H3HzqHE47TF6q4c565dN3k89FTTTcomXM/h03MvRzeRz2n1XDmzAr5QHedcpTrOpuwcUul0xOp2Nu26+SStm885nbR7ljn9Fq/jLKecTazjtOrmWtWdQ3bltMTqjx05TofHqSonZRO7p+5Vv23q5m2hm9ZYvcE6ztx6pIo7h5yr6vYcJ2fzj4KxuupzUjb/pGQyOv9il1dor/qvebqZquOcc7NZqI7TvnNovcrOoa0iOc4yuvlM0U1kk/H5nF5/ec4c522Hbv7ScI5zqWSO03nGR7GdQxaPU8lxPimQ49SUU9dNrpx/if7cq7BXnZCiOc5JMlmqjpPEkk1SX6uTpuwcytTNXX1V3Z7jTOvmM003WfsLu2fJI4lz5cIAWnjNzL/PBEHROs66Xy9VxxmEcm3ID9dJYOgme0NBkLVzaIkYHieUDwUk4dNaj1QmVue6KdnkdD5n1/MXQOgt1E1uR7UFNUIiP9R1k+E5lxerc90c9AdL1XEG4aqyc4gERh4phDdEakHWzqFRX18divxoOPIJepxpNh/bY/WnjlhdZVPRTezYPFuOkytnENKVYHqSMdSJxZROEpKgNhfHMxNkgoS9c7P4GUb5JHwJuIzjO+Qc0034/yQQOri8MByFfTSPBPcAm5NR2EvZhP/9DHaETzzDOUmin8Cg6/DwOszrpE4JjQmweY3w3RlhCGz6ESpnDE/wYHOWwLmfd+h/mGYOEp/EW9uDERlFj7NWAy6DkNFJhntDAnYkwyQ692Q4IrnKmeimm03kk/UXf72ASem2UzcDWugRQn0x8YMaPQs+DGpBbXamVpur+7Va4Mfi8/zFgH5pfqEWBD6hbIZ+zQ+uUkLhm+G+5SX4ehAuDwfwDWRlhf4vGF0NAxjGd6huDvv0ZriGD19bDwgYdJ34wGYUKHWcxJ9lygkvAE+wOUzfAHwnvD9KJoEBHgKW8KYIU84w2Hn4e0CYctK36IfsM3wZbtdcHmcWm6py/oXaKdsLQag9xwlvNISffuYOtSzxR4k/Budrx3O12mzdJ7NzQQgGhYPP4HNYW5wb88l8LRhksXrs9y/+FPaDQVcIWVwJg6XYX4FDZOHW8koMdwY/rcAAX/HjtVXCdDMMflr9BQglZP1eCGM9IPfpkPfJ+kYQKbF6EKJuRr2bD4Jwa9gnW5tBbXszDJhuxv7W9u8+2aGWpFQuUQjC/8s8ziDc+YP4554AsE9qwfIT4i/n5d+L6SZjk/H5Egzq3qvODOqHMz/59OxMH/7NzUz68SwjtD4HVpyHzwvwGWwKjwjCxVoNY/UwYDlOMCjLcUbB8rAfxqCb8DR10M0wYGoINiU8xwm2XIMPzN+MgnUkFOah2sawP5rEQ73+QKKbtRAIHd7cAiNvkYD5nLG/vU18mI3q/iitgI/hFWpBL9PMgI53+BCQx4/D4MmTJb9eLMdp1U3O5nPJJlwvXrz0xHi3rA0F4R16zHZc99nkCtXHQURneEZofXYhRMMuwmecfsP5Wg1znGGN+ZyMUPal5RU2GlfBtjDrrtTYU9ZWYhibtZ/pvA62vLdOhzx7+AYYdAMIhREOxlc8TvgPzuvwTgI/3AaDbm/CKN8iPpvXwaA7cHMXMI0poUEIc1FYY74R2PLJbhCxz2Hw+Mnvfn+xHGeebiKb2D0tj/Sb5m8yDb3mk0k4RZJVwJNaGA7PzRmEzjNCcV4HQlmOE8Y49TkpoTCwwYp0Tv+Z+EswDw2HARhJxkPALJ3TwZbrlFD4dx8Ivc+GPOhmQMC0MlaHd4NzOkjP5laNDvnhLZh0KJZsVheE7gKhVDd9oHI3DDihzKoA6ZMnERB6DwyaleN8puhmyufUGrfqi5dA6K/2taFf0KCzMMsPg5iOQj3SHMDKYnVuUEooWGweCF0I/XjhdFAHg+LaUOyTpTggw0AozESToFgkmITxvhyGdLyvwtdX12pkOIjXr4JMQh0nPMO92A/Bpvd/qjFCH5AAyAQ/8pdNEtTR5wwDHg+Ngnb2BwahMKuDQXd/h5kHpkfmcdaCyd1zQcg8zqB270lENZQ8BoM+fgoGfRrVXLr59HkqVk+xyXUz4fPlCy9jbQj/ZgvQCWejwoif/ZneEURg0AWunYvweREMuzhLx3C4uARDHrQTdDP0YSwvrIAHQKdt4td+olNruNIbsEkc7Acj/toafViwQuOhO1QRAE/+8A0gNPb9jQfgRzx4AJ4k+pxULTEeCtnjyDn/nELozvYdOKljB9TFD/oYlaMBfSO/M4+TuhE+0KkSWvMzdNOIh+y6+deLl5TNv16+BD5fvvSKncdZJzDiw9rsAhwXbK/jHCU/a7H6KBnl8dAkmWWx0CiJqcdZJ+forD5PRmlENEuIyHEOxyxW/4ksqDnO+z7Z2DznT6Z2Do3GGTuH4mG5c+hnMs+9zYD8/+T/MzzOwThbN+1sPrexid1DNu+k80jpHGetNjk3G/lNrEfK3zkUs1kojMw9lyV2DknPnZJpq/nIyiOlYnWbbr5gbGLzSuQ4YzbkScvrONUcZxSQSjuHbBXwNULZrJDjVHzOv/6yscn4fAXmfA0GLXce53w76jibu3NIi9StOc6nuWz+ZcRDyCbyqbVX3idZx9nUnUP66pCZ4/zTmeN8buhm2ueUyvnyvy9fQX+NhDZ2HudiE+o416vlOOXOoYeZO4caz3Hm6SayiTb1PsE6zsydQw9zdw794Vy5rKib3Oe06eZ/X1LtRDZfK4Q2Xse53Mw6zubuHEqU0x6r/1kqVhe6+UJn8/XLV69ev3r9mhI6WzD/3oQ6Ttuey5bvHLLlOJ85VtXzY3VVOXU2oYNNvfw6zvnFjJ1D1pqPJtRxFto5tFNo55DD43Tl358bupmlnKibjE1KJ23eJ1jH2aydQ6bH6V4bepad4zTYlLr5SmPzzWt6eaXqOJfaUsfZ5J1DCZuueqRKuvlSsPla0gntjfdJ1XE+aHDn0G7WqvqfxXOcz8vpJvL55vXfr99SQtPxUOMnwOecZNzWnUPOaiSLz/lXYZ+T21FhE7v3CdZxundnVNo5pFdxmrpZKFZ/gbF6WjcFm2/ZRbtn1nHON6eOc81Sx3m/aB2njNUfNmPn0FONzWfmnG6L1e1sSt18abLJ+HzzlnavSh3nSgvqOLcK7hzate65zFy5zPY4Dd20ROovXpnKqbCJysk7WNRrfR2nWo9Uqo5TOeWjkZ1DzlX18rF6Jptv3tLmZdRxLuxTHWexnUO7pauRsmP1jBynppsvkzn9jcYm7e9gyJeq41xuRx1nVo5zt/zOIamcznokzed0zOnC5zTZRD7fcj7fvvMycpyk+8sDn3z77iBpZFXdHau/tPuc3DtKs/kP9LdAqPs8TnLgM2kke+dQopxN0U3uc775+w1n8x/OJnbPfR5n9+di0J7snUOachq6+cKhm6+suil8Tsnma9TNd/R6++/b93B5CpuLS1qOs+tzMeiXhSL1LN20rA3hbJSO1W26iXy+e//u3bt/Ped5nEsHPpuWvXOoRI7ToZuvXWy+QTbfMzahv/sXhrzzPM7Px6DZO4cskXoWmy+THOdrS6yu+pxCO1l7Dx0s6rnP4/x8DFp0dciS40ytDdlynJTNv3U2/2Ha+c97oZ3vkE9KqHOv+mc05DN3Dml05uU4X2OsnmLzb4duSu189+976P8yQh3ncX5GhGbuHMrNcb7IznHqsbrQTZzXBZe8g009d6z+GRFabFW9co7Tppt8Xn+fsInNYz6n9TzOz8egBSrgbT7nS0es/trMcQo2XbrJbPoOrPmeDnlnPVKWK93TqC/e04yHyCHvXFXXlFOr40zH6q/teaRUPKTr5rt/9ea5c5zpN95FyBShP+fdPfnT9uzdLW3Orrt7e7lhg/Ia+YRWXVVPZzmdeaR/cJxzj1Nl8z2y+e+/H9ik5KxHSjMDdoBGtB+2i0Tl+dy7m2+sEgbN3jmkzuq2WP2VGau/4bG6XTffpXVTI9Rdx5l631N7Uz1dPY/2IvbDdvWQqKcLPvXA6PwO/sPuwMHaRT/A7Z6I3UNvysdBgoiwJ+rpIexZ0G499MnwVwR3dR1QXwO+Cpsk4XMX7O+x/P6ep/xNq8eZm+N8bbKZjtWtuolsfmDdc9dxprn6QH82AojSH3aK0jrFhny0R/8z9Whv79F37BERfsCHdB14BAO8Bx5LH/foAHyC1kMf3wO/nj2KK4P/Ef462F3fqa8BT0bv6+q6y0eIadCyq+pGjvNVTo5TjdXfvVfsqDdmVc+9NpQ26JQyHHumSFfXo73v0KCPesBKpAeHKXvgo0ddYD94GBg2Mege5ZER2rNHMaXfEIGJqPm5GsNd3/FfmniNLvqrJPR7pg50TU2l5Le4bv63Uo5TjdUV5XyfWDLpnruO03zbkWZQOjQjyhgzKFDzAazG2ER7cysxQyqEyl9ND2UVPsCOOniGu3vwGUzHJzkY6eprwO0pqh30lxBZlDV755C1Hsma43QpZxKrv3dqp7Co567jtEwl7BP+sEDj3akPikEf7R0QBgXACBuwPaZBiWLQu8ygU7TBI9jnHmUcKK8Br4mK0DOFEmASmrlzKPE4bWy+fvW3O1Z/+96Mh5Lx/v6Dpp3YP8Kk5KzjTDk7j5g9+HAkaESrQb/bm7p790BC6F1Az2HQu+gnMBwj0iUIndJeAyawLrAlgXmsJ7LM/tk7h+x1nEaOMytWz9JNhU1snruOM73UAHSQKUoK+2GnIrgjshmUTiYEvaMenFumYDxbDHqAWokwdZgCFb7bxe+aEr80fI3v9h5FVIzlE6YIte4cKuBxKrrJlfOd6XEasbpNNwWdHz98BIM66zgP2CxKpxIxHPfuEjpbWwwKYkd/6ojO+4RN3I+iuzaD4hj+Dih9tMedU3YXsKq8Rld0F90F8sE6yxetgLd7nCJW/8fmcWb6nDqb0MCinq2OE2s+rOFgTzLF9nyHl6XJ+YvHj109zsCoS4SYSajZldyUr8FfuMsakBZeVTdynG/ydNMZD6Xo/IB8/g8IddZxVg/Up/b+19PO5EjO6pD0OIvm31E3S7LJ+PxICXXVcVY3KJlqqz0P2HTTVclpVCMlyqnmON8W1U3G5gdkE7vnruP8fNJ32TuHsnTT4nGW1c0Pgk1o//v4P89dx/n5GDR751ASqbtznI5YvaBucj4/0stz13F+PgbN3jn0KqNWzsi/c5/zfUasTtn819DND5RNxuf/gFBnHednRWhmBbzd4zTYLBSpS49T107GJnbPXcf5GRk0c+eQEqlnsZkVqZtsflDY/Mgt+T9snruO87MyaM6q+ps0m0o9Ul6OU9XNDxbdVDoMeWcd52dGqHPnkEpntRyng02pm4JOTqjrPM7Pp1gse+eQ4XG6c5yZPmdKNz+YbHKDus/jPPjZlDOWWlV/Z+Y4y8Xqqs/5UWOTG1Tbc6nVv38+BbfZO4ekx5mlm1lsfjB8zo92NsWQF7qZ3nNJDn4OJeE9JGfnkMJmI7r54WMOmwmhTfhrbc04Af6543Qk+86h/xbeOZR4nO8d9UhlYnU3m5LQ1N8Hdv9d9cwT4HcbPAG+qRXwb7PXhkrF6rm6qRk0Z6+63NfW+hPgW7VzKFHO4rH6R2esLm1qteseDHnrXnXrnsudh+ZfxOK7sXL2XBY8Ab5UBXyJnUNSObU6zn8rxurZfFJCy+nmdrm/cpl/Avzzlu8ccqyqZ+mmqZy6bjptuoeEGqfKWfeqb7fhBPgypyNZV9X5zqF/jJ1D+qp6K3RTtahXZc9la06Ab9XOIYPN6rG6UzeRTexezp5LRTcL/bW2sifAP2/9ziH0OCvkkQrrZmLVPSC02OlIO/I8zmLK2eBJxqV3Dr02dw5ZPc4sNrM9zkzd3DMILXseZ5bH2cAJ8IzNF83fOVRBOcuwyW0qmpf719rc53FWPwH+z8w6zgKnI5XZOaTaNEs3P5bQTRub3KDWc+Wa9lcum3A6kn3n0OvUzqG3rp1DrdfNhE8ktIV/5bLiqXKldg69zdk5VMrj/FBFN/dUg6bPPHxoP4+zzF+5fNbq05HK7BxqPMfpsqqtedsNnMeZ+iuXDZ0A36qdQzbl/JifRyrgc6bGOzNoA+dxgh0bOwH+RZWdQymf07Jz6L2TTAubmm5+LMimnU8waIPncT7NPsm40AnwLd451HiOM185VUKbdR5n82L1ijuH3rkqPpqqmy4ypUEbOI+zySfA22P1v8vE6jmRujXH2QTd1Id8tfM4n2acAN+EHGczdw5pHufHyj5nth01Qhs5j/NZxupQVh6pao7zTfmdQ7rHWS5SL6qcGqGNn8fZwhxnE3YO2es4y+lmETYFoWVynC05Af5V1Z1Db4vtHEpm9WbE6vkGzT+P88nTpvy1trwT4MvtHHpXeOdQMquX180ybEqDWleHqp/HWfkEeNeqevWdQx/Sa0MNx+pFCNV083G5VfUmngCfsXPobSM7hyooZyU2sf0/G8WZkFmrghEAAAAASUVORK5CYII=';
$binaryFiles['fonts/archivo.woff2'] = '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';
$binaryFiles['fonts/ibm-plex-sans.woff2'] = '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';
$binaryFiles['fonts/OFL-Archivo.txt'] = 'Q29weXJpZ2h0IDIwMjAgVGhlIEFyY2hpdm8gUHJvamVjdCBBdXRob3JzIChodHRwczovL2dpdGh1Yi5jb20vT21uaWJ1cy1UeXBlL0FyY2hpdm8pCgpUaGlzIEZvbnQgU29mdHdhcmUgaXMgbGljZW5zZWQgdW5kZXIgdGhlIFNJTCBPcGVuIEZvbnQgTGljZW5zZSwgVmVyc2lvbiAxLjEuClRoaXMgbGljZW5zZSBpcyBjb3BpZWQgYmVsb3csIGFuZCBpcyBhbHNvIGF2YWlsYWJsZSB3aXRoIGEgRkFRIGF0OgpodHRwOi8vc2NyaXB0cy5zaWwub3JnL09GTAoKCi0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tClNJTCBPUEVOIEZPTlQgTElDRU5TRSBWZXJzaW9uIDEuMSAtIDI2IEZlYnJ1YXJ5IDIwMDcKLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0KClBSRUFNQkxFClRoZSBnb2FscyBvZiB0aGUgT3BlbiBGb250IExpY2Vuc2UgKE9GTCkgYXJlIHRvIHN0aW11bGF0ZSB3b3JsZHdpZGUKZGV2ZWxvcG1lbnQgb2YgY29sbGFib3JhdGl2ZSBmb250IHByb2plY3RzLCB0byBzdXBwb3J0IHRoZSBmb250IGNyZWF0aW9uCmVmZm9ydHMgb2YgYWNhZGVtaWMgYW5kIGxpbmd1aXN0aWMgY29tbXVuaXRpZXMsIGFuZCB0byBwcm92aWRlIGEgZnJlZSBhbmQKb3BlbiBmcmFtZXdvcmsgaW4gd2hpY2ggZm9udHMgbWF5IGJlIHNoYXJlZCBhbmQgaW1wcm92ZWQgaW4gcGFydG5lcnNoaXAKd2l0aCBvdGhlcnMuCgpUaGUgT0ZMIGFsbG93cyB0aGUgbGljZW5zZWQgZm9udHMgdG8gYmUgdXNlZCwgc3R1ZGllZCwgbW9kaWZpZWQgYW5kCnJlZGlzdHJpYnV0ZWQgZnJlZWx5IGFzIGxvbmcgYXMgdGhleSBhcmUgbm90IHNvbGQgYnkgdGhlbXNlbHZlcy4gVGhlCmZvbnRzLCBpbmNsdWRpbmcgYW55IGRlcml2YXRpdmUgd29ya3MsIGNhbiBiZSBidW5kbGVkLCBlbWJlZGRlZCwgCnJlZGlzdHJpYnV0ZWQgYW5kL29yIHNvbGQgd2l0aCBhbnkgc29mdHdhcmUgcHJvdmlkZWQgdGhhdCBhbnkgcmVzZXJ2ZWQKbmFtZXMgYXJlIG5vdCB1c2VkIGJ5IGRlcml2YXRpdmUgd29ya3MuIFRoZSBmb250cyBhbmQgZGVyaXZhdGl2ZXMsCmhvd2V2ZXIsIGNhbm5vdCBiZSByZWxlYXNlZCB1bmRlciBhbnkgb3RoZXIgdHlwZSBvZiBsaWNlbnNlLiBUaGUKcmVxdWlyZW1lbnQgZm9yIGZvbnRzIHRvIHJlbWFpbiB1bmRlciB0aGlzIGxpY2Vuc2UgZG9lcyBub3QgYXBwbHkKdG8gYW55IGRvY3VtZW50IGNyZWF0ZWQgdXNpbmcgdGhlIGZvbnRzIG9yIHRoZWlyIGRlcml2YXRpdmVzLgoKREVGSU5JVElPTlMKIkZvbnQgU29mdHdhcmUiIHJlZmVycyB0byB0aGUgc2V0IG9mIGZpbGVzIHJlbGVhc2VkIGJ5IHRoZSBDb3B5cmlnaHQKSG9sZGVyKHMpIHVuZGVyIHRoaXMgbGljZW5zZSBhbmQgY2xlYXJseSBtYXJrZWQgYXMgc3VjaC4gVGhpcyBtYXkKaW5jbHVkZSBzb3VyY2UgZmlsZXMsIGJ1aWxkIHNjcmlwdHMgYW5kIGRvY3VtZW50YXRpb24uCgoiUmVzZXJ2ZWQgRm9udCBOYW1lIiByZWZlcnMgdG8gYW55IG5hbWVzIHNwZWNpZmllZCBhcyBzdWNoIGFmdGVyIHRoZQpjb3B5cmlnaHQgc3RhdGVtZW50KHMpLgoKIk9yaWdpbmFsIFZlcnNpb24iIHJlZmVycyB0byB0aGUgY29sbGVjdGlvbiBvZiBGb250IFNvZnR3YXJlIGNvbXBvbmVudHMgYXMKZGlzdHJpYnV0ZWQgYnkgdGhlIENvcHlyaWdodCBIb2xkZXIocykuCgoiTW9kaWZpZWQgVmVyc2lvbiIgcmVmZXJzIHRvIGFueSBkZXJpdmF0aXZlIG1hZGUgYnkgYWRkaW5nIHRvLCBkZWxldGluZywKb3Igc3Vic3RpdHV0aW5nIC0tIGluIHBhcnQgb3IgaW4gd2hvbGUgLS0gYW55IG9mIHRoZSBjb21wb25lbnRzIG9mIHRoZQpPcmlnaW5hbCBWZXJzaW9uLCBieSBjaGFuZ2luZyBmb3JtYXRzIG9yIGJ5IHBvcnRpbmcgdGhlIEZvbnQgU29mdHdhcmUgdG8gYQpuZXcgZW52aXJvbm1lbnQuCgoiQXV0aG9yIiByZWZlcnMgdG8gYW55IGRlc2lnbmVyLCBlbmdpbmVlciwgcHJvZ3JhbW1lciwgdGVjaG5pY2FsCndyaXRlciBvciBvdGhlciBwZXJzb24gd2hvIGNvbnRyaWJ1dGVkIHRvIHRoZSBGb250IFNvZnR3YXJlLgoKUEVSTUlTU0lPTiAmIENPTkRJVElPTlMKUGVybWlzc2lvbiBpcyBoZXJlYnkgZ3JhbnRlZCwgZnJlZSBvZiBjaGFyZ2UsIHRvIGFueSBwZXJzb24gb2J0YWluaW5nCmEgY29weSBvZiB0aGUgRm9udCBTb2Z0d2FyZSwgdG8gdXNlLCBzdHVkeSwgY29weSwgbWVyZ2UsIGVtYmVkLCBtb2RpZnksCnJlZGlzdHJpYnV0ZSwgYW5kIHNlbGwgbW9kaWZpZWQgYW5kIHVubW9kaWZpZWQgY29waWVzIG9mIHRoZSBGb250ClNvZnR3YXJlLCBzdWJqZWN0IHRvIHRoZSBmb2xsb3dpbmcgY29uZGl0aW9uczoKCjEpIE5laXRoZXIgdGhlIEZvbnQgU29mdHdhcmUgbm9yIGFueSBvZiBpdHMgaW5kaXZpZHVhbCBjb21wb25lbnRzLAppbiBPcmlnaW5hbCBvciBNb2RpZmllZCBWZXJzaW9ucywgbWF5IGJlIHNvbGQgYnkgaXRzZWxmLgoKMikgT3JpZ2luYWwgb3IgTW9kaWZpZWQgVmVyc2lvbnMgb2YgdGhlIEZvbnQgU29mdHdhcmUgbWF5IGJlIGJ1bmRsZWQsCnJlZGlzdHJpYnV0ZWQgYW5kL29yIHNvbGQgd2l0aCBhbnkgc29mdHdhcmUsIHByb3ZpZGVkIHRoYXQgZWFjaCBjb3B5CmNvbnRhaW5zIHRoZSBhYm92ZSBjb3B5cmlnaHQgbm90aWNlIGFuZCB0aGlzIGxpY2Vuc2UuIFRoZXNlIGNhbiBiZQppbmNsdWRlZCBlaXRoZXIgYXMgc3RhbmQtYWxvbmUgdGV4dCBmaWxlcywgaHVtYW4tcmVhZGFibGUgaGVhZGVycyBvcgppbiB0aGUgYXBwcm9wcmlhdGUgbWFjaGluZS1yZWFkYWJsZSBtZXRhZGF0YSBmaWVsZHMgd2l0aGluIHRleHQgb3IKYmluYXJ5IGZpbGVzIGFzIGxvbmcgYXMgdGhvc2UgZmllbGRzIGNhbiBiZSBlYXNpbHkgdmlld2VkIGJ5IHRoZSB1c2VyLgoKMykgTm8gTW9kaWZpZWQgVmVyc2lvbiBvZiB0aGUgRm9udCBTb2Z0d2FyZSBtYXkgdXNlIHRoZSBSZXNlcnZlZCBGb250Ck5hbWUocykgdW5sZXNzIGV4cGxpY2l0IHdyaXR0ZW4gcGVybWlzc2lvbiBpcyBncmFudGVkIGJ5IHRoZSBjb3JyZXNwb25kaW5nCkNvcHlyaWdodCBIb2xkZXIuIFRoaXMgcmVzdHJpY3Rpb24gb25seSBhcHBsaWVzIHRvIHRoZSBwcmltYXJ5IGZvbnQgbmFtZSBhcwpwcmVzZW50ZWQgdG8gdGhlIHVzZXJzLgoKNCkgVGhlIG5hbWUocykgb2YgdGhlIENvcHlyaWdodCBIb2xkZXIocykgb3IgdGhlIEF1dGhvcihzKSBvZiB0aGUgRm9udApTb2Z0d2FyZSBzaGFsbCBub3QgYmUgdXNlZCB0byBwcm9tb3RlLCBlbmRvcnNlIG9yIGFkdmVydGlzZSBhbnkKTW9kaWZpZWQgVmVyc2lvbiwgZXhjZXB0IHRvIGFja25vd2xlZGdlIHRoZSBjb250cmlidXRpb24ocykgb2YgdGhlCkNvcHlyaWdodCBIb2xkZXIocykgYW5kIHRoZSBBdXRob3Iocykgb3Igd2l0aCB0aGVpciBleHBsaWNpdCB3cml0dGVuCnBlcm1pc3Npb24uCgo1KSBUaGUgRm9udCBTb2Z0d2FyZSwgbW9kaWZpZWQgb3IgdW5tb2RpZmllZCwgaW4gcGFydCBvciBpbiB3aG9sZSwKbXVzdCBiZSBkaXN0cmlidXRlZCBlbnRpcmVseSB1bmRlciB0aGlzIGxpY2Vuc2UsIGFuZCBtdXN0IG5vdCBiZQpkaXN0cmlidXRlZCB1bmRlciBhbnkgb3RoZXIgbGljZW5zZS4gVGhlIHJlcXVpcmVtZW50IGZvciBmb250cyB0bwpyZW1haW4gdW5kZXIgdGhpcyBsaWNlbnNlIGRvZXMgbm90IGFwcGx5IHRvIGFueSBkb2N1bWVudCBjcmVhdGVkCnVzaW5nIHRoZSBGb250IFNvZnR3YXJlLgoKVEVSTUlOQVRJT04KVGhpcyBsaWNlbnNlIGJlY29tZXMgbnVsbCBhbmQgdm9pZCBpZiBhbnkgb2YgdGhlIGFib3ZlIGNvbmRpdGlvbnMgYXJlCm5vdCBtZXQuCgpESVNDTEFJTUVSClRIRSBGT05UIFNPRlRXQVJFIElTIFBST1ZJREVEICJBUyBJUyIsIFdJVEhPVVQgV0FSUkFOVFkgT0YgQU5ZIEtJTkQsCkVYUFJFU1MgT1IgSU1QTElFRCwgSU5DTFVESU5HIEJVVCBOT1QgTElNSVRFRCBUTyBBTlkgV0FSUkFOVElFUyBPRgpNRVJDSEFOVEFCSUxJVFksIEZJVE5FU1MgRk9SIEEgUEFSVElDVUxBUiBQVVJQT1NFIEFORCBOT05JTkZSSU5HRU1FTlQKT0YgQ09QWVJJR0hULCBQQVRFTlQsIFRSQURFTUFSSywgT1IgT1RIRVIgUklHSFQuIElOIE5PIEVWRU5UIFNIQUxMIFRIRQpDT1BZUklHSFQgSE9MREVSIEJFIExJQUJMRSBGT1IgQU5ZIENMQUlNLCBEQU1BR0VTIE9SIE9USEVSIExJQUJJTElUWSwKSU5DTFVESU5HIEFOWSBHRU5FUkFMLCBTUEVDSUFMLCBJTkRJUkVDVCwgSU5DSURFTlRBTCwgT1IgQ09OU0VRVUVOVElBTApEQU1BR0VTLCBXSEVUSEVSIElOIEFOIEFDVElPTiBPRiBDT05UUkFDVCwgVE9SVCBPUiBPVEhFUldJU0UsIEFSSVNJTkcKRlJPTSwgT1VUIE9GIFRIRSBVU0UgT1IgSU5BQklMSVRZIFRPIFVTRSBUSEUgRk9OVCBTT0ZUV0FSRSBPUiBGUk9NCk9USEVSIERFQUxJTkdTIElOIFRIRSBGT05UIFNPRlRXQVJFLgo=';
$binaryFiles['fonts/OFL-IBMPlexSans.txt'] = 'Q29weXJpZ2h0IMKpIDIwMTcgSUJNIENvcnAuIHdpdGggUmVzZXJ2ZWQgRm9udCBOYW1lICJQbGV4Ig0KDQpUaGlzIEZvbnQgU29mdHdhcmUgaXMgbGljZW5zZWQgdW5kZXIgdGhlIFNJTCBPcGVuIEZvbnQgTGljZW5zZSwgVmVyc2lvbiAxLjEuDQoNClRoaXMgbGljZW5zZSBpcyBjb3BpZWQgYmVsb3csIGFuZCBpcyBhbHNvIGF2YWlsYWJsZSB3aXRoIGEgRkFRIGF0OiBodHRwOi8vc2NyaXB0cy5zaWwub3JnL09GTA0KDQoNCi0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tDQpTSUwgT1BFTiBGT05UIExJQ0VOU0UgVmVyc2lvbiAxLjEgLSAyNiBGZWJydWFyeSAyMDA3DQotLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLQ0KDQpQUkVBTUJMRQ0KVGhlIGdvYWxzIG9mIHRoZSBPcGVuIEZvbnQgTGljZW5zZSAoT0ZMKSBhcmUgdG8gc3RpbXVsYXRlIHdvcmxkd2lkZQ0KZGV2ZWxvcG1lbnQgb2YgY29sbGFib3JhdGl2ZSBmb250IHByb2plY3RzLCB0byBzdXBwb3J0IHRoZSBmb250IGNyZWF0aW9uDQplZmZvcnRzIG9mIGFjYWRlbWljIGFuZCBsaW5ndWlzdGljIGNvbW11bml0aWVzLCBhbmQgdG8gcHJvdmlkZSBhIGZyZWUgYW5kDQpvcGVuIGZyYW1ld29yayBpbiB3aGljaCBmb250cyBtYXkgYmUgc2hhcmVkIGFuZCBpbXByb3ZlZCBpbiBwYXJ0bmVyc2hpcA0Kd2l0aCBvdGhlcnMuDQoNClRoZSBPRkwgYWxsb3dzIHRoZSBsaWNlbnNlZCBmb250cyB0byBiZSB1c2VkLCBzdHVkaWVkLCBtb2RpZmllZCBhbmQNCnJlZGlzdHJpYnV0ZWQgZnJlZWx5IGFzIGxvbmcgYXMgdGhleSBhcmUgbm90IHNvbGQgYnkgdGhlbXNlbHZlcy4gVGhlDQpmb250cywgaW5jbHVkaW5nIGFueSBkZXJpdmF0aXZlIHdvcmtzLCBjYW4gYmUgYnVuZGxlZCwgZW1iZWRkZWQsIA0KcmVkaXN0cmlidXRlZCBhbmQvb3Igc29sZCB3aXRoIGFueSBzb2Z0d2FyZSBwcm92aWRlZCB0aGF0IGFueSByZXNlcnZlZA0KbmFtZXMgYXJlIG5vdCB1c2VkIGJ5IGRlcml2YXRpdmUgd29ya3MuIFRoZSBmb250cyBhbmQgZGVyaXZhdGl2ZXMsDQpob3dldmVyLCBjYW5ub3QgYmUgcmVsZWFzZWQgdW5kZXIgYW55IG90aGVyIHR5cGUgb2YgbGljZW5zZS4gVGhlDQpyZXF1aXJlbWVudCBmb3IgZm9udHMgdG8gcmVtYWluIHVuZGVyIHRoaXMgbGljZW5zZSBkb2VzIG5vdCBhcHBseQ0KdG8gYW55IGRvY3VtZW50IGNyZWF0ZWQgdXNpbmcgdGhlIGZvbnRzIG9yIHRoZWlyIGRlcml2YXRpdmVzLg0KDQpERUZJTklUSU9OUw0KIkZvbnQgU29mdHdhcmUiIHJlZmVycyB0byB0aGUgc2V0IG9mIGZpbGVzIHJlbGVhc2VkIGJ5IHRoZSBDb3B5cmlnaHQNCkhvbGRlcihzKSB1bmRlciB0aGlzIGxpY2Vuc2UgYW5kIGNsZWFybHkgbWFya2VkIGFzIHN1Y2guIFRoaXMgbWF5DQppbmNsdWRlIHNvdXJjZSBmaWxlcywgYnVpbGQgc2NyaXB0cyBhbmQgZG9jdW1lbnRhdGlvbi4NCg0KIlJlc2VydmVkIEZvbnQgTmFtZSIgcmVmZXJzIHRvIGFueSBuYW1lcyBzcGVjaWZpZWQgYXMgc3VjaCBhZnRlciB0aGUNCmNvcHlyaWdodCBzdGF0ZW1lbnQocykuDQoNCiJPcmlnaW5hbCBWZXJzaW9uIiByZWZlcnMgdG8gdGhlIGNvbGxlY3Rpb24gb2YgRm9udCBTb2Z0d2FyZSBjb21wb25lbnRzIGFzDQpkaXN0cmlidXRlZCBieSB0aGUgQ29weXJpZ2h0IEhvbGRlcihzKS4NCg0KIk1vZGlmaWVkIFZlcnNpb24iIHJlZmVycyB0byBhbnkgZGVyaXZhdGl2ZSBtYWRlIGJ5IGFkZGluZyB0bywgZGVsZXRpbmcsDQpvciBzdWJzdGl0dXRpbmcgLS0gaW4gcGFydCBvciBpbiB3aG9sZSAtLSBhbnkgb2YgdGhlIGNvbXBvbmVudHMgb2YgdGhlDQpPcmlnaW5hbCBWZXJzaW9uLCBieSBjaGFuZ2luZyBmb3JtYXRzIG9yIGJ5IHBvcnRpbmcgdGhlIEZvbnQgU29mdHdhcmUgdG8gYQ0KbmV3IGVudmlyb25tZW50Lg0KDQoiQXV0aG9yIiByZWZlcnMgdG8gYW55IGRlc2lnbmVyLCBlbmdpbmVlciwgcHJvZ3JhbW1lciwgdGVjaG5pY2FsDQp3cml0ZXIgb3Igb3RoZXIgcGVyc29uIHdobyBjb250cmlidXRlZCB0byB0aGUgRm9udCBTb2Z0d2FyZS4NCg0KUEVSTUlTU0lPTiAmIENPTkRJVElPTlMNClBlcm1pc3Npb24gaXMgaGVyZWJ5IGdyYW50ZWQsIGZyZWUgb2YgY2hhcmdlLCB0byBhbnkgcGVyc29uIG9idGFpbmluZw0KYSBjb3B5IG9mIHRoZSBGb250IFNvZnR3YXJlLCB0byB1c2UsIHN0dWR5LCBjb3B5LCBtZXJnZSwgZW1iZWQsIG1vZGlmeSwNCnJlZGlzdHJpYnV0ZSwgYW5kIHNlbGwgbW9kaWZpZWQgYW5kIHVubW9kaWZpZWQgY29waWVzIG9mIHRoZSBGb250DQpTb2Z0d2FyZSwgc3ViamVjdCB0byB0aGUgZm9sbG93aW5nIGNvbmRpdGlvbnM6DQoNCjEpIE5laXRoZXIgdGhlIEZvbnQgU29mdHdhcmUgbm9yIGFueSBvZiBpdHMgaW5kaXZpZHVhbCBjb21wb25lbnRzLA0KaW4gT3JpZ2luYWwgb3IgTW9kaWZpZWQgVmVyc2lvbnMsIG1heSBiZSBzb2xkIGJ5IGl0c2VsZi4NCg0KMikgT3JpZ2luYWwgb3IgTW9kaWZpZWQgVmVyc2lvbnMgb2YgdGhlIEZvbnQgU29mdHdhcmUgbWF5IGJlIGJ1bmRsZWQsDQpyZWRpc3RyaWJ1dGVkIGFuZC9vciBzb2xkIHdpdGggYW55IHNvZnR3YXJlLCBwcm92aWRlZCB0aGF0IGVhY2ggY29weQ0KY29udGFpbnMgdGhlIGFib3ZlIGNvcHlyaWdodCBub3RpY2UgYW5kIHRoaXMgbGljZW5zZS4gVGhlc2UgY2FuIGJlDQppbmNsdWRlZCBlaXRoZXIgYXMgc3RhbmQtYWxvbmUgdGV4dCBmaWxlcywgaHVtYW4tcmVhZGFibGUgaGVhZGVycyBvcg0KaW4gdGhlIGFwcHJvcHJpYXRlIG1hY2hpbmUtcmVhZGFibGUgbWV0YWRhdGEgZmllbGRzIHdpdGhpbiB0ZXh0IG9yDQpiaW5hcnkgZmlsZXMgYXMgbG9uZyBhcyB0aG9zZSBmaWVsZHMgY2FuIGJlIGVhc2lseSB2aWV3ZWQgYnkgdGhlIHVzZXIuDQoNCjMpIE5vIE1vZGlmaWVkIFZlcnNpb24gb2YgdGhlIEZvbnQgU29mdHdhcmUgbWF5IHVzZSB0aGUgUmVzZXJ2ZWQgRm9udA0KTmFtZShzKSB1bmxlc3MgZXhwbGljaXQgd3JpdHRlbiBwZXJtaXNzaW9uIGlzIGdyYW50ZWQgYnkgdGhlIGNvcnJlc3BvbmRpbmcNCkNvcHlyaWdodCBIb2xkZXIuIFRoaXMgcmVzdHJpY3Rpb24gb25seSBhcHBsaWVzIHRvIHRoZSBwcmltYXJ5IGZvbnQgbmFtZSBhcw0KcHJlc2VudGVkIHRvIHRoZSB1c2Vycy4NCg0KNCkgVGhlIG5hbWUocykgb2YgdGhlIENvcHlyaWdodCBIb2xkZXIocykgb3IgdGhlIEF1dGhvcihzKSBvZiB0aGUgRm9udA0KU29mdHdhcmUgc2hhbGwgbm90IGJlIHVzZWQgdG8gcHJvbW90ZSwgZW5kb3JzZSBvciBhZHZlcnRpc2UgYW55DQpNb2RpZmllZCBWZXJzaW9uLCBleGNlcHQgdG8gYWNrbm93bGVkZ2UgdGhlIGNvbnRyaWJ1dGlvbihzKSBvZiB0aGUNCkNvcHlyaWdodCBIb2xkZXIocykgYW5kIHRoZSBBdXRob3Iocykgb3Igd2l0aCB0aGVpciBleHBsaWNpdCB3cml0dGVuDQpwZXJtaXNzaW9uLg0KDQo1KSBUaGUgRm9udCBTb2Z0d2FyZSwgbW9kaWZpZWQgb3IgdW5tb2RpZmllZCwgaW4gcGFydCBvciBpbiB3aG9sZSwNCm11c3QgYmUgZGlzdHJpYnV0ZWQgZW50aXJlbHkgdW5kZXIgdGhpcyBsaWNlbnNlLCBhbmQgbXVzdCBub3QgYmUNCmRpc3RyaWJ1dGVkIHVuZGVyIGFueSBvdGhlciBsaWNlbnNlLiBUaGUgcmVxdWlyZW1lbnQgZm9yIGZvbnRzIHRvDQpyZW1haW4gdW5kZXIgdGhpcyBsaWNlbnNlIGRvZXMgbm90IGFwcGx5IHRvIGFueSBkb2N1bWVudCBjcmVhdGVkDQp1c2luZyB0aGUgRm9udCBTb2Z0d2FyZS4NCg0KVEVSTUlOQVRJT04NClRoaXMgbGljZW5zZSBiZWNvbWVzIG51bGwgYW5kIHZvaWQgaWYgYW55IG9mIHRoZSBhYm92ZSBjb25kaXRpb25zIGFyZQ0Kbm90IG1ldC4NCg0KRElTQ0xBSU1FUg0KVEhFIEZPTlQgU09GVFdBUkUgSVMgUFJPVklERUQgIkFTIElTIiwgV0lUSE9VVCBXQVJSQU5UWSBPRiBBTlkgS0lORCwNCkVYUFJFU1MgT1IgSU1QTElFRCwgSU5DTFVESU5HIEJVVCBOT1QgTElNSVRFRCBUTyBBTlkgV0FSUkFOVElFUyBPRg0KTUVSQ0hBTlRBQklMSVRZLCBGSVRORVNTIEZPUiBBIFBBUlRJQ1VMQVIgUFVSUE9TRSBBTkQgTk9OSU5GUklOR0VNRU5UDQpPRiBDT1BZUklHSFQsIFBBVEVOVCwgVFJBREVNQVJLLCBPUiBPVEhFUiBSSUdIVC4gSU4gTk8gRVZFTlQgU0hBTEwgVEhFDQpDT1BZUklHSFQgSE9MREVSIEJFIExJQUJMRSBGT1IgQU5ZIENMQUlNLCBEQU1BR0VTIE9SIE9USEVSIExJQUJJTElUWSwNCklOQ0xVRElORyBBTlkgR0VORVJBTCwgU1BFQ0lBTCwgSU5ESVJFQ1QsIElOQ0lERU5UQUwsIE9SIENPTlNFUVVFTlRJQUwNCkRBTUFHRVMsIFdIRVRIRVIgSU4gQU4gQUNUSU9OIE9GIENPTlRSQUNULCBUT1JUIE9SIE9USEVSV0lTRSwgQVJJU0lORw0KRlJPTSwgT1VUIE9GIFRIRSBVU0UgT1IgSU5BQklMSVRZIFRPIFVTRSBUSEUgRk9OVCBTT0ZUV0FSRSBPUiBGUk9NDQpPVEhFUiBERUFMSU5HUyBJTiBUSEUgRk9OVCBTT0ZUV0FSRS4NCg==';

$files['favicon.svg'] = <<<'FAVICON_FILE'
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 28 28"><rect width="28" height="28" rx="6" fill="#0B6E4F"/><path d="M6 8.5h11.5L23 14l-5.5 5.5H6l3.2-5.5z" fill="#fff"/></svg>
FAVICON_FILE;

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

        if (($_POST['tos'] ?? '') !== '1')
            throw new RuntimeException('Please read and agree to the Terms of Service to place your order.');

        [$path, $w, $h] = gb_save_upload($_FILES['image'] ?? []);

        if (gb_migrate()) {   // record which Terms version was accepted, and when
            gb_db()->prepare(
                "INSERT INTO gb_banners (spot_id, advertiser_name, advertiser_email,
                 kind, image_path, target_url, width, height, status, paid, tos_version, tos_accepted_at)
                 VALUES (?,?,?,'image',?,?,?,?, 'pending', 0, ?, ?)")
                ->execute([(int)$spot['id'], $name, $email, $path, $url, $w, $h, GB_TOS_VERSION, gmdate('Y-m-d H:i:s')]);
        } else {               // database not upgradable: still take the order
            gb_db()->prepare(
                "INSERT INTO gb_banners (spot_id, advertiser_name, advertiser_email,
                 kind, image_path, target_url, width, height, status, paid)
                 VALUES (?,?,?,'image',?,?,?,?, 'pending', 0)")
                ->execute([(int)$spot['id'], $name, $email, $path, $url, $w, $h]);
        }

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

$minPrice = $spots ? min(array_map(fn($x) => (float)$x['price'], $spots)) : 0.0;
$offers = array_map(fn($x) => ['@type' => 'Offer', 'name' => $x['name'] . ' banner ad (' . (int)$x['width'] . 'x' . (int)$x['height'] . ')',
    'price' => number_format((float)$x['price'], 2, '.', ''), 'priceCurrency' => GB_CURRENCY,
    'availability' => 'https://schema.org/InStock', 'url' => gb_url('/advertise.php')], $spots);
echo gb_public_open('Buy a banner ad', 'Choose an ad size, upload your banner and review your order. '
    . 'Every ad is checked by our team before it goes live, usually within one business day.', 1, [
    'index'       => true,
    'path'        => '/advertise.php',
    'title_full'  => 'Buy a Banner Ad Online: ' . (int)count($spots) . ' Sizes from $' . rtrim(rtrim(number_format($minPrice, 2), '0'), '.')
                   . ' | ' . GB_BRAND_NAME,
    'description' => 'Order a banner ad in minutes: pick a size (728x90, 300x250, 160x600 and more), upload your image, '
                   . 'pay by card or PayPal. From $' . rtrim(rtrim(number_format($minPrice, 2), '0'), '.') . ' for 30 days.',
    'jsonld'      => [gb_breadcrumb('Buy a banner ad', '/advertise.php'),
        ['@type' => 'Service', 'name' => 'Banner ad placement', 'serviceType' => 'Display advertising',
         'provider' => ['@id' => gb_url('/#organization')], 'areaServed' => 'Worldwide',
         'offers' => ['@type' => 'AggregateOffer', 'priceCurrency' => GB_CURRENCY, 'offerCount' => count($spots),
                      'lowPrice' => number_format($minPrice, 2, '.', ''),
                      'highPrice' => number_format($spots ? max(array_map(fn($x) => (float)$x['price'], $spots)) : 0, 2, '.', ''),
                      'offers' => $offers]]],
]);
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
    <label class="agree">
      <input type="checkbox" name="tos" value="1" required <?= ($_POST['tos'] ?? '') === '1' ? 'checked' : '' ?>>
      <span>I agree to the <a href="terms.php" target="_blank">Terms of Service</a>: no fraud, no adult content, nothing illegal,
        and no redirecting my link after it is submitted. I understand that breaking any rule means my ad is removed
        immediately with no refund. See our <a href="privacy.php" target="_blank">Privacy Policy</a>.</span>
    </label>
    <div class="form-foot">
      <p class="muted small">Next you'll see a preview and choose how to pay. You won't be charged yet.</p>
      <button class="btn btn-primary btn-lg" type="submit">Continue to preview</button>
    </div>
  </fieldset>
</form>

<section class="panel prose" aria-labelledby="why">
  <h2 id="why">Buy banner ads online without the hassle</h2>
  <div class="feature-grid">
    <div><h3>One flat price</h3><p>Each placement has a fixed price for a fixed run, shown above. No bidding, no minimum spend and no contract.</p></div>
    <div><h3>Every standard size</h3><p>From the 728×90 leaderboard to the 300×250 medium rectangle and 320×50 mobile banner. See the <a href="banner-ad-sizes.php">banner ad size guide</a>.</p></div>
    <div><h3>Reviewed by a person</h3><p>We check each banner and landing page before it runs, so ads stay clean and relevant.</p></div>
    <div><h3>Pay how you like</h3><p>Pay by card through Stripe or with PayPal. Your run starts when your banner is approved.</p></div>
  </div>
  <p class="muted small">New to banner ads? Read <a href="how-to-buy-banner-ads.php">how to buy banner ads</a>, compare <a href="pricing.php">banner ad pricing</a>,
    get ideas from the <a href="/blog/">online marketing blog</a> or check the <a href="faq.php">FAQ</a>.</p>
</section>

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
    'This is how your banner will look on our pages. Check the details, then choose how to pay.', $paid ? 3 : 2,
    ['analytics' => true]);
?>
<div class="review-grid">
  <section class="panel">
    <div class="panel-head"><div><h2>Preview</h2>
      <p>Shown in a sample page. Views and clicks here are not counted.</p></div></div>
    <div class="browser">
      <div class="browser-bar"><i></i><i></i><i></i><span class="browser-url"><?= e(parse_url(GB_BASE_URL, PHP_URL_HOST) ?: 'yourwebsite.com') ?></span></div>
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
       . '<span>' . gb_wordmark() . '<small>Publisher console</small></span></a>'
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
       . '<span>' . gb_wordmark() . '<small>Publisher console</small></span></a>'
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
                   . '<div class="cell-sub">' . (int)$r['width'] . ' × ' . (int)$r['height'] . ($r['kind'] === 'html' ? ', HTML' : '') . '</div>'
                   . (!empty($r['tos_accepted_at']) ? '<div class="cell-sub" title="Accepted ' . e((string)$r['tos_accepted_at']) . ' UTC">Terms v' . e((string)$r['tos_version']) . ' accepted</div>' : '')
                   . '</div></div></td>'
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
$targetDir = (string)realpath($targetDir);   // absolute: templates are rendered with this as working dir

$written = 0;
foreach ($files as $name => $content) {
    $path = $targetDir . DIRECTORY_SEPARATOR . $name;
    if (!is_dir(dirname($path)) && !@mkdir(dirname($path), 0755, true)) fail("Could not create folder for: $path");
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

foreach ($binaryFiles as $name => $b64) {
    $path = $targetDir . DIRECTORY_SEPARATOR . $name;
    if (!is_dir(dirname($path)) && !@mkdir(dirname($path), 0755, true)) fail("Could not create folder for: $path");
    if (file_exists($path) && !$overwrite) { echo "  SKIP   $name (already exists)\n"; continue; }
    if (@file_put_contents($path, base64_decode($b64)) === false) fail("Could not write: $path");
    @chmod($path, 0644);
    echo "  WROTE  $name (" . number_format(strlen(base64_decode($b64)) / 1024, 1) . " KB)\n";
    $written++;
}

// Render templates into static files: the home page becomes index.html,
// plus sitemap.xml and robots.txt. Templates are removed afterwards.
$lastmod = (string)($opts['lastmod'] ?? gmdate('Y-m-d'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $lastmod)) fail("--lastmod must be YYYY-MM-DD, got: $lastmod");
foreach (['home.php' => 'index.html', 'sitemap.xml.php' => 'sitemap.xml', 'robots.txt.php' => 'robots.txt'] as $tpl => $outName) {
    $tplPath = $targetDir . DIRECTORY_SEPARATOR . $tpl;
    $outPath = $targetDir . DIRECTORY_SEPARATOR . $outName;
    if (!is_file($tplPath)) continue;
    if (file_exists($outPath) && !$overwrite) {
        echo "  SKIP   $outName (already exists; use --force to replace it)\n";
    } else {
        $proc = proc_open([PHP_BINARY, '-d', 'display_errors=stderr', $tplPath],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $targetDir, array_merge(getenv(), ['GB_LASTMOD' => $lastmod]));
        $html = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        $code = proc_close($proc);
        if ($code !== 0 || trim((string)$html) === '' || trim((string)$err) !== '')
            fail("Could not render $outName from $tpl: " . trim((string)$err));
        if (@file_put_contents($outPath, $html) === false) fail("Could not write: $outPath");
        @chmod($outPath, 0644);
        echo "  WROTE  $outName (rendered, " . number_format(strlen((string)$html) / 1024, 1) . " KB)\n";
        $written++;
    }
    @unlink($tplPath);
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
