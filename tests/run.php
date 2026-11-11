<?php
declare(strict_types=1);

/* ================================================================
   End-to-end tests for the Banner Ads Manager.

   Runs install.php into build/test, boots PHP's built-in web server
   on it, and exercises the whole advertiser -> payment -> approval
   -> serving -> click -> stats flow over real HTTP.

     php tests/run.php                 # SQLite (no MySQL needed)
     GB_TEST_DB=mysql php tests/run.php  # real MySQL (see README)

   Exit code 0 = all passed.
   ================================================================ */

$root  = dirname(__DIR__);
$build = $root . '/build/test';
$port  = (int)(getenv('GB_TEST_PORT') ?: 8765);
$base  = "http://127.0.0.1:$port";
$mode  = getenv('GB_TEST_DB') ?: 'sqlite';

$pass = 0; $failed = [];
function check(bool $ok, string $label, string $detail = ''): void {
    global $pass, $failed;
    if ($ok) { $pass++; echo "  \033[32m✓\033[0m $label\n"; }
    else     { $failed[] = $label; echo "  \033[31m✗ $label\033[0m" . ($detail !== '' ? "\n      $detail" : '') . "\n"; }
}
function section(string $t): void { echo "\n\033[1m$t\033[0m\n"; }
function sh(string $cmd): array { exec($cmd . ' 2>&1', $out, $code); return [$code, implode("\n", $out)]; }

/* ---------- 1. installer ---------- */
section('Installer');
sh('rm -rf ' . escapeshellarg($build));
[$code, $out] = sh('php ' . escapeshellarg("$root/install.php") . ' --dir=' . escapeshellarg($build));
check($code === 0, 'install.php exits 0', $out);
foreach (['schema.sql','config.php','lib.php','serve.php','go.php','embed.js.php','advertise.php',
          'preview.php','pay-stripe.php','ipn-paypal.php','webhook-stripe.php','admin.php'] as $f) {
    $ok = is_file("$build/$f");
    if ($ok && str_ends_with($f, '.php')) { [$c] = sh('php -l ' . escapeshellarg("$build/$f")); $ok = $c === 0; }
    check($ok, "$f written and lints clean");
}
check(is_file("$build/uploads/.htaccess"), 'uploads/.htaccess written at install time');
[$code, $out] = sh('php ' . escapeshellarg("$root/install.php") . ' --dir=' . escapeshellarg($build));
check(str_contains($out, 'SKIP') && $code === 0, 'second run skips existing files without --force');

[, $sql] = sh('php ' . escapeshellarg("$root/install.php") . ' --print-sql');
check(substr_count($sql, '-- [') === 5, 'schema splits into exactly 5 statements', $sql);
check(preg_match('/^--(?! \[\d+\])/m', $sql) === 0, 'no statement starts with a comment (original installer skipped those)', $sql);
check(str_contains($sql, 'CREATE TABLE IF NOT EXISTS gb_spots'), 'schema import includes CREATE TABLE gb_spots (regression)');
check(str_contains($sql, 'INSERT IGNORE INTO gb_spots'), 'schema import includes the 8 seed spots (regression)');

/* ---------- 2. database + server ---------- */
section("Database ($mode) + web server");
copy(__DIR__ . '/config.local.php', "$build/config.local.php");
$envVars = [
    'GB_TEST_DB' => $mode,
    'GB_TEST_BASE_URL' => $base,
    'GB_TEST_SQLITE_FILE' => "$build/test.sqlite",
];
foreach ($envVars as $k => $v) putenv("$k=$v");

if ($mode === 'mysql') {
    $h = getenv('GB_TEST_MYSQL_HOST') ?: '127.0.0.1'; $u = getenv('GB_TEST_MYSQL_USER') ?: 'root';
    $p = getenv('GB_TEST_MYSQL_PASS') ?: '';           $d = getenv('GB_TEST_MYSQL_DB') ?: 'greenbox_test';
    $pdo = new PDO("mysql:host=$h", $u, $p);
    $pdo->exec("DROP DATABASE IF EXISTS `$d`");
    // Uses the installer's own --db-* import path, i.e. tests the real fix.
    [$code, $out] = sh('php ' . escapeshellarg("$root/install.php") . ' --force --dir=' . escapeshellarg($build)
        . ' --db-host=' . escapeshellarg($h) . ' --db-user=' . escapeshellarg($u)
        . ' --db-pass=' . escapeshellarg($p) . ' --db-name=' . escapeshellarg($d));
    check($code === 0 && str_contains($out, '5 statements executed'), 'installer --db-* import creates the schema', $out);
} else {
    $pdo = new PDO('sqlite:' . "$build/test.sqlite");
    $pdo->exec(file_get_contents(__DIR__ . '/schema.sqlite.sql'));
}
unset($pdo);
[$code, $out] = sh('GB_TEST_BASE_URL=' . escapeshellarg($base) . ' php ' . escapeshellarg("$root/tools/build-blog.php") . ' --site=' . escapeshellarg($build));
check($code === 0 && str_contains($out, 'blog/index.html'), 'blog builds (every article passes the SEO checks)', $out);

require "$build/config.php";   // loads config.local.php + lib.php -> gb_db()
check((int)gb_db()->query('SELECT COUNT(*) FROM gb_spots')->fetchColumn() === 8, '8 preset spots seeded');
check((int)gb_db()->query('SELECT COUNT(*) FROM gb_spots WHERE price = 10 AND period_days = 30')->fetchColumn() === 8, 'every preset placement costs 10.00 for 30 days');

function start_server(string $build, int $port, array $env): array {
    $mail = "$build/mail.log";
    $cmd  = ['php', '-d', 'sendmail_path=tee -a ' . escapeshellarg($mail) . ' >/dev/null',
             '-d', 'display_errors=stderr', '-S', "127.0.0.1:$port", '-t', $build];
    $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['file', "$build/server-$port.log", 'a'],
                             2 => ['file', "$build/server-$port.log", 'a']], $pipes, $build,
                      array_merge(getenv(), $env));
    for ($i = 0; $i < 50; $i++) {
        $s = @fsockopen('127.0.0.1', $port, $en, $es, 0.2);
        if ($s) { fclose($s); return [$proc, true]; }
        usleep(100000);
    }
    return [$proc, false];
}
[$server, $up] = start_server($build, $port, $envVars);
check($up, "built-in server listening on $base");
register_shutdown_function(function () use ($server) { proc_terminate($server); });
if (!$up) { echo "Server log:\n" . @file_get_contents("$build/server-$port.log"); exit(1); }

/** Minimal HTTP client with a per-"browser" cookie jar. */
function http(string $method, string $url, array $o = []): array {
    global $base, $build;
    $ch = curl_init(str_starts_with($url, 'http') ? $url : $base . $url);
    $jar = "$build/cookies-" . ($o['as'] ?? 'anon') . '.txt';
    $hdr = [];
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 45,
        CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*',
        CURLOPT_COOKIEFILE => $jar, CURLOPT_COOKIEJAR => $jar,
        CURLOPT_HTTPHEADER => $o['headers'] ?? [],
        CURLOPT_HEADERFUNCTION => function ($c, $line) use (&$hdr) {
            if (str_contains($line, ':')) { [$k, $v] = explode(':', $line, 2); $hdr[strtolower(trim($k))] = trim($v); }
            return strlen($line);
        },
    ]);
    if (array_key_exists('body', $o)) curl_setopt($ch, CURLOPT_POSTFIELDS, $o['body']);
    $body = (string)curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return [$status, $hdr, $body];
}
function csrf_from(string $html): string {
    return preg_match('/name="csrf" value="([0-9a-f]+)"/', $html, $m) ? $m[1] : '';
}
function png(int $w, int $h, string $path): string {
    $im = imagecreatetruecolor($w, $h);
    imagefill($im, 0, 0, imagecolorallocate($im, 47, 168, 85));
    imagepng($im, $path); imagedestroy($im);
    return $path;
}
function banner(int $id): array { return gb_db()->query("SELECT * FROM gb_banners WHERE id=$id")->fetch() ?: []; }

/* ---------- 3. embed + serving ---------- */
section('Embed loader + serving');
[$s, $h, $b] = http('GET', '/embed.js.php');
check($s === 200 && str_contains($h['content-type'] ?? '', 'javascript'), 'embed.js.php serves JavaScript');
check(str_contains($b, json_encode($base)), 'embed.js.php points at GB_BASE_URL');
[$s, $h, $b] = http('GET', '/serve.php?spot=nope');
check($s === 200 && json_decode($b, true)['html'] === null, 'serve.php unknown spot -> html null');
check(($h['access-control-allow-origin'] ?? '') === '*', 'serve.php sends CORS header for remote embedding');
[$s, , $b] = http('GET', '/serve.php?spot=medium-rectangle');
$j = json_decode($b, true);
check($j['html'] === null && $j['width'] === 300 && $j['height'] === 250, 'serve.php empty spot -> null html + spot size');

/* ---------- 4. advertiser purchase form ---------- */
section('Advertiser purchase flow');
[$s, , $form] = http('GET', '/advertise.php', ['as' => 'adv']);
check($s === 200 && substr_count($form, 'name="spot" value=') === 8, 'advertise.php lists 8 placements');
$csrf = csrf_from($form);
check($csrf !== '', 'advertise.php has CSRF token');

$tmp = "$build/fixtures"; @mkdir($tmp);
$good = png(300, 250, "$tmp/good.png");
$wrong = png(728, 90, "$tmp/wrong.png");
file_put_contents("$tmp/evil.png", "<?php echo 'pwned'; ?>");
$fields = fn(string $file, string $tok) => ['csrf' => $tok, 'spot' => 'medium-rectangle', 'name' => 'Ada Advertiser',
    'email' => 'ada@example.test', 'url' => 'https://advertiser.example.test/landing',
    'tos' => '1', 'image' => new CURLFile($file, 'image/png', basename($file))];

[$s, , $b] = http('POST', '/advertise.php', ['as' => 'nosession', 'body' => $fields($good, 'deadbeef')]);
check($s === 200 && str_contains($b, 'Session expired'), 'POST without a session/CSRF is rejected (no TypeError)', substr(strip_tags($b), 0, 200));
[$s, , $b] = http('POST', '/advertise.php', ['as' => 'adv', 'body' => $fields($wrong, $csrf)]);
check(str_contains($b, 'needs exactly 300×250'), 'wrong image size rejected');
[$s, , $b] = http('POST', '/advertise.php', ['as' => 'adv', 'body' => $fields("$tmp/evil.png", $csrf)]);
check(str_contains($b, 'not a valid image'), 'PHP file disguised as .png rejected');
$f = $fields($good, $csrf); $f['url'] = 'javascript:alert(1)';
[, , $b] = http('POST', '/advertise.php', ['as' => 'adv', 'body' => $f]);
check(str_contains($b, 'valid http'), 'javascript: target URL rejected');
$uploadsBefore = count(glob("$build/uploads/*"));
check($uploadsBefore === 0, 'rejected uploads leave no files behind');

[$s, $h, ] = http('POST', '/advertise.php', ['as' => 'adv', 'body' => $fields($good, $csrf)]);
$loc = $h['location'] ?? '';
check($s === 302 && preg_match('~^preview\.php\?id=(\d+)&sig=[0-9a-f]{24}$~', $loc, $m) === 1, 'valid order redirects to signed preview', "status $s loc $loc");
$bid = (int)($m[1] ?? 0);
$bn = banner($bid);
check(($bn['status'] ?? '') === 'pending' && (int)$bn['paid'] === 0, 'banner stored as pending + unpaid');
check(count(glob("$build/uploads/*.png")) === 1, 'image stored with random name in uploads/');

/* ---------- 5. preview ---------- */
$f = $fields($good, $csrf); unset($f['tos']);
$before = (int)gb_db()->query('SELECT COUNT(*) FROM gb_banners')->fetchColumn();
[, , $b] = http('POST', '/advertise.php', ['as' => 'adv', 'body' => $f]);
check(str_contains($b, 'agree to the Terms of Service') && (int)gb_db()->query('SELECT COUNT(*) FROM gb_banners')->fetchColumn() === $before,
      'order without agreeing to the Terms is refused (server-side, even if the checkbox is bypassed)');
check(str_contains($form, 'name="tos" value="1" required') && str_contains($form, 'href="terms.php"'), 'order form has a required Terms checkbox linking to the Terms');
$bn = banner($bid);
check(($bn['tos_version'] ?? '') === GB_TOS_VERSION && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string)($bn['tos_accepted_at'] ?? '')) === 1,
      'the order records the Terms version and the time it was accepted', json_encode([$bn['tos_version'] ?? null, $bn['tos_accepted_at'] ?? null]));

section('Preview page');
[$s] = http('GET', "/preview.php?id=$bid&sig=000000000000000000000000");
check($s === 404, 'preview with bad signature -> 404');
[$s, , $pv] = http('GET', '/' . $loc, ['as' => 'adv']);
check($s === 200 && str_contains($pv, 'Pay with PayPal') && str_contains($pv, 'Pay by card'), 'preview shows both payment buttons');
check(str_contains($pv, 'sandbox.paypal.com'), 'PayPal form targets sandbox');
check((int)banner($bid)['impressions'] === 0, 'preview does not count impressions');
[$s, , $b] = http('GET', '/serve.php?spot=medium-rectangle');
check(json_decode($b, true)['html'] === null, 'unpaid banner is not served');

/* ---------- 6. payments ---------- */
section('Payments');
[$s] = http('POST', '/pay-stripe.php', ['as' => 'nosession2', 'body' => ['banner_id' => $bid]]);
check($s === 400, 'pay-stripe.php without CSRF -> 400');
[$s, , $b] = http('POST', '/pay-stripe.php', ['as' => 'adv', 'body' => ['csrf' => csrf_from($pv), 'banner_id' => $bid]]);
check($s === 502 && str_contains($b, 'Stripe error'), 'pay-stripe.php with placeholder key fails cleanly (502)', "status $s");

[$s] = http('POST', '/ipn-paypal.php', ['body' => http_build_query(['custom' => $bid, 'payment_status' => 'Completed',
    'mc_gross' => '10.00', 'mc_currency' => 'USD', 'receiver_email' => 'seller@example.test', 'txn_id' => 'FAKE'])]);
check($s === 400 && (int)banner($bid)['paid'] === 0, 'forged PayPal IPN (not VERIFIED by PayPal) is rejected');

$stripe = function (array $event, ?int $t = null, ?string $secret = null): int {
    $payload = json_encode($event);
    $t ??= time();
    $sig = hash_hmac('sha256', "$t.$payload", $secret ?? GB_STRIPE_WEBHOOK_SECRET);
    [$s] = http('POST', '/webhook-stripe.php', ['body' => $payload,
        'headers' => ['Content-Type: application/json', "Stripe-Signature: t=$t,v1=$sig"]]);
    return $s;
};
// Simulate the pending order pay-stripe.php records when checkout starts.
gb_db()->prepare("INSERT INTO gb_orders (banner_id,gateway,amount,currency,status,txn_ref) VALUES (?,'stripe',10,'USD','pending','cs_test_1')")->execute([$bid]);
$evt = fn(int $amount) => ['type' => 'checkout.session.completed', 'data' => ['object' => [
    'id' => 'cs_test_1', 'payment_status' => 'paid', 'amount_total' => $amount, 'metadata' => ['banner_id' => (string)$bid]]]];

check($stripe($evt(1000), null, 'whsec_wrong') === 400, 'Stripe webhook with wrong secret -> 400');
check($stripe($evt(1000), time() - 3600) === 400, 'Stripe webhook replayed 1h later -> 400');
check($stripe($evt(100)) === 200 && (int)banner($bid)['paid'] === 0, 'Stripe underpayment does not mark paid');
check($stripe($evt(1000)) === 200 && (int)banner($bid)['paid'] === 1, 'valid Stripe webhook marks banner paid');
check($stripe($evt(1000)) === 200, 'duplicate webhook delivery accepted (200)');
$orders = gb_db()->query("SELECT status, COUNT(*) c FROM gb_orders WHERE banner_id=$bid GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
check(($orders['paid'] ?? 0) == 1 && !isset($orders['pending']), 'exactly one paid order, pending order converted (no duplicates)', json_encode($orders));
$mail = (string)@file_get_contents("$build/mail.log");
check(str_contains($mail, 'New paid banner #' . $bid) && str_contains($mail, 'Thank you for your purchase'), 'admin + buyer payment emails sent');
check(banner($bid)['status'] === 'pending', 'paid banner still waits for approval (GB_AUTO_APPROVE=false)');
[, , $pv] = http('GET', '/' . $loc, ['as' => 'adv']);
check(str_contains($pv, 'awaiting approval'), 'preview now says payment received');

/* ---------- 7. admin ---------- */
section('Admin panel');
[$s, , $b] = http('GET', '/admin.php', ['as' => 'admin']);
check($s === 200 && str_contains($b, 'Admin login'), 'admin.php shows login form');
[$s, , $b] = http('POST', '/admin.php', ['as' => 'admin', 'body' => ['password' => 'wrong-password']]);
check(str_contains($b, 'Admin login'), 'wrong password rejected');
[$s, $h] = http('POST', '/admin.php', ['as' => 'admin', 'body' => ['password' => GB_ADMIN_PASSWORD]]);
check($s === 302, 'correct password logs in');
[$s, , $dash] = http('GET', '/admin.php', ['as' => 'admin']);
check(str_contains($dash, '>10.00 USD<'), 'dashboard shows revenue 10.00 USD');
[, , $bl] = http('GET', '/admin.php?a=banners', ['as' => 'admin']);
$tok = csrf_from($bl);
$act = fn(string $a, int $id, array $extra = []) => http('POST', '/admin.php', ['as' => 'admin',
    'body' => array_merge(['csrf' => $tok, 'act' => $a, 'id' => $id, 'back' => 'banners'], $extra)]);
[$s] = http('POST', '/admin.php', ['as' => 'admin', 'body' => ['act' => 'approve', 'id' => $bid]]);
check(banner($bid)['status'] === 'pending', 'admin action without CSRF is ignored');
$act('approve', $bid);
$bn = banner($bid);
check($bn['status'] === 'active' && $bn['ends_at'] !== null, 'approve -> active with an end date');
$days = (strtotime($bn['ends_at']) - strtotime($bn['starts_at'])) / 86400;
check(abs($days - 30) < 0.01, 'run window is 30 days', "got $days");
check(str_contains((string)@file_get_contents("$build/mail.log"), 'Your banner is now live'), '"now live" email sent');

/* ---------- 8. serving, clicks, stats ---------- */
section('Serving, clicks, stats');
for ($i = 0; $i < 3; $i++) [, , $b] = http('GET', '/serve.php?spot=medium-rectangle');
$html = (string)json_decode($b, true)['html'];
check(str_contains($html, "/go.php?id=$bid") && str_contains($html, 'rel="nofollow sponsored noopener"'), 'active banner served with tracked, nofollow link');
check(!str_contains($html, 'advertiser.example.test'), 'target URL not exposed in served HTML');
[$s, $h] = http('GET', '/' . banner($bid)['image_path']);
check($s === 200 && ($h['content-type'] ?? '') === 'image/png', 'banner image is downloadable');
[$s, $h] = http('GET', "/go.php?id=$bid");
check($s === 302 && ($h['location'] ?? '') === 'https://advertiser.example.test/landing', 'go.php redirects to target URL');
check((int)banner($bid)['impressions'] === 3 && (int)banner($bid)['clicks'] === 1, 'counters: 3 impressions, 1 click');
[, , $st] = http('GET', '/admin.php?a=stats', ['as' => 'admin']);
check(str_contains($st, '33.33%'), 'stats page shows 33.33% CTR');
[$s, $h, $b] = http('GET', '/stats.php');
$pub = json_decode($b, true);
$total = max((int)gb_db()->query('SELECT SUM(impressions) FROM gb_stats')->fetchColumn(), (int)gb_db()->query('SELECT SUM(impressions) FROM gb_banners')->fetchColumn());
check($s === 200 && ($pub['served'] ?? null) === $total && $total >= 3, 'stats.php reports the network total of banners served');
check(array_keys($pub) === ['served', 'today', 'updated'] && !str_contains($b, 'advertiser'), 'stats.php exposes totals only, nothing per advertiser');
[, , $home] = http('GET', '/');
check(str_contains($home, 'id="served"') && str_contains($home, "fetch('stats.php'"), 'home page has the live banners-served counter');
[$s, , $b] = http('GET', '/pricing.php', ['headers' => ['Host: 173.249.54.18']]);
check($s === 403 && !str_contains($b, GB_BRAND_NAME), 'site refuses requests made to the bare server IP');
[$s] = http('GET', '/pricing.php', ['headers' => ['Host: [2606:4700::1]:443']]);
check($s === 403, 'site refuses requests made to a bare IPv6 address');
[$s] = http('GET', '/pricing.php', ['headers' => ['Host: www.bannerpromote.com']]);
check($s === 200, 'site still answers on its domain name');
[$s, , $b] = http('GET', '/contact.php');
check($s === 200 && str_contains($b, 'mailto:help@bannerpromote.com') && str_contains($b, 'ContactPage'), 'contact page lists help@bannerpromote.com');
check(str_contains($home, 'contact.php">Contact us</a>'), 'footer links to the contact page');
check(str_contains((string)@file_get_contents("$build/.htaccess"), 'RewriteRule ^ - [F,L]'), '.htaccess refuses bare-IP requests');
[, $h] = http('GET', '/pricing.php');
check(str_contains($h['cache-control'] ?? '', 'no-transform'), 'pages ask proxies not to inject scripts (Cache-Control: no-transform)');
check(str_contains((string)@file_get_contents("$build/.htaccess"), 'Header merge Cache-Control "no-transform"'), '.htaccess sends no-transform for static pages');
[$s] = http('GET', '/go.php?id=999999');
check($s === 404, 'go.php unknown id -> 404');

$act('pause', $bid);
[, , $b] = http('GET', '/serve.php?spot=medium-rectangle');
check(json_decode($b, true)['html'] === null, 'paused banner not served');
$act('resume', $bid);

gb_db()->exec("UPDATE gb_banners SET ends_at='2000-01-01 00:00:00' WHERE id=$bid");
[, , $b] = http('GET', '/serve.php?spot=medium-rectangle');
check(json_decode($b, true)['html'] === null && banner($bid)['status'] === 'expired', 'past end date -> expired, not served');
[$s] = http('GET', "/go.php?id=$bid");
check($s === 404, 'clicks on expired banner -> 404');

/* ---------- 9. admin banners + spots ---------- */
section('No self-ads (domain matching)');
foreach ([
    ['https://www.examplewebsite.com/sale', 'examplewebsite.com', true,  'www. is ignored'],
    ['https://shop.examplewebsite.com', 'blog.examplewebsite.com', true, 'subdomains count as the same website'],
    ['HTTPS://ExampleWebsite.COM:8443/x?y', 'examplewebsite.com', true, 'case and port are ignored'],
    ['https://examplewebsite.com', 'examplewebsite.net', false, 'different ending is a different website'],
    ['https://notexamplewebsite.com', 'examplewebsite.com', false, 'look-alike name is a different website'],
    ['https://shop.example.co.uk', 'example.co.uk', true, '.co.uk subdomain is the same website'],
    ['https://alpha.co.uk', 'beta.co.uk', false, 'two .co.uk sites are different websites'],
    ['https://alice.github.io/page', 'bob.github.io', false, 'two github.io users are different websites'],
    ['https://alice.github.io/page', 'alice.github.io', true, 'same github.io user is the same website'],
    ['https://a-8000.app.github.dev', 'b-8000.app.github.dev', false, 'two Codespaces are different websites'],
    ['', 'examplewebsite.com', false, 'banner without a destination never matches'],
] as [$url, $site, $want, $why]) check(gb_same_site($url, $site) === $want, "domain rule: $why");

$db = gb_db();
$db->exec("INSERT INTO gb_spots (slug,name,width,height,price,period_days) VALUES ('selftest','Self test',120,60,10,30)");
$sid = (int)$db->lastInsertId();
$mk = function (string $target, string $kind = 'image', string $html = '') use ($db, $sid): int {
    $db->prepare("INSERT INTO gb_banners (spot_id,kind,image_path,html,target_url,width,height,status,paid)
                  VALUES (?,?,?,?,?,120,60,'active',1)")
       ->execute([$sid, $kind, $kind === 'image' ? 'uploads/x.png' : null, $html, $target]);
    return (int)$db->lastInsertId();
};
$own   = $mk('https://www.examplewebsite.com/sale');
$other = $mk('https://other-shop.com/deal');
$served = function (string $query, array $headers = []): array {
    $seen = [];
    for ($i = 0; $i < 30; $i++) {
        [, , $b] = http('GET', '/serve.php?spot=selftest' . $query, ['headers' => $headers]);
        $h = (string)json_decode($b, true)['html'];
        $seen[preg_match('~go\.php\?id=(\d+)~', $h, $m) ? (int)$m[1] : ($h === '' ? 'none' : 'html')] = true;
    }
    return array_keys($seen);
};
check($served('&site=examplewebsite.com') === [$other], "examplewebsite.com never gets its own banner (30 requests)");
check($served('&site=www.examplewebsite.com') === [$other], 'www.examplewebsite.com never gets it either');
check($served('&site=blog.examplewebsite.com') === [$other], 'blog.examplewebsite.com never gets it either');
check($served('&site=other-shop.com') === [$own], 'the rule works the other way round too');
$both = $served('&site=unrelated-news.com'); sort($both);
check($both === [$own, $other], 'an unrelated website gets both banners in rotation');
check($served('', ['Origin: https://examplewebsite.com']) === [$other], 'works for old embed code via the Origin header');
check($served('', ['Referer: https://examplewebsite.com/articles/1']) === [$other], 'works via the Referer header');
check($served('&site=examplewebsite.com', ['Origin: https://other-shop.com']) === [$other], 'the page address from embed.js takes priority');

$db->exec("UPDATE gb_banners SET status='paused' WHERE id=$other");
$before = (int)banner($own)['impressions'];
check($served('&site=examplewebsite.com') === ['none'], 'slot stays empty when only the site\'s own banners exist');
check((int)banner($own)['impressions'] === $before, 'no views are counted for skipped banners');
$db->exec("UPDATE gb_banners SET status='paused' WHERE id=$own");
$houseAd = $mk('', 'html', '<a href="https://shop.examplewebsite.com/promo"><b>Promo</b></a>');
check($served('&site=examplewebsite.com') === ['none'], 'HTML banners are checked by the links inside them');
check($served('&site=elsewhere.org') === ['html'], 'that HTML banner still shows on other websites');
[, , $js] = http('GET', '/embed.js.php');
check(str_contains($js, '"&site=" + encodeURIComponent(location.hostname)'), 'embed.js sends the page\'s website to the server');
$db->exec("DELETE FROM gb_spots WHERE id=$sid");

section('Unpaid banners (payment skipped)');
gb_db()->prepare("INSERT INTO gb_banners (spot_id, advertiser_name, advertiser_email, kind, image_path, target_url, width, height, status, paid)
                  VALUES (2, 'Skip Payer', 'skip@example.test', 'image', 'uploads/none.png', 'https://skip.example.test', 728, 90, 'pending', 0)")->execute();
$ub = (int)gb_db()->lastInsertId();
$act('approve', $ub);
[, , $bl] = http('GET', '/admin.php?a=banners', ['as' => 'admin']);
check(banner($ub)['status'] === 'pending' && str_contains($bl, "Banner #$ub NOT approved: it is unpaid"), 'approving an unpaid banner says why it was not approved');
check(str_contains($bl, 'pill-off">Not serving</span> <span class="reason">Unpaid'), 'banner list shows unpaid banner as "Not serving: Unpaid"');
$act('resume', $ub);
check(banner($ub)['status'] === 'pending', 'resume cannot activate a banner that was never paused');
$act('mark_paid', $ub);
check((int)banner($ub)['paid'] === 1, 'Mark paid marks the banner paid');
$o = gb_db()->query("SELECT gateway, amount, status FROM gb_orders WHERE banner_id=$ub")->fetch();
check($o && $o['gateway'] === 'manual' && (float)$o['amount'] === 10.0 && $o['status'] === 'paid', 'Mark paid records a manual order at the spot price', json_encode($o));
$act('approve', $ub);
check(banner($ub)['status'] === 'active', 'approve after Mark paid makes it active');
[, , $b] = http('GET', '/serve.php?spot=leaderboard');
check(str_contains((string)json_decode($b, true)['html'], "go.php?id=$ub") || str_contains((string)json_decode($b, true)['html'], 'house ad'), 'banner is now in the leaderboard rotation');
[, , $bl] = http('GET', '/admin.php?a=banners', ['as' => 'admin']);
check(str_contains($bl, 'pill-live">Serving'), 'banner list shows it as serving');
$act('delete', $ub);

section('Admin banners + spots');
$act('banner_create', 0, ['spot_id' => 2, 'kind' => 'html', 'html' => '<b>house ad</b>', 'url' => '', 'width' => 728, 'height' => 90]);
[, , $b] = http('GET', '/serve.php?spot=leaderboard');
check(json_decode($b, true)['html'] === '<b>house ad</b>', 'admin HTML banner created + served');
[$s, , $b] = http('POST', '/admin.php', ['as' => 'admin', 'body' => ['csrf' => $tok, 'act' => 'banner_create', 'back' => 'banners',
    'spot_id' => 1, 'kind' => 'image', 'width' => 300, 'height' => 250]]);
[, , $b] = http('GET', '/admin.php?a=banners', ['as' => 'admin']);
check($s === 302 && str_contains($b, 'Error: Upload failed'), 'admin image banner with no file shows an error instead of a 500');
$act('spot_save', 0, ['slug' => 'billboard', 'name' => 'Billboard', 'width' => 970, 'height' => 250, 'price' => 49, 'period_days' => 14]);
[, , $b] = http('GET', '/serve.php?spot=billboard');
check(json_decode($b, true)['width'] === 970, 'custom spot size added');
$act('delete', $bid);
check(banner($bid) === [] && count(glob("$build/uploads/*.png")) === 0, 'delete removes banner + its image file');

section('Edit placements');
$spot = fn(string $slug) => gb_db()->query("SELECT * FROM gb_spots WHERE slug='$slug'")->fetch();
$sq = $spot('square');
[, , $pl] = http('GET', '/admin.php?a=spots', ['as' => 'admin']);
check(str_contains($pl, '?a=spots&amp;edit=' . $sq['id']), 'each placement has an Edit button');
[$s, , $pl] = http('GET', '/admin.php?a=spots&edit=' . $sq['id'], ['as' => 'admin']);
check($s === 200 && str_contains($pl, 'value="spot_update"') && str_contains($pl, 'value="10.00"') && str_contains($pl, 'Save changes'),
      'Edit opens an inline form with the current price');
$act('spot_update', (int)$sq['id'], ['name' => 'Square Plus', 'price' => '12.50', 'period_days' => '14']);
$sq2 = $spot('square');
check($sq2['name'] === 'Square Plus' && (float)$sq2['price'] === 12.5 && (int)$sq2['period_days'] === 14,
      'Save changes updates name, price and run length', json_encode($sq2));
check((int)$sq2['width'] === 250 && $sq2['slug'] === 'square', 'size and embed name are not changed by an edit');
[, , $pl] = http('GET', '/admin.php?a=spots', ['as' => 'admin']);
check(str_contains($pl, 'Placement &quot;Square Plus&quot; updated: 12.50 USD for 14 days'), 'confirmation message after saving');
[, , $form] = http('GET', '/advertise.php', ['as' => 'adv']);
check(str_contains($form, '<strong>12.50</strong>USD') && str_contains($form, 'for 14 days'), 'advertiser page shows the new price and run length');

gb_db()->exec("INSERT INTO gb_banners (spot_id, advertiser_name, advertiser_email, kind, image_path, target_url, width, height) VALUES ({$sq['id']},'E','e@example.test','image','uploads/e.png','https://e.example.test',250,250)");
$eb = (int)gb_db()->lastInsertId();
$act('mark_paid', $eb);
$amt = gb_db()->query("SELECT amount FROM gb_orders WHERE banner_id=$eb")->fetchColumn();
check((float)$amt === 12.5, 'a new order is charged the edited price', (string)$amt);
$act('approve', $eb);
$e = banner($eb);
check(abs((strtotime($e['ends_at']) - strtotime($e['starts_at'])) / 86400 - 14) < 0.01, 'a new order runs for the edited run length');

foreach ([['price' => '-5', 'period_days' => '30', 'why' => 'negative price'],
          ['price' => 'abc', 'period_days' => '30', 'why' => 'non-numeric price'],
          ['price' => '10', 'period_days' => '0', 'why' => 'zero-day run']] as $bad) {
    [$s, $h] = $act('spot_update', (int)$sq['id'], ['name' => 'Broken', 'price' => $bad['price'], 'period_days' => $bad['period_days']]);
    check($s === 302 && str_contains($h['location'] ?? '', 'edit=' . $sq['id']) && $spot('square')['name'] === 'Square Plus',
          "edit with a {$bad['why']} is refused and reopens the form");
}
[, , $pl] = http('GET', '/admin.php?a=spots&edit=' . $sq['id'], ['as' => 'admin']);
check(str_contains($pl, 'Error: Enter a run length'), 'refused edit shows what to fix');
$act('spot_save', 0, ['slug' => 'billboard', 'name' => 'Billboard 2', 'width' => 970, 'height' => 250, 'price' => 10, 'period_days' => 30]);
[, , $pl] = http('GET', '/admin.php?a=spots', ['as' => 'admin']);
check(str_contains($pl, 'embed name &quot;billboard&quot; already exists'), 'adding a duplicate embed name gives a clear error');
$act('delete', $eb);
$act('spot_update', (int)$sq['id'], ['name' => 'Square', 'price' => '10', 'period_days' => '30']);

[$s, $h] = http('GET', '/admin.php?a=logout', ['as' => 'admin']);
[, , $b] = http('GET', '/admin.php', ['as' => 'admin']);
check(str_contains($b, 'Admin login'), 'logout works');

/* ---------- 10. placeholder password guard ---------- */
section('Design');
[$s, $h, $b] = http('GET', '/style.css');
check($s === 200 && str_contains($h['content-type'] ?? '', 'text/css'), 'style.css is served');
gb_db()->exec("INSERT INTO gb_banners (spot_id, advertiser_name, advertiser_email, kind, image_path, target_url, width, height) VALUES (1,'D','d@example.test','image','uploads/x.png','https://d.example.test',300,250)");
$did = (int)gb_db()->lastInsertId();
foreach (['/advertise.php' => 'adv', "/preview.php?id=$did&sig=" . gb_sig($did) => 'adv', '/admin.php' => 'admin2',
          '/admin.php?a=dashboard' => 'admin', '/admin.php?a=spots' => 'admin', '/admin.php?a=stats' => 'admin'] as $page => $who) {
    [, , $b] = http('GET', $page, ['as' => $who]);
    check(str_contains($b, 'href="/style.css?v=') && str_contains($b, GB_BRAND_NAME), "$page uses the shared design and brand name");
}

section('Browser setup page (setup.php)');
$counts = fn() => [(int)gb_db()->query('SELECT COUNT(*) FROM gb_spots')->fetchColumn(),
                   (int)gb_db()->query('SELECT COUNT(*) FROM gb_banners')->fetchColumn(),
                   (int)gb_db()->query('SELECT COUNT(*) FROM gb_orders')->fetchColumn()];
$runSetup = function (string $pw) {
    [, , $p] = http('GET', '/setup.php', ['as' => 'setup']);
    return http('POST', '/setup.php', ['as' => 'setup', 'body' => ['csrf' => csrf_from($p), 'password' => $pw]]);
};
[$s, , $p] = http('GET', '/setup.php', ['as' => 'setup']);
check($s === 200 && str_contains($p, 'Run setup'), 'setup.php asks for the admin password');
[, , $p] = $runSetup('wrong-password');
check(str_contains($p, 'password is not correct') && !str_contains($p, 'Setup complete'), 'setup.php refuses a wrong password');
$keep = $counts();
[$s, , $p] = $runSetup(GB_ADMIN_PASSWORD);
check($s === 200 && str_contains($p, 'Setup complete') && substr_count($p, 'already existed, kept as is') === 4,
      'on an existing site, setup keeps all 4 tables', strip_tags($p));
check($counts() === $keep, 'existing placements, banners and orders are untouched', json_encode([$keep, $counts()]));
check(str_contains($p, 'Placements already exist, kept as is'), 'starter placements are not re-added');
if ($mode === 'mysql') {
    foreach (['gb_stats', 'gb_orders', 'gb_banners', 'gb_spots'] as $t) gb_db()->exec("DROP TABLE $t");
    [, , $p] = $runSetup(GB_ADMIN_PASSWORD);
    check(substr_count($p, 'Created table') === 4 && str_contains($p, 'Added the 8 starter placements'), 'on an empty database, setup creates everything', strip_tags($p));
    check($counts() === [8, 0, 0], 'fresh database has the 8 placements and no banners');
    [, , $p] = $runSetup(GB_ADMIN_PASSWORD);
    check($counts() === [8, 0, 0] && substr_count($p, 'already existed') === 4, 'running setup twice changes nothing');
}

section('Move banners from a test site to the live site');
// A Codespace-style test site with its own database and images.
$src = "$build/codespace"; @mkdir("$src/uploads", 0777, true);
$sp = new PDO("sqlite:$src/dev.sqlite"); $sp->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$sp->exec(file_get_contents(__DIR__ . '/schema.sqlite.sql'));
$sp->exec("INSERT INTO gb_spots (slug,name,width,height,price,period_days) VALUES ('wide-tower','Wide Tower',240,400,10,30)");
png(728, 90, "$src/uploads/aaaa1111bbbb2222cccc.png");
png(300, 250, "$src/uploads/dddd3333eeee4444ffff.png");
$end = gmdate('Y-m-d H:i:s', time() + 20 * 86400); $start = gmdate('Y-m-d H:i:s', time() - 10 * 86400);
$sp->exec("INSERT INTO gb_banners (id,spot_id,kind,image_path,target_url,width,height,status,paid,starts_at,ends_at,impressions,clicks) VALUES
  (13,2,'image','uploads/aaaa1111bbbb2222cccc.png','https://move-one.example',728,90,'active',1,'$start','$end',120,7),
  (14,1,'image','uploads/dddd3333eeee4444ffff.png','https://move-two.example',300,250,'active',1,'$start','$end',80,2),
  (15,9,'html',NULL,'',240,400,'active',1,'$start','$end',5,0),
  (16,1,'image','uploads/dddd3333eeee4444ffff.png','https://rejected.example',300,250,'rejected',0,NULL,NULL,0,0)");
$sp->exec("UPDATE gb_banners SET html='<a href=\"https://move-three.example\">Three</a>' WHERE id=15");
$sp->exec("INSERT INTO gb_orders (banner_id,gateway,amount,status,txn_ref) VALUES (13,'manual',10,'paid','admin test')");
$sp->exec("INSERT INTO gb_stats VALUES (13,'" . gmdate('Y-m-d', time() - 86400) . "',100,6), (13,'" . gmdate('Y-m-d') . "',20,1)");
unset($sp);

[$code, $out] = sh('php ' . escapeshellarg(__DIR__ . '/export-banners.php') . ' --db=' . escapeshellarg("$src/dev.sqlite")
    . ' --uploads=' . escapeshellarg("$src/uploads") . ' --out=' . escapeshellarg("$src/banners-export.json"));
check($code === 0 && str_contains($out, 'Exported 3 banner(s): #13, #14, #15'), 'export packs the 3 live-worthy banners (rejected one skipped)', $out);

$import = function (string $file, string $pw) {
    [, , $p] = http('GET', '/import.php', ['as' => 'import']);
    return http('POST', '/import.php', ['as' => 'import', 'body' => ['csrf' => csrf_from($p), 'password' => $pw,
        'export' => new CURLFile($file, 'application/json', 'banners-export.json')]]);
};
$before = $counts();
[, , $p] = $import("$src/banners-export.json", 'wrong-password');
check(str_contains($p, 'password is not correct') && $counts() === $before, 'import refuses a wrong password and changes nothing');
file_put_contents("$src/not-export.json", '{"hello":"world"}');
[, , $p] = $import("$src/not-export.json", GB_ADMIN_PASSWORD);
check(str_contains($p, 'not a banner export') && $counts() === $before, 'import refuses a file that is not an export');

[$s, , $p] = $import("$src/banners-export.json", GB_ADMIN_PASSWORD);
check($s === 200 && str_contains($p, 'Import finished') && substr_count($p, 'Imported banner #') === 3, 'imports all 3 banners', strip_tags($p));
check(str_contains($p, 'Added placement &quot;Wide Tower&quot;'), 'a placement missing on the live site is added');
$after = $counts();
check($after[1] === $before[1] + 3 && $after[0] === $before[0] + 1, 'exactly 3 banners and 1 placement were added', json_encode([$before, $after]));

$moved = gb_db()->query("SELECT b.*, s.slug FROM gb_banners b JOIN gb_spots s ON s.id=b.spot_id WHERE target_url='https://move-one.example'")->fetch();
check($moved && $moved['slug'] === 'leaderboard' && $moved['status'] === 'active' && (int)$moved['paid'] === 1
      && $moved['ends_at'] === $end && (int)$moved['impressions'] === 120 && (int)$moved['clicks'] === 7,
      'banner keeps its placement, link, status, paid flag, end date and counters', json_encode($moved));
check(is_file("$build/" . $moved['image_path']) && sha1_file("$build/" . $moved['image_path']) === sha1_file("$src/uploads/aaaa1111bbbb2222cccc.png"),
      'its image file is copied into uploads/');
check((int)gb_db()->query("SELECT SUM(impressions) FROM gb_stats WHERE banner_id={$moved['id']}")->fetchColumn() === 120, 'its daily stats come along');
check((float)gb_db()->query("SELECT amount FROM gb_orders WHERE banner_id={$moved['id']}")->fetchColumn() === 10.0, 'its order comes along');
$html = gb_db()->query("SELECT * FROM gb_banners WHERE kind='html' AND html LIKE '%move-three.example%'")->fetch();
check($html && $html['status'] === 'active', 'HTML banner is imported too');
$hit = false;
for ($i = 0; $i < 40 && !$hit; $i++) {
    [, , $b] = http('GET', '/serve.php?spot=leaderboard&site=some-publisher.example');
    $hit = str_contains((string)json_decode($b, true)['html'], 'go.php?id=' . $moved['id']);
}
check($hit, 'imported banners are served on the live site');

[, , $p] = $import("$src/banners-export.json", GB_ADMIN_PASSWORD);
check(substr_count($p, 'was already imported; skipped') === 3 && $counts() === $after, 'importing the same file again adds nothing');
foreach (gb_db()->query("SELECT id FROM gb_banners WHERE target_url LIKE 'https://move-%' OR html LIKE '%move-three%'") as $r) gb_db()->exec("DELETE FROM gb_banners WHERE id={$r['id']}");
gb_db()->exec("DELETE FROM gb_spots WHERE slug='wide-tower'");

section('Search engine optimization');
$pub = ['/' => 'index.html', '/advertise.php' => 'advertise.php', '/pricing.php' => 'pricing.php',
        '/banner-ad-sizes.php' => 'banner-ad-sizes.php', '/how-to-buy-banner-ads.php' => 'how-to-buy-banner-ads.php', '/faq.php' => 'faq.php'];
check(is_file("$build/index.html") && !is_file("$build/home.php"), 'home page rendered to a static index.html (template removed)');
$titles = [];
foreach ($pub as $path => $file) {
    [$s, , $h] = http('GET', $path === '/' ? '/' : $path, ['as' => 'seo']);
    preg_match('~<title>([^<]+)</title>~', $h, $t); preg_match('~name="description" content="([^"]+)"~', $h, $d);
    preg_match('~rel="canonical" href="([^"]+)"~', $h, $c);
    $ld = preg_match('~<script type="application/ld\+json">(.+?)</script>~s', $h, $j) ? json_decode($j[1], true) : null;
    $ok = $s === 200 && str_contains($h, 'name="robots" content="index, follow')
        && isset($t[1], $d[1], $c[1]) && mb_strlen(html_entity_decode($t[1])) <= 65 && mb_strlen(html_entity_decode($d[1])) <= 165
        && substr_count($h, '<h1') === 1 && str_ends_with($c[1], $path) && is_array($ld['@graph'] ?? null)
        && str_contains($h, 'property="og:image"');
    check($ok, "$path: indexable, unique title/description, canonical, one H1, valid structured data",
          json_encode(['status' => $s, 'title' => $t[1] ?? null, 'canon' => $c[1] ?? null, 'h1' => substr_count($h, '<h1')]));
    $titles[] = $t[1] ?? '';
}
check(count(array_unique($titles)) === count($titles), 'every public page has a different title');
foreach (['/admin.php', '/setup.php', '/import.php'] as $path) {
    [, , $h] = http('GET', $path, ['as' => 'seo-private']);
    check(str_contains($h, 'name="robots" content="noindex, nofollow"') && !str_contains($h, 'rel="canonical"'), "$path is hidden from search engines");
}
[, , $h] = http('GET', '/pricing.php');
check(str_contains($h, '"@type":"Offer"') && str_contains($h, '"price":"10.00"'), 'pricing page lists live prices as structured data');
[$s, $hd, $robots] = http('GET', '/robots.txt');
check($s === 200 && str_contains($robots, "Disallow: /admin.php") && str_contains($robots, "Disallow: /preview.php")
      && preg_match('~^Sitemap: https?://\S+/sitemap\.xml$~m', $robots) === 1, 'robots.txt blocks private pages and points to the sitemap');
[$s, , $xml] = http('GET', '/sitemap.xml');
$sx = @simplexml_load_string($xml);
$locs = $sx ? array_map('strval', iterator_to_array($sx->url ? (function () use ($sx) { foreach ($sx->url as $u) yield (string)$u->loc; })() : [], false)) : [];
check($s === 200 && $sx !== false && count($locs) >= 7 && (bool)array_filter($locs, fn($l) => str_ends_with($l, '/blog/')), 'sitemap.xml is valid XML with the public pages and the blog', $xml);
check(!array_filter($locs, fn($l) => preg_match('~admin|preview|setup|import|serve|go\.php~', $l)), 'sitemap contains no private pages');
[$s, $hd] = http('GET', '/og-image.png');
check($s === 200 && ($hd['content-type'] ?? '') === 'image/png' && getimagesize("$build/og-image.png")[0] === 1200, 'social sharing image (1200x630) is served');
[$s, , $k] = http('GET', '/8896ea600333598d3649af506349872f.txt');
check($s === 200 && trim($k) === '8896ea600333598d3649af506349872f', 'IndexNow key file is served for Bing');
[, $hd] = http('GET', '/embed.js.php');
check(($hd['x-robots-tag'] ?? '') === 'noindex', 'embed script is not indexed');

$t2 = "$build/../seo-install"; sh('rm -rf ' . escapeshellarg($t2)); @mkdir($t2);
file_put_contents("$t2/index.html", '<h1>Existing home page</h1>');
[$code, $out] = sh('php ' . escapeshellarg("$root/install.php") . ' --dir=' . escapeshellarg($t2) . ' --lastmod=2026-01-02');
check($code === 0 && file_get_contents("$t2/index.html") === '<h1>Existing home page</h1>' && str_contains($out, 'SKIP   index.html'),
      'installer never replaces an existing index.html without --force');
check(str_contains((string)@file_get_contents("$t2/sitemap.xml"), '<lastmod>2026-01-02</lastmod>'), '--lastmod sets the sitemap dates');
[$code] = sh('php ' . escapeshellarg("$root/install.php") . ' --dir=' . escapeshellarg($t2) . ' --force --lastmod=yesterday');
check($code !== 0, 'an invalid --lastmod is rejected');
sh('rm -rf ' . escapeshellarg($t2));

section('Analytics, consent and self-hosted fonts');
foreach (['/', '/advertise.php', '/pricing.php', '/faq.php'] as $path) {
    [, , $h] = http('GET', $path, ['as' => 'ga']);
    $headStart = strpos($h, '<head>') ?: 0;
    check(str_contains($h, '"GTM-T9SK6QS3"') && strpos($h, 'gtag("consent","default"') < strpos($h, '<meta name="viewport"')
          && str_contains($h, 'id="consent"') && str_contains($h, 'data-cookie-settings'),
          "$path: consent defaults first in <head>, Tag Manager, cookie popup and footer link");
    check(!str_contains($h, 'fonts.googleapis') && !str_contains($h, 'fonts.gstatic'), "$path: no requests to Google Fonts");
}
[, , $h] = http('GET', '/');
check(str_contains($h, 'analytics_storage:"denied",region:["AT"') && str_contains($h, 'ad_storage:"denied",ad_user_data:"denied",ad_personalization:"denied",analytics_storage:"granted"'),
      'Consent Mode: denied by default in Europe/UK, ads always denied');
foreach (['/admin.php', '/setup.php', '/import.php'] as $path) {
    [, , $h] = http('GET', $path, ['as' => 'ga-private']);
    check(!str_contains($h, 'googletagmanager') && !str_contains($h, 'id="consent"'), "$path: no analytics");
}
[$s, $hd] = http('GET', '/fonts/archivo.woff2');
[$s2] = http('GET', '/fonts/ibm-plex-sans.woff2');
check($s === 200 && $s2 === 200 && filesize("$build/fonts/archivo.woff2") > 50000, 'self-hosted fonts are served');
check(is_file("$build/fonts/OFL-Archivo.txt") && is_file("$build/fonts/OFL-IBMPlexSans.txt"), 'font licenses are included');
[, , $css] = http('GET', '/style.css');
check(str_contains($css, 'src: url(fonts/archivo.woff2)') && str_contains($css, 'src: url(fonts/ibm-plex-sans.woff2)'), 'style.css uses the local fonts');

section('Privacy Policy and Terms of Service');
foreach (['/privacy.php' => 'Privacy Policy', '/terms.php' => 'Terms of Service'] as $path => $h1) {
    [$s, , $h] = http('GET', $path, ['as' => 'legal']);
    check($s === 200 && str_contains($h, "<h1>$h1</h1>") && str_contains($h, 'name="robots" content="index, follow')
          && str_contains($h, 'rel="canonical" href="' . gb_url($path) . '"'), "$path is live and indexable");
}
[, , $h] = http('GET', '/terms.php');
foreach (['Fraud or deception', 'Pornography or sexually explicit material', 'Anything illegal', 'redirect or forward the link',
          'terminated immediately', 'no refund', 'law enforcement authorities', 'No exceptions'] as $rule)
    check(str_contains($h, $rule), "Terms include: $rule");
[, , $h] = http('GET', '/privacy.php');
foreach (['We never receive or store your card number', 'Google Analytics', 'Stripe and PayPal', 'Do Not Track', 'Law enforcement'] as $item)
    check(str_contains($h, $item), "Privacy Policy covers: $item");
[, , $h] = http('GET', '/');
check(str_contains($h, 'href="/privacy.php">Privacy Policy</a>') && str_contains($h, 'href="/terms.php">Terms of Service</a>'), 'footer links to both legal pages');
check(substr_count($h, 'href="/privacy.php"') >= 2, 'cookie popup links to the Privacy Policy');

// An older database (before the Terms columns) is upgraded without losing data.
$old = new PDO('sqlite::memory:'); $old->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$old->exec("CREATE TABLE gb_banners (id INTEGER PRIMARY KEY, target_url TEXT)");
$old->exec("INSERT INTO gb_banners (target_url) VALUES ('https://kept.example')");
check(gb_migrate($old) === true, 'older database gets the Terms columns added');
check($old->query("SELECT target_url FROM gb_banners")->fetchColumn() === 'https://kept.example', 'existing banners are kept during the upgrade');
check(gb_migrate($old) === true, 'running the upgrade again is harmless');
if ($mode === 'mysql') {   // the live site's path: real MySQL without the columns
    $cnt = (int)gb_db()->query('SELECT COUNT(*) FROM gb_banners')->fetchColumn();
    gb_db()->exec('ALTER TABLE gb_banners DROP COLUMN tos_version, DROP COLUMN tos_accepted_at');
    check(gb_migrate(gb_db()) === true && (int)gb_db()->query('SELECT COUNT(*) FROM gb_banners')->fetchColumn() === $cnt,
          'MySQL: missing Terms columns are added back, banners kept');
}

section('Blog');
$posts = array_map(fn($f) => preg_replace('/^\d{4}-\d{2}-\d{2}-/', '', basename($f, '.html')), glob("$root/content/blog/*.html"));
[$s, , $idx] = http('GET', '/blog/');
check($s === 200 && substr_count($idx, 'class="post-card"') === count($posts) && str_contains($idx, '"@type":"Blog"'), 'blog index lists every article, with Blog structured data');
foreach (glob("$root/content/blog/*.html") as $f) {
    preg_match('/^slug:\s*(\S+)/m', (string)file_get_contents($f), $m); $slug = $m[1];
    [$s, , $h] = http('GET', "/blog/$slug.html");
    $ld = preg_match('~<script type="application/ld\+json">(.+?)</script>~s', $h, $j) ? json_decode($j[1], true) : [];
    $types = array_column($ld['@graph'] ?? [], '@type');
    $crumb = array_values(array_filter($ld['@graph'] ?? [], fn($g) => $g['@type'] === 'BreadcrumbList'))[0] ?? [];
    $ok = $s === 200 && in_array('BlogPosting', $types, true) && in_array('FAQPage', $types, true) && count($crumb['itemListElement'] ?? []) === 3
        && str_contains($h, 'rel="canonical" href="' . gb_url("/blog/$slug.html") . '"') && str_contains($h, 'property="og:type" content="article"')
        && str_contains($h, 'class="post-toc"') && substr_count($h, '<h1') === 1 && !preg_match('~<a [^>]*href="https?://(?![^"]*127\.0\.0\.1)[^"]*"(?![^>]*rel="noopener")~', $h);
    check($ok, "/blog/$slug.html: article schema, FAQ, breadcrumb, canonical, table of contents, safe external links");
}
$sm = (string)file_get_contents("$build/sitemap.xml");
check(count(array_filter($posts, fn($p) => str_contains($sm, "/blog/$p.html</loc>"))) === count($posts), 'every article is in sitemap.xml');
$rss = @simplexml_load_string((string)file_get_contents("$build/blog/feed.xml"));
check($rss !== false && count($rss->channel->item) === count($posts), 'RSS feed lists the articles');
[, , $h] = http('GET', '/');
check(str_contains($h, 'Latest from the blog') && substr_count($h, 'class="post-card"') === min(3, count($posts)), 'home page links to the latest 3 articles');

$tmp = "$build/../blog-tests"; sh('rm -rf ' . escapeshellarg($tmp)); @mkdir($tmp, 0777, true);
copy(glob("$root/content/blog/*.html")[0], "$tmp/good.html");
file_put_contents("$tmp/bad.html", "---\ntitle: Bad post\nseo_title: Short\ndescription: Too short.\nslug: Bad Slug\ndate: 2026-13-45\nprimary_keyword: widgets\nkeywords: widgets\ncategory: Test\n---\n<h1>Nope</h1><p>lorem ipsum</p>\n");
[$code, $out] = sh('php ' . escapeshellarg("$root/tools/build-blog.php") . ' --check-file=' . escapeshellarg("$tmp/bad.html"));
check($code !== 0 && str_contains($out, 'seo_title must be 30-60') && str_contains($out, 'at least 1000 words') && str_contains($out, 'placeholder'),
      'the checker refuses an article that breaks the SEO rules', $out);
$future = preg_replace('/^date: .*/m', 'date: 2099-01-01', (string)file_get_contents("$tmp/good.html"));
file_put_contents("$tmp/good.html", $future); @unlink("$tmp/bad.html");
sh('rm -rf ' . escapeshellarg("$tmp/site")); sh('cp -r ' . escapeshellarg($build) . ' ' . escapeshellarg("$tmp/site"));
[$code, $out] = sh('php ' . escapeshellarg("$root/tools/build-blog.php") . ' --site=' . escapeshellarg("$tmp/site") . ' --content=' . escapeshellarg($tmp));
check($code === 0 && str_contains($out, '0 articles, 1 scheduled') && !glob("$tmp/site/blog/*-*.html.new"), 'future-dated articles stay hidden until their date', $out);
sh('rm -rf ' . escapeshellarg($tmp));

section('Branding');
[, , $b] = http('GET', '/advertise.php', ['as' => 'brand']);
check(str_contains($b, '<span class="wordmark"><b>Banner</b>Promote</span>'), 'BannerPromote wordmark in the header');
check(preg_match('~<title>Buy a Banner Ad Online: \d+ Sizes from \$\d+ \| BannerPromote</title>~', $b) === 1, 'page titles use BannerPromote');
[$s, $h] = http('GET', '/favicon.svg');
check($s === 200 && str_contains($h['content-type'] ?? '', 'svg'), 'favicon is served');
[, , $cfg] = [0, 0, (string)file_get_contents("$build/config.php")];
check(str_contains($cfg, "'https://bannerpromote.com'") && str_contains($cfg, "'BannerPromote'"), 'config.php defaults to bannerpromote.com');

section('Safety guard');
[$srv2, $up2] = start_server($build, $port + 1, array_merge($envVars, ['GB_TEST_ADMIN_PASS' => 'CHANGE-ME-NOW']));
[$s, , $b] = http('GET', 'http://127.0.0.1:' . ($port + 1) . '/admin.php');
check($up2 && $s === 503, 'admin refuses to run with the placeholder password');
proc_terminate($srv2);

/* ---------- server log ---------- */
$log = (string)@file_get_contents("$build/server-$port.log");
$problems = preg_grep('/(Fatal|Warning|Notice|Deprecated|Uncaught)/i', explode("\n", $log));
check($problems === [], 'no PHP errors/warnings in the server log', implode("\n      ", array_slice($problems, 0, 10)));

echo "\n" . ($failed ? "\033[31m" : "\033[32m") . "$pass passed, " . count($failed) . " failed\033[0m\n";
exit($failed ? 1 : 0);
