<?php
// GET  -> { csrf, user }  (user is null when signed out)
// POST -> action = register | login | logout
declare(strict_types=1);
require __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] === 'GET') json_out(['csrf' => csrf_token(), 'user' => current_user()]);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail(405, 'Method not allowed.');

$in     = post_json();
$action = (string) ($in['action'] ?? '');
$pdo    = db();

function sign_in(int $userId): void {
    session_regenerate_id(true);          // new session id after login (prevents session fixation)
    $_SESSION['uid'] = $userId;
}

if ($action === 'logout') {
    $_SESSION = [];
    session_regenerate_id(true);
    json_out(['ok' => true]);
}

$email = strtolower(trim((string) ($in['email'] ?? '')));
$pw    = (string) ($in['password'] ?? '');
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 120) fail(422, 'Enter a valid email address.');

if ($action === 'register') {
    $name = trim((string) ($in['name'] ?? ''));
    if (mb_strlen($name) < 2 || mb_strlen($name) > 80) fail(422, 'Enter your full name.');
    if (strlen($pw) < 8 || strlen($pw) > 72)           fail(422, 'Password must be 8 to 72 characters long.');

    $exists = $pdo->prepare('SELECT 1 FROM users WHERE email = ?');
    $exists->execute([$email]);
    if ($exists->fetch()) fail(409, 'An account with this email already exists. Try signing in.');

    $pdo->prepare('INSERT INTO users(name, email, pass_hash, created_at) VALUES(?,?,?,?)')
        ->execute([$name, $email, password_hash($pw, PASSWORD_DEFAULT), time()]);
    sign_in((int) $pdo->lastInsertId());
    json_out(['ok' => true, 'user' => current_user()]);
}

if ($action === 'login') {
    // lock an account for 15 minutes after 5 wrong passwords
    $pdo->prepare('DELETE FROM login_fails WHERE ts < ?')->execute([time() - 900]);
    $cnt = $pdo->prepare('SELECT COUNT(*) FROM login_fails WHERE email = ?');
    $cnt->execute([$email]);
    if ((int) $cnt->fetchColumn() >= 5) fail(429, 'Too many failed attempts. Try again in 15 minutes.');

    $st = $pdo->prepare('SELECT id, pass_hash FROM users WHERE email = ?');
    $st->execute([$email]);
    $u = $st->fetch();
    if ($u) {
        $ok = password_verify($pw, $u['pass_hash']);
    } else {
        password_hash($pw, PASSWORD_DEFAULT);   // spend the same time, so response speed doesn't reveal which emails exist
        $ok = false;
    }
    if (!$ok) {
        $pdo->prepare('INSERT INTO login_fails(email, ts) VALUES(?,?)')->execute([$email, time()]);
        fail(401, 'Email or password is incorrect.');
    }
    $pdo->prepare('DELETE FROM login_fails WHERE email = ?')->execute([$email]);
    sign_in((int) $u['id']);
    json_out(['ok' => true, 'user' => current_user()]);
}

fail(400, 'Unknown action.');
