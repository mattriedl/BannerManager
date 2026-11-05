<?php
declare(strict_types=1);

/* ================================================================
   LOCAL DEV / TEST CONFIG — never upload to production.

   Copied next to config.php by dev.sh and tests/run.php. When
   present, config.php loads this file instead of its own values.

   Database:
     GB_TEST_DB=sqlite (default)  -> SQLite file, MySQL functions
                                     emulated by GbSqlitePdo below
     GB_TEST_DB=mysql             -> real MySQL using GB_TEST_MYSQL_*
                                     env vars (used by GitHub Actions)
   ================================================================ */

$env = static fn(string $k, string $d = ''): string =>
    (getenv($k) !== false && getenv($k) !== '') ? (string)getenv($k) : $d;

define('GB_DB_HOST', $env('GB_TEST_MYSQL_HOST', '127.0.0.1'));
define('GB_DB_NAME', $env('GB_TEST_MYSQL_DB', 'greenbox_test'));
define('GB_DB_USER', $env('GB_TEST_MYSQL_USER', 'root'));
define('GB_DB_PASS', $env('GB_TEST_MYSQL_PASS', ''));

define('GB_BASE_URL', $env('GB_TEST_BASE_URL', 'http://127.0.0.1:8765'));
define('GB_ADMIN_PASSWORD', $env('GB_TEST_ADMIN_PASS', 'local-test-admin-pass'));
define('GB_ADMIN_EMAIL', 'admin@example.test');

define('GB_UPLOAD_DIR', __DIR__ . '/uploads');
define('GB_MAX_UPLOAD_BYTES', 5 * 1024 * 1024);

define('GB_AUTO_APPROVE', $env('GB_TEST_AUTO_APPROVE') === '1');
define('GB_CURRENCY', 'USD');

define('GB_PAYPAL_BUSINESS', 'seller@example.test');
define('GB_PAYPAL_SANDBOX', true);

define('GB_STRIPE_SECRET', $env('GB_TEST_STRIPE_SECRET', 'sk_test_local'));
define('GB_STRIPE_WEBHOOK_SECRET', 'whsec_local_test_secret');

define('GB_MAIL_FROM', 'noreply@example.test');

date_default_timezone_set('UTC');
ini_set('display_errors', '1');
error_reporting(E_ALL);

define('GB_TEST_SQLITE_FILE', $env('GB_TEST_SQLITE_FILE', __DIR__ . '/dev.sqlite'));

/**
 * PDO for SQLite that rewrites the few MySQL-only constructs the app
 * uses, so the real PHP code runs unchanged without a MySQL server.
 */
final class GbSqlitePdo extends PDO
{
    public static function translate(string $sql): string
    {
        $sql = preg_replace('/DATE_ADD\(\s*NOW\(\)\s*,\s*INTERVAL\s+\?\s+DAY\s*\)/i', 'gb_now_plus_days(?)', $sql);
        $sql = preg_replace('/ON DUPLICATE KEY UPDATE/i', 'ON CONFLICT(banner_id, day) DO UPDATE SET', $sql);
        $sql = preg_replace('/\bCURDATE\(\)/i', 'gb_curdate()', $sql);
        $sql = preg_replace('/\bNOW\(\)/i', 'gb_now()', $sql);
        $sql = preg_replace('/\bRAND\(\)/i', 'random()', $sql);
        $sql = preg_replace('/\bFIELD\(/i', 'gb_field(', $sql);
        return $sql;
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    { return parent::prepare(self::translate($query), $options); }

    public function exec(string $statement): int|false
    { return parent::exec(self::translate($statement)); }

    public function query(string $query, ?int $fetchMode = null, mixed ...$args): PDOStatement|false
    {
        $query = self::translate($query);
        return $fetchMode === null ? parent::query($query) : parent::query($query, $fetchMode, ...$args);
    }
}

function gb_pdo_factory(): PDO
{
    $opts = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
             PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];

    if ((getenv('GB_TEST_DB') ?: 'sqlite') === 'mysql') {
        $pdo = new PDO('mysql:host=' . GB_DB_HOST . ';dbname=' . GB_DB_NAME . ';charset=utf8mb4',
                       GB_DB_USER, GB_DB_PASS, $opts);
        $pdo->exec("SET time_zone = '+00:00'");
        return $pdo;
    }

    $pdo = new GbSqlitePdo('sqlite:' . GB_TEST_SQLITE_FILE, null, null, $opts);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA busy_timeout = 5000');
    $pdo->sqliteCreateFunction('gb_now', fn() => gmdate('Y-m-d H:i:s'), 0);
    $pdo->sqliteCreateFunction('gb_curdate', fn() => gmdate('Y-m-d'), 0);
    $pdo->sqliteCreateFunction('gb_now_plus_days',
        fn($d) => gmdate('Y-m-d H:i:s', time() + (int)$d * 86400), 1);
    $pdo->sqliteCreateFunction('gb_field', function ($needle, ...$list) {
        $i = array_search($needle, $list, false);
        return $i === false ? 0 : $i + 1;
    });
    return $pdo;
}

require_once __DIR__ . '/lib.php';
