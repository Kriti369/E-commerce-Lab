<?php
// Shared setup: session, security headers, database, helpers.
declare(strict_types=1);

const PRIVATE_DIR = __DIR__ . '/../../private';   // database + book files live OUTSIDE the web root

ini_set('display_errors', '0');
session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict', 'secure' => !empty($_SERVER['HTTPS'])]);
session_start();

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header('Cache-Control: no-store');

function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    if (!is_dir(PRIVATE_DIR)) mkdir(PRIVATE_DIR, 0775, true);
    $pdo = new PDO('sqlite:' . PRIVATE_DIR . '/store.sqlite', null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT            => 5,
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec("
      CREATE TABLE IF NOT EXISTS users(
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        email TEXT NOT NULL UNIQUE,               -- stored in lower case
        pass_hash TEXT NOT NULL,                  -- password_hash() result, never the password
        created_at INTEGER NOT NULL);
      CREATE TABLE IF NOT EXISTS login_fails(     -- used to slow down password guessing
        email TEXT NOT NULL, ts INTEGER NOT NULL);
      CREATE TABLE IF NOT EXISTS orders(
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER REFERENCES users(id),
        ref TEXT NOT NULL UNIQUE,
        idem_key TEXT NOT NULL UNIQUE,            -- stops double-clicks creating two orders
        name TEXT NOT NULL, email TEXT NOT NULL,
        total_cents INTEGER NOT NULL CHECK(total_cents > 0),
        status TEXT NOT NULL CHECK(status IN ('pending','paid','failed')),
        created_at INTEGER NOT NULL);
      CREATE TABLE IF NOT EXISTS order_items(
        order_id INTEGER NOT NULL REFERENCES orders(id),
        book_id TEXT NOT NULL, title TEXT NOT NULL,
        price_cents INTEGER NOT NULL,             -- price at time of purchase
        PRIMARY KEY(order_id, book_id));
      CREATE TABLE IF NOT EXISTS payments(
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        order_id INTEGER NOT NULL REFERENCES orders(id),
        status TEXT NOT NULL, card_last4 TEXT NOT NULL,   -- never the full card number
        provider_ref TEXT NOT NULL, note TEXT NOT NULL, created_at INTEGER NOT NULL);
    ");
    // Upgrade a database created by the first version of the project (orders had no user_id).
    $cols = array_column($pdo->query('PRAGMA table_info(orders)')->fetchAll(), 'name');
    if (!in_array('user_id', $cols, true)) $pdo->exec('ALTER TABLE orders ADD COLUMN user_id INTEGER REFERENCES users(id)');
    return $pdo;
}

// Prices ALWAYS come from this file on the server, never from the browser.
function catalog(): array {
    $list = json_decode(file_get_contents(__DIR__ . '/../data/books.json'), true) ?: [];
    $out = [];
    foreach ($list as $b) {
        $out[$b['id']] = ['id' => $b['id'], 'title' => $b['title'], 'cents' => (int) round($b['price'] * 100)];
    }
    return $out;
}

function current_user(): ?array {
    if (empty($_SESSION['uid'])) return null;
    $st = db()->prepare('SELECT id, name, email FROM users WHERE id = ?');
    $st->execute([$_SESSION['uid']]);
    return $st->fetch() ?: null;
}

function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}

function json_out(array $data, int $code = 200): never {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

function fail(int $code, string $message): never {
    json_out(['ok' => false, 'message' => $message], $code);
}

/** Every POST goes through here: same-origin check, valid JSON, valid CSRF token. */
function post_json(): array {
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin !== '' && parse_url($origin, PHP_URL_HOST) !== explode(':', $_SERVER['HTTP_HOST'] ?? '')[0]) {
        fail(403, 'Request blocked.');
    }
    $in = json_decode(file_get_contents('php://input') ?: '', true);
    if (!is_array($in)) fail(400, 'Invalid request.');
    if (!hash_equals(csrf_token(), (string) ($in['csrf'] ?? ''))) fail(403, 'Your session expired. Refresh the page and try again.');
    return $in;
}

function luhn_ok(string $n): bool {   // standard card-number checksum
    $sum = 0; $alt = false;
    for ($i = strlen($n) - 1; $i >= 0; $i--) {
        $d = (int) $n[$i];
        if ($alt) { $d *= 2; if ($d > 9) $d -= 9; }
        $sum += $d; $alt = !$alt;
    }
    return $sum % 10 === 0;
}
