<?php
declare(strict_types=1);

/* ================================================================
   DEV ONLY: pretend a Stripe card payment succeeded for a banner.

     php tests/fake-payment.php 1          # banner #1, dev server on :8000
     PORT=9000 php tests/fake-payment.php 3

   Sends a correctly signed checkout.session.completed event to the
   running dev server's webhook-stripe.php, so the real webhook code
   (signature check, amount check, marking paid, e-mails, auto-approve)
   runs exactly as it would for a live payment.
   ================================================================ */

$bid  = (int)($argv[1] ?? 0);
$port = (int)(getenv('PORT') ?: 8000);
if ($bid <= 0) { fwrite(STDERR, "Usage: php tests/fake-payment.php BANNER_ID\n"); exit(2); }

$db = new PDO('sqlite:' . dirname(__DIR__) . '/build/dev/dev.sqlite');
$st = $db->prepare('SELECT s.price FROM gb_banners b JOIN gb_spots s ON s.id = b.spot_id WHERE b.id = ?');
$st->execute([$bid]);
$price = $st->fetchColumn();
$st->closeCursor(); $st = null;   // release SQLite's read lock before the server writes
if ($price === false) { fwrite(STDERR, "No banner #$bid in build/dev/dev.sqlite\n"); exit(1); }

$secret  = 'whsec_local_test_secret';           // matches tests/config.local.php
$payload = json_encode(['type' => 'checkout.session.completed', 'data' => ['object' => [
    'id' => 'cs_fake_' . bin2hex(random_bytes(6)), 'payment_status' => 'paid',
    'amount_total' => (int)round((float)$price * 100),
    'metadata' => ['banner_id' => (string)$bid],
]]]);
$t   = time();
$sig = hash_hmac('sha256', "$t.$payload", $secret);

$ch = curl_init("http://127.0.0.1:$port/webhook-stripe.php");
curl_setopt_array($ch, [
    CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload, CURLOPT_RETURNTRANSFER => true,
    CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*',
    CURLOPT_HTTPHEADER => ['Content-Type: application/json', "Stripe-Signature: t=$t,v1=$sig"],
]);
$body = curl_exec($ch);
$code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
curl_close($ch);

if ($code !== 200) { fwrite(STDERR, "Webhook returned HTTP $code: $body\nIs ./dev.sh running on port $port?\n"); exit(1); }
$paid = (int)$db->query("SELECT paid FROM gb_banners WHERE id = $bid")->fetchColumn();
echo $paid ? "Banner #$bid marked paid ($price USD). Approve it in admin.php → Banners.\n"
           : "Webhook accepted but banner #$bid is not paid — check the dev.sh terminal for errors.\n";
exit($paid ? 0 : 1);
