<?php
// Serves a purchased book, only to the signed-in customer who has a PAID order containing it.
declare(strict_types=1);
require __DIR__ . '/config.php';

$user = current_user();
if (!$user) { http_response_code(401); exit('Please sign in to download your books.'); }

$ref = (string) ($_GET['ref'] ?? '');
$id  = (string) ($_GET['book'] ?? '');
if (!preg_match('/^IB-[A-F0-9]{10}$/', $ref) || !preg_match('/^[a-z0-9-]+$/', $id)) { http_response_code(404); exit('Not found.'); }

$q = db()->prepare("SELECT i.title FROM orders o
                    JOIN order_items i ON i.order_id = o.id
                    WHERE o.ref = ? AND o.user_id = ? AND o.status = 'paid' AND i.book_id = ?");
$q->execute([$ref, $user['id'], $id]);
$book = $q->fetch();
if (!$book) { http_response_code(404); exit('Not found.'); }

// Put real files in /private/books/<book-id>.pdf (or .epub). Without one, a sample text file is sent.
foreach (['pdf' => 'application/pdf', 'epub' => 'application/epub+zip'] as $ext => $mime) {
    $path = PRIVATE_DIR . "/books/$id.$ext";
    if (is_file($path)) {
        header("Content-Type: $mime");
        header("Content-Disposition: attachment; filename=\"$id.$ext\"");
        header('Content-Length: ' . filesize($path));
        readfile($path);
        exit;
    }
}
header('Content-Type: text/plain; charset=utf-8');
header("Content-Disposition: attachment; filename=\"$id.txt\"");
echo $book['title'] . "\n\nThis is a placeholder for your assignment.\nAdd the real e-book as private/books/$id.pdf or .epub.\n";
