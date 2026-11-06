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

/** Public pages search engines may index, in menu/sitemap order: path => [menu label, lastmod key]. */
function gb_public_pages(): array {
    return [
        '/'                          => 'Home',
        '/advertise.php'             => 'Buy a banner ad',
        '/pricing.php'               => 'Pricing',
        '/banner-ad-sizes.php'       => 'Banner ad sizes',
        '/how-to-buy-banner-ads.php' => 'How it works',
        '/faq.php'                   => 'FAQ',
    ];
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
       . '<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>'
       . '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Archivo:wdth,wght@100..125,500..800&family=IBM+Plex+Sans:wght@400;500;600&display=swap">'
       . '<link rel="icon" href="favicon.svg" type="image/svg+xml">'
       . '<link rel="stylesheet" href="style.css?v=' . (int)@filemtime(__DIR__ . '/style.css') . '">'
       . '</head><body' . ($bodyClass !== '' ? ' class="' . e($bodyClass) . '"' : '') . '>';
}

/** BreadcrumbList schema for a page one level below Home. */
function gb_breadcrumb(string $name, string $path): array {
    return ['@type' => 'BreadcrumbList', 'itemListElement' => [
        ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => gb_url('/')],
        ['@type' => 'ListItem', 'position' => 2, 'name' => $name, 'item' => gb_url($path)],
    ]];
}

/** Top bar with the logo and the site menu. $active is the current page path. */
function gb_topbar(string $active = ''): string {
    $links = '';
    foreach (['/pricing.php' => 'Pricing', '/banner-ad-sizes.php' => 'Banner sizes',
              '/how-to-buy-banner-ads.php' => 'How it works', '/faq.php' => 'FAQ'] as $path => $label)
        $links .= '<a href="' . e(ltrim($path, '/')) . '"' . ($path === $active ? ' aria-current="page"' : '') . '>' . $label . '</a>';
    return '<div class="topbar"><a class="brand" href="./" aria-label="' . e(GB_BRAND_NAME) . ' home">' . gb_brand_mark() . gb_wordmark() . '</a>'
         . '<nav class="site-nav" aria-label="Main">' . $links
         . '<a class="btn btn-primary btn-sm nav-cta" href="advertise.php"' . ($active === '/advertise.php' ? ' aria-current="page"' : '') . '>Buy a banner ad</a></nav></div>';
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

/** Content page top (pricing, guides, FAQ): masthead with menu, breadcrumb, H1 and intro. */
function gb_site_open(string $h1, string $lede, array $meta, string $crumb = ''): string {
    return gb_head($h1, '', $meta + ['index' => true])
         . '<header class="masthead"><div class="wrap">' . gb_topbar($meta['path'] ?? '')
         . '<div class="intro">'
         . ($crumb !== '' ? '<nav class="crumbs" aria-label="Breadcrumb"><a href="./">Home</a><span aria-hidden="true">/</span><span>' . e($crumb) . '</span></nav>' : '')
         . '<h1>' . e($h1) . '</h1><p class="lede">' . e($lede) . '</p></div>'
         . '</div></header><main class="page"><div class="wrap">';
}

function gb_public_close(): string {
    $links = '';
    foreach (gb_public_pages() as $path => $label)
        $links .= '<li><a href="' . e($path === '/' ? './' : ltrim($path, '/')) . '">' . e($label) . '</a></li>';
    return '</div></main><footer class="site-foot"><div class="wrap">'
         . '<div class="foot-brand"><a class="brand" href="./">' . gb_brand_mark() . gb_wordmark() . '</a>'
         . '<p>Self-serve banner advertising: pick an ad size, upload your banner and run it for 30 days.</p></div>'
         . '<nav aria-label="Site"><ul class="foot-links">' . $links
         . '<li><a href="mailto:' . e(GB_ADMIN_EMAIL) . '">Contact us</a></li></ul></nav>'
         . '<p class="foot-legal">&copy; ' . date('Y') . ' ' . e(GB_BRAND_NAME) . '. All advertising is reviewed before publication. '
         . 'Payments are processed securely by PayPal and Stripe.</p>'
         . '</div></footer></body></html>';
}

/** A call-to-action band used at the end of content pages. */
function gb_cta(string $heading = 'Ready to run your banner?', string $text = 'Choose an ad size, upload your image and check out in a few minutes.'): string {
    return '<section class="cta-band"><div><h2>' . e($heading) . '</h2><p>' . e($text) . '</p></div>'
         . '<a class="btn btn-primary btn-lg" href="advertise.php">Buy a banner ad</a></section>';
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
    'title_full'  => 'Buy Banner Ads Online, ' . GB_PRICE_PITCH . ' | ' . GB_BRAND_NAME,
    'description' => 'Self-serve banner advertising: choose a standard ad size, upload your banner and pay by card or PayPal. '
                   . ucfirst(GB_PRICE_PITCH) . ', every size.',
    'jsonld'      => [['@type' => 'FAQPage', 'mainEntity' => array_map(fn($q) => ['@type' => 'Question', 'name' => $q[0],
                        'acceptedAnswer' => ['@type' => 'Answer', 'text' => $q[1]]], $faq)]],
]);
?>
<header class="masthead"><div class="wrap">
  <?= gb_topbar('/') ?>
  <section class="hero">
    <div>
      <h1>Buy banner ads online, <?= e(GB_PRICE_PITCH) ?></h1>
      <p class="lede">Put your business in front of new visitors with a self-serve banner ad. Pick a standard ad size,
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
];

echo gb_site_open('Banner ad pricing', 'Simple, flat-rate banner advertising: one price per placement for a full run, '
    . 'with no bidding, no minimum spend and no contract.', [
    'path'        => '/pricing.php',
    'title_full'  => 'Banner Ad Pricing: ' . ucfirst($pitch) . ', Every Size | ' . GB_BRAND_NAME,
    'description' => 'How much do banner ads cost? ' . ucfirst($pitch) . ' for any standard size, 728x90 to 320x50. '
                   . 'Flat rate, no bidding, no minimum spend and no contract.',
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

  <h2>How banner ads are usually priced</h2>
  <p>Most ad networks sell banner ads in one of three ways. With <strong>CPM</strong> (cost per thousand impressions) you pay for every thousand times your ad is shown.
    With <strong>CPC</strong> (cost per click) you pay each time someone clicks. Both usually involve bidding against other advertisers, so the final cost of a campaign is hard to predict.</p>
  <p><?= e(GB_BRAND_NAME) ?> uses <strong>flat-rate pricing</strong> instead: you pay one fixed price for a fixed run. You know the total cost before you start,
    and it never goes up because another advertiser outbid you. That makes it a simple, low-risk way to test banner advertising or run a steady campaign on a small budget.</p>

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

echo gb_site_open('Banner ad sizes: the standard sizes and when to use each',
    'A practical guide to the most common display ad sizes, from the 728×90 leaderboard to the 320×50 mobile banner: '
    . 'where each one appears, what it is best for, and how to design for it.', [
    'path'        => '/banner-ad-sizes.php',
    'og_type'     => 'article',
    'title_full'  => 'Banner Ad Sizes Guide: 728x90, 300x250 & More | ' . GB_BRAND_NAME,
    'description' => 'Standard banner ad sizes explained: 728x90 leaderboard, 300x250 medium rectangle, 160x600 skyscraper, '
                   . '320x50 mobile banner and more, with design tips.',
    'jsonld'      => [gb_breadcrumb('Banner ad sizes', '/banner-ad-sizes.php'),
        ['@type' => 'Article', 'headline' => 'Banner ad sizes: the standard sizes and when to use each',
         'author' => ['@id' => gb_url('/#organization')], 'publisher' => ['@id' => gb_url('/#organization')],
         'mainEntityOfPage' => gb_url('/banner-ad-sizes.php'), 'image' => gb_url('/og-image.png'), 'inLanguage' => 'en-US']],
], 'Banner ad sizes');
?>
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
  <p>Display ad sizes are measured in pixels, width × height. The sizes below are the ones you will find on most websites and in most ad networks, so a banner made for one site usually works on many others.</p>
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
        ['Can an ad be declined?', 'Yes. We review every banner and landing page and may decline ads that are misleading, unsafe or don\'t link to a working page. Contact us if your ad was declined.'],
        ['How do I track clicks from my banner?', 'Add UTM tags to the link you enter, such as ?utm_source=bannerpromote&utm_medium=banner. Your analytics tool will then show the visits your banner sends.'],
        ['Can I change my banner after ordering?', 'Contact us with your order number and the new image, and we will help you update it.'],
    ],
];
$all = array_merge(...array_values($groups));

echo gb_site_open('Banner advertising FAQ', 'Answers to the questions advertisers ask most about pricing, ad sizes, files and how long banner ads run.', [
    'path'        => '/faq.php',
    'title_full'  => 'Banner Ad FAQ: Cost, Sizes, Files & Run Times | ' . GB_BRAND_NAME,
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
$binaryFiles['og-image.png'] = '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';

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
    <div class="form-foot">
      <p class="muted small">Next you'll see a preview and choose how to pay. You won't be charged yet.</p>
      <button class="btn btn-primary btn-lg" type="submit">Continue to preview</button>
    </div>
  </fieldset>
</form>

<section class="panel prose" aria-labelledby="why">
  <h2 id="why">Banner advertising without the hassle</h2>
  <div class="feature-grid">
    <div><h3>One flat price</h3><p>Each placement has a fixed price for a fixed run, shown above. No bidding, no minimum spend and no contract.</p></div>
    <div><h3>Every standard size</h3><p>From the 728×90 leaderboard to the 300×250 medium rectangle and 320×50 mobile banner. See the <a href="banner-ad-sizes.php">banner ad size guide</a>.</p></div>
    <div><h3>Reviewed by a person</h3><p>We check each banner and landing page before it runs, so ads stay clean and relevant.</p></div>
    <div><h3>Pay how you like</h3><p>Pay by card through Stripe or with PayPal. Your run starts when your banner is approved.</p></div>
  </div>
  <p class="muted small">New to banner ads? Read <a href="how-to-buy-banner-ads.php">how to buy banner ads</a>, compare <a href="pricing.php">banner ad pricing</a> or check the <a href="faq.php">FAQ</a>.</p>
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
    'This is how your banner will look on our pages. Check the details, then choose how to pay.', $paid ? 3 : 2);
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
$targetDir = (string)realpath($targetDir);   // absolute: templates are rendered with this as working dir

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

foreach ($binaryFiles as $name => $b64) {
    $path = $targetDir . DIRECTORY_SEPARATOR . $name;
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
