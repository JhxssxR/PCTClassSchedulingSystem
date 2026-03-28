<?php
session_start();

require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'instructor') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}

$action = $_POST['action'] ?? 'seen';
try {
    $now = (string)$conn->query('SELECT NOW()')->fetchColumn();
} catch (Throwable $e) {
    $now = date('Y-m-d H:i:s');
}

if ($action === 'delete') {
    $_SESSION['notif_seen_at'] = $now;
    $_SESSION['notif_cleared_at'] = $now;

    try {
        $uid = (int)($_SESSION['user_id'] ?? 0);
        if ($uid > 0) {
            $stmt = $conn->prepare('INSERT INTO notification_state (user_id, notif_seen_at, notif_cleared_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE notif_seen_at = VALUES(notif_seen_at), notif_cleared_at = VALUES(notif_cleared_at)');
            $stmt->execute([$uid, $now, $now]);
        }
    } catch (Throwable $e) {
        // ignore
    }
    echo json_encode([
        'ok' => true,
        'action' => 'delete',
        'seen_at' => $_SESSION['notif_seen_at'],
        'cleared_at' => $_SESSION['notif_cleared_at'],
    ]);
    exit;
}

$_SESSION['notif_seen_at'] = $now;

try {
    $uid = (int)($_SESSION['user_id'] ?? 0);
    if ($uid > 0) {
        $stmt = $conn->prepare('INSERT INTO notification_state (user_id, notif_seen_at) VALUES (?, ?) ON DUPLICATE KEY UPDATE notif_seen_at = VALUES(notif_seen_at)');
        $stmt->execute([$uid, $now]);
    }
} catch (Throwable $e) {
    // ignore
}

echo json_encode([
    'ok' => true,
    'action' => 'seen',
    'seen_at' => $_SESSION['notif_seen_at'],
]);
