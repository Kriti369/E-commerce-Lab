<?php
// POST -> creates an order for the signed-in customer, takes payment, records the result
declare(strict_types=1);
require __DIR__ . '/config.php';
require __DIR__ . '/payment.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail(405, 'Method not allowed.');

/** Builds the response for an order from what is stored in the database. */
function order_result(PDO $pdo, int $id): array {
    $o = $pdo->prepare('SELECT ref, status, total_cents FROM orders WHERE id = ?');
    $o->execute([$id]); $o = $o->fetch();
    $p = $pdo->prepare('SELECT note FROM payments WHERE order_id = ? ORDER BY id DESC LIMIT 1');
    $p->execute([$id]); $note = $p->fetchColumn();
    $i = $pdo->prepare('SELECT book_id, title FROM order_items WHERE order_id = ?');
    $i->execute([$id]);
    $downloads = [];
    if ($o['status'] === 'paid') {
        foreach ($i->fetchAll() as $r) {
            $downloads[] = ['title' => $r['title'], 'url' => 'api/download.php?ref=' . $o['ref'] . '&book=' . $r['book_id']];
        }
    }
    return [
        'ok' => $o['status'] === 'paid', 'status' => $o['status'], 'ref' => $o['ref'],
        'total' => $o['total_cents'] / 100,
        'message' => $o['status'] === 'pending' ? 'This order is still being processed.' : (string) $note,
        'downloads' => $downloads,
    ];
}
function respond(array $r): never {
    json_out($r, $r['status'] === 'paid' ? 200 : ($r['status'] === 'pending' ? 409 : 402));
}

// ---- 1. Request checks: origin, JSON, CSRF, signed in, rate limit ---------------------------
$in   = post_json();
$user = current_user();
if (!$user) fail(401, 'Please sign in to place an order.');

$_SESSION['attempts'] = array_values(array_filter($_SESSION['attempts'] ?? [], fn($t) => $t > time() - 600));
if (count($_SESSION['attempts']) >= 10) fail(429, 'Too many attempts. Please wait a few minutes.');
$_SESSION['attempts'][] = time();

// ---- 2. Validate everything the browser sent ----------------------------------------------
$idem = (string) ($in['idem'] ?? '');
$ids  = $in['items'] ?? [];
$card = is_array($in['card'] ?? null) ? $in['card'] : [];
$num  = preg_replace('/\s+/', '', (string) ($card['number'] ?? ''));
$exp  = trim((string) ($card['exp'] ?? ''));
$cvc  = trim((string) ($card['cvc'] ?? ''));

if (!preg_match('/^[a-f0-9]{32,64}$/', $idem))    fail(400, 'Invalid request.');
if (!is_array($ids) || !$ids || count($ids) > 50) fail(422, 'Your cart is empty.');

$cat = catalog();
$ids = array_values(array_unique(array_map('strval', $ids)));
foreach ($ids as $id) {
    if (!isset($cat[$id])) fail(422, 'An item in your cart is no longer available. Refresh and try again.');
}
if (!preg_match('/^\d{13,19}$/', $num) || !luhn_ok($num)) fail(422, 'The card number is not valid.');
if (!preg_match('#^(0[1-9]|1[0-2])/(\d{2})$#', $exp, $m)
    || mktime(0, 0, 0, (int) $m[1] + 1, 1, 2000 + (int) $m[2]) <= time()) fail(422, 'The card has expired or the date is invalid.');
if (!preg_match('/^\d{3,4}$/', $cvc))              fail(422, 'Enter the 3 or 4 digit security code.');

$pdo = db();

// ---- 3. Same idempotency key = same order (protects against double submits) ---------------
$find = $pdo->prepare('SELECT id FROM orders WHERE idem_key = ? AND user_id = ?');
$find->execute([$idem, $user['id']]);
if ($row = $find->fetch()) respond(order_result($pdo, (int) $row['id']));

// ---- 4. Create the order as "pending" (one atomic transaction) ----------------------------
$total = array_sum(array_map(fn($id) => $cat[$id]['cents'], $ids));   // total computed on the SERVER
$ref   = 'IB-' . strtoupper(bin2hex(random_bytes(5)));
try {
    $pdo->beginTransaction();
    $pdo->prepare("INSERT INTO orders(user_id, ref, idem_key, name, email, total_cents, status, created_at)
                   VALUES(?,?,?,?,?,?,'pending',?)")
        ->execute([$user['id'], $ref, $idem, $user['name'], $user['email'], $total, time()]);
    $orderId = (int) $pdo->lastInsertId();
    $item = $pdo->prepare('INSERT INTO order_items(order_id, book_id, title, price_cents) VALUES(?,?,?,?)');
    foreach ($ids as $id) $item->execute([$orderId, $id, $cat[$id]['title'], $cat[$id]['cents']]);
    $pdo->commit();
} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $find->execute([$idem, $user['id']]);                      // two clicks raced each other
    if ($row = $find->fetch()) respond(order_result($pdo, (int) $row['id']));
    error_log('Order create failed: ' . $e->getMessage());
    fail(500, 'We could not create your order. Please try again.');
}

// ---- 5. Take payment (no database lock is held while we wait for the "bank") --------------
try {
    $pay = process_payment($total, $num, $exp, $cvc);
} catch (Throwable $e) {
    error_log('Payment error: ' . $e->getMessage());
    $pay = ['approved' => false, 'ref' => 'error', 'note' => 'Payment service unavailable. You have not been charged.'];
}
$last4 = substr($num, -4);
unset($num, $cvc, $exp, $card, $in);                           // card data is never stored or logged

// ---- 6. Record the outcome atomically: order status + payment row -------------------------
try {
    $pdo->beginTransaction();
    $upd = $pdo->prepare("UPDATE orders SET status = ? WHERE id = ? AND status = 'pending'");
    $upd->execute([$pay['approved'] ? 'paid' : 'failed', $orderId]);   // can only change once
    if ($upd->rowCount() === 1) {
        $pdo->prepare('INSERT INTO payments(order_id, status, card_last4, provider_ref, note, created_at)
                       VALUES(?,?,?,?,?,?)')
            ->execute([$orderId, $pay['approved'] ? 'approved' : 'declined', $last4, $pay['ref'], $pay['note'], time()]);
    }
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('Order finalise failed (' . $ref . '): ' . $e->getMessage());
    fail(500, 'We could not record your payment. Quote reference ' . $ref . ' to support.');
}
respond(order_result($pdo, $orderId));
