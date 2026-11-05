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
check(str_contains($sql, 'CREATE TABLE gb_spots'), 'schema import includes CREATE TABLE gb_spots (regression)');
check(str_contains($sql, 'INSERT INTO gb_spots'), 'schema import includes the 8 seed spots (regression)');

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
    'image' => new CURLFile($file, 'image/png', basename($file))];

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
    check(str_contains($b, 'href="style.css?v=') && str_contains($b, GB_BRAND_NAME), "$page uses the shared design and brand name");
}

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
