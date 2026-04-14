<?php
require_once __DIR__ . '/../config/app.php';

function activity_log_path(): string {
    return __DIR__ . '/../logs/activity.log';
}

function activity_log_ensure_directory(): void {
    $dir = dirname(activity_log_path());
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
}

function activity_log_write(string $event, ?int $user_id = null, ?string $role = null, array $details = []): void {
    activity_log_ensure_directory();

    $timestamp = gmdate('Y-m-d H:i:s');
    $session_user_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
    $session_role = isset($_SESSION['role']) ? (string)$_SESSION['role'] : null;
    $session_name = trim((string)($_SESSION['first_name'] ?? '') . ' ' . (string)($_SESSION['last_name'] ?? ''));

    $entry = [
        'timestamp' => $timestamp,
        'event' => $event,
        'user_id' => $user_id ?? $session_user_id,
        'role' => $role ?? $session_role,
        'name' => $session_name !== '' ? $session_name : null,
        'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
        'details' => $details,
    ];

    $line = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($line !== false) {
        file_put_contents(activity_log_path(), $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}

function activity_log_read(int $limit = 200): array {
    $path = activity_log_path();
    if (!is_file($path)) {
        return [];
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return [];
    }

    $limit = max(1, $limit);
    $lines = array_slice($lines, max(0, count($lines) - $limit));
    $entries = [];
    foreach ($lines as $line) {
        $row = json_decode($line, true);
        if (is_array($row) && !empty($row['timestamp']) && !empty($row['event'])) {
            $entries[] = $row;
        }
    }

    return array_reverse($entries);
}

function activity_format_timestamp_ph(string $timestamp): string {
    if ($timestamp === '') {
        return '';
    }

    try {
        $utc_time = new DateTimeImmutable($timestamp, new DateTimeZone('UTC'));
        return $utc_time->setTimezone(new DateTimeZone('Asia/Manila'))->format('Y-m-d H:i:s');
    } catch (Throwable $e) {
        return $timestamp;
    }
}

function activity_label(string $event): string {
    $map = [
        'login_success' => 'Logged in',
        'login_failed' => 'Login failed',
        'logout' => 'Logged out',
        'student_added' => 'Added student',
        'schedule_added' => 'Added schedule',
        'schedule_updated' => 'Updated schedule',
        'schedule_deleted' => 'Deleted schedule',
        'enrollment_added' => 'Added enrollment',
        'enrollment_status_updated' => 'Updated enrollment',
        'enrollment_deleted' => 'Deleted enrollment',
    ];

    return $map[$event] ?? ucwords(str_replace(['_', '-'], ' ', $event));
}

function activity_icon(string $event): string {
    $map = [
        'login_success' => 'bi-box-arrow-in-right',
        'login_failed' => 'bi-shield-exclamation',
        'logout' => 'bi-box-arrow-right',
        'student_added' => 'bi-mortarboard-plus',
        'schedule_added' => 'bi-calendar-plus',
        'schedule_updated' => 'bi-calendar-event',
        'schedule_deleted' => 'bi-calendar-x',
        'enrollment_added' => 'bi-person-plus',
        'enrollment_status_updated' => 'bi-arrow-repeat',
        'enrollment_deleted' => 'bi-trash',
    ];

    return $map[$event] ?? 'bi-clock-history';
}

function activity_badge_class(string $event): string {
    $map = [
        'login_success' => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
        'login_failed' => 'bg-rose-50 text-rose-700 ring-rose-200',
        'logout' => 'bg-slate-100 text-slate-700 ring-slate-200',
        'student_added' => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
        'schedule_added' => 'bg-blue-50 text-blue-700 ring-blue-200',
        'schedule_updated' => 'bg-amber-50 text-amber-700 ring-amber-200',
        'schedule_deleted' => 'bg-rose-50 text-rose-700 ring-rose-200',
        'enrollment_added' => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
        'enrollment_status_updated' => 'bg-indigo-50 text-indigo-700 ring-indigo-200',
        'enrollment_deleted' => 'bg-rose-50 text-rose-700 ring-rose-200',
    ];

    return $map[$event] ?? 'bg-slate-100 text-slate-700 ring-slate-200';
}
