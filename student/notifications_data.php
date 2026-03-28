<?php
// Shared notification data for Student pages.
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
$notif_new_enrollments = 0;
$notif_unread_total = 0;
$notif_badge_label = '';
$student_id = (int)($_SESSION['user_id'] ?? 0);

try {
    // Determine "active" enrollment status label
    $active_status = 'approved';
    try {
        $row = $conn->query("SHOW COLUMNS FROM enrollments LIKE 'status'")->fetch(PDO::FETCH_ASSOC);
        $type = (string)($row['Type'] ?? '');
        if (preg_match("/^enum\((.*)\)$/i", $type, $m)) {
            $vals = str_getcsv($m[1], ',', "'");
            $allowed = [];
            foreach ($vals as $v) {
                $allowed[strtolower(trim($v))] = true;
            }
            if (isset($allowed['approved'])) $active_status = 'approved';
            elseif (isset($allowed['enrolled'])) $active_status = 'enrolled';
        }
    } catch (Throwable $e) {
        // ignore
    }

        // Upcoming approved classes in the next 7 days
    if ($student_id > 0) {
        $stmt = $conn->prepare("
                        SELECT s.id, s.day_of_week, s.start_time, s.end_time,
                                     c.course_code, c.course_name,
                                     cr.room_number,
                                     i.first_name AS instructor_first,
                                     i.last_name AS instructor_last,
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
                        JOIN enrollments e ON e.schedule_id = s.id
                        JOIN courses c ON c.id = s.course_id
                        JOIN classrooms cr ON cr.id = s.classroom_id
                        JOIN users i ON i.id = s.instructor_id
                        WHERE e.student_id = :sid
                            AND e.status = :active
                            AND s.status = 'active'
                            AND (
                                        d.class_date > CURDATE()
                                 OR (d.class_date = CURDATE() AND s.start_time >= CURTIME())
                            )
                        ORDER BY d.class_date ASC, s.start_time ASC
                        LIMIT 6
        ");
                $stmt->execute(['sid' => $student_id, 'active' => $active_status]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $notif_new_enrollments = count($rows);
        foreach ($rows as $r) {
            $course = trim(($r['course_code'] ?? '') . ' — ' . ($r['course_name'] ?? ''));
            $classDate = (string)($r['class_date'] ?? '');
            $dayLabel = (string)($r['day_of_week'] ?? '');
            $dateLabel = '';
            if ($classDate !== '') {
                $ts = strtotime($classDate);
                if ($ts !== false) {
                    $dateLabel = date('M j, Y', $ts);
                }
            }
            $when = trim($dayLabel . ($dateLabel !== '' ? (' (' . $dateLabel . ')') : '') . ' ' . (string)($r['start_time'] ?? ''));
            if (!empty($r['end_time'])) {
                $when .= '-' . (string)$r['end_time'];
            }
            $room = trim((string)($r['room_number'] ?? ''));
            $inst = trim(($r['instructor_first'] ?? '') . ' ' . ($r['instructor_last'] ?? ''));
            $sub = $course;
            $extra = trim($when . ($room !== '' ? (' • Room ' . $room) : '') . ($inst !== '' ? (' • ' . $inst) : ''));
            if ($extra !== '') {
                $sub = ($sub !== '' ? ($sub . ' • ' . $extra) : $extra);
            }
            $title = 'Upcoming class';
            if ($classDate !== '' && $classDate === date('Y-m-d')) {
                $title = 'Today\'s class';
            }
            $notif_items[] = [
                'ts' => $notif_now,
                'icon' => 'bi-calendar-check',
                'title' => $title,
                'subtitle' => $sub !== '' ? $sub : 'Class schedule',
                'href' => 'dashboard.php',
            ];
        }
    }

    $notif_unread_total = (int)$notif_new_enrollments;
    $notif_badge_label = $notif_unread_total > 99 ? '99+' : (string)$notif_unread_total;
    $notif_has_new = $notif_unread_total > 0;

} catch (Throwable $e) {
    $notif_items = [];
    $notif_has_new = false;
    $notif_unread_total = 0;
    $notif_badge_label = '';
}
