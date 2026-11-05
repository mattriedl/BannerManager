<?php
declare(strict_types=1);

/* Writes one PNG per preset slot size into the given folder, plus an
   index.html with download links, so the purchase form can be tried
   without making images by hand. Used by dev.sh. Needs the gd extension. */

$dir = $argv[1] ?? 'sample-banners';
@mkdir($dir, 0775, true);

$sizes = [
    'medium-rectangle' => [300, 250], 'leaderboard' => [728, 90],
    'full-banner' => [468, 60],       'mobile-banner' => [320, 50],
    'skyscraper' => [160, 600],       'half-page' => [300, 600],
    'large-rectangle' => [336, 280],  'square' => [250, 250],
];
$palette = [[47, 168, 85], [36, 50, 95], [214, 92, 46], [99, 91, 255]];

$links = '';
$i = 0;
foreach ($sizes as $slug => [$w, $h]) {
    [$r, $g, $b] = $palette[$i++ % count($palette)];
    $im = imagecreatetruecolor($w, $h);
    imagefill($im, 0, 0, imagecolorallocate($im, $r, $g, $b));
    $white = imagecolorallocate($im, 255, 255, 255);
    imagerectangle($im, 2, 2, $w - 3, $h - 3, $white);
    $label = "SAMPLE AD {$w}x{$h}";
    $font = 5;
    imagestring($im, $font, max(4, (int)(($w - imagefontwidth($font) * strlen($label)) / 2)),
        (int)(($h - imagefontheight($font)) / 2), $label, $white);
    imagepng($im, "$dir/$slug-{$w}x{$h}.png");
    imagedestroy($im);
    $links .= "<li><a href=\"$slug-{$w}x{$h}.png\" download>$slug-{$w}x{$h}.png</a></li>\n";
}

file_put_contents("$dir/index.html",
    "<!doctype html><meta charset=\"utf-8\"><title>Sample banners</title>"
  . "<h1>Sample banners</h1><p>Download one, then upload it on advertise.php "
  . "for the slot with the same size.</p><ul>\n$links</ul>\n");

echo "Wrote " . count($sizes) . " sample banners to $dir\n";
