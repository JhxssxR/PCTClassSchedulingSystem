<?php
// Shared notification data for Instructor pages.
// Expects: $conn (PDO), $_SESSION

if (!isset($_SESSION)) {
    @session_start();
}

if (!isset($conn) || !($conn instanceof PDO)) {
    $notif_items = [];
    $notif_has_new = false;
    $notif_seen_at = $_SESSION['notif_seen_at'] ?? null;
    return;
}

$user_id = (int)($_SESSION['user_id'] ?? 0);

try {
    $notif_now = (string)$conn->query('SELECT NOW()')->fetchColumn();
} catch (Throwable $e) {
    $notif_now = date('Y-m-d H:i:s');
}

$notif_seen_at = null;
$notif_cleared_at = null;

if ($user_id > 0) {
    try {
        $stmt = $conn->prepare('SELECT notif_seen_at, notif_cleared_at FROM notification_state WHERE user_id = ?');
        $stmt->execute([$user_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $notif_seen_at = $row['notif_seen_at'] ?? null;
            $notif_cleared_at = $row['notif_cleared_at'] ?? null;
        }
    } catch (Throwable $e) {
        // ignore
    }
}

if (empty($notif_seen_at)) {
    $notif_seen_at = $notif_now;
    try {
        if ($user_id > 0) {
            $stmt = $conn->prepare('INSERT INTO notification_state (user_id, notif_seen_at, notif_cleared_at) VALUES (?, ?, NULL) ON DUPLICATE KEY UPDATE notif_seen_at = VALUES(notif_seen_at)');
            $stmt->execute([$user_id, $notif_seen_at]);
        }
    } catch (Throwable $e) {
        // ignore
    }
}

$notif_cutoff_at = $notif_seen_at;
if (!empty($notif_cleared_at) && (string)$notif_cleared_at > (string)$notif_cutoff_at) {
    $notif_cutoff_at = $notif_cleared_at;
}

$notif_items = [];
$notif_has_new = false;
$notif_new_my_classes = 0;
$notif_unread_total = 0;
$notif_badge_label = '';

$instructor_id = (int)($_SESSION['user_id'] ?? 0);

try {
    // New classes assigned to me (schedules.created_at)
    $s_cols = [];
    try {
        $s_stmt = $conn->prepare('DESCRIBE schedules');
        $s_stmt->execute();
        foreach ($s_stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (!empty($r['Field'])) $s_cols[$r['Field']] = true;
        }
    } catch (Throwable $e) {
        $s_cols = [];
    }

    if ($instructor_id > 0 && isset($s_cols['created_at'])) {
        $stmt = $conn->prepare("SELECT COUNT(*) FROM schedules WHERE instructor_id = :iid AND created_at > :cutoff_at AND created_at <= :now_ts");
        $stmt->execute(['iid' => $instructor_id, 'cutoff_at' => $notif_cutoff_at, 'now_ts' => $notif_now]);
        $notif_new_my_classes = (int)$stmt->fetchColumn();

        $select_end = isset($s_cols['end_time']) ? ', s.end_time' : '';
        $stmt = $conn->prepare("
            SELECT s.id, s.created_at, s.day_of_week, s.start_time$select_end,
                   c.course_code, c.course_name,
                   r.room_number
            FROM schedules s
            JOIN courses c ON c.id = s.course_id
            JOIN classrooms r ON r.id = s.classroom_id
            WHERE s.instructor_id = :iid
              AND s.created_at > :cutoff_at
              AND s.created_at <= :now_ts
            ORDER BY s.created_at DESC
            LIMIT 5
        ");
        $stmt->execute(['iid' => $instructor_id, 'cutoff_at' => $notif_cutoff_at, 'now_ts' => $notif_now]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $course = trim(($r['course_code'] ?? '') . ' — ' . ($r['course_name'] ?? ''));
            $when = trim((string)($r['day_of_week'] ?? '') . ' ' . (string)($r['start_time'] ?? ''));
            if (!empty($r['end_time'])) {
                $when .= '-' . (string)$r['end_time'];
            }
            $room = trim((string)($r['room_number'] ?? ''));
            $sub = $course;
            $extra = trim($when . ($room !== '' ? (' • Room ' . $room) : ''));
            if ($extra !== '') {
                $sub = ($sub !== '' ? ($sub . ' • ' . $extra) : $extra);
            }

            $notif_items[] = [
                'ts' => (string)($r['created_at'] ?? ''),
                'icon' => 'bi-calendar-plus',
                'title' => 'New class assigned',
                'subtitle' => $sub !== '' ? $sub : 'New schedule',
                'href' => 'my_classes.php',
            ];
        }
    }

    // Upcoming classes in the next 7 days (reminders)
    if ($instructor_id > 0) {
        $stmt = $conn->prepare("
            SELECT s.id, s.day_of_week, s.start_time, s.end_time,
                   c.course_code, c.course_name,
                   r.room_number,
                   d.class_date
            FROM (
                SELECT CURDATE() AS class_date
                UNION ALL SELECT CURDATE() + INTERVAL 1 DAY
                UNION ALL SELECT CURDATE() + INTERVAL 2 DAY
                UNION ALL SELECT CURDATE() + INTERVAL 3 DAY
                UNION ALL SELECT CURDATE() + INTERVAL 4 DAY
                UNION ALL SELECT CURDATE() + INTERVAL 5 DAY
                UNION ALL SELECT CURDATE() + INTERVAL 6 DAY
            ) d
            JOIN schedules s ON s.day_of_week = DAYNAME(d.class_date)
            JOIN courses c ON c.id = s.course_id
            JOIN classrooms r ON r.id = s.classroom_id
            WHERE s.instructor_id = :iid
              AND s.status = 'active'
              AND (
                    d.class_date > CURDATE()
                 OR (d.class_date = CURDATE() AND s.start_time >= CURTIME())
              )
            ORDER BY d.class_date ASC, s.start_time ASC
            LIMIT 5
        ");
        $stmt->execute(['iid' => $instructor_id]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $course = trim(($r['course_code'] ?? '') . ' — ' . ($r['course_name'] ?? ''));
            $when = trim((string)($r['day_of_week'] ?? '') . ' ' . (string)($r['start_time'] ?? ''));
            if (!empty($r['end_time'])) {
                $when .= '-' . (string)$r['end_time'];
            }
            $room = trim((string)($r['room_number'] ?? ''));
            $sub = $course;
            $extra = trim($when . ($room !== '' ? (' • Room ' . $room) : ''));
            if ($extra !== '') {
                $sub = ($sub !== '' ? ($sub . ' • ' . $extra) : $extra);
            }
            $notif_items[] = [
                'ts' => $notif_now,
                'icon' => 'bi-clock-history',
                'title' => 'Upcoming class',
                'subtitle' => $sub !== '' ? $sub : 'Upcoming class',
                'href' => 'my_schedule.php',
            ];
        }
    }

    $notif_unread_total = (int)$notif_new_my_classes;
    $notif_badge_label = $notif_unread_total > 99 ? '99+' : (string)$notif_unread_total;
    $notif_has_new = $notif_unread_total > 0;

    usort($notif_items, function ($a, $b) {
        $ta = strtotime((string)($a['ts'] ?? '')) ?: 0;
        $tb = strtotime((string)($b['ts'] ?? '')) ?: 0;
        return $tb <=> $ta;
    });
    $notif_items = array_slice($notif_items, 0, 6);

} catch (Throwable $e) {
    // Fail closed: no notifications
    $notif_items = [];
    $notif_has_new = false;
    $notif_unread_total = 0;
    $notif_badge_label = '';
}
