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

$user_id = (int) ($_SESSION['user_id'] ?? 0);

try {
    $notif_now = (string) $conn->query('SELECT NOW()')->fetchColumn();
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
        // Ignore read errors and fail safely.
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
        // Ignore write errors and keep page usable.
    }
}

$notif_cutoff_at = $notif_seen_at;
if (!empty($notif_cleared_at) && (string) $notif_cleared_at > (string) $notif_cutoff_at) {
    $notif_cutoff_at = $notif_cleared_at;
}

$notif_items = [];
$notif_has_new = false;
$notif_new_my_classes = 0;
$notif_unread_total = 0;
$notif_badge_label = '';

$instructor_id = (int) ($_SESSION['user_id'] ?? 0);

try {
    $s_cols = [];
    try {
        $s_stmt = $conn->prepare('DESCRIBE schedules');
        $s_stmt->execute();
        foreach ($s_stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (!empty($r['Field'])) {
                $s_cols[$r['Field']] = true;
            }
        }
    } catch (Throwable $e) {
        $s_cols = [];
    }

    $subjects_enabled = false;
    if (isset($s_cols['subject_id'])) {
        try {
            $subjects_exists_stmt = $conn->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'subjects'");
            $subjects_exists_stmt->execute();
            $subjects_enabled = ((int) $subjects_exists_stmt->fetchColumn() > 0);
        } catch (Throwable $e) {
            $subjects_enabled = false;
        }
    }

    $subject_join_sql = $subjects_enabled
        ? 'LEFT JOIN subjects subj ON subj.id = s.subject_id'
        : '';
    $notif_course_code_expr = $subjects_enabled
        ? "COALESCE(subj.subject_code, c.course_code, 'N/A')"
        : "COALESCE(c.course_code, 'N/A')";
    $notif_course_name_expr = $subjects_enabled
        ? "COALESCE(subj.subject_name, c.course_name, 'Untitled Subject')"
        : "COALESCE(c.course_name, 'Untitled Subject')";

    // New classes assigned to this instructor.
    $schedule_ts_col = '';
    if (isset($s_cols['updated_at'])) {
        $schedule_ts_col = 'updated_at';
    } elseif (isset($s_cols['created_at'])) {
        $schedule_ts_col = 'created_at';
    }

    if ($instructor_id > 0 && $schedule_ts_col !== '') {
        $stmt = $conn->prepare("SELECT COUNT(*) FROM schedules WHERE instructor_id = :iid AND {$schedule_ts_col} > :cutoff_at AND {$schedule_ts_col} <= :now_ts");
        $stmt->execute([
            'iid' => $instructor_id,
            'cutoff_at' => $notif_cutoff_at,
            'now_ts' => $notif_now,
        ]);
        $notif_new_my_classes = (int) $stmt->fetchColumn();

        $select_end = isset($s_cols['end_time'])
            ? 's.end_time AS end_time'
            : (isset($s_cols['duration_minutes'])
                ? 'ADDTIME(s.start_time, SEC_TO_TIME(s.duration_minutes * 60)) AS end_time'
                : "ADDTIME(s.start_time, '02:00:00') AS end_time");

        $stmt = $conn->prepare("\n            SELECT\n                s.id,\n                s.{$schedule_ts_col} AS notif_ts,\n                s.day_of_week,\n                s.start_time,\n                {$select_end},\n                {$notif_course_code_expr} AS course_code,\n                {$notif_course_name_expr} AS course_name,\n                r.room_number\n            FROM schedules s\n            JOIN courses c ON c.id = s.course_id\n            {$subject_join_sql}\n            JOIN classrooms r ON r.id = s.classroom_id\n            WHERE s.instructor_id = :iid\n              AND s.{$schedule_ts_col} > :cutoff_at\n              AND s.{$schedule_ts_col} <= :now_ts\n            ORDER BY s.{$schedule_ts_col} DESC\n            LIMIT 5\n        ");
        $stmt->execute([
            'iid' => $instructor_id,
            'cutoff_at' => $notif_cutoff_at,
            'now_ts' => $notif_now,
        ]);

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $course = trim((string) ($r['course_code'] ?? '') . ' - ' . (string) ($r['course_name'] ?? ''));
            $start_label = !empty($r['start_time']) ? date('g:i A', strtotime((string) $r['start_time'])) : '';
            $end_label = !empty($r['end_time']) ? date('g:i A', strtotime((string) $r['end_time'])) : '';
            $when = trim((string) ($r['day_of_week'] ?? '') . ' ' . $start_label);
            if ($end_label !== '') {
                $when .= '-' . $end_label;
            }

            $room = trim((string) ($r['room_number'] ?? ''));
            $sub = $course;
            $extra = trim($when . ($room !== '' ? (' • Room ' . $room) : ''));
            if ($extra !== '') {
                $sub = ($sub !== '' ? ($sub . ' • ' . $extra) : $extra);
            }

            $schedule_id = (int) ($r['id'] ?? 0);
            $notif_items[] = [
                'ts' => (string) ($r['notif_ts'] ?? ''),
                'icon' => 'bi-calendar-plus',
                'title' => 'Class schedule updated',
                'subtitle' => $sub !== '' ? $sub : 'New schedule assigned',
                'href' => $schedule_id > 0 ? ('my_schedule.php?schedule_id=' . $schedule_id) : 'my_schedule.php',
            ];
        }
    }

    // Upcoming classes in the next 7 days (reminders).
    if ($instructor_id > 0) {
        $upcoming_end = isset($s_cols['end_time'])
            ? 's.end_time'
            : (isset($s_cols['duration_minutes'])
                ? 'ADDTIME(s.start_time, SEC_TO_TIME(s.duration_minutes * 60))'
                : "ADDTIME(s.start_time, '02:00:00')");

        $stmt = $conn->prepare("\n            SELECT\n                s.id,\n                s.day_of_week,\n                s.start_time,\n                {$upcoming_end} AS end_time,\n                {$notif_course_code_expr} AS course_code,\n                {$notif_course_name_expr} AS course_name,\n                r.room_number,\n                d.class_date\n            FROM (\n                SELECT CURDATE() AS class_date\n                UNION ALL SELECT CURDATE() + INTERVAL 1 DAY\n                UNION ALL SELECT CURDATE() + INTERVAL 2 DAY\n                UNION ALL SELECT CURDATE() + INTERVAL 3 DAY\n                UNION ALL SELECT CURDATE() + INTERVAL 4 DAY\n                UNION ALL SELECT CURDATE() + INTERVAL 5 DAY\n                UNION ALL SELECT CURDATE() + INTERVAL 6 DAY\n            ) d\n            JOIN schedules s ON s.day_of_week = DAYNAME(d.class_date)\n            JOIN courses c ON c.id = s.course_id\n            {$subject_join_sql}\n            JOIN classrooms r ON r.id = s.classroom_id\n            WHERE s.instructor_id = :iid\n              AND s.status = 'active'\n              AND (\n                    d.class_date > CURDATE()\n                 OR (d.class_date = CURDATE() AND s.start_time >= CURTIME())\n              )\n            ORDER BY d.class_date ASC, s.start_time ASC\n            LIMIT 5\n        ");
        $stmt->execute(['iid' => $instructor_id]);

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $course = trim((string) ($r['course_code'] ?? '') . ' - ' . (string) ($r['course_name'] ?? ''));
            $start_label = !empty($r['start_time']) ? date('g:i A', strtotime((string) $r['start_time'])) : '';
            $end_label = !empty($r['end_time']) ? date('g:i A', strtotime((string) $r['end_time'])) : '';
            $when = trim((string) ($r['day_of_week'] ?? '') . ' ' . $start_label);
            if ($end_label !== '') {
                $when .= '-' . $end_label;
            }

            $room = trim((string) ($r['room_number'] ?? ''));
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

    $notif_unread_total = (int) $notif_new_my_classes;
    $notif_badge_label = $notif_unread_total > 99 ? '99+' : (string) $notif_unread_total;
    $notif_has_new = $notif_unread_total > 0;

    usort($notif_items, static function ($a, $b) {
        $ta = strtotime((string) ($a['ts'] ?? '')) ?: 0;
        $tb = strtotime((string) ($b['ts'] ?? '')) ?: 0;
        return $tb <=> $ta;
    });
} catch (Throwable $e) {
    // Fail closed: no notifications.
    $notif_items = [];
    $notif_has_new = false;
    $notif_unread_total = 0;
    $notif_badge_label = '';
}
