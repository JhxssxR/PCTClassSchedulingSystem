<?php
session_start();

require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id']) || !in_array(($_SESSION['role'] ?? ''), ['admin', 'registrar', 'super_admin'], true)) {
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

try {
    $ns_cols = [];
    $stmt = $conn->prepare('DESCRIBE notification_state');
    $stmt->execute();
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if (!empty($r['Field'])) {
            $ns_cols[$r['Field']] = true;
        }
    }
    if (!isset($ns_cols['registrar_notif_seen_at'])) {
        $conn->exec('ALTER TABLE notification_state ADD COLUMN registrar_notif_seen_at DATETIME NULL AFTER notif_cleared_at');
    }
    if (!isset($ns_cols['registrar_notif_cleared_at'])) {
        $conn->exec('ALTER TABLE notification_state ADD COLUMN registrar_notif_cleared_at DATETIME NULL AFTER registrar_notif_seen_at');
    }
} catch (Throwable $e) {
    // keep compatibility with older schemas
}

if ($action === 'delete') {
    $_SESSION['registrar_notif_seen_at'] = $now;
    $_SESSION['registrar_notif_cleared_at'] = $now;
    try {
        $uid = (int)($_SESSION['user_id'] ?? 0);
        if ($uid > 0) {
            $stmt = $conn->prepare('INSERT INTO notification_state (user_id, registrar_notif_seen_at, registrar_notif_cleared_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE registrar_notif_seen_at = VALUES(registrar_notif_seen_at), registrar_notif_cleared_at = VALUES(registrar_notif_cleared_at)');
            $stmt->execute([$uid, $now, $now]);
        }
    } catch (Throwable $e) {
        // ignore
    }
    echo json_encode([
        'ok' => true,
        'action' => 'delete',
        'seen_at' => $_SESSION['registrar_notif_seen_at'],
        'cleared_at' => $_SESSION['registrar_notif_cleared_at'],
    ]);
    exit;
}

$_SESSION['registrar_notif_seen_at'] = $now;

try {
    $uid = (int)($_SESSION['user_id'] ?? 0);
    if ($uid > 0) {
        $stmt = $conn->prepare('INSERT INTO notification_state (user_id, registrar_notif_seen_at) VALUES (?, ?) ON DUPLICATE KEY UPDATE registrar_notif_seen_at = VALUES(registrar_notif_seen_at)');
        $stmt->execute([$uid, $now]);
    }
} catch (Throwable $e) {
    // ignore
}

echo json_encode([
    'ok' => true,
    'action' => 'seen',
    'seen_at' => $_SESSION['registrar_notif_seen_at'],
]);
