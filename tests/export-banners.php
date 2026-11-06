<?php
declare(strict_types=1);

/* ================================================================
   Export banners from the local/Codespace test site into ONE file
   that the live site's import.php can read.

     php tests/export-banners.php                 # all banners
     php tests/export-banners.php --only=13,14    # just these IDs
     php tests/export-banners.php --db=path/to.sqlite --uploads=path/to/uploads --out=file.json

   Includes each banner's settings, image, order and daily stats,
   plus the placements they use. Rejected banners are skipped.
   ================================================================ */

$root = dirname(__DIR__);
$o = getopt('', ['db:', 'uploads:', 'out:', 'only:']);
$dbFile  = $o['db']      ?? "$root/build/dev/dev.sqlite";
$uploads = $o['uploads'] ?? "$root/build/dev/uploads";
$out     = $o['out']     ?? "$root/build/dev/banners-export.json";
$only    = isset($o['only']) ? array_map('intval', explode(',', (string)$o['only'])) : null;

if (!is_file($dbFile)) { fwrite(STDERR, "No test database at $dbFile\n"); exit(1); }
$db = new PDO('sqlite:' . $dbFile, null, null,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);

$banners = $db->query("SELECT b.*, s.slug spot_slug FROM gb_banners b JOIN gb_spots s ON s.id=b.spot_id
                       WHERE b.status <> 'rejected' ORDER BY b.id")->fetchAll();
if ($only !== null) $banners = array_values(array_filter($banners, fn($b) => in_array((int)$b['id'], $only, true)));
if (!$banners) { fwrite(STDERR, "No banners to export.\n"); exit(1); }

$ids = array_map(fn($b) => (int)$b['id'], $banners);
$in  = implode(',', $ids);
$spotIds = array_unique(array_map(fn($b) => (int)$b['spot_id'], $banners));
$export = [
    'format'   => 'bannerpromote-export-1',
    'exported' => gmdate('Y-m-d H:i:s'),
    'spots'    => $db->query('SELECT slug,name,width,height,price,period_days FROM gb_spots WHERE id IN (' . implode(',', $spotIds) . ')')->fetchAll(),
    'banners'  => [],
];
$missing = [];
foreach ($banners as $b) {
    $img = null;
    if ($b['kind'] === 'image' && $b['image_path']) {
        $file = $uploads . '/' . basename((string)$b['image_path']);
        if (is_file($file)) $img = ['name' => basename($file), 'data' => base64_encode((string)file_get_contents($file))];
        else $missing[] = "#{$b['id']} (" . basename((string)$b['image_path']) . ')';
    }
    $export['banners'][] = [
        'source_id'        => (int)$b['id'],
        'spot_slug'        => $b['spot_slug'],
        'advertiser_name'  => $b['advertiser_name'], 'advertiser_email' => $b['advertiser_email'],
        'kind'             => $b['kind'], 'html' => $b['html'], 'target_url' => $b['target_url'],
        'width'            => (int)$b['width'], 'height' => (int)$b['height'],
        'status'           => $b['status'], 'paid' => (int)$b['paid'],
        'starts_at'        => $b['starts_at'], 'ends_at' => $b['ends_at'],
        'impressions'      => (int)$b['impressions'], 'clicks' => (int)$b['clicks'],
        'created_at'       => $b['created_at'],
        'image'            => $img,
        'orders'           => $db->query("SELECT gateway,amount,currency,status,txn_ref,created_at FROM gb_orders WHERE banner_id={$b['id']}")->fetchAll(),
        'stats'            => $db->query("SELECT day,impressions,clicks FROM gb_stats WHERE banner_id={$b['id']}")->fetchAll(),
    ];
}

file_put_contents($out, json_encode($export, JSON_UNESCAPED_SLASHES));
printf("Exported %d banner(s): #%s\n", count($ids), implode(', #', $ids));
if ($missing) echo "Note: image file missing for " . implode(', ', $missing) . " (exported without image)\n";
printf("File: %s (%.1f KB)\n", $out, filesize($out) / 1024);
echo "Next: download this file, then open https://bannerpromote.com/import.php to import it.\n";
