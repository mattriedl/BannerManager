<?php
declare(strict_types=1);

/* ================================================================
   BannerPromote blog builder.

     php tools/build-blog.php --site=build/site                 # build
     php tools/build-blog.php --site=build/site --check         # validate only
     php tools/build-blog.php --check-file=content/blog/x.html  # validate one draft

   Reads content/blog/*.html (front matter + HTML body) and writes:
     blog/index.html        list of articles (Blog + ItemList schema)
     blog/<slug>.html       one page per article (BlogPosting, Breadcrumb, FAQPage)
     blog/feed.xml          RSS feed
     sitemap.xml            article URLs appended, /blog/ lastmod = newest article
   Articles dated in the future are skipped until that date (scheduling).
   The build FAILS if any article breaks the SEO/quality rules in check_post().
   Requires the site's config.php/lib.php (run after install.php).
   ================================================================ */

$root = dirname(__DIR__);
$o = getopt('', ['site:', 'content:', 'check', 'check-file:', 'today:']);
$contentDir = $o['content'] ?? "$root/content/blog";
$today = $o['today'] ?? gmdate('Y-m-d');

/* ---------------- parsing ---------------- */

function parse_post(string $file): array {
    $raw = (string)file_get_contents($file);
    if (!preg_match('/^---\s*\n(.*?)\n---\s*\n(.*)$/s', $raw, $m)) throw new RuntimeException('missing front matter (--- ... ---)');
    $meta = [];
    foreach (explode("\n", $m[1]) as $line) {
        if (trim($line) === '' || str_starts_with(trim($line), '#')) continue;
        if (!preg_match('/^([a-z_]+):\s*(.*)$/', $line, $kv)) throw new RuntimeException("bad front matter line: $line");
        $meta[$kv[1]] = trim($kv[2]);
    }
    $meta['keywords'] = array_values(array_filter(array_map('trim', explode(',', $meta['keywords'] ?? ''))));
    $meta['body'] = trim($m[2]);
    $meta['file'] = basename($file);
    return $meta;
}

function slugify(string $s): string {
    return trim((string)preg_replace('/[^a-z0-9]+/', '-', strtolower(html_entity_decode(strip_tags($s)))), '-');
}

function plain_words(string $html): array {
    $text = html_entity_decode(strip_tags((string)preg_replace('~<(script|style)\b.*?</\1>~s', ' ', $html)), ENT_QUOTES | ENT_HTML5);
    return preg_split('/\s+/', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
}

/** FAQ pairs from <details><summary>Q</summary><p>A</p></details> inside <section class="faq">. */
function extract_faq(string $html): array {
    if (!preg_match('~<section class="faq"[^>]*>(.*?)</section>~s', $html, $sec)) return [];
    preg_match_all('~<details>\s*<summary>(.*?)</summary>(.*?)</details>~s', $sec[1], $m, PREG_SET_ORDER);
    return array_map(fn($x) => [trim(html_entity_decode(strip_tags($x[1]))),
        trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($x[2]))))], $m);
}

/* ---------------- quality rules ---------------- */

/** @return string[] problems (empty = publishable) */
function check_post(array $p): array {
    $err = [];
    $req = ['title', 'seo_title', 'description', 'slug', 'date', 'primary_keyword', 'category'];
    foreach ($req as $k) if (($p[$k] ?? '') === '') $err[] = "missing '$k'";
    if ($err) return $err;
    $kw = strtolower($p['primary_keyword']);
    $body = $p['body'];
    $words = plain_words($body);
    $first = strtolower(implode(' ', array_slice($words, 0, 150)));
    $len = fn($s) => mb_strlen(html_entity_decode($s));

    if (!preg_match('/^[a-z0-9]+(-[a-z0-9]+){1,11}$/', $p['slug'])) $err[] = 'slug must be 2-12 lowercase words joined by dashes';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $p['date']) || !strtotime($p['date'])) $err[] = 'date must be YYYY-MM-DD';
    if (($p['updated'] ?? '') !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $p['updated']) || $p['updated'] < $p['date'])) $err[] = 'updated must be YYYY-MM-DD, not before date';
    if ($len($p['seo_title']) < 30 || $len($p['seo_title']) > 60) $err[] = 'seo_title must be 30-60 characters (is ' . $len($p['seo_title']) . ')';
    if ($len($p['title']) > 100) $err[] = 'title (H1) must be at most 100 characters';
    if ($len($p['description']) < 120 || $len($p['description']) > 160) $err[] = 'description must be 120-160 characters (is ' . $len($p['description']) . ')';
    if (count($p['keywords']) < 3 || count($p['keywords']) > 10) $err[] = 'keywords: list 3-10 target keywords (primary first)';
    if (!str_contains(strtolower($p['seo_title']), $kw)) $err[] = 'primary keyword must appear in seo_title';
    if (!str_contains(strtolower($p['description']), $kw)) $err[] = 'primary keyword must appear in description';
    if (!str_contains($first, $kw)) $err[] = 'primary keyword must appear in the first 150 words';
    $slugHits = count(array_intersect(explode('-', $p['slug']), preg_split('/\W+/', $kw)));
    if ($slugHits < min(2, count(preg_split('/\W+/', $kw)))) $err[] = 'slug should contain the primary keyword';
    if (count($words) < 1000) $err[] = 'body must be at least 1000 words (is ' . count($words) . ')';
    if (preg_match('~<h1\b~i', $body)) $err[] = 'body must not contain <h1> (the title is the H1)';
    preg_match_all('~<h2\b[^>]*>(.*?)</h2>~is', $body, $h2);
    if (count($h2[1]) < 4) $err[] = 'body needs at least 4 <h2> sections';
    if (!array_filter($h2[1], fn($h) => str_contains(strtolower(strip_tags($h)), $kw) ||
         count(array_intersect(preg_split('/\W+/', strtolower(strip_tags($h))), preg_split('/\W+/', $kw))) >= 2))
        $err[] = 'at least one <h2> should contain the primary keyword (or most of its words)';
    preg_match_all('~<a\s[^>]*href="([^"]+)"~i', $body, $links);
    $internal = array_filter($links[1], fn($h) => str_starts_with($h, '/'));
    $external = array_filter($links[1], fn($h) => preg_match('~^https://~', $h));
    if (count(array_unique($internal)) < 3) $err[] = 'link to at least 3 different pages on this site (href="/...")';
    if (count(array_unique($external)) < 2) $err[] = 'cite at least 2 external sources (https:// links)';
    if (count(extract_faq($body)) < 3) $err[] = 'add a <section class="faq"> with at least 3 <details><summary>Q</summary><p>A</p></details>';
    $hits = substr_count(strtolower(implode(' ', $words)), $kw);
    $density = count($words) ? $hits * count(preg_split('/\s+/', $kw)) / count($words) : 0;
    if ($density > 0.03) $err[] = sprintf('primary keyword is overused (%.1f%%); keep it under 3%% to avoid keyword stuffing', $density * 100);
    if (preg_match('/lorem ipsum|\bTODO\b|\[citation|as an ai\b|as a language model/i', $body)) $err[] = 'contains placeholder or AI boilerplate text';
    if (preg_match('~<(script|iframe|style|form)\b~i', $body)) $err[] = 'body must not contain script, iframe, style or form tags';
    if (preg_match('~<img\b(?![^>]*\balt="[^"]+")~i', $body)) $err[] = 'every <img> needs alt text';
    return $err;
}

/* ---------------- load + validate ---------------- */

if (isset($o['check-file'])) {
    $p = parse_post($o['check-file']);
    $errs = check_post($p);
    $words = count(plain_words($p['body']));
    if ($errs) { fwrite(STDERR, "NOT publishable: {$o['check-file']}\n  - " . implode("\n  - ", $errs) . "\n"); exit(1); }
    echo "OK: {$p['slug']} ($words words, " . count(extract_faq($p['body'])) . " FAQs)\n";
    exit(0);
}

$posts = []; $problems = [];
foreach (glob("$contentDir/*.html") ?: [] as $file) {
    try { $p = parse_post($file); } catch (RuntimeException $ex) { $problems[basename($file)] = [$ex->getMessage()]; continue; }
    if ($errs = check_post($p)) { $problems[basename($file)] = $errs; continue; }
    $posts[] = $p;
}
$slugs = array_count_values(array_map(fn($p) => $p['slug'], $posts));
$titles = array_count_values(array_map(fn($p) => strtolower($p['seo_title']), $posts));
foreach ($posts as $p) {
    if ($slugs[$p['slug']] > 1) $problems[$p['file']][] = "duplicate slug '{$p['slug']}'";
    if ($titles[strtolower($p['seo_title'])] > 1) $problems[$p['file']][] = 'duplicate seo_title';
}
if ($problems) {
    foreach ($problems as $f => $errs) fwrite(STDERR, "NOT publishable: $f\n  - " . implode("\n  - ", $errs) . "\n");
    exit(1);
}
$live = array_values(array_filter($posts, fn($p) => $p['date'] <= $today));
usort($live, fn($a, $b) => [$b['date'], $b['slug']] <=> [$a['date'], $a['slug']]);
$queued = count($posts) - count($live);
if (isset($o['check'])) { echo "OK: " . count($posts) . " article(s) pass the checks ($queued scheduled for later)\n"; exit(0); }

/* ---------------- build ---------------- */

$site = $o['site'] ?? null;
if (!$site || !is_file("$site/config.php")) { fwrite(STDERR, "--site must point at an installed site (run install.php first)\n"); exit(2); }
$site = (string)realpath($site);
chdir($site);
require "$site/config.php";
@mkdir("$site/blog", 0755, true);

$fmtDate = fn(string $d) => date('F j, Y', strtotime($d));
$minutes = fn(array $p) => max(1, (int)round(count(plain_words($p['body'])) / 220));

/** Body ready for the page: heading ids, safe external links. Returns [html, toc]. */
$prepare = function (string $body): array {
    $toc = []; $used = [];
    $body = preg_replace_callback('~<h2\b([^>]*)>(.*?)</h2>~is', function ($m) use (&$toc, &$used) {
        $id = slugify($m[2]) ?: 'section';
        while (isset($used[$id])) $id .= '-2';
        $used[$id] = true;
        $label = trim(html_entity_decode(strip_tags($m[2])));
        if (strcasecmp($label, 'Key takeaways') !== 0) $toc[] = [$id, $label];   // summary box isn't a section
        return '<h2 id="' . $id . '"' . preg_replace('/\sid="[^"]*"/', '', $m[1]) . '>' . $m[2] . '</h2>';
    }, $body);
    $body = preg_replace_callback('~<a\s([^>]*href="https?://[^"]+"[^>]*)>~i', function ($m) {
        $attrs = preg_replace('/\s(rel|target)="[^"]*"/', '', ' ' . $m[1]);
        return '<a' . $attrs . ' rel="noopener" target="_blank">';
    }, (string)$body);
    return [(string)$body, $toc];
};

$related = function (array $p) use ($live): array {
    $score = [];
    foreach ($live as $q) {
        if ($q['slug'] === $p['slug']) continue;
        $score[$q['slug']] = [count(array_intersect(array_map('strtolower', $p['keywords']), array_map('strtolower', $q['keywords']))) * 2
            + ($q['category'] === $p['category'] ? 1 : 0), $q['date'], $q];
    }
    uasort($score, fn($a, $b) => [$b[0], $b[1]] <=> [$a[0], $a[1]]);
    return array_map(fn($x) => $x[2], array_slice(array_values($score), 0, 3));
};

$card = fn(array $q) => '<a class="post-card" href="' . e(gb_path("/blog/{$q['slug']}.html")) . '">'
    . '<span class="post-cat">' . e($q['category']) . '</span>'
    . '<strong>' . e($q['title']) . '</strong>'
    . '<span class="post-excerpt">' . e($q['description']) . '</span>'
    . '<span class="post-meta">' . e($fmtDate($q['date'])) . ', ' . $minutes($q) . '-minute read</span></a>';

$feedLink = '<link rel="alternate" type="application/rss+xml" title="' . e(GB_BRAND_NAME . ' blog') . '" href="' . e(gb_url('/blog/feed.xml')) . '">';

foreach ($live as $p) {
    [$html, $toc] = $prepare($p['body']);
    $path = "/blog/{$p['slug']}.html";
    $updated = ($p['updated'] ?? '') !== '' ? $p['updated'] : $p['date'];
    $faq = extract_faq($p['body']);
    $words = count(plain_words($p['body']));
    $full = $p['seo_title'] . (mb_strlen($p['seo_title'] . ' | ' . GB_BRAND_NAME) <= 65 ? ' | ' . GB_BRAND_NAME : '');
    $meta = [
        'path' => $path, 'og_type' => 'article', 'title_full' => $full, 'description' => $p['description'],
        'extra_head' => $feedLink
            . '<meta property="article:published_time" content="' . e($p['date']) . 'T00:00:00Z">'
            . '<meta property="article:modified_time" content="' . e($updated) . 'T00:00:00Z">'
            . '<meta property="article:section" content="' . e($p['category']) . '">'
            . implode('', array_map(fn($k) => '<meta property="article:tag" content="' . e($k) . '">', $p['keywords'])),
        'jsonld' => array_values(array_filter([
            gb_breadcrumb($p['title'], $path, ['Blog' => '/blog/']),
            ['@type' => 'BlogPosting', 'headline' => mb_substr($p['title'], 0, 110), 'description' => $p['description'],
             'datePublished' => $p['date'], 'dateModified' => $updated, 'inLanguage' => 'en-US',
             'author' => ['@type' => 'Organization', 'name' => ($p['author'] ?? '') !== '' ? $p['author'] : GB_BRAND_NAME . ' Editorial Team', 'url' => gb_url('/blog/')],
             'publisher' => ['@id' => gb_url('/#organization')], 'image' => gb_url('/og-image.png'),
             'mainEntityOfPage' => gb_url($path), 'articleSection' => $p['category'],
             'keywords' => implode(', ', $p['keywords']), 'wordCount' => $words],
            $faq ? ['@type' => 'FAQPage', 'mainEntity' => array_map(fn($q) => ['@type' => 'Question', 'name' => $q[0],
                'acceptedAnswer' => ['@type' => 'Answer', 'text' => $q[1]]], $faq)] : null,
        ])),
    ];
    $byline = '<p class="post-byline">Published <time datetime="' . e($p['date']) . '">' . e($fmtDate($p['date'])) . '</time>'
        . ($updated !== $p['date'] ? '. Updated <time datetime="' . e($updated) . '">' . e($fmtDate($updated)) . '</time>' : '')
        . '. ' . $minutes($p) . '-minute read.</p>';
    $tocHtml = '<nav class="post-toc" aria-label="In this article"><p class="post-toc-title">In this article</p><ol>'
        . implode('', array_map(fn($t) => '<li><a href="#' . e($t[0]) . '">' . e($t[1]) . '</a></li>', $toc)) . '</ol></nav>';
    $rel = $related($p);
    $out = gb_site_open($p['title'], $p['description'], $meta, mb_strimwidth($p['title'], 0, 48, '…'), ['Blog' => '/blog/'], $byline)
        . '<div class="post-layout"><article class="article prose post-body">' . $html . '</article>'
        . '<aside class="post-aside">' . $tocHtml . '</aside></div>'
        . gb_cta('Advertise your business with a banner ad', 'Every standard size, ' . GB_PRICE_PITCH . '. Upload your banner and go live after a quick review.')
        . ($rel ? '<section class="related" aria-labelledby="related"><h2 id="related">Related articles</h2><div class="post-grid">'
               . implode('', array_map($card, $rel)) . '</div></section>' : '')
        . gb_public_close();
    file_put_contents("$site/blog/{$p['slug']}.html", $out);
    echo "  WROTE  blog/{$p['slug']}.html ($words words)\n";
}

/* Blog index */
$indexMeta = [
    'path' => '/blog/',
    'title_full' => 'Online Marketing & Advertising Blog | ' . GB_BRAND_NAME,
    'description' => 'Practical guides to online marketing, banner advertising, display ads and growing a small business online, from the '
                   . GB_BRAND_NAME . ' team.',
    'extra_head' => $feedLink,
    'jsonld' => [gb_breadcrumb('Blog', '/blog/'),
        ['@type' => 'Blog', 'name' => GB_BRAND_NAME . ' blog', 'url' => gb_url('/blog/'), 'publisher' => ['@id' => gb_url('/#organization')],
         'blogPost' => array_map(fn($p) => ['@type' => 'BlogPosting', 'headline' => mb_substr($p['title'], 0, 110),
             'url' => gb_url("/blog/{$p['slug']}.html"), 'datePublished' => $p['date']], $live)]],
];
$index = gb_site_open('Online marketing and advertising blog', 'Practical, no-hype guides to internet marketing, banner advertising and '
        . 'display ads for small businesses. New articles every few days.', $indexMeta, 'Blog')
    . ($live ? '<div class="post-grid">' . implode('', array_map($card, $live)) . '</div>'
             : '<div class="article empty"><h3>New articles are on the way</h3><p>Check back soon.</p></div>')
    . gb_cta()
    . gb_public_close();
file_put_contents("$site/blog/index.html", $index);
echo "  WROTE  blog/index.html (" . count($live) . " articles" . ($queued ? ", $queued scheduled" : '') . ")\n";

/* Home page: latest articles (fresh internal links for crawlers) */
$home = "$site/index.html";
if (is_file($home) && str_contains((string)file_get_contents($home), '<!--LATEST_POSTS-->')) {
    $latest = $live ? '<section class="home-section" aria-labelledby="latest"><div class="section-head"><h2 id="latest">Latest from the blog</h2>'
        . '<p>Practical guides to online marketing and banner advertising. <a href="' . e(gb_path('/blog/')) . '">See all articles</a>.</p></div>'
        . '<div class="post-grid">' . implode('', array_map($card, array_slice($live, 0, 3))) . '</div></section>' : '';
    file_put_contents($home, str_replace('<!--LATEST_POSTS-->', $latest, (string)file_get_contents($home)));
    echo "  WROTE  index.html (latest " . min(3, count($live)) . " articles)\n";
}

/* RSS feed */
$rss = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
     . '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom"><channel>'
     . '<title>' . htmlspecialchars(GB_BRAND_NAME . ' blog', ENT_XML1) . '</title>'
     . '<link>' . htmlspecialchars(gb_url('/blog/'), ENT_XML1) . '</link>'
     . '<atom:link href="' . htmlspecialchars(gb_url('/blog/feed.xml'), ENT_XML1) . '" rel="self" type="application/rss+xml"/>'
     . '<description>Online marketing and advertising guides</description><language>en-us</language>';
foreach (array_slice($live, 0, 30) as $p)
    $rss .= '<item><title>' . htmlspecialchars($p['title'], ENT_XML1) . '</title>'
          . '<link>' . htmlspecialchars(gb_url("/blog/{$p['slug']}.html"), ENT_XML1) . '</link>'
          . '<guid isPermaLink="true">' . htmlspecialchars(gb_url("/blog/{$p['slug']}.html"), ENT_XML1) . '</guid>'
          . '<pubDate>' . gmdate('D, d M Y 00:00:00 +0000', strtotime($p['date'])) . '</pubDate>'
          . '<category>' . htmlspecialchars($p['category'], ENT_XML1) . '</category>'
          . '<description>' . htmlspecialchars($p['description'], ENT_XML1) . '</description></item>';
file_put_contents("$site/blog/feed.xml", $rss . '</channel></rss>' . "\n");
echo "  WROTE  blog/feed.xml\n";

/* Sitemap: add article URLs; /blog/ lastmod = newest article */
$smFile = "$site/sitemap.xml";
if (is_file($smFile)) {
    $sm = (string)file_get_contents($smFile);
    $sm = preg_replace('~\s*<url>\s*<loc>[^<]*/blog/[^<]+\.html</loc>.*?</url>~s', '', $sm);   // idempotent rebuilds
    if ($live) {
        $newest = max(array_map(fn($p) => ($p['updated'] ?? '') !== '' ? $p['updated'] : $p['date'], $live));
        $sm = preg_replace('~(<loc>[^<]*/blog/</loc>\s*<lastmod>)[^<]+~', '${1}' . $newest, $sm);
    }
    $add = '';
    foreach ($live as $p)
        $add .= "  <url>\n    <loc>" . htmlspecialchars(gb_url("/blog/{$p['slug']}.html"), ENT_XML1) . "</loc>\n"
              . "    <lastmod>" . (($p['updated'] ?? '') !== '' ? $p['updated'] : $p['date']) . "</lastmod>\n  </url>\n";
    file_put_contents($smFile, str_replace('</urlset>', $add . '</urlset>', $sm));
    echo "  WROTE  sitemap.xml (+" . count($live) . " articles)\n";
}
