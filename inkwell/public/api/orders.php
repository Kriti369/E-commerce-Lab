<?php
// Returns the signed-in customer's purchase history, newest first.
declare(strict_types=1);
require __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') fail(405, 'Method not allowed.');
$user = current_user();
if (!$user) fail(401, 'Please sign in to see your orders.');

$pdo = db();
$orders = $pdo->prepare('SELECT id, ref, status, total_cents, created_at FROM orders WHERE user_id = ? ORDER BY id DESC LIMIT 100');
$orders->execute([$user['id']]);
$itemsQ = $pdo->prepare('SELECT book_id, title, price_cents FROM order_items WHERE order_id = ?');
$payQ   = $pdo->prepare('SELECT card_last4, note FROM payments WHERE order_id = ? ORDER BY id DESC LIMIT 1');

$out = [];
foreach ($orders->fetchAll() as $o) {
    $itemsQ->execute([$o['id']]);
    $payQ->execute([$o['id']]);
    $pay = $payQ->fetch() ?: [];
    $out[] = [
        'ref' => $o['ref'], 'status' => $o['status'], 'total' => $o['total_cents'] / 100,
        'created' => (int) $o['created_at'],
        'card_last4' => $pay['card_last4'] ?? null, 'note' => $pay['note'] ?? '',
        'items' => array_map(fn($i) => [
            'title' => $i['title'], 'price' => $i['price_cents'] / 100,
            'url' => $o['status'] === 'paid' ? 'api/download.php?ref=' . $o['ref'] . '&book=' . $i['book_id'] : null,
        ], $itemsQ->fetchAll()),
    ];
}
json_out(['ok' => true, 'user' => $user, 'orders' => $out]);
